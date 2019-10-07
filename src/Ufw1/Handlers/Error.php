<?php
/**
 * Custom error handler.
 **/

namespace Ufw1\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;


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
        $root = dirname($_SERVER["DOCUMENT_ROOT"]);
        $stack = str_replace($root . "/", "", $stack);

        $data["e"] = [
            "class" => get_class($e),
            "message" => $e->getMessage(),
            "stack" => $stack,
        ];

        if (@$_SERVER["HTTP_X_REQUESTED_WITH"] == "XMLHttpRequest") {
            if ($data["e"]["class"] == "Ufw1\Errors\UserFailure") {
                return $response->withJSON([
                    "message" => $e->getMessage(),
                ]);
            }

            $message = "Ошибка {$data["e"]["class"]}: {$data["e"]["message"]}";
            $message .= "\n\n" . $data["e"]["stack"];

            return $response->withJSON([
                "error" => $data["e"]["class"],
                "message" => $message,
            ]);
        }

        if ($e instanceof \Ufw1\Errors\Unauthorized) {
            $tpl = "unauthorized.twig";
            $status = 401;
        }

        elseif ($e instanceof \Ufw1\Errors\Forbidden) {
            $tpl = "forbidden.twig";
            $status = 403;
        }

        elseif ($e instanceof \Ufw1\Errors\NotFound) {
            $tpl = "notfound.twig";
            $status = 404;
        }

        $response = $this->render($request, $tpl, $data);
        return $response->withStatus($status);
    }
}
