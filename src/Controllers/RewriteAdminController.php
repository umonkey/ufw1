<?php

/**
 * Rewrite database management UI.
 **/

declare(strict_types=1);

namespace Ufw1\Controllers;

use Psr\Log\LoggerInterface;
use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;

class RewriteAdminController extends CommonHandler
{
    public function onEdit(Request $request, Response $response, array $args): Response
    {
        $user = $this->auth->requireAdmin($request);

        if ($request->getMethod() == 'POST') {
            $id = (int)$args['id'];
            $src = $request->getParam('src');
            $dst = $request->getParam('dst');

            if (empty($dst)) {
                $this->db->query('DELETE FROM rewrite WHERE id = ?', [$id]);
            } else {
                $this->db->update('rewrite', [
                    'src' => $src,
                    'dst' => $dst,
                ], [
                    'id' => $id,
                ]);
            }

            return $response->withJSON([
                'redirect' => '/admin/rewrite',
            ]);
        }

        else {
            $row = $this->db->fetchOne('SELECT * FROM rewrite WHERE id = ?', [$args['id']]);

            if (null === $row) {
                $this->notfound();
            }

            return $this->render($request, ['admin/rewrite-edit.twig'], [
                'user' => $user,
                'item' => $row,
            ]);
        }
    }

    public function onIndex(Request $request, Response $response, array $args): Response
    {
        $user = $this->auth->requireAdmin($request);

        $query = $request->getParam('query');

        $data = [];
        $data['user'] = $user;

        if (!empty($query)) {
            $data['rows'] = $this->db->fetch('SELECT id, src, dst FROM rewrite WHERE dst IS NOT NULL AND src LIKE ? ORDER BY src LIMIT 100', ['%' . $query . '%']);
        } else {
            $data['rows'] = $this->db->fetch('SELECT id, src, dst FROM rewrite WHERE dst IS NOT NULL ORDER BY src LIMIT 100');
        }

        return $this->render($request, ['admin/rewrite.twig'], $data);
    }
}
