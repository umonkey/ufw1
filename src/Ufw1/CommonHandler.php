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

    protected function requireUser(Request $request)
    {
        if (!($sid = $this->sessionGetId($request)))
            throw new \Ufw1\Errors\Unauthorized;

        if (!($session = $this->sessionGet($sid)))
            throw new \Ufw1\Errors\Unauthorized;

        if (empty($session["user_id"]))
            throw new \Ufw1\Errors\Unauthorized;

        if (!($account = $this->db->fetchOne("SELECT * FROM `accounts` WHERE `id` = ?", [$session["user_id"]])))
            throw new \Ufw1\Errors\Unauthorized;

        // Reset sessions on password change.
        if ($account["password"] != @$session["password"])
            throw new \Ufw1\Errors\Unauthorized;

        if ($account["enabled"] != 1)
            throw new \Ufw1\Errors\Forbidden;

        return $account;
    }

    protected function requireAdmin(Request $request)
    {
        if ($this->isAdmin($request))
            return true;
        throw new \Ufw1\Errors\Unauthorized();
    }

    protected function isAdmin(Request $request)
    {
        if (!($sid = $this->sessionGetId($request))) {
            $this->logger->debug("isAdmin: session id not set.");
            return false;
        }

        if (!($session = $this->sessionGet($sid))) {
            $this->logger->debug("isAdmin: empty session.");
            return false;
        }

        if (empty($session["user_id"])) {
            $this->logger->debug("isAdmin: user_id not set.");
            return false;
        }

        return true;
    }

    protected function sessionGetId(Request $request)
    {
        $res = $request->getCookieParam("session_id");
        return $res;
    }

    protected function sessionSave(Request $request, array $data)
    {
        $sid = $this->sessionGetId($request);
        if (empty($sid)) {
            $this->logger->debug("session is not set.");
            return;
        }

        $now = strftime("%Y-%m-%d %H:%M:%S");

        $this->db->query("REPLACE INTO `sessions` (`id`, `updated`, `data`) VALUES (?, ?, ?)", [$sid, $now, serialize($data)]);

        $this->logger->debug("session {sid} updated.", [
            "sid" => $sid,
        ]);
    }

    public function sessionGet($id)
    {
        $row = $this->db->fetchOne("SELECT `data` FROM `sessions` WHERE `id` = ?", [$id]);
        return $row ? unserialize($row["data"]) : null;
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
            "get" => $request->getQueryParams(),
        ];

        $data["is_admin"] = $this->isAdmin($request);

        if ($data["is_admin"]) {
            $since = time() - 300;
            $tasks = $this->db->fetchcell("SELECT COUNT(1) FROM `tasks` WHERE `created` < ?", [$since]);
            if ((int)$tasks > 0)
                $data["have_warnings"] = true;
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

    protected function notfound(Request $request)
    {
		throw new \Ufw1\Errors\NotFound();
    }

    protected function unauthorized(Request $request)
    {
		throw new \Ufw1\Errors\Unauthorized();
    }

    protected function forbidden(Request $request)
    {
		throw new \Ufw1\Errors\Forbidden();
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

    protected function taskAdd($url, $args = [], $priority = 0)
    {
        try {
            if ($args) {
                $qs = [];
                foreach ($args as $k => $v)
                    $qs[] = urlencode($k) . '=' . urlencode($v);
                $url .= "?" . implode("&", $qs);
            }

            $now = time();

            $this->db->insert("tasks", [
                "url" => $url,
                "priority" => $priority,
                "created" => $now,
                "attempts" => 0,
                "run_after" => $now,
            ]);

            $this->logger->debug("tasks: scheduled {url}", [
                "url" => $url,
            ]);
        } catch (\Exception $e) {
            $this->logger->debug("tasks: error scheduling {url}: {e}", [
                "url" => $url,
                "e" => [
                    "message" => $e->getMessage(),
                    "code" => $e->getCode(),
                    "class" => get_class($e),
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                ],
            ]);
        }
    }

    protected function sendFromCache(Request $request, $callback, $key = null)
    {
        if ($key === null)
            $key = $request->getUri()->getPath();

        $cc = $request->getServerParam("HTTP_CACHE_CONTROL");
        $refresh = $cc == "no-cache";

        if ($request->getQueryParam("debug") == "tpl")
            $refresh = true;

        $row = $refresh ? null : $this->db->fetchOne("SELECT * FROM `cache` WHERE `key` = ?", [$key]);
        if (empty($row)) {
            $_tmp = $callback($request);
            if (!is_array($_tmp))
                return $_tmp;

            list($type, $body) = $_tmp;

            $added = time();

            $this->db->query("DELETE FROM `cache` WHERE `key` = ?", [$key]);

            $this->db->insert("cache", [
                "key" => $key,
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
}
