<?php

/**
 * Creates the show page response.
 **/

declare(strict_types=1);

namespace Ufw1\Wiki\Responders;

use Slim\Http\Response;
use Ufw1\AbstractResponder;
use Ufw1\Services\Template;

class ShowPageResponder extends AbstractResponder
{
    /**
     * @var Template
     **/
    protected $template;

    public function __construct(Template $template)
    {
        $this->template = $template;
    }

    public function getResponse(Response $response, array $responseData): Response
    {
        if (404 === ($responseData['error']['code'] ?? null)) {
            return $this->getNotFound($response, $responseData['error']);
        }

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
        $node = $responseData['response']['node'];

        return [
            "pages/node-{$node['id']}.twig",
            "pages/node-wiki.twig",
            "pages/wiki-page.twig",
            "pages/node.twig",
        ];
    }

    protected function getNotFound(Response $response, array $err): Response
    {
        $pageName = $err['pageName'];

        $html = $this->template->render('pages/wiki-notfound.twig', $data =[
            'user' => $err['user'] ?? null,
            'page' => [
                'name' => $pageName,
            ],
            'edit_link' => '/wiki/edit?name=' . urlencode($pageName),
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('content-type', 'text/html')
            ->withStatus(404);
    }
}
