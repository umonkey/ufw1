<?php

/**
 * Display results of RestorePasswordAction.
 **/

declare(strict_types=1);

namespace Ufw1\Accounts\Responders;

use Slim\Http\Response;
use Ufw1\AbstractResponder;
use Ufw1\ResponsePayload;
use Ufw1\Services\Template;

class RestorePasswordResponder extends AbstractResponder
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
        if ($common = $this->getCommonJsonResponse($response, $responseData)) {
            return $common;
        }

        return $response->withJSON($responseData['response']);
    }
}
