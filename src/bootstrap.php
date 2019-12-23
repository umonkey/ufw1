<?php

require_once __DIR__ . '/functions.php';

if (!defined('DATA_DIR')) {
    define('DATA_DIR', realpath(dirname($_SERVER['DOCUMENT_ROOT']) . '/data'));
}

/**
 * Default exception handler for unhandled exceptions.
 **/
set_exception_handler(function ($e) {
    while (ob_get_level())
        ob_end_clean();

    $trace = $e->getTraceAsString();
    $trace = str_replace(dirname(__DIR__) . '/', '', $trace);

    header('HTTP/1.0 500 Internal Server Error');
    header('Content-Type: text/plain; charset=utf-8');

    printf("Fatal %s: %s\n", get_class($e), $e->getMessage());
    printf("=== stack ===\n");
    printf("%s\n", $trace);
});

require __DIR__ . '/../vendor/autoload.php';

// Instantiate the app
$settings = require __DIR__ . '/../src/settings.php';
$app = new \Slim\App($settings);

// Set up dependencies
require __DIR__ . '/../src/dependencies.php';

// Register middleware
require __DIR__ . '/../src/middleware.php';

// Register routes
require __DIR__ . '/../src/routes.php';
