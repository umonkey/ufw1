<?php
/**
 * Show search results.
 * Currently only renders the template, we're using Yandex search.
 **/

namespace Ufw1\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;

class Search extends CommonHandler
{
    public function onGet(Request $request, Response $response, array $args)
    {
        $query = trim($request->getParam("query"));

        if ($query) {
            $results = $this->search($query);

            $this->db->insert("search_log", [
                "date" => strftime("%Y-%m-%d %H:%M:%S"),
                "query" => $query,
                "results" => count($results),
            ]);

            return $this->render($request, "search.twig", [
                "query" => $query,
                "wiki" => $this->findWikiPage($query),
                "results" => $results,
                "edit_link" => "/wiki/edit?name=" . urlencode($query),
            ]);
        } else {
            return $this->render($request, "search.twig", []);
        }
    }

    /**
     * Поддержка OpenSearch
     *
     * https://developer.mozilla.org/en-US/docs/Web/OpenSearch
     * https://developer.mozilla.org/en-US/docs/Archive/Add-ons/Supporting_search_suggestions_in_search_plugins
     **/
    public function onGetXML(Request $request, Response $response, array $args)
    {
        $settings = @$this->container->get("settings");
        $settings = $settings["opensearch"] ?? [];

        $host = $request->getUri()->getHost();
        $https = $request->getServerParam("HTTPS") == "on";
        $proto = $https ? "https" : "http";

        $name = $settings["name"] ?? $host;
        $desc = $settings["description"] ?? "Поиск по сайту {$host}";

        $xml = "<OpenSearchDescription xmlns='http://a9.com/-/spec/opensearch/1.1/' xmlns:moz='http://www.mozilla.org/2006/browser/search/'>\n";
        $xml .= "<ShortName>{$name}</ShortName>";
        $xml .= "<Description>{$desc}</Description>";
        $xml .= "<InputEncoding>UTF-8</InputEncoding>";
        $xml .= "<Image width='16' height='16' type='image/x-icon'>{$proto}://{$host}/favicon.ico</Image>";
        $xml .= "<Url type='text/html' method='get' template='{$proto}://{$host}/search?query={searchTerms}'/>";
        $xml .= "<Url type='application/x-suggestions+json' template='{$proto}://{$host}/search/suggest?query={searchTerms}'/>";
        $xml .= "</OpenSearchDescription>";

        $response->getBody()->write($xml);
        return $response->withHeader("content-type", "application/xml");
    }

    /**
     * Search suggestions, according to OpenSearch specs.
     **/
    public function onSuggest(Request $request, Response $response, array $args)
    {
        $query = $request->getParam("query");

        $res = [$query, []];

        $rows = $this->fts->suggest($query . '*');

        $res[1] = array_filter(array_map(function ($row) {
            return $row["meta"]["title"] ?? null;
        }, $rows));

        $res = json_encode($res);

        $response->getBody()->write($res);
        return $response->withHeader("Content-Type", "application/x-suggestions+json");
    }

    public function onLog(Request $request, Response $response, array $args)
    {
        $this->requireAdmin($request);

        $rows = $this->db->fetch("SELECT * FROM `search_log` ORDER BY `date` DESC LIMIT 100");

        return $this->render($request, "search-log.twig", [
            "entries" => $rows,
        ]);
    }

    /**
     * Finds whether there's a wiki page by that name.
     *
     * @param string $query Search query.
     * @return array|null Page meta if wiki's enabled.
     **/
    protected function findWikiPage($query)
    {
        $key = md5(mb_strtolower(trim($query)));
        if ($node = $this->node->getByKey($key)) {
            return [
                "name" => $node["name"],
                "exists" => true,
            ];
        }

        // Do we use wiki at all?
        $wikiCount = (int)$this->db->fetchCell("SELECT COUNT(1) FROM `nodes` WHERE `type` = 'wiki' AND `published` = 1");
        if ($wikiCount == 0)
            return null;

        return [
            "name" => $query,
            "exists" => false,
        ];
    }
}
