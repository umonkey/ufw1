<?php

namespace Ufw1\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Http\Environment;
use Slim\Http\Request;
use Slim\Http\Response;

class Base extends TestCase
{
    protected $app;

    protected $container;

    public function setUp(): void
    {
        include getcwd() . '/config/bootstrap.php';
        $this->app = $app;
        $this->container = $app->getContainer();
    }

    /**
     * Симуляция рапроса.
     *
     * Документация: http://www.slimframework.com/docs/v3/cookbook/environment.html
     *
     * @param string $method  Метод запроса: GET, POST.
     * @param string $path    Запрашиваемый путь.
     * @param array  $options Непонятно что.
     **/
    protected function request($method, $path, $options = []): Response
    {
        $env = Environment::mock([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
        ]);

        $req = Request::createFromEnvironment($env);

        $this->container['request'] = $req;

        $response = $this->app->run(true);

        return $response;
    }

    /**
     * Выполнение POST запроса.
     *
     * @param  string   $path    Адрес для отправки запроса.
     * @param  mixed    $body    Текст или массив.
     * @param  array    $options Дополнительные параметры окружения.
     * @return Response          Ответ.
     **/
    protected function doPOST($path, $body = null, $options = []): Response
    {
        if (is_array($body)) {
            $body = http_build_query($body);
        }

        $env = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => $path,
            'QUERY_STRING' => $body,
            'CONTENT_TYPE' => 'multipart/form-data',
            'ENVIRONMENT' => 'test',
        ];

        if (isset($options['user_id'])) {
            $env['MOCK_USER_ID'] = $options['user_id'];
        }

        $env = Environment::mock($env);

        $req = Request::createFromEnvironment($env);

        $this->container['request'] = $req;

        $response = $this->app->run(true);

        return $response;
    }

    protected function setUserId($id)
    {
        $this->container['environment']['MOCK_USER_ID'] = $id;
    }

    /**
     * Create class instance via DI.
     **/
    protected function getClassInstance(string $className): object
    {
        $resolver = $this->container['callableResolver'];
        $instance = $resolver->getClassInstance($className);
        return $instance;
    }

    protected function beginTransaction(): void
    {
        $this->container->db->beginTransaction();
    }

    protected function rollback(): void
    {
        $this->container->db->rollback();
    }
}
