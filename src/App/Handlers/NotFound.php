<?php
/**
 * Not found handler.
 **/

namespace App\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use App\CommonHandler;


class NotFound extends CommonHandler
{
    /**
     * Display a single page.
     **/
    public function __invoke(Request $request, Response $response, array $args)
    {
        $response = $this->render($request, "notfound.twig");
        return $response->withStatus(404);
    }
}
