<?php

/**
 * Handle file upload.
 **/

declare(strict_types=1);

namespace Ufw1\Wiki\Actions;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\AbstractAction;
use Ufw1\Services\AuthService;
use Ufw1\Services\Database;
use Ufw1\Wiki\WikiDomain;
use Ufw1\Wiki\Responders\UploadResponder;

class UploadAction extends AbstractAction
{
    /**
     * @var WikiDomain
     **/
    protected $domain;

    /**
     * @var UploadResponder
     **/
    protected $responder;

    /**
     * @var AuthService
     **/
    protected $auth;

    /**
     * @var Database
     **/
    protected $db;

    public function __construct(WikiDomain $domain, UploadResponder $responder, AuthService $auth, Database $db)
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
        $link = $request->getParam('link');
        $files = $request->getUploadedFiles()['files'] ?? [];
        $user = $this->auth->getUser($request);

        // (2) Process data.
        $responseData = $this->domain->upload($link, $files, $user);

        // (3) Show response.
        $response = $this->responder->getResponse($response, $responseData);

        if ($response->getStatusCode() == 200) {
            $this->db->commit();
        }

        return $response;
    }
}
