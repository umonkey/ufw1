<?php

namespace App\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use App\Handlers;

class FileList extends Handlers
{
    public function onGet(Request $request, Response $response, array $args)
    {
        $files = $this->db->findFiles();

        return $this->container->get("template")->render($response, "files.twig", array(
            "files" => $files,
            ));
    }
}
