<?php

/**
 * Save page changes.
 **/

declare(strict_types=1);

namespace Ufw1\Wiki\Actions;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\AbstractAction;
use Ufw1\Services\AuthService;
use Ufw1\Wiki\Responders\UpdateResponder;
use Ufw1\Wiki\WikiDomain;

class UpdatePageAction extends AbstractAction
{
    /**
     * @var WikiDomain
     **/
    protected $domain;

    /**
     * @var ShowPageResponder
     **/
    protected $responder;

    protected $auth;

    public function __construct(WikiDomain $domain, UpdateResponder $responder, AuthService $auth)
    {
        $this->domain = $domain;
        $this->responder = $responder;
        $this->auth = $auth;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        // (1) Collect input data.
        $pageName = $request->getParam('page_name');
        $sectionName = $request->getParam('page_section');
        $source = $request->getParam('page_source');
        $user = $this->auth->getUser($request);

        // (2) Process data.
        $responseData = $this->domain->updatePage($pageName, $sectionName, $source, $user);

        // (3) Show response.
        $this->addCommonData($request, $responseData);
        $response = $this->responder->getResponse($response, $responseData);

        return $response;
    }
}
