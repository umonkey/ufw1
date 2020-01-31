<?php

/**
 * Handle sitemap.
 **/

declare(strict_types=1);

namespace Ufw1\Controllers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;

class SitemapController extends CommonHandler
{
    public function onGet(Request $request, Response $response, array $args): Response
    {
        return $this->sendFromCache($request, function () use ($request) {
            $base = $request->getUri()->getBaseUrl();

            $xml = "<?xml version='1.0' encoding='utf-8'?" . ">\n";
            $xml .= "<urlset xmlns='http://www.sitemaps.org/schemas/sitemap/0.9'>\n";

            $sel = $this->db->query("SELECT * FROM `nodes` WHERE `published` = 1 ORDER BY `created`");
            while ($row = $sel->fetch(\PDO::FETCH_ASSOC)) {
                $node = $this->node->unpack($row);

                switch ($node["type"]) {
                    case "wiki":
                        $link = $base . "/wiki?name=" . urlencode($node["name"]);
                        break;

                    default:
                        $link = null;
                }

                if ($link) {
                    $ts = $node["updated"];
                    if (!is_numeric($ts)) {
                        $ts = strtotime($ts);
                    }
                    $date = strftime("%Y-%m-%d", $ts);
                    $xml .= "<url><loc>{$link}</loc><lastmod>{$date}</lastmod></url>\n";
                }
            }

            $xml .= "</urlset>\n";

            return ["text/xml", $xml];
        });
    }
}
