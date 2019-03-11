<?php
// DIC configuration

define("DATA_DIR", realpath(dirname($_SERVER["DOCUMENT_ROOT"]) . "/data"));

$container = $app->getContainer();

// view renderer
$container['renderer'] = function ($c) {
    $settings = $c->get('settings')['renderer'];
    return new Slim\Views\PhpRenderer($settings['template_path']);
};

$container['template'] = function ($c) {
    $settings = $c->get('settings')['templates'];
    $tpl = new \Ufw1\Template($c);
    return $tpl;
};

$container['notFoundHandler'] = function ($c) {
    return function ($request, $response) use ($c) {
        $h = new \Ufw1\Handlers\NotFound($c);
        return $h($request, $response, []);
    };
};

$container['errorHandler'] = function ($c) {
    return function ($request, $response, $e) use ($c) {
        $h = new \Ufw1\Handlers\Error($c);
        return $h($request, $response, ["exception" => $e]);
    };
};

$container['logger'] = function ($c) {
    $settings = (array)$c->get('settings')['logger'];
    $logger = new \Ufw1\Logger($settings);
    return $logger;
};


// database
$container['database'] = function ($c) {
    return new \Ufw1\Database($c->get("settings")["dsn"]);
};


function debug()
{
    while (ob_get_level())
        ob_end_clean();

    header("HTTP/1.0 503 Debug");
    header("Content-Type: text/plain; charset=utf-8");
    call_user_func_array("var_dump", func_get_args());
    print "---\n";

    ob_start();
    debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    $stack = ob_get_clean();
    $stack = str_replace(dirname(__DIR__) . "/", "", $stack);
    print $stack;

    die();
}
