<?php

/**
 * Display results of ListAction.
 **/

declare(strict_types=1);

namespace Ufw1\Errors\Responders;

use Slim\Http\Response;
use Ufw1\AbstractResponder;
use Ufw1\ResponsePayload;
use Ufw1\Services\Template;

class ListResponder extends AbstractResponder
{
    /**
     * @var Template;
     **/
    protected $template;

    public function __construct(Template $template)
    {
        $this->template = $template;
    }

    public function getResponse(Response $response, ResponsePayload $responseData): Response
    {
        if ($common = $this->getCommonResponse($response, $responseData)) {
            return $common;
        }

        $templateNames = $this->getTemplateNames($responseData);
        $html = $this->template->render($templateNames, $responseData['response']);

        $response->getBody()->write($html);
        return $response->withHeader('content-type', 'text/html');
    }

    protected function getTemplateNames(ResponsePayload $responseData): array
    {
        return [
            "admin/errors-list.twig",
        ];
    }
}
