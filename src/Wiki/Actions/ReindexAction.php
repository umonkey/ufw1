<?php

/**
 * Do something.
 **/

declare(strict_types=1);

namespace Ufw1\Wiki\Actions;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\AbstractAction;
use Ufw1\Services\AuthService;
use Ufw1\Services\Database;
use Ufw1\Wiki\Responders\ReindexResponder;
use Ufw1\Wiki\WikiDomain;

class ReindexAction extends AbstractAction
{
    /**
     * @var WikiDomain
     **/
    protected $domain;

    /**
     * @var ReindexResponder
     **/
    protected $responder;

    /**
     * @var AuthService
     **/
    protected $auth;

    /**
     * @var AuthService
     **/
    protected $db;

    public function __construct(WikiDomain $domain, ReindexResponder $responder, AuthService $auth, Database $db)
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
        $user = $this->auth->getUser($request);

        // (2) Process data.
        $responseData = $this->domain->reindex($user);

        if (empty($responseData['error'])) {
            $this->db->commit();
        }

        // (3) Show response.
        $this->addCommonData($request, $responseData);
        $response = $this->responder->getResponse($response, $responseData);

        return $response;
    }
}
