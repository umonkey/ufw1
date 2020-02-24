<?php

/**
 * Display something.
 **/

declare(strict_types=1);

namespace Ufw1\Wiki\Responders;

use Slim\Http\Response;
use Ufw1\AbstractResponder;
use Ufw1\Services\Template;

class IndexResponder extends AbstractResponder
{
    /**
     * @var Template;
     **/
    protected $template;

    public function __construct(Template $template)
    {
        $this->template = $template;
    }

    public function getResponse(Response $response, array $responseData): Response
    {
        if ($common = $this->getCommonResponse($response, $responseData)) {
            return $common;
        }

        $templateNames = $this->getTemplateNames($responseData);
        $html = $this->template->render($templateNames, $responseData['response']);

        $response->getBody()->write($html);
        return $response->withHeader('content-type', 'text/html');
    }

    protected function getTemplateNames(array $responseData): array
    {
        return [
            "pages/wiki-index.twig",
        ];
    }
}
