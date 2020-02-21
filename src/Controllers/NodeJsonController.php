<?php

/**
 * Rerutn node list in json, for DataTables.
 **/

declare(strict_types=1);

namespace Ufw1\Controllers;

use Psr\Log\LoggerInterface;
use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;

class NodeJsonController extends CommonHandler
{
    public function onIndex(Request $request, Response $response, array $args): Response
    {
        $user = $this->auth->requireAdmin($request);
        $form = $request->getParsedBody();

        $order = $this->getOrder($form);
        $offset = (int)$form['start'];
        $limit = (int)$form['length'];

        $count = $this->node->count("deleted = 0 AND type NOT IN ('user', 'file')");
        $nodes = $this->node->where("deleted = 0 AND type NOT IN ('user', 'file') ORDER BY {$order[0][0]} {$order[0][1]} LIMIT {$offset}, {$limit}");

        $rows = array_map(function ($node) {
            return [
                (int)$node['id'],
                $node['name'] ?? $node['title'] ?? 'no name',
                $node['type'],
                (bool)$node['published'],
                (bool)$node['deleted'],
                $node['created'],
            ];
        }, $nodes);

        $res = [
            'recordsTotal' => $count,
            'recordsFiltered' => $count,
            'data' => $rows,
            'draw' => $form['draw'],
        ];

        return $response->withJSON($res);
    }


    protected function getOrder(array $form): array
    {
        return [['id', 'ASC']];
    }
}
