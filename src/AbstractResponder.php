<?php

/**
 * Common responder functions.
 **/

declare(strict_types=1);

namespace Ufw1;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\ResponsePayload;
use Ufw1\Services\Template;

abstract class AbstractResponder
{
    /**
     * Used to determine whether we're in XHR mode.
     *
     * TODO: maybe this does not belong here?
     *
     * @var Request
     **/
    protected $request = null;

    /**
     * @var Template
     **/
    protected $template = null;

    public function __construct(Template $template, Request $request)
    {
        $this->template = $template;
        $this->request = $request;
    }

    public function getResponse(Response $response, ResponsePayload $responseData): ?Response
    {
        return self::getCommonResponse($response, $responseData);
    }

    public function getCommonResponse(Response $response, ResponsePayload $responseData): ?Response
    {
        if (isset($responseData['redirect'])) {
            return $this->getRedirect($response, $responseData);
        }

        if (null !== ($code = $responseData['error']['code'] ?? null)) {
            $data = $responseData['error'];

            switch ($code) {
                case 401:
                    return $this->getUnauthorized($response, $data);
                case 403:
                    return $this->getForbidden($response, $data);
                case 404:
                    return $this->getNotFound($response, $data);
                default:
                    return $this->getError($response, $data);
            }
        }

        return null;
    }

    public function getCommonJsonResponse(Response $response, ResponsePayload $responseData): ?Response
    {
        if (isset($responseData['redirect'])) {
            return $response->withJSON([
                'redirect' => $responseData['redirect'],
            ]);
        }

        if (isset($responseData['error'])) {
            return $response->withJSON([
                'message' => $responseData['error']['message'] ?? 'Unknown error.',
                'error' => $responseData['error']['code'],
            ]);
        }

        return null;
    }

    protected function getError(Response $response, array $data): Response
    {
        return $this->renderResponse($response, [
            "errors/{$data['code']}.twig",
            'errors/default.twig',
        ], $data, $data['code']);
    }

    protected function getForbidden(Response $response, array $data): Response
    {
        return $this->renderResponse($response, [
            'errors/forbidden.twig',
            'errors/403.twig',
            'errors/default.twig',
        ], $data, 403);
    }

    protected function getNotFound(Response $response, array $data): Response
    {
        return $this->renderResponse($response, [
            'errors/notfound.twig',
            'errors/404.twig',
            'errors/default.twig',
        ], $data, 404);
    }

    protected function getRedirect(Response $response, ResponsePayload $data): Response
    {
        // TODO: set cookies
        // Cookies are set by login actions, passed where?

        $location = $data['redirect'];
        $status = $data['status'];

        if ($this->request && $this->isXHR($this->request)) {
            return $response->withJSON([
                'redirect' => $location,
            ]);
        } else {
            return $response->withRedirect($location);
        }
    }

    protected function getUnauthorized(Response $response, array $data): Response
    {
        return $this->renderResponse($response, [
            'errors/unauthorized.twig',
            'errors/401.twig',
            'errors/default.twig',
        ], $data, 401);
    }

    protected function isXHR(Request $request): bool
    {
        return $request->getServerParam('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest';
    }

    protected function renderResponse(Response $response, array $templateNames, array $data, int $status): Response
    {
        $html = $this->template->render($templateNames, $data);

        $response->getBody()->write($html);

        return $response->withHeader('content-type', 'text/html')
            ->withStatus($status);
    }
}
