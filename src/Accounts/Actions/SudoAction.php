<?php

/**
 * Sudo into a user.
 **/

declare(strict_types=1);

namespace Ufw1\Accounts\Actions;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\AbstractAction;
use Ufw1\Services\AuthService;
use Ufw1\Services\Database;
use Ufw1\Accounts\Responders\SudoResponder;
use Ufw1\Accounts\Accounts;

class SudoAction extends AbstractAction
{
    /**
     * @var Accounts
     **/
    protected $domain;

    /**
     * @var SudoResponder
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

    public function __construct(Accounts $domain, SudoResponder $responder, AuthService $auth, Database $db)
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
        $userId = (int)$args['id'];
        $sessionId = $request->getCookieParam('session_id');
        $user = $this->auth->getUser($request);

        // (2) Process data.
        $responseData = $this->domain->sudo($userId, $sessionId, $user);

        // (3) Show response.
        $this->addCommonData($request, $responseData);
        $response = $this->responder->getResponse($response, $responseData);

        if ($response->getStatusCode() == 200) {
            $this->db->commit();
        }

        return $response;
    }
}
