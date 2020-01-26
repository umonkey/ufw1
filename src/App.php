<?php

/**
 * Slim application with extensions.
 **/

namespace Ufw1;

use Slim\Container;

class App extends \Slim\App
{
    public function __construct(array $settings)
    {
        $container = new Container($settings);

        $this->setupContainer($container);

        parent::__construct($container);
    }


    /**
     * Install user account routes: login, logout etc.
     **/
    public static function installAccount(App $app): void
    {
        Controllers\AccountController::setupRoutes($app);
    }

    public static function installAdmin(App $app): void
    {
        Controllers\AdminController::setupRoutes($app);
    }

    public static function installFiles(App $app): void
    {
        Handlers\Files::setupRoutes($app);
    }

    public static function installSearch(App $app): void
    {
        $class = Controllers\SearchController::class;

        $app->get('/search', $class . ':onGet');
        $app->get('/search.xml', $class . ':onGetXML');
        $app->get('/search/suggest', $class . ':onSuggest');
    }

    public static function installShortener(App $app): void
    {
        Controllers\ShortenerController::setupRoutes($app);
    }

    public static function installSitemap(App $app): void
    {
        $class = Controllers\SitemapController::class;

        $app->get('/sitemap.xml', $class . ':onGet');
    }

    public static function installTaskQ(App $app): void
    {
        $class = Controllers\TaskQueueController::class;
    }

    public static function installUpload(App $app): void
    {
        $class = Controllers\UploadController::class;
    }

    public static function installWiki(App $app): void
    {
        $class = Controllers\WikiController::class;

        $app->get('/wiki', $class . ':onRead');
        $app->get('/wiki/edit', $class . ':onEdit');
        $app->post('/wiki/edit', $class . ':onSave');
        $app->post('/wiki/embed-clipboard', $class . ':onEmbedClipboard');
        $app->get('/wiki/index', $class . ':onIndex');
        $app->get('/wiki/recent', $class . ':onRecent');
        $app->get('/wiki/recent-files.json', $class . ':onRecentFiles');
        $app->get('/wiki/reindex', $class . ':onReindex');
        $app->any('/wiki/upload', $class . ':onUpload');
    }

    /**
     * Register all built in services.
     **/
    protected function setupContainer(Container $container)
    {
        $container['auth'] = function ($c) {
            $session = $c->get('session');
            $logger = $c->get('logger');
            $node = $c->get('node');
            return new Services\AuthService($session, $logger, $node);
        };

        $container['db'] = function ($c) {
            $dsn = $c->get('settings')['dsn'];
            return new Services\Database($dsn);
        };

        $container['errorHandler'] = function ($c) {
            return function ($request, $response, $e) use ($c) {
                $h = new Controllers\ErrorController($c);
                return $h($request, $response, ['exception' => $e]);
            };
        };

        $container['file'] = function ($c) {
            $logger = $c->get('logger');
            $node = $c->get('node');
            $settings = $c->get('settings');
            return new Services\FileRepository($logger, $node, $settings);
        };

        $container['fts'] = function ($c) {
            $db = $c->get('db');
            $logger = $c->get('logger');
            $stemmer = $c->get('stemmer');
            return new Services\Search($db, $logger, $stemmer);
        };

        $container['logger'] = function ($c) {
            return new Services\Logger($c->get('settings')['logger']);
        };

        $container['notFoundHandler'] = function ($c) {
            return function ($request, $response) use ($c) {
                throw new Errors\NotFound();
            };
        };

        $container['node'] = function ($c) {
            $db = $c->get('db');
            $logger = $c->get('logger');
            $settings = $c->get('settings')['node'];
            return new Services\NodeRepository($settings, $db, $logger);
        };

        $container['phpErrorHandler'] = function ($c) {
            return function ($request, $response, $e) use ($c) {
                $h = new Controllers\ErrorController($c);
                return $h($request, $response, ['exception' => $e]);
            };
        };

        $container['S3'] = function ($c) {
            $config = $c->get('settings')['S3'];
            $logger = $c->get('logger');
            $node = $c->get('node');
            $taskq = $c->get('taskq');
            return new Services\S3($config, $logger, $node, $taskq);
        };

        $container['session'] = function ($c) {
            $db = $c->get('db');
            $logger = $c->get('logger');
            return new Services\SessionService($db, $logger);
        };

        $container['stemmer'] = function ($c) {
            return new Services\Stemmer();
        };

        $container['taskq'] = function ($c) {
            $db = $c->get('db');
            $logger = $c->get('logger');
            $settings = $c->get('settings')['taskq'];
            return new Services\TaskQueue($db, $logger, $settings);
        };

        $container['telega'] = function ($c) {
            $logger = $c->get('logger');
            $settings = $c->get('settings')['telega'];
            return new Services\Telega($logger, $settings);
        };

        $container['template'] = function ($c) {
            $settings = $c->get('settings')['templates'];
            return new Services\Template($settings);
        };

        $container['thumbnailer'] = function ($c) {
            $config = $c->get('settings')['thumbnails'];
            $logger = $c->get('logger');

            if (class_exists('\Imagickx')) {
                return new Services\Thumbnailer2($config, $logger);
            } else {
                return new Services\Thumbnailer($config, $logger);
            }
        };

        $container['wiki'] = function ($c) {
            $settings = $c->get('settings')['wiki'];
            $node = $c->get('node');
            $logger = $c->get('logger');
            $t = new Services\Wiki($settings, $node, $logger);
            return $t;
        };
    }
}
