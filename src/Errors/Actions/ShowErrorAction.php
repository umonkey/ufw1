<?php

/**
 * Display a stored error message.
 **/

declare(strict_types=1);

namespace Ufw1\Errors\Actions;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\AbstractAction;
use Ufw1\Services\AuthService;
use Ufw1\Services\Database;
use Ufw1\Errors\Responders\ShowErrorResponder;
use Ufw1\Errors\Errors;

class ShowErrorAction extends AbstractAction
{
    /**
     * @var Errors
     **/
    protected $domain;

    /**
     * @var ShowErrorResponder
     **/
    protected $responder;

    /**
     * @var AuthService
     **/
    protected $auth;

    public function __construct(Errors $domain, ShowErrorResponder $responder, AuthService $auth)
    {
        $this->domain = $domain;
        $this->responder = $responder;
        $this->auth = $auth;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        // (1) Collect input data.
        $errorId = (int)$args['id'];
        $user = $this->auth->getUser($request);

        // (2) Process data.
        $responseData = $this->domain->showError($errorId, $user);

        // (3) Show response.
        $this->addCommonData($request, $responseData);
        $response = $this->responder->getResponse($response, $responseData);

        return $response;
    }
}
