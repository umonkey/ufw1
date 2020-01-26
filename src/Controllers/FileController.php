<?php

/**
 * File related functions.
 **/

declare(strict_types=1);

namespace Ufw1\Controllers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;

class FileController extends CommonHandler
{
    /**
     * Download a file.
     *
     * URL: /node/{id}/download/{size}
     *
     * If the file is on remote storage, issues a redirect.
     **/
    public function onDownload(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $size = $args['size'];

        $file = $this->file->get($id);
        if ($file['deleted'] == 1) {
            $this->logger->debug('file {0} is gone.', [$id]);
            $this->gone();
        }

        if ($file['published'] == 0) {
            $this->logger->debug('file {0} is not published.', [$id]);
            $this->forbidden();
        }

        if (empty($file['files'][$size])) {
            if (isset($this->thumbnailer)) {
                $file = $this->thumbnailer->updateNode($file);

                if (!empty($file['files'][$size])) {
                    $file = $this->container->get('node')->save($file);
                }
            }

            if (empty($file['files'][$size])) {
                $this->logger->debug('file {0} has no {1} size.', [$id, $size]);
                $this->notfound();
            }
        }

        if ($file['files'][$size]['storage'] == 'local') {
            $lpath = $file['files'][$size]['path'];
            $path = $this->file->fsgetpath($lpath);

            if (!file_exists($path)) {
                $this->logger->warning('file {0} does not exist in the storage -- {1}', [$id, $lpath]);
                $this->notfound();
            }

            $body = file_get_contents($path);
            return $this->sendCached($request, $body, $file['files'][$size]['type'], $file['created']);
        }

        if (!empty($file['files'][$size]['url'])) {
            return $response->withRedirect($file['files'][$size]['url']);
        }

        $this->logger->warning('don\'t know how to serve file {0}, data: {1}', [$id, $file['files'][$size]]);
        $this->unavailable();
    }

    public static function setupRoutes(&$app): void
    {
        $class = get_called_class();

        $app->get('/node/{id:[0-9]+}/download/{size}', $class . ':onDownload');
    }
}
