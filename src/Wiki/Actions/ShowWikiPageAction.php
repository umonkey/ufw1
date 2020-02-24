<?php

/**
 * Display a wiki page.
 **/

declare(strict_types=1);

namespace Ufw1\Wiki\Actions;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\AbstractAction;
use Ufw1\Services\AuthService;
use Ufw1\Wiki\WikiDomain;
use Ufw1\Wiki\Responders\ShowPageResponder;

class ShowWikiPageAction extends AbstractAction
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

    public function __construct(WikiDomain $domain, ShowPageResponder $responder, AuthService $auth)
    {
        $this->domain = $domain;
        $this->responder = $responder;
        $this->auth = $auth;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $pageName = $request->getParam('name');
        $user = $this->auth->getUser($request);

        $responseData = $this->domain->getShowPageByName($pageName, $user);
        $this->addCommonData($request, $responseData);

        $response = $this->responder->getResponse($response, $responseData);

        return $response;
    }
}
