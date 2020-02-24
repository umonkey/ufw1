<?php

/**
 * Some action.
 *
 * TODO: ...
 **/

declare(strict_types=1);

namespace Ufw1\Actions;
use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\AbstractAction;

use Psr\Log\LoggerInterface;

class [:VIM_EVAL:]expand('%:p:t:r')[:END_EVAL:] extends AbstractAction
{
    /**
     * @var SomeDomain
     **/
    private $domain;

    /**
     * @var SomeResponder
     **/
    private $domain;

    /**
     * @var AuthService
     **/
    private $auth;

    public function __construct(Domain $domain, SomeResponder $responder, AuthService $auth)
    {
        $this->domain = $domain;
        $this->responder = $responder;
        $this->auth = $auth;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        // (1) Collect data.
        $data = $request->getParams();

        // (2) Process the request.
        $responseData = $this->domain->doSomething();

        // (3) Send the response.
        return $this->responder->getResponse($response, $responseData);
    }
}
