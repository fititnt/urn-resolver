<?php

declare(strict_types=1);

namespace URNResolver;

date_default_timezone_set('UTC');
define("ROOT_PATH", dirname(dirname(__FILE__)));
define("RESOLVER_RULE_PATH", ROOT_PATH . '/public/.well-known/urn');

// https://www.php-fig.org/psr/psr-12/

function debug()
{
    $info = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $chunks = explode('_', $key);
            $header = '';
            for ($i = 1; $y = sizeof($chunks) - 1, $i < $y; $i++) {
                $header .= ucfirst(strtolower($chunks[$i])).'-';
            }
            $header .= ucfirst(strtolower($chunks[$i])).': '.$value;
            array_push($info, $header);
            // echo $header."\n";
        }
    }
    return $info;
}

class Config
{
    public string $base_iri;
    public array $resolver_status_pages;

    public function __construct()
    {
        $source_config = ROOT_PATH . '/urnresolver.dist.conf.json';
        $conf = json_decode(file_get_contents($source_config), true);
        if (\file_exists(ROOT_PATH . '/urnresolver.conf.json')) {
            $source_config2 = ROOT_PATH . '/urnresolver.conf.json';
            $conf2 = json_decode(file_get_contents($source_config2), true);
            $conf = array_replace_recursive($conf, $conf2);
        }
        // $json = file_get_contents($source_config);
        // die($json);

        // var_dump($conf);
        // die($conf);

        $this->base_iri = $conf['base_iri'] ?? null;
        $this->resolver_status_pages = $conf['resolver_status_pages'] ?? null;
        // $this->aaa = $conf->aaa ?? null;
    }
}

class Response
{
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function execute_redirect($objective_iri)
    {
        http_response_code($this->active_urn_to_httpstatus);
        // @see https://developers.cloudflare.com/cache/about/cache-control/
        // This log really needs be reviewned later
        header('Cache-Control: public, max-age=3600, s-maxage=600, stale-while-revalidate=600, stale-if-error=600');
        // header('Vary: Accept-Encoding');
        header("Access-Control-Allow-Origin: *");
        // header('Location: ' . $this->active_urn_to_uri);
        header('Location: ' . $objective_iri);
    }
}

class Router
{
    private $resolvers = array();
    private $active_base;
    private $active_uri;
    private $active_urn = false;
    private $active_urn_to_uri = false;
    private $active_urn_to_httpstatus = 302;
    private $active_rule_prefix = false;
    private $active_rule_conf = false;
    private $_logs = [];
    private $_is_error = null;
    private $_is_home = false;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->active_base = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $this->active_uri = ltrim($_SERVER['REQUEST_URI'], '/');

        if (strlen($this->active_uri) == 0) {
            $this->_is_home = true;
        } elseif (strpos($this->active_uri, 'urn:') == 0) {
            $this->active_urn = $this->active_uri;
        }
        // $this->resolvers = [];
        $this->_init_rules();
    }

    private function _init_rules()
    {
        $urns_pattern_list = [];
        foreach (glob(RESOLVER_RULE_PATH . "/*.urnr.json") as $filepath) {
            $filename = str_replace(RESOLVER_RULE_PATH, '', $filepath);
            $filename = ltrim($filename, '/');
            // $urn_prefix = str_replace('.urnr.yml', '', $filename) . ':';
            $urn_pattern = str_replace('.urnr.json', '', $filename);
            $this->resolvers[$urn_pattern] = $filepath;
            array_push($urns_pattern_list, $urn_pattern);
        }

        usort($urns_pattern_list, function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });

        $this->_is_error = true;
        foreach ($urns_pattern_list as $key => $urn_pattern) {
            $full_pattern = '/' . $urn_pattern . '/i';
            $matches = null;
            // if (str_starts_with($this->active_uri, $value)) {
            if (preg_match($full_pattern, $this->active_uri, $matches)) {
                $this->active_rule_prefix = $urn_pattern;
                $json = file_get_contents($this->resolvers[$urn_pattern]);
                // Decode the JSON file
                $json_data = json_decode($json, false);
                // $this->active_rule_conf = [$json_data, $matches, $urn_pattern];
                $this->active_rule_conf = $json_data;
                $this->_is_error = false;
                $this->_rule_calc($full_pattern);
                break;
            }
        }

        return $this->resolvers;
    }

    // private function _rule_calc($in_urn_rule, $active_rule)
    private function _rule_calc(string $urn_pattern)
    {
        $all_options = [];
        // $in_urn_rule = 'TODO';
        $matches = null;
        preg_match($urn_pattern, $this->active_uri, $matches);
        foreach ($matches as $key => $value) {
            $all_options['{{ in[' . (string) $key . '] }}'] = $value;
            // array_push($this->_logs, $in_urn_rule);
        }

        // $out_iri = $this->active_rule_conf->rules[0]['iri'];
        // @TODO implement load balancing on this part: out[0]
        $rule = $this->active_rule_conf->rules[0];

        if (is_array($rule->out)) {
            $out_rule = $rule->out[0];
        } else {
            $out_rule = $rule->out;
        }

        $out_iri = $out_rule->iri;

        if (isset($out_rule->http_status)) {
            $out_http_status = $out_rule->http_status;
            if ($out_http_status) {
                $this->active_urn_to_httpstatus = $out_http_status;
            }
        }

        if ($this->active_rule_conf == false && empty($this->active_urn)) {
            $this->_is_error = true;
            return false;
        }

        // array_push($this->_logs, $out_iri);
        // array_push($this->_logs, $this->active_rule_conf);

        $iri_final = strtr($out_iri, $all_options);
        $this->active_urn_to_uri = $iri_final ;
    }

    public function meta()
    {
        // $rule = ltrim($_SERVER['REQUEST_URI'], '/');
        $meta = [
            // 'REQUEST_URI' => $_SERVER['REQUEST_URI'],
            'active_rule_prefix' => $this->active_rule_prefix,
            'active_rule_conf' => $this->active_rule_conf,
            'active_urn' => $this->active_urn,
            'active_urn_to_httpstatus' => $this->active_urn_to_httpstatus,
            'active_urn_to_uri' => $this->active_urn_to_uri,
            // 'rules' => $this->_init_rules(),
            // '_all' => var_export($this, true),
            // '_logs' => $this->_logs,
        ];

        return $meta;
    }

    public function execute()
    {
        http_response_code($this->active_urn_to_httpstatus);
        // @see https://developers.cloudflare.com/cache/about/cache-control/
        // This log really needs be reviewned later
        header('Cache-Control: public, max-age=3600, s-maxage=600, stale-while-revalidate=600, stale-if-error=600');
        // header('Vary: Accept-Encoding');
        header("Access-Control-Allow-Origin: *");
        header('Location: ' . $this->active_urn_to_uri);
        die();
        // header("HTTP/1.1 301 Moved Permanently");
    }

    public function execute_welcome()
    {
        if (!$this->_is_home && $this->_is_error) {
            http_response_code(404);
            header("Content-type: application/json; charset=utf-8");
            header("Access-Control-Allow-Origin: *");
            header('Cache-Control: public, max-age=900, s-maxage=900, stale-while-revalidate=120');

            $result = (object) [
                '$schema' => 'https://jsonapi.org/schema',
                '$id' => $this->active_base,
                '@context' => 'https://urn.etica.ai/urnresolver-context.jsonld',
                'error' => [
                    'status' => 404,
                    'title' => 'Not found',
                    ],
              ];

            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            die();
        }

        header("Content-type: application/json; charset=utf-8");
        header("Access-Control-Allow-Origin: *");
        header('Cache-Control: public, max-age=600, s-maxage=60, stale-while-revalidate=600, stale-if-error=600');

        $resolver_paths = [];
        foreach ($this->resolvers as $key => $value) {
            $parts = explode('/.well-known/urn/', $value);
            array_shift($parts);
            $path = '/.well-known/urn/' . $parts[0];
            $resolver_paths[$key] = $path;
        }

        $result = (object) [
            '$schema' => 'https://jsonapi.org/schema',
            '$id' => $this->active_base . $this->active_uri,
            '@context' => 'https://urn.etica.ai/urnresolver-context.jsonld',
            'data' => [
                'resolvers' => $resolver_paths,
                ],
            'meta' => (object) [
                '@type' => 'schema:Message',
                'schema:name' => 'URN Resolver',
                'schema:dateCreated' => date("c"),
                // 'schema:mainEntityOfPage' => 'https://github.com/EticaAI/urn-resolver',
                "schema:potentialAction" => (object) [
                    "schema:name" => "uptime",
                    "schema:url" => "https://stats.uptimerobot.com/jYDZlFY8jq"
                ]
            ]
          ];

        http_response_code(200);
        // $result->_debug['_router'] =  $this->meta();
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        die();
    }

    public function is_success()
    {
        return isset($this->active_urn_to_uri) and !empty($this->active_urn_to_uri);
    }
}
