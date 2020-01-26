<?php

/**
 * Some controller.
 *
 * TODO: ...
 **/

declare(strict_types=1);

namespace Ufw1\Controllers;

use Psr\Log\LoggerInterface;
use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;

class [:VIM_EVAL:]expand('%:p:t:r')[:END_EVAL:] extends CommonHandler
{
    public function onIndex(Request $request, Response $response, array $args): Response
    {
        $data = [];

        return $this->render($request, ['pages/[:VIM_EVAL:]expand('%:p:t:r')[:END_EVAL:].twig'], $data);
    }
}
