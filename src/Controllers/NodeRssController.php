<?php

/**
 * RSS feed generator.
 **/

declare(strict_types=1);

namespace Ufw1\Controllers;

use Psr\Log\LoggerInterface;
use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;
use Ufw1\Services\Template;
use Ufw1\Services\NodeRepository;
use Ufw1\Wiki\WikiService;

class NodeRssController extends CommonHandler
{
    /**
     * Template renderer.
     * @var Template
     **/
    protected $template;

    /**
     * Node repository.
     * @var NodeRepository
     **/
    protected $node;

    /**
     * Wiki renderer.
     **/
    protected $wiki;

    /**
     * @var LoggerInterface
     **/
    protected $logger;

    /**
     * Settings.
     * @var array
     **/
    protected $settings;

    public function __construct(Template $template, NodeRepository $node, WikiService $wiki, LoggerInterface $logger, $settings)
    {
        $this->settings = $settings['node']['rss'] ?? [];
        $this->template = $template;
        $this->node = $node;
        $this->logger = $logger;
        $this->wiki = $wiki;
    }

    public function onIndex(Request $request, Response $response, array $args): Response
    {
        $type = $args['type'] ?? null;

        if ($type !== null) {
            $nodes = $this->node->where('type = ? AND published = 1 AND deleted = 0 ORDER BY created DESC LIMIT 200', [$type]);
            $feed = $this->settings[$type] ?? [];
        } else {
            $nodes = $this->node->where('published = 1 AND deleted = 0 ORDER BY created DESC 100');
            $feed = $this->settings['default'] ?? [];
        }

        if (empty($nodes)) {
            $this->notfound();
        }

        $items = $this->formatItems($nodes, $request);

        if (isset($feed['limit'])) {
            $items = array_slice($items, 0, $feed['limit']);
        }

        return $this->renderXML($request, 'pages/nodes.rss.twig', [
            'feed' => $feed,
            'items' => $items,
        ]);
    }

    /**
     * Converts nodes to RSS items.
     **/
    protected function formatItems(array $nodes, Request $request): array
    {
        $base = $request->getUri()->getBaseUrl();

        $res = array_map(function (array $node) use ($base) {
            $title = $node['title'] ?? $node['name'] ?? 'No title';
            $description = $node['description'] ?? $node['subtitle'] ?? null;
            $link = $base . "/node/{$node['id']}";
            $guid = $base . "/node/{$node['id']}";
            $created = $node['created'];

            if ($node['type'] == 'wiki') {
                if (0 === strpos($node['name'], 'File:')) {
                    return null;
                }

                if (empty($node['source'])) {
                    $this->logger->debug('rss: wiki page "{name}" skipped: empty source.', [
                        'name' => $node['name'],
                    ]);
                    return null;
                }

                $page = $this->wiki->render($node['source']);

                $published = $page['published'] ?? true;
                if (!$published) {
                    $this->logger->debug('rss: wiki page "{name}" skipped: not published.', [
                        'name' => $node['name'],
                    ]);
                    return null;
                }

                $rss = $page['rss'] ?? null;
                if ($rss === 'off') {
                    $this->logger->debug('rss: wiki page "{name}" skipped: rss=off.', [
                        'name' => $node['name'],
                    ]);
                    return null;
                }

                $title = $page['title'] ?? $title;
                $description = $page['html'] ?? $description;

                $link = $base . "/wiki?name=" . urlencode($node['name']);
            }

            return [
                'title' => $title,
                'description' => $description,
                'link' => $link,
                'guid' => $guid,
                'created' => $created,
            ];
        }, $nodes);

        $res = array_filter($res);

        $res = array_values($res);

        return $res;
    }
}
