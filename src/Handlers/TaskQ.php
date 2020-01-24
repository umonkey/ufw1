<?php

/**
 * Background task handler.
 **/

namespace Ufw1\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;

class TaskQ extends CommonHandler
{
    /**
     * List pending tasks.
     *
     * TODO: override and add ACL.
     **/
    public function onShow(Request $request, Response $response, array $args)
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

        $settings = $this->getSettings($request);

        return $this->render($request, "pages/taskq.twig", [
            "tab" => "taskq",
            "tasks" => $tasks,
            "user" => $user,
            "domain" => $domain,
            "settings" => $settings,
        ]);
    }

    public function onList(Request $request, Response $response, array $args)
    {
        $settings = $this->getSettings($request);

        $rows = $this->db->fetch("SELECT `id` FROM `taskq` ORDER BY `priority` DESC, `id`");

        $urls = array_map(function ($row) use ($settings) {
            return sprintf($settings["exec_pattern"], $row["id"]);
        }, $rows);

        $this->logger->debug("taskq: report {count} tasks to the master.", [
            "count" => count($urls),
        ]);

        return $response->withJSON([
            "urls" => $urls,
        ]);
    }

    /**
     * Run one task with the given id.
     **/
    public function onRun(Request $request, Response $response, array $args)
    {
        $method = $request->getMethod();

        if ($method != "POST") {
            return $response->withJSON([
                "message" => "Tasks must be started using POST.",
            ]);
        }

        try {
            $this->db->beginTransaction();

            $id = $args["id"];
            $task = $this->db->fetchOne("SELECT * FROM `taskq` WHERE `id` = ?", [$id]);

            if ($task) {
                $payload = unserialize($task["payload"]);
                $action = $payload["__action"];
                unset($payload["__action"]);

                try {
                    $this->handleTask($action, $payload);
                } catch (\Throwable $e) {
                    $this->logger->error('taskq: error handling action={action}: {exception}', [
                        'action' => $action,
                        'exception' => [
                            'class' => get_class($e),
                            'message' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'stack' => $e->getTraceAsString(),
                        ],
                    ]);

                    $response->getBody()->write('Error: ' . $e->getMessage());

                    return $response->withStatus(500);
                }

                $this->db->query("DELETE FROM `taskq` WHERE `id` = ?", [$id]);
            } else {
                $this->logger->debug("taskq: task {id} not found, probably finished already.", [
                    "id" => $args["id"],
                ]);

                return $response->withJSON([
                    "message" => "Finished already.",
                ]);
            }

            $this->db->commit();

            return $response->withJSON([
                "message" => "Done.",
            ]);
        } catch (\Throwable $e) {
            return $response->withJSON([
                "message" => sprintf("%s: %s", get_class($e), $e->getMessage()),
            ])->withStatus(500);
        }
    }

    protected function handleTask($action, array $payload)
    {
        $parts = explode('.', $action);
        if (count($parts) == 2) {
            if ($this->container->has($parts[0])) {
                $obj = $this->container->get($parts[0]);
                if (method_exists($obj, $parts[1])) {
                    $this->logger->info('taskq: calling {0}', [$action]);
                    return call_user_func([$obj, $parts[1]], $payload);
                } else {
                    $this->logger->warning('taskq: dependency method not found, action={0}', [$action]);
                    return;
                }
            } else {
                $this->logger->warning('taskq: dependency not found, action={0}', [$action]);
                return;
            }
        } elseif ($action == 'update-node-thumbnail') {
            return $this->onUpdateNodeThumbnail($payload['id']);
        } elseif ($action == 'telega') {
            return $this->onTelega($payload['message']);
        } elseif ($action == 'handle-file-upload') {
            return $this->onHandleFileUpload($payload['id']);
        }

        $this->logger->warning("taskq: unhandled task with action={action}, payload={payload}.", [
            'action' => $action,
            'payload' => $payload,
        ]);
    }

    protected function getSettings(Request $request)
    {
        $settings = $this->container->get("settings")["taskq"];
        return $settings;
    }

    protected function onUpdateNodeThumbnail($id)
    {
        if (!($node = $this->node->get($id))) {
            $this->logger->debug('taskq: update-node-thumbnail: node {0} not found.', [$id]);
            return;
        }

        $tn = $this->container->get('thumbnailer');
        $node = $tn->updateNode($node);
        $node = $this->node->save($node);

        $this->container->get('S3')->autoUploadNode($node);
    }

    protected function onTelega($message)
    {
        $this->container->get('telega')->sendMessage($message);
    }

    protected function onHandleFileUpload($id)
    {
        if (!($node = $this->node->get($id))) {
            $this->logger->debug('taskq: node {0} not found.', [$id]);
            return;
        }

        if ($this->container->has('thumbnailer')) {
            $tn = $this->container->get('thumbnailer');
            $node = $tn->updateNode($node);
            $node = $this->node->save($node);
        }

        $this->container->get('S3')->autoUploadNode($node);
    }

    /**
     * Adds admin UI to the touring table.
     **/
    public static function setupRoutes(&$app)
    {
        $class = get_called_class();

        $app->get('/taskq/list', $class . ':onList');
        $app->any('/taskq/{id:[0-9]+}/run', $class . ':onRun');
    }
}
