<?php

/**
 * Common reponder functions.
 **/

declare(strict_types=1);

namespace Ufw1;

use Slim\Http\Response;

abstract class AbstractResponder
{
    public function getCommonResponse(Response $response, array $responseData): ?Response
    {
        if (isset($responseData['redirect'])) {
            return $response->withRedirect($responseData['redirect']);
        }

        if (null !== ($code = $responseData['error']['code'] ?? null)) {
            $data = $responseData['error'];

            switch ($code) {
                case 401:
                    return $this->getUnauthorized($response, $data);
                case 403:
                    return $this->getForbidden($response, $data);
                case 404:
                    return $this->getForbidden($response, $data);
                default:
                    return $this->getError($response, $data);
            }
        }

        return null;
    }

    public function getCommonJsonResponse(Response $response, array $responseData): ?Response
    {
        if (isset($responseData['redirect'])) {
            return $response->withJSON([
                'redirect' => $responseData['redirect'],
            ]);
        }

        if (isset($responseData['error'])) {
            return $response->withJSON([
                'message' => $responseData['error']['message'] ?? 'Unknown error.',
                'error' => $responseData['error']['error'],
            ]);
        }

        return null;
    }

    protected function getForbidden(Response $response, array $data): Response
    {
        return $this->renderResponse($response, [
            'errors/forbidden.twig',
            'errors/403.twig',
            'errors/default.twig',
        ], $data, 403);
    }

    protected function getUnauthorized(Response $response, array $data): Response
    {
        return $this->renderResponse($response, [
            'errors/unauthorized.twig',
            'errors/401.twig',
            'errors/default.twig',
        ], $data, 401);
    }

    protected function renderResponse(Response $response, array $templateNames, array $data, int $status): Response
    {
        $html = $this->template->render($templateNames, $data);

        $response->getBody()->write($html);

        return $response->withHeader('content-type', 'text/html')
            ->withStatus($status);
    }
}
