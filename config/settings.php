<?php

$settings = [
    'site_name' => 'Домашняя база знаний',

    'displayErrorDetails' => true, // set to false in production
    'addContentLengthHeader' => false, // Allow the web server to send the content-length header

    // Renderer settings
    'renderer' => [
        'template_path' => __DIR__ . '/../templates/',
    ],

    // Template settings.
    'templates' => [
        'template_path' => __DIR__ . '/../templates',
    ],

    // Monolog settings
    'logger' => [
        'path' => ini_get("error_log"),
    ],

    'dsn' => [
        'name' => 'sqlite:' . __DIR__ . '/../data/database.sqlite',
        'user' => null,
        'password' => null,
    ],

    'files' => [
        'path' => dirname($_SERVER["DOCUMENT_ROOT"]) . "/data/files",
        'umask' => 0002,
    ],

    'wiki' => [
        'homePage' => 'Введение',
        'read_role' => 'admin',
        'edit_role' => 'admin',
    ],

    'node' => [
        'indexes' => [
            'file' => ['kind'],
        ],
    ],

    'interwiki' => [
        '@^yt:(.+)$@' => 'https://youtu.be/%s',
        '@^g:(.+)$@' => 'https://www.google.com/search?q=%s&ie=utf-8&oe=utf-8',
        '@^gi:(.+)$@' => 'https://www.google.com/search?q=%s&tbm=isch',
        '@^w:(.+)$@' => 'http://ru.wikipedia.org/w/index.php?title=Special:Search&search=%s',
        '@^we:(.+)$@' => 'http://en.wikipedia.org/w/index.php?title=Special:Search&search=%s',
        '@^ali:(.+)$@' => 'https://ru.aliexpress.com/wholesale?SearchText=%s',
    ],

    'thumbnails' => [
        'small' => [
            'width' => 200,
        ],
    ],

    'opensearch' => [
        'name' => 'Bugs',
        'description' => 'Поиск по домашней базе знаний',
    ],
];

if (is_readable($fn = __DIR__ . '/../.env.php')) {
    include $fn;
}

if (is_readable($fn = __DIR__ . '/../.env.' . $_ENV['APP_ENV'] . '.php')) {
    include $fn;
}

return ['settings' => $settings];
