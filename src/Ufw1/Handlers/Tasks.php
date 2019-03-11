<?php
/**
 * Background task handler.
 **/

namespace Ufw1\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;

class Tasks extends CommonHandler
{
    /**
     * Run all pending tasks.
     **/
    public function onRunAll(Request $request, Response $response, array $args)
    {
        $tasks = $this->db->fetch("SELECT * FROM `tasks` WHERE `run_after` <= ? ORDER BY `priority`, `created`", [time()]);
        return $this->handleTasks($tasks, $response);
    }

    /**
     * Run one task with the highest priority.
     **/
    public function onRunOne(Request $request, Response $response, array $args)
    {
        $tasks = $this->db->fetch("SELECT * FROM `tasks` WHERE `run_after` <= ? ORDER BY `priority`, `created` LIMIT 1", [time()]);
        return $this->handleTasks($tasks, $response);
    }

    protected function handleTasks(array $tasks, Response $response)
    {
        foreach ($tasks as $task) {
            $delay = ((int)$task["attempts"] + 1) * 5;
            $after = time() + $delay;
            $this->db->query("UPDATE `tasks` SET `attempts` = ?, `run_after` = ? WHERE `id` = ?", [$task["attempts"] + 1, $after, $task["id"]]);

            try {
                $this->db->beginTransaction();

                $res = $this->handle($task);

                if ($res == "ERROR" or $res == "DELAY") {
                    $this->logger->debug("tasks: task {id} delayed.", [
                        "id" => $task["id"],
                    ]);
                }

                else {
                    $count = $this->db->fetchcell("SELECT COUNT(1) FROM `tasks`");

                    $this->logger->debug("tasks: task {id} finished, {count} tasks left.", [
                        "id" => $task["id"],
                        "count" => $count - 1,
                    ]);

                    $this->db->query("DELETE FROM `tasks` WHERE `id` = ? OR `url` = ?", [$task["id"], $task["url"]]);
                }

                $this->db->commit();
            }

            catch (\Exception $e) {
                $this->logger->debug("tasks: task {id} failed, exception={e}", [
                    "id" => $task["id"],
                    "e" => [
                        "message" => $e->getMessage(),
                        "code" => $e->getCode(),
                        "class" => get_class($e),
                        "file" => $e->getFile(),
                        "line" => $e->getLine(),
                    ],
                ]);

                $this->db->rollback();
            }
        }

        if (empty($tasks)) {
            $this->logger->debug("tasks: nothing to do.");
            $text = "EMPTY";
        } else {
            $text = "DONE";
        }

        $response->getBody()->write($text);
        return $response->withHeader("Content-Type", "text/plain; charset=utf-8");
    }

    protected function handle(array $task)
    {
        try {
            $environment = \Slim\Http\Environment::mock([
                "REQUEST_METHOD" => "GET",
                "REQUEST_URI" => $task["url"],
            ]);

            $request = Request::createFromEnvironment($environment);

            $router = $this->container->get("router");

            $routeInfo = $router->dispatch($request);
            if ($routeInfo[0] === \FastRoute\Dispatcher::FOUND) {
                $response = new Response(200);
                $response = $response->withHeader("Content-Type", "text/plain; charset=utf-8");

                $routeArguments = $routeInfo[2];
                $route = $router->lookupRoute($routeInfo[1]);
                $route->prepare($request, $routeArguments);
                $res = $route->run($request, $response);

                $body = $res->getBody();
                $body->rewind();
                $text = $body->getContents();
                return $text;
            } else {
                return "NOT FOUND";
            }
        }

        catch (\Exception $e) {
            $this->logger->error("tasks: handler for task {id} failed, exception={e}.", [
                "id" => $task["id"],
                "e" => [
                    "message" => $e->getMessage(),
                    "code" => $e->getCode(),
                    "class" => get_class($e),
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                ],
            ]);

            return "ERROR";
        }
    }
}
