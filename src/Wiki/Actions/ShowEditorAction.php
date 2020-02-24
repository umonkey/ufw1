<?php

/**
 * Display the wiki page editor.
 **/

declare(strict_types=1);

namespace Ufw1\Wiki\Actions;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\AbstractAction;
use Ufw1\Services\AuthService;
use Ufw1\Wiki\Responders\EditorResponder;
use Ufw1\Wiki\WikiDomain;

class ShowEditorAction extends AbstractAction
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

    public function __construct(WikiDomain $domain, EditorResponder $responder, AuthService $auth)
    {
        $this->domain = $domain;
        $this->responder = $responder;
        $this->auth = $auth;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $pageName = $request->getParam('name');
        $sectionName = $request->getParam('section');
        $user = $this->auth->getUser($request);

        $responseData = $this->domain->getPageEditorData($pageName, $sectionName, $user);
        $this->addCommonData($request, $responseData);

        $response = $this->responder->getResponse($response, $responseData);

        return $response;
    }
}
