<?php
/**
 * Administrative pages.
 **/

namespace Ufw1\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;

class Admin extends CommonHandler
{
    public function onWarnings(Request $request, Response $response, array $args)
    {
        $this->requireAdmin($request);

        $warnings = [];

        $taskCount = (int)$this->db->fetchcell("SELECT COUNT(1) FROM `tasks` WHERE `created` < ? AND `attempts` = 0", [time() - 300]);
        if ($taskCount > 0) {
            $warnings[] = [
                "type" => "warning",
                "text" => "Очередь задач непуста.  Похоже, что своевременный автоматический запуск не настроен.",
                "link" => "/admin/tasks",
            ];
        }

        return $this->render($request, "warnings.twig", [
            "warnings" => $warnings,
        ]);
    }

    public function onTasks(Request $request, Response $response, array $args)
    {
        $this->requireAdmin($request);

        $tasks = $this->db->fetch("SELECT * FROM `tasks` ORDER BY `id`", [], function (array $row) {
            $parts = explode("?", $row["url"]);
            $row["url_path"] = $parts[0];
            return $row;
        });

        return $this->render($request, "tasks.twig", [
            "tasks" => $tasks,
        ]);
    }
}
