<?php
/**
 * Handle sitemap.
 **/

namespace Ufw1\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;

class Sitemap extends CommonHandler
{
    public function onGet(Request $request, Response $response, array $args)
    {
        return $this->sendFromCache($request, function () use ($request) {
            $host = $request->getServerParam("HTTP_HOST");

            $https = $request->getServerParam("HTTPS") == "on";
            $proto = $https ? "https" : "http";

            $base = $proto . "://" . $host;

            $xml = "<?xml version='1.0' encoding='utf-8'?".">\n";
            $xml .= "<urlset xmlns='http://www.sitemaps.org/schemas/sitemap/0.9'>\n";

            $sel = $this->db->query("SELECT * FROM `nodes` WHERE `published` = 1 ORDER BY `created`");
            while ($row = $sel->fetch(\PDO::FETCH_ASSOC)) {
                $node = $this->node->unpack($row);

                switch ($node["type"]) {
                    case "wiki":
                        $link = "/wiki?name=" . urlencode($node["name"]);
                        break;

                    default:
                        $link = null;
                }

                if ($link) {
                    $ts = $node["updated"];
                    if (!is_numeric($ts))
                        $ts = strtotime($ts);
                    $date = strftime("%Y-%m-%d", $ts);
                    $xml .= "<url><loc>{$link}</loc><lastmod>{$date}</lastmod></url>\n";
                }
            }

            $xml .= "</urlset>\n";

            return ["text/xml; charset=utf-8", $xml];
        });
    }
}
