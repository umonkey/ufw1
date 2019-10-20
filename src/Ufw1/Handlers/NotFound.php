<?php
/**
 * Not found handler.
 **/

namespace Ufw1\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;


class NotFound extends CommonHandler
{
    /**
     * Display a single page.
     **/
    public function __invoke(Request $request, Response $response, array $args)
    {
        $tmp = $request->getServerParam('HTTP_X_REQUESTED_WITH');
        if ($tmp == 'XMLHttpRequest') {
            return $response->withJSON([
                'status' => 'error',
                'message' => 'Страница не найдена.',
            ]);
        }

        $response = $this->render($request, "notfound.twig");
        return $response->withStatus(404);
    }
}
