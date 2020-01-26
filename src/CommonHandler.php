<?php

/**
 * Base controller class.
 *
 * @todo Move user related code to a trait?
 **/

declare(strict_types=1);

namespace Ufw1;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\Controller;

class CommonHandler extends Controller
{
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        switch ($request->getMethod()) {
            case "GET":
                return $this->onGet($request, $response, $args);
            case "POST":
                return $this->onPost($request, $response, $args);
            default:
                debug($request);
        }
    }

    protected function search(string $query): array
    {
        return array_map(function ($em) {
            if (!($name = $em['meta']['title'])) {
                $name = substr($em['key'], 5);
            }

            if (!($link = $em['meta']['link'])) {
                $link = '/wiki?name=' . urlencode($name);
            }

            $snippet = Util::processTypography($em['meta']['snippet'] ?? '');
            $snippet = str_replace('&nbsp;', ' ', $snippet);

            return [
                'link' => $link,
                'title' => $name,
                'snippet' => $snippet,
                'updated' => @$em['meta']['updated'],
                'image' => @$em['meta']['image'],
                'words' => @$em['meta']['words'],
            ];
        }, $this->fts->search($query));
    }

    /**
     * Schedule task for background execution.
     *
     * TODO: delete.
     **/
    protected function taskq(string $action, array $data = [], int $priority = 0): int
    {
        $tq = $this->container->get('taskq');
        return $tq->add($action, $data, $priority);
    }

    /**
     * Returns the breadcrumbs array.
     *
     * @param Request $request Request info.
     * @param array $data Template data.
     * @return array Breadcrumbs info.
     **/
    public function getBreadcrumbs(Request $request, array $data): array
    {
        return [];
    }

    /**
     * Reads the file from the file storage.
     *
     * @param string $name File name, eg: "1/12/12345".
     **/
    protected function fsget(string $name): string
    {
        $st = $this->container->get("settings");

        if (empty($st['files']['path'])) {
            throw new \RuntimeException('file storage not configured');
        }

        $path = $st['files']['path'] . '/' . $name;

        if (!file_exists($path)) {
            throw new \RuntimeException("file {$name} is not readable");
        }

        return file_get_contents($path);
    }

    protected function fsput(string $body): string
    {
        $hash = md5($body);

        $name = substr($hash, 0, 1) . '/' . substr($hash, 1, 2) . '/' . $hash;

        $st = $this->container->get("settings");

        if (empty($st['files']['path'])) {
            throw new \RuntimeException('file storage not configured');
        }

        $path = $st['files']['path'] . '/' . $name;

        $dir = dirname($path);
        if (!is_dir($dir)) {
            $res = mkdir($dir, 0775, true);

            if ($res === false) {
                throw new \RuntimeException("could not create folder {$dir}");
            }
        }

        if (!file_put_contents($path)) {
            throw new \RuntimeException("file {$name} is not readable");
        }

        chmod($path, 0664);

        return $name;
    }
}
