<?php

/**
 * List recently uploaded files.
 **/

declare(strict_types=1);

namespace Ufw1\Wiki\Actions;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\AbstractAction;
use Ufw1\Services\AuthService;
use Ufw1\Wiki\WikiDomain;
use Ufw1\Wiki\Responders\RecentFilesResponder;

class RecentFilesAction extends AbstractAction
{
    /**
     * @var WikiDomain
     **/
    protected $domain;

    /**
     * @var RecentFilesResponder
     **/
    protected $responder;

    /**
     * @var AuthService
     **/
    protected $auth;

    public function __construct(WikiDomain $domain, RecentFilesResponder $responder, AuthService $auth)
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
        $responseData = $this->domain->recentFiles($user);

        // (3) Show response.
        $this->addCommonData($request, $responseData);
        $response = $this->responder->getResponse($response, $responseData);

        return $response;
    }
}
