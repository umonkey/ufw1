<?php

/**
 * Custom error handler.
 **/

declare(strict_types=1);

namespace Ufw1\Controllers;

use Slim\Http\Request;
use Slim\Http\Response;
use Throwable;
use Ufw1\CommonHandler;

class ErrorController extends CommonHandler
{
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $e = $args['exception'];
        $data = $this->getTemplateData($request, $e);

        if ($this->db->isTransactionActive()) {
            $this->db->rollback();
        }

        if ($e instanceof \Ufw1\Errors\Unauthorized) {
            $templates = ['errors/unauthorized.twig', 'errors/401.twig', 'errors/default.twig'];
            $data['status'] = 401;
        } elseif ($e instanceof \Ufw1\Errors\Forbidden) {
            $templates = ['errors/forbidden.twig', 'errors/403.twig', 'errors/default.twig'];
            $data['status'] = 403;
        } elseif ($e instanceof \Ufw1\Errors\NotFound) {
            $templates = ['errors/notfound.twig', 'errors/404.twig', 'errors/default.twig'];
            $data['status'] = 404;

            // Rewrite support.
            if ($url = $this->getRedirect($request->getUri()->getPath())) {
                return $response->withRedirect($url);
            }
        } else {
            $templates = ['errors/other.twig', 'errors/default.twig'];
            $data['status'] = 500;
            $this->logError($e, $request);
        }

        if ($this->isXHR($request)) {
            return $response->withJSON([
                'error' => $data['e']['class'],
                'message' => $data['e']['message'],
                'data' => $data,
            ]);
        }

        try {
            $nodeId = $this->settings['error_nodes'][$data['status']] ?? null;
            if ($nodeId !== null) {
                if ($node = $this->node->get((int)$nodeId)) {
                    $data['node'] = $node;
                }
            }
            $response = $this->render($request, $templates, $data);
            return $response->withStatus($data['status']);
        } catch (Throwable $e) {
            $this->logger->error('error {err} rendering error message: {data}, error templates: {templates}', [
                'err' => $e->getMessage(),
                'data' => $data,
                'templates' => $templates,
            ]);

            $response->getBody()->write('Error displaying error message.');

            return $response
                ->withStatus(500)
                ->withHeader('Content-Type', 'text/plain');
        }
    }

    protected function getRedirect(string $url): ?string
    {
        $dst = $this->db->fetchcell('SELECT dst FROM rewrite WHERE src = ?', [$url]);
        return $dst ?: null;
    }

    /**
     * Форматирование стэка исключения.
     *
     * @param Throwable $e       Полученное исключение.
     * @param Request   $request Параметры запроса.
     *
     * @return string Отформатированный текст.
     **/
    private function getExceptionStack(Throwable $e, Request $request): string
    {
        $stack = $e->getTraceAsString();

        $root = dirname($request->getServerParam('DOCUMENT_ROOT'));
        $stack = str_replace($root . '/', '', $stack);

        // Hide arguments: passwords, etc.
        $stack = preg_replace_callback('@(\(([^()]+)\))@', function ($m) {
            if (is_numeric($m[2])) {
                return $m[1];
            }

            return '(...)';
        }, $stack);

        return $stack;
    }

    /**
     * Подготовка данных для шаблонов.
     *
     * @param Request   $request Параметры запроса.
     * @param Throwable $e       Возникшая ошибка.
     *
     * @return array Данные для шаблона.
     **/
    private function getTemplateData(Request $request, Throwable $e): array
    {
        $data = [];

        $data['e'] = [
            'class' => get_class($e),
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
            'stack' => $this->getExceptionStack($e, $request),
        ];

        $data['path'] = $request->getUri()->getPath();

        $data['user'] = $this->auth->getUser($request);

        return $data;
    }

    private function isXHR(Request $request): bool
    {
        return $request->getServerParam('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest';
    }

    /**
     * Save error information somewhere.
     *
     * Cannot save it in the database, because the transaction is about to be rolled back.
     **/
    protected function logError(Throwable $e, Request $request): void
    {
        $headers = [
            'params' => $request->getParams(),
            'cookies' => $request->getCookieParams(),
            'server' => $request->getServerParams(),
        ];

        $row = [
            'date' => strftime('%Y-%m-%d %H:%M:%S'),
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'stack' => $this->getExceptionStack($e, $request),
            'headers' => serialize($headers),
            'read' => 0,
        ];

        try {
            $id = $this->db->insert('errors', $row);
            $url = $request->getUri()->getBaseUrl() . '/admin/errors/' . $id;
        } catch (\PDOException $e) {
            $this->logger->error('ERROR logging exception to database: {row}', [
                'row' => $row,
            ]);

            $id = $url = null;
        }


        $this->logger->error('Exception {class}: {message} -- at {file} line {line}, logged, id={id}, see it at {url}', [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'id' => $id,
            'url' => $url,
        ]);

        $this->taskq->add('telega', [
            'message' => sprintf("New %s at %s\n%s\n%s", get_class($e), $request->getUri()->getHost(), $e->getMessage(), $url),
        ]);
    }
}
