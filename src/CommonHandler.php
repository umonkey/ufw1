<?php

declare(strict_types=1);

namespace Ufw1;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\Controller;

class CommonHandler extends Controller
{
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        switch ($request->getMethod()) {
            case "GET":
                return $this->onGet($request, $response, $args);
            case "POST":
                return $this->onPost($request, $response, $args);
            default:
                debug($request);
        }
    }

    protected function requireRole(Request $request, string $role): bool
    {
        if (empty($role)) {
            return true;
        }

        $account = $this->requireUser($request);

        if (is_array($role) and in_array($account["role"], $role)) {
            return true;
        } elseif (!is_array($role) and $account["role"] == $role) {
            return true;
        }

        throw new \Ufw1\Errors\Forbidden();
    }

    /**
     * Returns the user for the current session.
     *
     * Makes sure that the user exists and is enabled.
     *
     * @param Request $request Request to get the session from.
     * @return array|null User info, if found and valid, or null.
     **/
    protected function getUser(Request $request): ?array
    {
        $session = $this->sessionGet($request);
        if (empty($session)) {
            return null;
        }

        if (empty($session["user_id"])) {
            return null;
        }

        $user = $this->node->get((int)$session["user_id"]);
        if (empty($user)) {
            return null;
        }

        if ($user["type"] != "user") {
            return null;
        }

        if (!empty($session["password"]) and $session["password"] != $user["password"]) {
            return null;
        }

        return $user;
    }

    protected function requireUser(Request $request): array
    {
        $user = $this->getUser($request);
        if (empty($user)) {
            throw new \Ufw1\Errors\Unauthorized();
        }

        if ($user["published"] == 0) {
            throw new \Ufw1\Errors\Forbidden();
        }

        return $user;
    }

    protected function requireAdmin(Request $request): array
    {
        $user = $this->requireUser($request);

        if (empty($user["role"])) {
            throw new \Ufw1\Errors\Forbidden();
        }

        if ($user["role"] != "admin") {
            throw new \Ufw1\Errors\Forbidden();
        }

        return $user;
    }

    protected function isAdmin(Request $request): array
    {
        $user = $this->getUser($request);

        if (empty($user)) {
            return false;
        }

        if ($user["published"] != 1) {
            return false;
        }

        if (empty($user["role"])) {
            return false;
        }

        if ($user["role"] != "admin") {
            return false;
        }

        return true;
    }

    protected function sessionGetId(Request $request): string
    {
        $res = $request->getCookieParam("session_id");
        return $res;
    }

    /**
     * Get session contents.
     *
     * Returns session data as array, if there is one.
     *
     * @param Request $request Request to extract the session id from.
     * @return array|null Session contents.
     **/
    public function sessionGet(Request $request): ?array
    {
        $id = $this->sessionGetId($request);
        if ($id) {
            $row = $this->db->fetchOne("SELECT `data` FROM `sessions` WHERE `id` = ?", [$id]);
            if ($row) {
                return unserialize($row["data"]);
            }
        }
    }

    /**
     * Saves the current session.
     *
     * Session id must be set in the cookie session_id.
     *
     * @param Request $request Request to get the session id from.
     * @param array $data New session contents.
     **/
    protected function sessionSave(Request $request, array $data): string
    {
        $sid = $this->sessionGetId($request);

        if (empty($sid)) {
            $sid = md5(microtime(true) . $_SERVER["REMOTE_ADDR"] . $_SERVER["REMOTE_PORT"]);
            setcookie("session_id", $sid, time() + 86400 * 30, "/");

            $this->logger->info("session {id} created.", [
                "id" => $sid,
            ]);
        }

        $now = strftime("%Y-%m-%d %H:%M:%S");

        $this->db->query("REPLACE INTO `sessions` (`id`, `updated`, `data`) "
            . "VALUES (?, ?, ?)", [$sid, $now, serialize($data)]);

        $this->logger->debug("session {sid} updated.", [
            "sid" => $sid,
        ]);

        return $sid;
    }

    /**
     * Edit current session.
     *
     * Calls the callback function with current session data (if any).
     * Creates the session if necessary,
     * deletes it if callback returns empty data.
     *
     * @param Request $request Current request, to get the cookie from.
     * @param mixed $callback Data editor.
     * @return void
     **/
    protected function sessionEdit(Request $request, $callback): void
    {
        if ($sid = $this->sessionGetId($request)) {
            $cell = $this->db->fetchcell("SELECT `data` FROM `sessions` WHERE `id` = ?", [$sid]);
            $data = $cell ? unserialize($cell) : [];
        } else {
            $sid = md5(microtime(true) . $_SERVER["REMOTE_ADDR"] . $_SERVER["REMOTE_PORT"]);
            $data = [];
        }

        $data = $callback($data);

        if ($data) {
            $now = strftime("%Y-%m-%d %H:%M:%S");
            $this->db->query("REPLACE INTO `sessions` (`id`, `updated`, `data`) "
                . "VALUES (?, ?, ?)", [$sid, $now, serialize($data)]);

            setcookie("session_id", $sid, time() + 86400 * 30, "/");

            $this->logger->debug("session {id} updated.", [
                "id" => $sid,
            ]);
        } else {
            $this->db->query("DELETE FROM `sessions` WHERE `id` = ?", [$sid]);

            $this->logger->debug("session {id} closed.", [
                "id" => $sid,
            ]);

            setcookie("session_id", "", time() - 3600, "/");
        }
    }

    /**
     * Delete an existing session.
     *
     * Deletes session contents.  The cookie remains in place, so the session
     * could be reopened later.
     **/
    protected function sessionDelete(Request $request): void
    {
        if ($sid = $this->sessionGetId($request)) {
            $this->db->query("DELETE FROM `sessions` WHERE `id` = ?", [$sid]);
        }
    }

    protected function search(string $query): array
    {
        return array_map(function ($em) {
            if (!($name = $em['meta']['title'])) {
                $name = substr($em['key'], 5);
            }

            if (!($link = $em['meta']['link'])) {
                $link = '/wiki?name=' . urlencode($name);
            }

            $snippet = Util::processTypography($em['meta']['snippet'] ?? '');
            $snippet = str_replace('&nbsp;', ' ', $snippet);

            return [
                'link' => $link,
                'title' => $name,
                'snippet' => $snippet,
                'updated' => @$em['meta']['updated'],
                'image' => @$em['meta']['image'],
                'words' => @$em['meta']['words'],
            ];
        }, $this->fts->search($query));
    }

    /**
     * Schedule task for background execution.
     *
     * TODO: delete.
     **/
    protected function taskq(string $action, array $data = [], int $priority = 0): int
    {
        $tq = $this->container->get('taskq');
        return $tq->add($action, $data, $priority);
    }

    /**
     * Returns the breadcrumbs array.
     *
     * @param Request $request Request info.
     * @param array $data Template data.
     * @return array Breadcrumbs info.
     **/
    public function getBreadcrumbs(Request $request, array $data): array
    {
        return [];
    }

    /**
     * Reads the file from the file storage.
     *
     * @param string $name File name, eg: "1/12/12345".
     **/
    protected function fsget(string $name): string
    {
        $st = $this->container->get("settings");

        if (empty($st['files']['path'])) {
            throw new \RuntimeException('file storage not configured');
        }

        $path = $st['files']['path'] . '/' . $name;

        if (!file_exists($path)) {
            throw new \RuntimeException("file {$name} is not readable");
        }

        return file_get_contents($path);
    }

    protected function fsput(string $body): string
    {
        $hash = md5($body);

        $name = substr($hash, 0, 1) . '/' . substr($hash, 1, 2) . '/' . $hash;

        $st = $this->container->get("settings");

        if (empty($st['files']['path'])) {
            throw new \RuntimeException('file storage not configured');
        }

        $path = $st['files']['path'] . '/' . $name;

        $dir = dirname($path);
        if (!is_dir($dir)) {
            $res = mkdir($dir, 0775, true);

            if ($res === false) {
                throw new \RuntimeException("could not create folder {$dir}");
            }
        }

        if (!file_put_contents($path)) {
            throw new \RuntimeException("file {$name} is not readable");
        }

        chmod($path, 0664);

        return $name;
    }
}
