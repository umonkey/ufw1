<?php

/**
 * Base controller, implements service access etc.
 **/

declare(strict_types=1);

namespace Ufw1;

use Slim\Http\Request;
use Slim\Http\Response;
use Psr\Container\ContainerInterface;

abstract class Controller
{
    /**
     * Dependency container instance.
     *
     * @var ContainerInterface
     **/
    protected $container;

    /**
     * Set up the handler.
     **/
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Get service by code.
     **/
    public function __get(string $key)
    {
        if (null === $this->container) {
            throw new \OutOfBoundsException('container not set');
        }

        if (!$this->container->has($key)) {
            throw new \OutOfBoundsException("service {$key} not found");
        }

        return $this->container->get($key);
    }

    /**
     * Check if service exists.
     **/
    public function __isset(string $key): bool
    {
        return $this->container->has($key);
    }

    /**
     * Renders the page using a template.
     *
     * Calls renderHTML(), then wraps the result in a Response(200).
     *
     * @param Request      $request      Request info, used to get host, path information, etc.
     * @param string|array $templateName File name, e.g. "pages.twig".
     * @param array        $data         Template variables.

     * @return Response ready to use response.
     **/
    protected function render(Request $request, $template, array $data = [], int $status = 200): Response
    {
        if (empty($data['breadcrumbs'])) {
            $data['breadcrumbs'] = $this->getBreadcrumbs($request, $data);
        }

        $html = $this->renderHTML($request, $template, $data);

        $response = new Response($status);
        $response->getBody()->write($html);
        return $response->withHeader('content-type', 'text/html');
    }

    /**
     * Renders the page using a template.
     *
     * @param Request      $request  Request info, used to get host, path information, etc.
     * @param string|array $template File name, e.g. "pages.twig".
     * @param array        $data     Template variables.
     *
     * @return Response ready to use response.
     **/
    protected function renderHTML(Request $request, $template, array $data = []): string
    {
        $data = array_replace([
            'language' => 'ru',
        ], $data);

        $data['request'] = [
            'base' => $request->getUri()->getBaseUrl(),
            'host' => $request->getUri()->getHost(),
            'path' => $request->getUri()->getPath(),
            'uri' => strval($request->getUri()),
            'get' => $request->getQueryParams(),
        ];

        $html = $this->template->render($template, $data);
        $html = $this->fixAssetsCache($request, $html);

        return $html;
    }

    protected function renderXML(Request $request, string $templateName, array $data = []): Response
    {
        $data['request'] = [
            'base' => $request->getUri()->getBaseUrl(),
            'host' => $request->getUri()->getHost(),
            'path' => $request->getUri()->getPath(),
            'uri' => strval($request->getUri()),
            'get' => $request->getQueryParams(),
        ];

        $xml = $this->template->render($templateName, $data);

        $xml = preg_replace('@>\s*<@', "><", $xml);

        $response = new Response(200);
        $response->getBody()->write($xml);
        return $response->withHeader("Content-Type", "text/xml");
    }

    protected function renderRSS(Request $request, array $channel, array $items): Response
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

    protected function sendFromCache(Request $request, $callback, string $key = null): Response
    {
        if ($key === null) {
            $key = $request->getUri()->getPath();
        }

        $ckey = md5($key);

        $cc = $request->getServerParam("HTTP_CACHE_CONTROL");
        $refresh = $cc == "no-cache";

        if ($request->getQueryParam("debug") == "tpl") {
            $refresh = true;
        }

        $row = $refresh ? null : $this->db->fetchOne("SELECT * FROM `cache` WHERE `key` = ?", [$ckey]);
        if (empty($row)) {
            $_tmp = $callback($request);
            if (!is_array($_tmp)) {
                return $_tmp;
            }

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
            if (file_exists($folder) and is_dir($folder) and is_writable($folder)) {
                file_put_contents($path, $body);
            }
        }

        return $this->sendCached($request, $body, $type, $added);
    }

    protected function sendCached(Request $request, string $body, string $type, $lastmod): Response
    {
        $etag = sprintf("\"%x-%x\"", $lastmod, strlen($body));

        $response = new Response(200);

        if ($lastmod) {
            if (!is_numeric($lastmod)) {
                $lastmod = strtotime($lastmod);
            }
            $ts = gmstrftime("%a, %d %b %Y %H:%M:%S %z", $lastmod);
            $response = $response->withHeader("Last-Modified", $ts);
        }

        $headers = $request->getHeaders();
        if (($headers["HTTP_IF_NONE_MATCH"][0] ?? null) == $etag) {
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

    /**
     * Add etag arguments to asset links.
     *
     * TODO: move to Twig filter, e.g. |etag
     **/
    protected function fixAssetsCache(Request $request, string $html): string
    {
        $root = $request->getServerParam("DOCUMENT_ROOT");

        $html = preg_replace_callback('@(src|href)="([^"]+\.(css|js))"@', function ($m) use ($root) {
            $path = $root . $m[2];

            if (!file_exists($path)) {
                return $m[0];
            }

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
    public function getBreadcrumbs(Request $request, array $data): array
    {
        return [];
    }

    protected function forbidden(): void
    {
        throw new \Ufw1\Errors\Forbidden();
    }

    protected function unauthorized(): void
    {
        throw new \Ufw1\Errors\Unauthorized();
    }

    protected function notfound(string $message = null): void
    {
        if ($message) {
            throw new Errors\NotFound($message);
        } else {
            throw new Errors\NotFound();
        }
    }

    protected function unavailable(string $message = null): void
    {
        throw new \Ufw1\Errors\Unavailable($message);
    }

    protected function fail(string $message): void
    {
        throw new \Ufw1\Errors\UserFailure($message);
    }
}
