<?php

/**
 * Display results of SudoAction.
 **/

declare(strict_types=1);

namespace Ufw1\Accounts\Responders;

use Slim\Http\Response;
use Ufw1\AbstractResponder;
use Ufw1\ResponsePayload;
use Ufw1\Services\Template;

class SudoResponder extends AbstractResponder
{
    public function getResponse(Response $response, ResponsePayload $responseData): Response
    {
        if (null !== ($res = parent::getResponse($response, $responseData))) {
            return $res;
        }

        $templateNames = $this->getTemplateNames($responseData);
        $html = $this->template->render($templateNames, $responseData['response']);

        $response->getBody()->write($html);
        return $response->withHeader('content-type', 'text/html');
    }

    protected function getTemplateNames(ResponsePayload $responseData): array
    {
        return [
            "pages/accounts-sudo.twig",
        ];
    }
}
