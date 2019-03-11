<?php

use Slim\Http\Request;
use Slim\Http\Response;

// CLI routes.
if (PHP_SAPI == 'cli') {
    $app->post('/cli/{action:.+}', '\Ufw1\Handlers\CLI:onDefault');
}

// Routes

$app->get ('/tasks/run-one', '\Ufw1\Handlers\Tasks:onRunOne');
$app->get ('/tasks/files/dump', '\Ufw1\Handlers\Files:onDump');

$app->get ('/wiki', '\Ufw1\Handlers\Wiki:onRead');
$app->get ('/wiki/backlinks', '\Ufw1\Handlers\Wiki:onBacklinks');
$app->get ('/wiki/edit', '\Ufw1\Handlers\Wiki:onEdit');
$app->post('/wiki/edit', '\Ufw1\Handlers\Wiki:onSave');
$app->post('/wiki/embed-clipboard', '\Ufw1\Handlers\Wiki:onEmbedClipboard');
$app->get ('/wiki/flush', '\Ufw1\Handlers\Wiki:onFlush');
$app->get ('/wiki/index', '\Ufw1\Handlers\Wiki:onIndex');
$app->get ('/wiki/migrate-to-node', '\Ufw1\Handlers\Wiki:onMigrate');
$app->get ('/wiki/recent', '\Ufw1\Handlers\Wiki:onRecent');
$app->get ('/wiki/recent.rss', '\Ufw1\Handlers\Wiki:onRecentRSS');
$app->get ('/wiki/reindex', '\Ufw1\Handlers\Wiki:onReindex');
$app->post('/wiki/upload', '\Ufw1\Handlers\Wiki:onUpload');
$app->get ('/tasks/reindex/wiki/{id:[0-9]+}', '\Ufw1\Handlers\Wiki:onReindexPage');

$app->any('/upload', '\Ufw1\Handlers\Upload');

$app->get('/files', '\Ufw1\Handlers\Files:onList');
$app->get('/files/{id:[0-9]+}/download', '\Ufw1\Handlers\Files:onDownload');
$app->get('/files/export', '\Ufw1\Handlers\Files:onExport');
$app->get('/files/{name:.*}', '\Ufw1\Handlers\File');
$app->get('/thumbnail/{name:.*}', \Ufw1\Handlers\Thumbnail::class . ':onGet');
$app->get('/i/{id:[0-9]+}.jpg', '\Ufw1\Handlers\Files:onThumbnail');
$app->get('/i/thumbnails/{id:[0-9]+}.jpg', '\Ufw1\Handlers\Files:onThumbnail');
$app->get('/i/photos/{id:[0-9]+}.jpg', '\Ufw1\Handlers\Files:onPhoto');

$app->get('/search', '\Ufw1\Handlers\Search:onGet');
$app->get('/search.xml', '\Ufw1\Handlers\Search:onGetXML');
$app->get('/search/suggest', '\Ufw1\Handlers\Search:onSuggest');

$app->get ('/sitemap.xml', '\Ufw1\Handlers\Sitemap:onGet');

$app->get('/admin/database', '\Ufw1\Handlers\Database:onStatus');
$app->get('/admin/tasks', '\Ufw1\Handlers\Admin:onTasks');
$app->get('/admin/warnings', '\Ufw1\Handlers\Admin:onWarnings');

$app->get('/login', '\Ufw1\Handlers\Account:onGetLoginForm');
$app->post('/login', '\Ufw1\Handlers\Account:onLogin');

$app->get('/', '\Ufw1\Handlers:getHome');
