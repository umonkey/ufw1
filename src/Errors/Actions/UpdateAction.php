<?php

/**
 * Update an error.
 **/

declare(strict_types=1);

namespace Ufw1\Errors\Actions;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\AbstractAction;
use Ufw1\Services\AuthService;
use Ufw1\Services\Database;
use Ufw1\Errors\Responders\UpdateResponder;
use Ufw1\Errors\Errors;

class UpdateAction extends AbstractAction
{
    /**
     * @var Errors
     **/
    protected $domain;

    /**
     * @var UpdateResponder
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

    public function __construct(Errors $domain, UpdateResponder $responder, AuthService $auth, Database $db)
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
        $read = $request->getParam('read') === 'yes';
        $user = $this->auth->getUser($request);

        // (2) Process data.
        $responseData = $this->domain->updateAction($id, $read, $user);

        // (3) Show response.
        $this->addCommonData($request, $responseData);
        $response = $this->responder->getResponse($response, $responseData);

        if (!$responseData->isError()) {
            $this->db->commit();
        }

        return $response;
    }
}
