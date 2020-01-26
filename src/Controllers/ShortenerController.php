<?php

/**
 * URL shortener.
 **/

declare(strict_types=1);

namespace Ufw1\Controllers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;

class ShortenerController extends CommonHandler
{
    /**
     * Display shortener UI.
     **/
    public function onPreview(Request $request, Response $response, array $args): Response
    {
        $user = $this->auth->requireUser($request);

        $src = $request->getParam('url');
        $dst = $request->getUri()->getBaseUrl() . $this->shorten($src);

        return $this->render($request, 'pages/shortener.twig', [
            'user' => $user,
            'src' => $src,
            'dst' => $dst,
        ]);
    }

    /**
     * Handle a redirect.
     **/
    public function onRedirect(Request $request, Response $response, array $args): Response
    {
        $link = $args['link'];
        $id = intval($link, 36);

        $node = $this->node->get($id);

        if (empty($node)) {
            $this->notfound();
        }

        if ($node['deleted'] == 1) {
            $this->gone();
        }

        if ($node['published'] == 0) {
            $this->forbidden();
        }

        // TODO: log access

        return $response->withRedirect($node['target']);
    }

    /**
     * Process a single link.
     **/
    public function onShorten(Request $request, Response $response, array $args): Response
    {
        $user = $this->auth->requireAdmin($request);

        if ($link = $request->getParam('target')) {
            $key = md5($link);

            $this->db->beginTransaction();

            $node = $this->node->getByKey($key);

            if ($node and $node['type'] != 'sokr') {
                $this->fail('Невозможно создать ссылку: коллизия.');
            }

            $node = array_merge($node, [
                'type' => 'sokr',
                'key' => $key,
                'name' => null,
                'target' => $link,
                'published' => 1,
                'deleted' => 0,
            ]);

            $node = $this->node->save($node);

            $node['name'] = '/l/' . base_convert($node['id'], 10, 36);

            $node = $this->node->save($node);

            $this->db->commit();
        }

        return $response->withJSON([
            'refresh' => true,
        ]);
    }

    /**
     * Add handlers to the routing table.
     *
     * Call this from within src/routes.php
     **/
    public static function setupRoutes(&$app): void
    {
        $class = get_called_class();

        $app->post('/admin/shortener', $class . ':onShorten');
        $app->get('/l/{link}', $class . ':onRedirect');
        $app->get('/shorten', $class . ':onPreview');
    }

    protected function shorten(string $link): string
    {
        $key = md5($link);

        $this->db->beginTransaction();

        $node = $this->node->getByKey($key);

        if ($node and $node['type'] != 'sokr') {
            $this->fail('Невозможно создать ссылку: коллизия.');
        }

        $node = array_merge($node ?? [], [
            'type' => 'sokr',
            'key' => $key,
            'name' => null,
            'target' => $link,
            'published' => 1,
            'deleted' => 0,
        ]);

        $node = $this->node->save($node);

        $node['name'] = '/l/' . base_convert($node['id'], 10, 36);
        $node = $this->node->save($node);

        $this->db->commit();

        return $node['name'];
    }
}
