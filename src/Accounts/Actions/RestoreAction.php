<?php

/**
 * Restore the password.
 **/

declare(strict_types=1);

namespace Ufw1\Accounts\Actions;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\AbstractAction;
use Ufw1\Services\AuthService;
use Ufw1\Services\Database;
use Ufw1\Accounts\Responders\RestoreResponder;
use Ufw1\Accounts\Accounts;

class RestoreAction extends AbstractAction
{
    /**
     * @var Accounts
     **/
    protected $domain;

    /**
     * @var RestoreResponder
     **/
    protected $responder;

    /**
     * @var Database
     **/
    protected $db;

    /**
     * @var AuthService
     **/
    protected $auth;

    public function __construct(Accounts $domain, RestoreResponder $responder, AuthService $auth, Database $db)
    {
        $this->domain = $domain;
        $this->responder = $responder;
        $this->auth = $auth;
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $this->db->beginTransaction();

        // (1) Collect input data.
        $id = (int)$args['id'];
        $code = $args['code'];
        $sessionId = $request->getCookieParam('session_id');

        // (2) Process data.
        $responseData = $this->domain->restoreAction($id, $code, $sessionId);

        // (3) Show response.
        $this->addCommonData($request, $responseData);
        $response = $this->responder->getResponse($response, $responseData);

        if (!$responseData->isError()) {
            $this->db->commit();
        }

        return $response;
    }
}
