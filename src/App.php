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
        $app->get('/admin/errors', 'Ufw1\Errors\Actions\ListAction');
        $app->post('/admin/errors/{id:[0-9]+}', 'Ufw1\Errors\Actions\UpdateAction');
        $app->get('/admin/errors/{id:[0-9]+}', 'Ufw1\Errors\Actions\ShowErrorAction');
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
        Controllers\TaskQueueController::setupRoutes($app);
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
            return $this->resolve('Ufw1\Services\AuthService');
        };

        $container['accounts'] = function ($c) {
            return $this->resolve('Ufw1\Accounts\Accounts');
        };

        $container['callableResolver'] = function ($c) {
            return new CallableResolver($c);
        };

        $container['db'] = function ($c) {
            return $this->resolve('Ufw1\Services\Database');
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
            return $this->resolve('Ufw1\Services\Search');
        };

        $container['http'] = function ($c) {
            return $this->resolve('Ufw1\Services\HttpService');
        };

        $container['logger'] = function ($c) {
            return new Services\Logger($c->get('settings')['logger']);
        };

        $container['mail'] = function ($c) {
            return $c['callableResolver']->getClassInstance('Ufw1\Mail\Mail');
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
            return new \Ufw1\Node\NodeRepository($settings, $db, $logger);
        };

        $container['phpErrorHandler'] = function ($c) {
            return function ($request, $response, $e) use ($c) {
                $h = new Controllers\ErrorController($c);
                return $h($request, $response, ['exception' => $e]);
            };
        };

        $container['S3'] = function ($c) {
            $config = $c->get('settings')['S3'] ?? [];
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
            $settings = $c->get('settings')['telega'] ?? [];
            return new Services\Telega($settings, $logger);
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
            return $c['callableResolver']->getClassInstance('Ufw1\Wiki\WikiService');
        };
    }

    protected function resolve(string $className): object
    {
        $resolver = $this->getContainer()['callableResolver'];
        return $resolver->getClassInstance($className);
    }
}
