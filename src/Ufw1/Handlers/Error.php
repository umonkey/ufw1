<?php
/**
 * Custom error handler.
 **/

namespace Ufw1\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use App\CommonHandler;


class Error extends CommonHandler
{
    public function __invoke(Request $request, Response $response, array $args)
    {
        $e = $args["exception"];

        $tpl = "error.twig";
        $status = 500;
        $data = [];
        $data["path"] = $request->getUri()->getPath();

        $stack = $e->getTraceAsString();
        $root = dirname(dirname(dirname(__DIR__)));
        $stack = str_replace($root . "/", "", $stack);

        $data["e"] = [
            "class" => get_class($e),
            "message" => $e->getMessage(),
            "stack" => $stack,
        ];

        if ($e instanceof \App\Errors\Unauthorized) {
            $tpl = "unauthorized.twig";
            $status = 401;
        }

        elseif ($e instanceof \App\Errors\Forbidden) {
            $tpl = "forbidden.twig";
            $status = 403;
        }

        $response = $this->render($request, $tpl, $data);
        return $response->withStatus($status);
    }
}
