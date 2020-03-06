<?php

/**
 * Display results of LoginAction.
 **/

declare(strict_types=1);

namespace Ufw1\Accounts\Responders;

use Slim\Http\Response;
use Slim\Http\Cookies;
use Ufw1\AbstractResponder;
use Ufw1\ResponsePayload;
use Ufw1\Services\Template;

class LoginResponder extends AbstractResponder
{
    public function __construct()
    {
    }

    public function getResponse(Response $response, ResponsePayload $responseData): Response
    {
        if (!empty($responseData['response']['sessionId']) and !empty($responseData['response']['redirect'])) {
            $data = json_encode([
                'redirect' => $responseData['response']['redirect'],
            ]);
            $response->getBody()->write($data);

            $jar = new Cookies();
            $jar->set('session_id', $responseData['response']['sessionId']);

            $response = $response->withHeader('Set-Cookie', $jar->toHeaders());
            return $response;
        }

        if ($common = $this->getCommonJsonResponse($response, $responseData)) {
            return $common;
        }

        debug($responseData);
    }
}
