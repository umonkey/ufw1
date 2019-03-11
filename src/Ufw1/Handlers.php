<?php

namespace Ufw1;

use Slim\Http\Request;
use Slim\Http\Response;

class Handlers
{
    protected $container;

    protected $db;

    /**
     * Set up the handler.
     **/
    public function __construct($container)
    {
        $this->container = $container;
        $this->db = $container->get("database");
    }

    /**
     * Display the page edit form.
     **/
    public function getEdit(Request $request, Response $response)
    {
        $pageName = $request->getQueryParam("name");

        if (empty($pageName))
            return $response->withRedirect("/wiki?name=Welcome", 302);

        $page = $this->db->getPageByName($pageName);
        if ($page === false) {
            $contents = "# {$pageName}\n\n**{$pageName}** -- something that we don't have information on, yet.\n";
        } else {
            $contents = $page["source"];
        }

        $html = Template::renderFile("editor.twig", array(
            "page_name" => $pageName,
            "page_source" => $contents,
            ));

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Update page contents.
     **/
    public function postEdit(Request $request, Response $response)
    {
        // TODO: access control.

        $name = $_POST["page_name"];
        $text = $_POST["page_source"];

        $this->db->updatePage($name, $text);

        return $response->withRedirect("/wiki?name=" . urlencode($name), 303);
    }

    public function getHome(Request $request, Response $response)
    {
        $homePage = $this->container->get("settings")["wiki"]["homePage"];
        $link = "/wiki?name=" . urlencode($homePage);
        return $response->withRedirect($link, 303);
    }

    public function __get($key)
    {
        switch ($key) {
            case "template":
                return $this->container->get("template");
            case "sphinx":
                return $this->container->get("sphinx");
        }
    }
}
