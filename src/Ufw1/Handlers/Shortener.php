<?php
/**
 * URL shortener.
 **/

namespace Ufw1\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;


class Shortener extends \Ufw1\CommonHandler
{
    /**
     * Process a single link.
     **/
    public function onShorten(Request $request, Response $response, array $args)
    {
        $user = $this->requireAdmin($request);

        $link = $request->getParam('target');
        $key = md5($link);

        $this->db->beginTransaction();

        $node = $this->node->getByKey($key);

        if ($node and $node['type'] != 'sokr')
            $this->fail('Невозможно создать ссылку: коллизия.');

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

        return $response->withJSON([
            'refresh' => true,
        ]);
    }

    /**
     * Display shortener UI.
     **/
    public function onPreview(Request $request, Response $response, array $args)
    {
        $user = $this->requireUser($request);

        $src = $request->getParam('url');
        $dst = $request->getUri()->getBaseUrl() . $this->shorten($src);

        return $this->render($request, 'shortener.twig', [
            'user' => $user,
            'src' => $src,
            'dst' => $dst,
        ]);
    }

    /**
     * Handle a redirect.
     **/
    public function onRedirect(Request $request, Response $response, array $args)
    {
        $link = $args['link'];
        $id = intval($link, 36);

        $node = $this->node->get($id);

        if (empty($node))
            $this->notfound();

        if ($node['deleted'] == 1)
            $this->gone();

        if ($node['published'] == 0)
            $this->forbidden();

        // TODO: log access

        return $response->withRedirect($node['target']);
    }

    /**
     * Add handlers to the routing table.
     *
     * Call this from within src/routes.php
     **/
    public static function setupRoutes(&$app)
    {
        $class = get_called_class();

        $app->post('/admin/shortener', $class . ':onShorten');
        $app->get ('/l/{link}',        $class . ':onRedirect');
        $app->get ('/shorten',         $class . ':onPreview');
    }

    protected function shorten($link)
    {
        $key = md5($link);

        $this->db->beginTransaction();

        $node = $this->node->getByKey($key);

        if ($node and $node['type'] != 'sokr')
            $this->fail('Невозможно создать ссылку: коллизия.');

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
