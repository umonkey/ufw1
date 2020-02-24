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
        Controllers\FileController::setupRoutes($app);
    }

    public static function installHome(App $app): void
    {
        $app->get('/', 'Ufw1\Controllers\HomeController:onIndex');
    }

    public static function installNode(App $app): void
    {
        $app->get('/node/{type:[a-z0-9]+}.xml', 'Ufw1\Controllers\NodeRssController:onIndex');
        $app->post('/admin/nodes.json', 'Ufw1\Controllers\NodeJsonController:onIndex');
    }

    public static function installRewrite(App $app): void
    {
        $app->get('/admin/rewrite', 'Ufw1\Controllers\RewriteAdminController:onIndex');
        $app->any('/admin/rewrite/{id:[0-9]+}/edit', 'Ufw1\Controllers\RewriteAdminController:onEdit');
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
        $app->get ('/wiki',                   'Ufw1\Wiki\Actions\ShowWikiPageAction');
        $app->get ('/wiki/edit',              'Ufw1\Wiki\Actions\ShowEditorAction');
        $app->post('/wiki/edit',              'Ufw1\Wiki\Actions\UpdatePageAction');
        $app->get ('/wiki/index',             'Ufw1\Wiki\Actions\IndexAction');
        $app->get ('/wiki/recent-files.json', 'Ufw1\Wiki\Actions\RecentFilesAction');
        $app->get ('/wiki/reindex',           'Ufw1\Wiki\Actions\ReindexAction');
        $app->any ('/wiki/upload',            'Ufw1\Wiki\Actions\UploadAction');
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

        $container['callableResolver'] = function ($c) {
            return new CallableResolver($c);
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
            $settings = $c->get('settings')['files'] ?? [];
            return new Services\FileRepository($logger, $node, $settings);
        };

        $container['fts'] = function ($c) {
            $db = $c->get('db');
            $logger = $c->get('logger');
            $stemmer = $c->get('stemmer');
            $wiki = $c->get('wiki');
            return new Services\Search($db, $logger, $stemmer, $wiki);
        };

        $container['http'] = function ($c) {
            $logger = $c->get('logger');
            return new Services\HttpService($logger);
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
            if (empty($settings)) {
                throw new \RuntimeException('node service not configured');
            }
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
            $settings = $c->get('settings')['taskq'] ?? [];
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
            $file = $c->get('file');

            if (class_exists('\Imagickx')) {
                return new Services\Thumbnailer2($config, $logger, $file);
            } else {
                return new Services\Thumbnailer($config, $logger, $file);
            }
        };

        $container['vk'] = function ($c) {
            $logger = $c->get('logger');
            $http = $c->get('http');
            $settings = $c->get('settings')['vk'];
            return new Services\VkService($logger, $http, $settings);
        };

        $container['wiki'] = function ($c) {
            $settings = $c->get('settings')['wiki'];
            $node = $c->get('node');
            $logger = $c->get('logger');
            $t = new Wiki\WikiService($settings, $node, $logger);
            return $t;
        };
    }
}
