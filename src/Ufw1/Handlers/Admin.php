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

        $types = array_filter($types, function ($em) {
            return !in_array($em, ['file', 'user']);
        });

        if (empty($types)) {
            $this->forbidden();
        }

        $types = implode(', ', array_map(function ($em) {
            return "'{$em}'";
        }, $types));

        $nodes = $this->node->where("`deleted` = 0 AND `type` IN ({$types}) ORDER BY `created` DESC LIMIT 1000");

        if (isset($args['type']) and $args['type'] == 'user') {
            usort($nodes, function ($a, $b) {
                $_a = mb_strtolower($a['name']);
                $_b = mb_strtolower($b['name']);
                return strcmp($_a, $_b);
            });
        }

        $types = $this->getNodeTypes();

        return $this->render($request, 'admin-nodes.twig', [
            'user' => $user,
            'nodes' => $nodes,
            'types' => $types,
            'selected_type' => $args['type'] ?? null,
        ]);
    }

    public function onNodeListOne(Request $request, Response $response, array $args)
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

        $nodes = $this->node->where("`deleted` = 0 AND `type` IN ({$types}) ORDER BY `created` DESC LIMIT 1000");

        if (isset($args['type']) and $args['type'] == 'user') {
            usort($nodes, function ($a, $b) {
                $_a = mb_strtolower($a['name']);
                $_b = mb_strtolower($b['name']);
                return strcmp($_a, $_b);
            });
        }

        $types = $this->getNodeTypes();

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
        $next = $request->getParam('next');

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

        if ($node['type'] == 'file') {
            $this->taskq->add('update-node-thumbnail', ['id' => $node['id']]);
        }

        if (empty($next))
            $next = "/admin/nodes/{$node['type']}?edited={$node['id']}";

        $this->db->commit();

        return $response->withJSON([
            'redirect' => $next,
        ]);
    }

    /**
     * Delete or undelete a node.
     **/
    public function onDeleteNode(Request $request, Response $response, array $args)
    {
        $this->db->beginTransaction();

        $user = $this->requireUser($request);

        $id = (int)$request->getParam('id');
        $deleted = (int)$request->getParam('deleted');

        if (!($node = $this->node->get($id)))
            $this->fail('Документ не найден.');

        // Check access.
        $config = $this->getNodeConfig($node['type']);
        if ($user['role'] != 'admin' and (empty($config['edit_roles']) or !in_array($user['role'], $config['edit_roles'])))
            $this->forbidden();

        $node['deleted'] = $deleted;
        $node = $this->node->save($node);

        $this->db->commit();

        if ($node['type'] == 'user' and $deleted)
            $message = 'Пользователь удалён.';
        elseif ($node['type'] == 'user' and !$deleted)
            $message = 'Пользователь восстановлен.';
        elseif ($deleted)
            $message = 'Документ удалён';
        elseif (!$deleted)
            $message = 'Документ восстановлен.';

        return $response->withJSON([
            'success' => true,
            'message' => $message,
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

        if ($node['type'] == 'user' and $published)
            $message = 'Пользователь активирован.';
        elseif ($node['type'] == 'user' and !$published)
            $message = 'Пользователь заблокирован.';
        elseif ($published)
            $message = 'Документ опубликован';
        elseif (!$published)
            $message = 'Документ сокрыт.';

        return $response->withJSON([
            'success' => true,
            'message' => $message,
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
     * Schedule uploading of new files to the S3 cloud, via taskq.
     *
     * Does not actually upload anything, so uses a transaction to handle large
     * number of nodes quicker.
     **/
    public function onScheduleS3(Request $request, Response $response, array $args)
    {
        $this->requireAdmin($request);

        $this->db->beginTransaction();

        $nodes = $this->node->where('`type` = \'file\' AND `deleted` = 0 ORDER BY `updated`');
        foreach ($nodes as $node) {
            $this->container->get('S3')->autoUploadNode($node, true);
        }

        $this->db->commit();

        return $response->withJSON([
            'message' => 'Запланирована фоновая выгрузка.',
        ]);
    }

    public function onEditSession(Request $request, Response $response, array $args)
    {
        $user = $this->requireAdmin($request);

        if ($request->getMethod() == 'POST') {
            $code = $request->getParam('session');
            $update = json_decode($code, true);

            $this->sessionEdit($request, function ($data) use ($update) {
                return $update;
            });

            $next = $request->getParam('next');

            return $response->withJSON([
                'redirect' => $next,
            ]);
        }

        else {
            $session = $this->sessionGet($request);
            $session = json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $this->render($request, 'admin-session-editor.twig', [
                'user' => $user,
                'session' => $session,
            ]);
        }
    }

    /**
     * Switch to another user.
     **/
    public function onSudo(Request $request, Response $response, array $args)
    {
        $user = $this->requireAdmin($request);

        $node = $this->node->get($args["id"]);
        if (empty($node) or $node['type'] != 'user')
            $this->notfound();

        $this->sessionEdit($request, function ($data) use ($node) {
            $data['user_stack'][] = [
                'id' => $data['user_id'],
                'password' => $data['password'],
            ];

            $data['user_id'] = (int)$node['id'];
            $data['password'] = $node['password'];

            return $data;
        });

        $next = $request->getParam('next');

        return $response->withJSON([
            'redirect' => $next ? $next : '/',
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

    protected function getNodeTypes()
    {
        $st = $this->container->get('settings')['node_forms'] ?? [];

        $types = [];
        foreach ($st as $k => $v)
            $types[$k] = $v['title'] ?? $k;

        asort($types);

        return $types;
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

    public function onUploadS3(Request $request, Response $response, array $args)
    {
        $id = $args['id'];
        $node = $this->node->get($id);

        if (empty($node)) {
            $this->notfound();
        } elseif ($node['type'] != 'file') {
            $this->notfound();
        }

        $this->container->get('taskq')->add('S3.uploadNodeTask', [
            'id' => $id,
            'force' => true,
        ]);

        return $response->withJSON([
            'message' => 'Upload scheduled.',
        ]);
    }

    public static function setupRoutes(&$app)
    {
        $class = get_called_class();

        $app->get ('/admin',                             $class . ':onDashboard');
        $app->get ('/admin/database',                    $class . ':onDatabaseStatus');
        $app->get ('/admin/nodes',                       $class . ':onNodeList');
        $app->get ('/admin/nodes/{type}',                $class . ':onNodeListOne');
        $app->post('/admin/nodes/delete',                $class . ':onDeleteNode');
        $app->post('/admin/nodes/save',                  $class . ':onSaveNode');
        $app->post('/admin/nodes/publish',               $class . ':onPublishNode');
        $app->get ('/admin/nodes/{id:[0-9]+}/edit',      $class . ':onEditNode');
        $app->get ('/admin/nodes/{id:[0-9]+}/edit-raw',  $class . ':onEditRawNode');
        $app->get ('/admin/nodes/{id:[0-9]+}/dump',      $class . ':onDumpNode');
        $app->post('/admin/nodes/{id:[0-9]+}/sudo',      $class . ':onSudo');
        $app->post('/admin/nodes/{id:[0-9]+}/upload-s3', $class . ':onUploadS3');
        $app->get ('/admin/s3',                          $class . ':onS3');
        $app->post('/admin/s3',                          $class . ':onScheduleS3');
        $app->any ('/admin/session',                     $class . ':onEditSession');
        $app->get ('/admin/submit',                      $class . ':onSubmitList');
        $app->get ('/admin/submit/{type}',               $class . ':onSubmit');
        $app->get ('/admin/taskq',                       $class . ':onTaskQ');
    }
}
