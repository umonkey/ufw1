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

        return $this->render($request, "taskq.twig", [
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

                $this->handleTask($action, $payload);

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
        } catch (\Exception $e) {
            return $response->withJSON([
                "message" => sprintf("%s: %s", get_class($e), $e->getMessage()),
            ]);
        }
    }

    protected function handleTask($action, array $payload)
    {
        if ($action == "node-s3-upload")
            return $this->onUploadNodeS3($payload["id"]);

        $this->logger->warning("taskq: unhandled task with action={action}.", [
            "action" => $action,
        ]);
    }

    protected function getSettings(Request $request)
    {
        $settings = $this->container->get("settings")["taskq"];
        return $settings;
    }

	/**
	 * Upload node files to S3, if configured.
	 *
	 * @param int $id Node id.
	 **/
    protected function onUploadNodeS3($id)
    {
		try {
			$node = $this->node->get($id);

			if (empty($node)) {
				$this->logger->error("upload-s3: node {id} does not exist.", [
					"id" => $id,
				]);

				return;
			}

			if ($node["type"] != "file") {
				$this->logger->error("upload-s3: node {id} is {type}, not a file.", [
					"id" => $id,
					"type" => $node["type"],
				]);
			}

			if ($this->container->has('thumbnailer')) {
				$tn = $this->container->get('thumbnailer');
				$node = $tn->updateNode($node);
			}

			$s3 = $this->container->get("S3");

			$node = $s3->uploadNodeFiles($node);
			$this->node->save($node);

			$this->logger->info("upload-s3: node {id} sent to S3.", [
				"id" => $id,
			]);
		} catch (\Exception $e) {
			$this->logger->error('s3: error uploading node {id}: {message}', [
				'id' => $id,
				'message' => $e->getMessage(),
			]);
		}
    }
}
