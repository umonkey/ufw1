<?php

/**
 * Display the restore account form.
 **/

declare(strict_types=1);

namespace Ufw1\Accounts\Actions;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\AbstractAction;
use Ufw1\Services\AuthService;
use Ufw1\Services\Database;
use Ufw1\Accounts\Responders\ShowRestoreFormResponder;
use Ufw1\Accounts\Accounts;

class ShowRestoreFormAction extends AbstractAction
{
    /**
     * @var Accounts
     **/
    protected $domain;

    /**
     * @var ShowRestoreFormResponder
     **/
    protected $responder;

    /**
     * @var AuthService
     **/
    protected $auth;

    public function __construct(Accounts $domain, ShowRestoreFormResponder $responder, AuthService $auth)
    {
        $this->domain = $domain;
        $this->responder = $responder;
        $this->auth = $auth;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        // (1) Collect input data.
        $user = $this->auth->getUser($request);

        // (2) Process data.
        $responseData = $this->domain->showRestoreFormAction($user);

        // (3) Show response.
        $this->addCommonData($request, $responseData);
        $response = $this->responder->getResponse($response, $responseData);

        return $response;
    }
}
