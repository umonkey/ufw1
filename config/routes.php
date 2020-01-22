<?php

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\Util;

Util::installAdmin($app);
Util::installWiki($app);

$app->get('/tasks/run-one', '\Ufw1\Handlers\Tasks:onRunOne');
$app->get('/tasks/files/dump', '\Ufw1\Handlers\Files:onDump');

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

$app->get('/sitemap.xml', '\Ufw1\Handlers\Sitemap:onGet');

$app->get('/login', '\Ufw1\Handlers\Account:onGetLoginForm');
$app->post('/login', '\Ufw1\Handlers\Account:onLogin');

$app->get('/', '\Ufw1\Handlers:getHome');
