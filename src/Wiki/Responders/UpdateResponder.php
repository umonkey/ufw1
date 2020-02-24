<?php

/**
 * Display results of page editing.
 **/

declare(strict_types=1);

namespace Ufw1\Wiki\Responders;

use Slim\Http\Response;
use Ufw1\AbstractResponder;
use Ufw1\Services\Template;

class UpdateResponder extends AbstractResponder
{
    public function getResponse(Response $response, array $responseData): Response
    {
        if ($common = $this->getCommonJsonResponse($response, $responseData)) {
            return $common;
        }

        return $response->withJson([
            'message' => 'Unhandled response.',
        ]);
    }
}
