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
        debug(1);
        $e = $args["exception"];

        $tpl = "errors/default.twig";
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

        $log = true;

        if ($e instanceof \Ufw1\Errors\Unauthorized) {
            $tpl = "errors/unauthorized.twig";
            $status = 401;
            $log = false;
        } elseif ($e instanceof \Ufw1\Errors\Forbidden) {
            // $tpl = "errors/forbidden.twig";
            $status = 403;
            $log = false;
        } elseif ($e instanceof \Ufw1\Errors\NotFound) {
            $tpl = "errors/notfound.twig";
            $status = 404;
            $log = false;
        }

        if ($log) {
            $this->logger->error('exception: {class}: {message}, stack: {stack}', $data['e']);
        }

        $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? null;
        if ($xrw == "XMLHttpRequest") {
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

        $response = $this->render($request, $tpl, $data);
        return $response->withStatus($status);
    }
}
