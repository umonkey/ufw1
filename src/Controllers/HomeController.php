<?php

/**
 * Wiki pages.
 **/

declare(strict_types=1);

namespace Ufw1\Controllers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;
use Ufw1\Services\AuthService;
use Ufw1\Services\Template;
use Ufw1\Util;

class HomeController extends CommonHandler
{
    private $template;

    private $wikiHome;

    private $auth;

    public function __construct(Template $template, AuthService $auth, $settings)
    {
        $this->template = $template;

        $this->auth = $auth;

        $this->wikiHome = $settings['wiki']['home_page'] ?? null;
    }

    public function onIndex(Request $request, Response $response, array $args): Response
    {
        if (null !== $this->wikiHome) {
            $url = '/wiki?name=' . urlencode($this->wikiHome);

            return $response->withRedirect($url);
        }

        $user = $this->auth->getUser($request);

        return $this->render($request, 'pages/home/twig', [
            'user' => $user,
        ]);
    }
}
