<?php

namespace Ufw1;

use Slim\Http\Request;
use Slim\Http\Response;

class CommonHandler
{
    protected $container;

    /**
     * Set up the handler.
     **/
    public function __construct($container)
    {
        $this->container = $container;
    }

    public function __get($key)
    {
        switch ($key) {
            case "db":
                return $this->container->get("database");
            case "file":
                return $this->container->get("file");
            case "fts":
                return new \Ufw1\Search($this->db, $this->logger);
            case "logger":
                return $this->container->get("logger");
            case "node":
                return $this->container->get("node");
            case "template":
                return $this->container->get("template");
            case "taskq":
                return $this->container->get("taskq");
        }
    }

    public function __invoke(Request $request, Response $response, array $args)
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

    protected function requireRole(Request $request, $role)
    {
        if (empty($role))
            return true;

        $account = $this->requireUser($request);
        if ($account["role"] != $role)
            throw new \Ufw1\Errors\Forbidden;

        return true;
    }

    /**
     * Returns the user for the current session.
     *
     * Makes sure that the user exists and is enabled.
     *
     * @param Request $request Request to get the session from.
     * @return array|null User info, if found and valid, or null.
     **/
    protected function getUser(Request $request)
    {
        $session = $this->sessionGet($request);
        if (empty($session))
            return null;

        if (empty($session["user_id"]))
            return null;

        $user = $this->node->get($session["user_id"]);
        if (empty($user))
            return null;

        if ($user["type"] != "user")
            return null;

        if (!empty($session["password"]) and $session["password"] != $user["password"])
            return null;

        return $user;
    }

    protected function requireUser(Request $request)
    {
        $user = $this->getUser($request);
        if (empty($user))
            throw new \Ufw1\Errors\Unauthorized;

        if ($user["published"] == 0)
            throw new \Ufw1\Errors\Forbidden;

        return $user;
    }

    protected function requireAdmin(Request $request)
    {
        $user = $this->requireUser($request);

        if (empty($user["role"]))
            throw new \Ufw1\Errors\Forbidden;

        if ($user["role"] != "admin")
            throw new \Ufw1\Errors\Forbidden;

        return $user;
    }

    protected function isAdmin(Request $request)
    {
        $user = $this->getUser($request);

        if (empty($user))
            return false;

        if ($user["published"] != 1)
            return false;

        if (empty($user["role"]))
            return false;

        if ($user["role"] != "admin")
            return false;

        return true;
    }

    protected function sessionGetId(Request $request)
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
    public function sessionGet(Request $request)
    {
        $id = $this->sessionGetId($request);
        if ($id) {
            $row = $this->db->fetchOne("SELECT `data` FROM `sessions` WHERE `id` = ?", [$id]);
            if ($row)
                return unserialize($row["data"]);
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
    protected function sessionSave(Request $request, array $data)
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

        $this->db->query("REPLACE INTO `sessions` (`id`, `updated`, `data`) VALUES (?, ?, ?)", [$sid, $now, serialize($data)]);

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
    protected function sessionEdit(Request $request, $callback)
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
            $this->db->query("REPLACE INTO `sessions` (`id`, `updated`, `data`) VALUES (?, ?, ?)", [$sid, $now, serialize($data)]);

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
    protected function sessionDelete(Request $request)
    {
        if ($sid = $this->sessionGetId($request)) {
            $this->db->query("DELETE FROM `sessions` WHERE `id` = ?", [$sid]);
        }
    }

    /**
     * Renders the page using a template.
     *
     * Calls renderHTML(), then wraps the result in a Response(200).
     *
     * @param Request $request Request info, used to get host, path information, etc.
     * @param string $templateName File name, e.g. "pages.twig".
     * @param array $data Template variables.
     * @return Response ready to use response.
     **/
    protected function render(Request $request, $templateName, array $data = [])
    {
        if (empty($data["breadcrumbs"]))
            $data["breadcrumbs"] = $this->getBreadcrumbs($request, $data);

        $html = $this->renderHTML($request, $templateName, $data);

        $response = new Response(200);
        $response->getBody()->write($html);
        return $response->withHeader("content-type", "text/html; chaset=utf-8");
    }

    /**
     * Renders the page using a template.
     *
     * @param Request $request Request info, used to get host, path information, etc.
     * @param string $templateName File name, e.g. "pages.twig".
     * @param array $data Template variables.
     * @return Response ready to use response.
     **/
    protected function renderHTML(Request $request, $templateName, array $data = [])
    {
        $defaults = [
            "language" => "ru",
        ];

        $data = array_merge($defaults, $data);

        $data["request"] = [
            "host" => $request->getUri()->getHost(),
            "path" => $request->getUri()->getPath(),
            "uri" => strval($request->getUri()),
            "get" => $request->getQueryParams(),
        ];

        if ($user = $this->getUser($request)) {
            $data["user"] = $user;
            $data["is_admin"] = $user["role"] == "admin";
            unset($data["user"]["password"]);
        } else {
            $data["user"] = null;
            $data["is_admin"] = false;
        }

        $lang = $data["language"];

        $html = $this->template->render($templateName, $data);
        $html = $this->fixAssetsCache($request, $html);

        return $html;
    }

    protected function renderXML(Request $request, $templateName, array $data)
    {
        $def = $this->container->get("settings")["templates"];
        if (!empty($def["defaults"]))
            $data = array_merge($def["defaults"], $data);

        $xml = $this->template->render($templateName, $data);

        $xml = preg_replace('@>\s*<@', "><", $xml);

        $response = new Response(200);
        $response->getBody()->write($xml);
        return $response->withHeader("Content-Type", "text/xml; charset=utf-8");
    }

    protected function renderRSS(Request $request, array $channel, array $items)
    {
        $proto = $request->getServerParam("HTTPS") == "https" ? "https" : "http";
        $host = $request->getUri()->getHost();
        $base = $proto . "://" . $host;

        $settings = $this->container->get("settings");

        return $this->renderXML($request, "rss.twig", [
            "request" => [
                "host" => $host,
                "base" => $base,
                "path" => $request->getUri()->getPath(),
            ],
            "site_name" => @$settings["site_name"],
            "channel" => $channel,
            "items" => $items,
        ]);
    }

    protected function search($query)
    {
        return array_map(function ($em) {
            $name = $em["meta"]["title"] ?? substr($em["key"], 5);
            $link = $em["meta"]["link"] ?? "/wiki?name=" . urlencode($name);

            return [
                "link" => $link,
                "title" => $name,
                "snippet" => @$em["meta"]["snippet"],
                "updated" => @$em["meta"]["updated"],
                "image" => @$em["meta"]["image"],
                "words" => @$em["meta"]["words"],
            ];
        }, $this->fts->search($query));
    }

    /**
     * Schedule task for background execution.
     **/
    protected function taskq($action, array $data = [], $priority = 0)
    {
        static $ping = false;

        $logger = $this->container->get("logger");

        $data["__action"] = $action;

        $id = $this->db->insert("taskq", [
            "priority" => $priority,
            "payload" => serialize($data),
        ]);

        $logger->debug("taskq: task {id} added with action {action}.", [
            "id" => $id,
            "action" => $action,
        ]);

        // Ping the server once per request.
        if ($ping === false) {
            $ping = true;

            $domain = $_SERVER["HTTP_HOST"];
            $settings = $this->container->get("settings");
            if (!empty($settings["taskq"][$domain]["ping_url"])) {
                $url = $settings["taskq"][$domain]["ping_url"];
                @file_get_contents($url);

                $logger->info("taskq: ping sent to {url}", [
                    "url" => $url,
                ]);
            } else {
                $logger->info("taskq: ping_url not set.");
            }
        }

        return $id;
    }

    protected function sendFromCache(Request $request, $callback, $key = null)
    {
        if ($key === null)
            $key = $request->getUri()->getPath();

        $ckey = md5($key);

        $cc = $request->getServerParam("HTTP_CACHE_CONTROL");
        $refresh = $cc == "no-cache";

        if ($request->getQueryParam("debug") == "tpl")
            $refresh = true;

        $row = $refresh ? null : $this->db->fetchOne("SELECT * FROM `cache` WHERE `key` = ?", [$ckey]);
        if (empty($row)) {
            $_tmp = $callback($request);
            if (!is_array($_tmp))
                return $_tmp;

            list($type, $body) = $_tmp;

            $added = time();

            $this->db->query("DELETE FROM `cache` WHERE `key` = ?", [$ckey]);

            $this->db->insert("cache", [
                "key" => $ckey,
                "added" => $added,
                "value" => $type . "|" . $body,
            ]);
        } else {
            $added = (int)$row["added"];
            list($type, $body) = explode("|", $row["value"], 2);
        }

        if ($key[0] == "/") {
            $path = $_SERVER["DOCUMENT_ROOT"] . $key;
            $folder = dirname($path);
            if (file_exists($folder) and is_dir($folder) and is_writable($folder))
                file_put_contents($path, $body);
        }

        return $this->sendCached($request, $body, $type, $added);
    }

    protected function sendCached(Request $request, $body, $type, $lastmod)
    {
        $etag = sprintf("\"%x-%x\"", $lastmod, strlen($body));
        $ts = gmstrftime("%a, %d %b %Y %H:%M:%S %z", $lastmod);

        $response = new Response(200);
        $response = $response->withHeader("Last-Modified", $ts);

        $headers = $request->getHeaders();
        if (@$headers["HTTP_IF_NONE_MATCH"][0] == $etag) {
            return $response->withStatus(304)
                ->withHeader("ETag", $etag)
                ->withHeader("Cache-Control", "public, max-age=31536000");
        }

        $response = $response->withHeader("Content-Type", $type)
            ->withHeader("ETag", $etag)
            ->withHeader("Content-Length", strlen($body))
            ->withHeader("Cache-Control", "public, max-age=31536000");
        $response->getBody()->write($body);

        return $response;
    }

    protected function fixAssetsCache(Request $request, $html)
    {
        $root = $request->getServerParam("DOCUMENT_ROOT");

        $html = preg_replace_callback('@(src|href)="([^"]+\.(css|js))"@', function ($m) use ($root) {
            $path = $root . $m[2];

            if (!file_exists($path))
                return $m[0];

            $etag = sprintf("%x-%x", filemtime($path), filesize($path));
            return sprintf('%s="%s?etag=%s"', $m[1], $m[2], $etag);
        }, $html);

        return $html;
    }

    /**
     * Returns the breadcrumbs array.
     *
     * @param Request $request Request info.
     * @param array $data Template data.
     * @return array Breadcrumbs info.
     **/
    public function getBreadcrumbs(Request $request, array $data)
    {
        return [];
    }

    protected function forbidden()
    {
        throw new \Ufw1\Errors\Forbidden;
    }

    protected function unauthorized()
    {
        throw new \Ufw1\Errors\Unauthorized;
    }

    protected function notfound()
    {
        throw new \Ufw1\Errors\NotFound;
    }

    protected function fail($message)
    {
        throw new \Ufw1\Errors\UserFailure($message);
    }
}
