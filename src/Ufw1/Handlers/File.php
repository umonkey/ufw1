<?php
/**
 * Handle file download.
 **/

namespace Ufw1\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1@gCommonHandler;

class File extends CommonHandler
{
    public function onGet(Request $request, Response $response, array $args)
    {
        $name = $args["name"];
        $file = $this->db->getFileByName($name);

        $hash = empty($file["hash"])
            ? md5($file["body"])
            : $file["hash"];

        $response = $response->withHeader("Content-Type", $file["type"])
            ->withHeader("Content-Length", $file["length"])
            ->withHeader("ETag", "\"{$hash}\"")
            ->withHeader("Cache-Control", "max-age=31536000");
        $response->getBody()->write($file["body"]);

        return $response;
    }
}
