<?php
/**
 * Basic administrative UI.
 **/

namespace Ufw1\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;

class Admin extends CommonHandler
{
    public function onDashboard(Request $request, Response $response, array $args)
    {
        $user = $this->requireAdmin($request);
        $warnings = $this->getWarnings();

        return $this->render($request, 'admin-dashboard.twig', [
            'user' => $user,
            'warnings' => $warnings,
        ]);
    }

    public function onNodeList(Request $request, Response $response, array $args)
    {
        $user = $this->requireAdmin($request);

        $st = $this->container->get('settings')['node_forms'] ?? null;
        if (empty($st)) {
            $this->logger->warning('admin: node_types not configured.');
            $this->unavailable();
        }

        $types = array_keys($st);

        if (isset($args['type']))
            $types = in_array($args['type'], $types) ? [$args['type']] : [];

        if (empty($types))
            $this->forbidden();

        $types = implode(', ', array_map(function ($em) {
            return "'{$em}'";
        }, $types));

        $nodes = $this->node->where("`deleted` = 0 AND `type` IN ({$types}) ORDER BY `id` DESC LIMIT 100");

        return $this->render($request, 'admin-nodes.twig', [
            'user' => $user,
            'nodes' => $nodes,
            'types' => $types,
            'selected_type' => $args['type'] ?? null,
        ]);
    }

    public function onDumpNode(Request $request, Response $response, array $args)
    {
        $user = $this->requireUser($request);

        $id = $args["id"];
        $node = $this->node->get($id);

        $node = json_encode($node, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->render($request, 'admin-dump.twig', [
            'user' => $user,
            'node' => $node,
        ]);
    }

    /**
     * Display node edit form.
     **/
    public function onEditNode(Request $request, Response $response, array $args)
    {
        $user = $this->requireAdmin($request);

        $id = $args['id'];
        if (!($node = $this->node->get($id)))
            $this->notfound();

        $st = $this->container->get('settings');
        $form = $st['node_forms'][$node['type']] ?? null;

        if (empty($form)) {
            $this->logger->error('admin: node_forms.{0} not defined.', [$node['type']]);
            $this->unavailable();
        }

        return $this->render($request, 'admin-edit-node.twig', [
            'user' => $user,
            'node' => $node,
            'form' => $form,
        ]);
    }

    /**
     * Edit raw node contents, in JSON.
     **/
    public function onEditRawNode(Request $request, Response $response, array $args)
    {
        $user = $this->requireAdmin($request);

        $id = $args['id'];
        if (!($node = $this->node->get($id)))
            $this->notfound();

        $code = json_encode($node, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $code = str_replace('\r', '\n', $code);

        return $this->render($request, 'admin-edit-raw.twig', [
            'user' => $user,
            'node' => $node,
            'code' => $code,
        ]);
    }

    /**
     * Save changes to a node.
     **/
    public function onSaveNode(Request $request, Response $response, array $args)
    {
        $this->db->beginTransaction();

        $user = $this->requireUser($request);

        $form = $request->getParam('node');

        if (isset($form['id'])) {
            $node = $this->node->get($form['id']);
        } elseif (isset($form['type'])) {
            $node = [
                'type' => $form['type'],
            ];
        } else {
            throw new \Ufw1\Errors\UserFailure('Neither node type nor id specified.');
        }

        // Check access.
        $config = $this->getNodeConfig($node['type']);
        if ($user['role'] != 'admin' and (empty($config['edit_roles']) or !in_array($user['role'], $config['edit_roles'])))
            $this->forbidden();

        if ($raw = $request->getParam('raw_node')) {
            $node = json_decode($raw, true);
            if ($node === null)
                $this->fail('Ошибка в коде.');
        } else {
            $node = array_merge($node, $form);
        }

        $node = $this->node->save($node);

        $next = $request->getParam('next');

        $this->db->commit();

        return $response->withJSON([
            'redirect' => $next ? $next : '/admin/nodes?edited=' . $node['id'],
        ]);
    }

    /**
     * Publish or unpublish a node.
     **/
    public function onPublishNode(Request $request, Response $response, array $args)
    {
        $this->db->beginTransaction();

        $user = $this->requireUser($request);

        $id = (int)$request->getParam('id');
        $published = (int)$request->getParam('published');

        if (!($node = $this->node->get($id)))
            $this->fail('Документ не найден.');

        // Check access.
        $config = $this->getNodeConfig($node['type']);
        if ($user['role'] != 'admin' and (empty($config['edit_roles']) or !in_array($user['role'], $config['edit_roles'])))
            $this->forbidden();

        $node['published'] = $published;
        $node = $this->node->save($node);

        $this->db->commit();

        return $response->withJSON([
            'success' => true,
        ]);
    }

    /**
     * Display basic database statistics.
     **/
    public function onDatabaseStatus(Request $request, Response $response, array $args)
    {
        $user = $this->requireAdmin($request);

        return $this->render($request, 'admin-dbstats.twig', [
            'dbtype' => $this->db->getConnectionType(),
            'tables' => $this->db->getStats(),
        ]);
    }

    /**
     * Display TaskQ status.
     **/
    public function onTaskQ(Request $request, Response $response, array $args)
    {
        $user = $this->requireAdmin($request);

        $tasks = $this->db->fetch("SELECT * FROM `taskq` ORDER BY `id` DESC", [], function ($row) {
            $payload = unserialize($row["payload"]);
            unset($row["payload"]);

            $row["action"] = $payload["__action"];

            $ts = strtotime($row["added"]);
            $age = time() - $ts;

            if ($age >= 86400) {
                $age = sprintf("%02u d", $age / 86400);
            } elseif ($age >= 3600) {
                $age = sprintf("%02u h", $age / 3600);
            } elseif ($age >= 60) {
                $age = sprintf("%02u m", $age / 60);
            } else {
                $age = sprintf("%02u s", $age);
            }

            return [
                "id" => $row["id"],
                "age" => $age,
                "action" => $payload["__action"],
                "attempts" => $row["attempts"],
                "priority" => $row["priority"],
            ];
        });

        $settings = $this->container->get('settings')['taskq'] ?? [];

        return $this->render($request, 'admin-taskq.twig', [
            'tab' => 'taskq',
            'tasks' => $tasks,
            'user' => $user,
            'settings' => $settings,
        ]);
    }

    public function onSubmitList(Request $request, Response $response, array $args)
    {
        $user = $this->requireAdmin($request);

        $st = $this->container->get('settings')['node_forms'];

        $types = [];
        foreach ($st as $k => $v) {
            if (isset($v['title'])) {
                $types[] = [
                    'name' => $k,
                    'label' => $v['title'],
                    'description' => $v['description'] ?? null,
                ];
            }
        }

        if (empty($types))
            $this->forbidden();

        return $this->render($request, 'admin-submit.twig', [
            'user' => $user,
            'types' => $types,
        ]);
    }

    public function onSubmit(Request $request, Response $response, array $args)
    {
        $user = $this->requireAdmin($request);

        if (isset($args['type'])) {
            $form = $this->container->get('settings')['node_forms'][$args['type']] ?? null;
            if (empty($form))
                $this->notfound();

            return $this->render($request, 'admin-submit-node.twig', [
                'user' => $user,
                'type' => $args['type'],
                'form' => $form,
            ]);
        }

        debug($args);
    }

    /**
     * Shows the list of remote files.
     **/
    public function onS3(Request $request, Response $response, array $args)
    {
        $this->requireAdmin($request);

        $s3 = $this->container->get("S3");
        $files = $s3->getFileList();
        $config = $this->container->get("settings")["S3"];

        return $this->render($request, "admin-s3.twig", [
            "files" => $files,
            "config" => $config,
        ]);
    }

    /**
     * Upload new files to the S3 cloud, via taskq.
     **/
    public function onScheduleS3(Request $request, Response $response, array $args)
    {
        $this->requireAdmin($request);

        $nodes = $this->node->where('`type` = \'file\' AND `deleted` = 0 ORDER BY `updated`');
        foreach ($nodes as $node) {
            $save = false;

            if (empty($node['files']['original'])) {
                $node['files']['original'] = [
                    'type' => $node['mime_type'],
                    'length' => $node['length'],
                    'storage' => 'local',
                    'url' => "/node/{$node['id']}/download/original",
                    'path' => $node['fname'],
                ];

                $save = true;
            }

            $count = count($node['files']);

            if ($this->container->has('thumbnailer')) {
                $tn = $this->container->get('thumbnailer');
                $node = $tn->updateNode($node);
                if (count($node['files']) != $count)
                    $save = true;
            }

            if ($save)
                $node = $this->node->save($node);

            $this->taskq->add('node-s3-upload', [
                'id' => $node['id'],
            ]);
        }

        return $response->withJSON([
            'message' => 'Запланирована фоновая выгрузка.',
        ]);
    }

    /**
     * Makes sure that the current user has access to the admin UI.
     *
     * @param Request $request Request information, for cookies etc.
     * @return array User info, or throws an exception.
     **/
    protected function requireAdmin($request)
    {
        $st = $this->container->get('settings');

        if (!($roles = $st['admin']['allowed_roles'])) {
            $this->logger->warning('admin: allowed_roles not set.');
            $this->forbidden();
        }

        if (!is_array($roles)) {
            $this->logger->warning('admin: allowed_roles is not an array.');
            $this->forbidden();
        }

        $user = $this->requireUser($request);
        if (!in_array($user['role'], $roles))
            $this->forbidden();

        return $user;
    }

    protected function getNodeConfig($type)
    {
        $st = $this->container->get('settings')['node_forms'][$type] ?? null;

        if (empty($st)) {
            $this->logger->error('admin: node type {0} not configured, type is not editable.', [$type]);
            $this->unavailable();
        }

        return $st;
    }

    /**
     * Returns status of some systems.
     **/
    protected function getWarnings()
    {
        $res = [];

        try {
            $limit = strftime('%Y-%m-%d %H:%M:%S', time() - 600);
            $count = $this->db->fetchcell('SELECT COUNT(1) FROM `taskq` WHERE `added` < ?', [$limit]);
            if ($count > 0)
                $res['taskq_stale'] = $count;
        } catch (\Exception $e) {
            $res['taskq_dberror'] = $e->getMessage();
        }

        $st = $this->container->get('settings')['taskq'];
        if (empty($st['ping_url']) or empty($st['exec_pattern']))
            $res['taskq_config'] = true;

        $st = $this->container->get('settings')['S3'];
        if (empty($st['access_key']))
            $res['s3_config'] = true;

        return $res;
    }
}
