<?php

use Slim\Http\Request;
use Slim\Http\Response;

// CLI routes.
if (PHP_SAPI == 'cli') {
    $app->post('/cli/{action:.+}', '\App\Handlers\CLI:onDefault');
}

// Routes

$app->get ('/tasks/run-one', '\App\Handlers\Tasks:onRunOne');
$app->get ('/tasks/files/dump', '\App\Handlers\Files:onDump');

$app->get ('/wiki', '\App\Handlers\Wiki:onRead');
$app->get ('/wiki/backlinks', '\App\Handlers\Wiki:onBacklinks');
$app->get ('/wiki/edit', '\App\Handlers\Wiki:onEdit');
$app->post('/wiki/edit', '\App\Handlers\Wiki:onSave');
$app->post('/wiki/embed-clipboard', '\App\Handlers\Wiki:onEmbedClipboard');
$app->get ('/wiki/flush', '\App\Handlers\Wiki:onFlush');
$app->get ('/wiki/index', '\App\Handlers\Wiki:onIndex');
$app->get ('/wiki/migrate-to-node', '\App\Handlers\Wiki:onMigrate');
$app->get ('/wiki/recent', '\App\Handlers\Wiki:onRecent');
$app->get ('/wiki/recent.rss', '\App\Handlers\Wiki:onRecentRSS');
$app->get ('/wiki/reindex', '\App\Handlers\Wiki:onReindex');
$app->post('/wiki/upload', '\App\Handlers\Wiki:onUpload');
$app->get ('/tasks/reindex/wiki/{id:[0-9]+}', '\App\Handlers\Wiki:onReindexPage');

$app->any('/upload', '\App\Handlers\Upload');

$app->get('/files', '\App\Handlers\Files:onList');
$app->get('/files/{id:[0-9]+}/download', '\App\Handlers\Files:onDownload');
$app->get('/files/export', '\App\Handlers\Files:onExport');
$app->get('/files/{name:.*}', '\App\Handlers\File');
$app->get('/thumbnail/{name:.*}', \App\Handlers\Thumbnail::class . ':onGet');
$app->get('/i/{id:[0-9]+}.jpg', '\App\Handlers\Files:onThumbnail');
$app->get('/i/thumbnails/{id:[0-9]+}.jpg', '\App\Handlers\Files:onThumbnail');
$app->get('/i/photos/{id:[0-9]+}.jpg', '\App\Handlers\Files:onPhoto');

$app->get('/search', '\App\Handlers\Search:onGet');
$app->get('/search.xml', '\App\Handlers\Search:onGetXML');
$app->get('/search/suggest', '\App\Handlers\Search:onSuggest');

$app->get ('/sitemap.xml', '\App\Handlers\Sitemap:onGet');

$app->get('/admin/database', '\App\Handlers\Database:onStatus');
$app->get('/admin/tasks', '\App\Handlers\Admin:onTasks');
$app->get('/admin/warnings', '\App\Handlers\Admin:onWarnings');

$app->get('/login', '\App\Handlers\Account:onGetLoginForm');
$app->post('/login', '\App\Handlers\Account:onLogin');

$app->get('/', '\App\Handlers:getHome');
