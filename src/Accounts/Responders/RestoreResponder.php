<?php

/**
 * Display results of RestoreAction.
 **/

declare(strict_types=1);

namespace Ufw1\Accounts\Responders;

use Slim\Http\Cookies;
use Slim\Http\Response;
use Ufw1\AbstractResponder;
use Ufw1\ResponsePayload;
use Ufw1\Services\Template;

class RestoreResponder extends AbstractResponder
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
        if (!empty($responseData['response']['sessionId']) and !empty($responseData['response']['redirect'])) {
            $jar = new Cookies();
            $jar->set('session_id', [
                'value' => $responseData['response']['sessionId'],
                'path' => '/',
                'expires' => time() + 86400 * 365,
            ]);

            return $response->withHeader('Set-Cookie', $jar->toHeaders())
                ->withRedirect($responseData['response']['redirect']);
        }

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
            "pages/accounts-restore.twig",
        ];
    }
}
