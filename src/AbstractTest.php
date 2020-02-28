<?php

/**
 * Base class for all tests.
 **/

namespace Ufw1;

use PHPUnit\Framework\TestCase;
use Slim\Http\Environment;
use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\AbstractResponder;

class AbstractTest extends TestCase
{
    protected $app;

    protected $container;

    public function setUp(): void
    {
        include getcwd() . '/config/bootstrap.php';
        $this->app = $app;
        $this->container = $app->getContainer();
        $this->container->db->beginTransaction();
    }

    public function tearDown(): void
    {
        $this->container->db->rollback();
    }

    protected function assertError(int $code, array $response): void
    {
        $this->assertFalse(empty($response['error']), 'MUST be an error');
        $this->assertEquals($code, $response['error']['code'] ?? null, "MUST be an {$code} error");
        $this->assertTrue(empty($response['redirect']), "MUST NOT redirect");
        $this->assertTrue(empty($response['response']), "MUST NOT have response data");
    }

    protected function assertRedirect(array $response, ?string $target = null): void
    {
        $this->assertTrue(empty($response['error']), 'MUST NOT fail, MUST be a valid response');
        $this->assertTrue(isset($response['redirect']), 'MUST redirect');
        $this->assertTrue(empty($response['response']), 'MUST NOT return data');

        if (null !== $target) {
            $this->assertEquals($target, $response['redirect'], 'wrong redirect target');
        }
    }

    protected function assertResponse(array $response): void
    {
        $this->assertTrue(empty($response['error']), 'MUST NOT fail, MUST be a valid response');
        $this->assertTrue(empty($response['redirect']), 'MUST NOT redirect');
        $this->assertTrue(isset($response['response']), 'MUST be a valid response');
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

    protected function getNobody(): ?array
    {
        return null;
    }

    protected function getEditor(array $props = []): array
    {
        $node = array_merge([
            'type' => 'user',
            'published' => 1,
            'deleted' => 0,
            'role' => 'editor',
            'name' => 'Головач Елена',
        ], $props);

        return $this->container->node->save($node);
    }

    protected function getAdmin(): array
    {
        return $this->container->node->save([
            'type' => 'user',
            'published' => 1,
            'deleted' => 0,
            'role' => 'admin',
            'name' => 'Сусанин Иван',
        ]);
    }

    protected function checkJsonResponderBasics(AbstractResponder $responder): void
    {
        // (1) Redirect.

        $res = $responder->getResponse(new Response(), [
            'redirect' => '/',
        ]);

        $this->assertTrue($res instanceof Response, 'MUST be an HTTP response');
        $this->assertEquals(200, $res->getStatusCode(), 'MUST redirect with a 302');

        $headers = $res->getHeaders();
        $this->assertEquals('application/json', $headers['Content-Type'][0] ?? null, 'MUST be an application/json');
        $body = json_decode((string)$res->getBody(), true);
        $this->assertTrue(isset($body['redirect']), 'MUST have the redirect property');

        // (2) Error 404.

        $res = $responder->getResponse(new Response(), [
            'error' => [
                'code' => 404,
                'message' => 'Not found.',
            ],
        ]);

        $this->assertTrue($res instanceof Response, 'MUST be an HTTP response');
        $this->assertEquals(200, $res->getStatusCode(), 'MUST redirect with a 302');

        $headers = $res->getHeaders();
        $this->assertEquals('application/json', $headers['Content-Type'][0] ?? null, 'MUST be an application/json');
        $body = json_decode((string)$res->getBody(), true);
        $this->assertTrue(isset($body['error']), 'MUST have the error property');
        $this->assertTrue(isset($body['message']), 'MUST have the message property');
    }

    protected function checkResponderBasics(AbstractResponder $responder): void
    {
        // (1) Redirect.

        $res = $responder->getResponse(new Response(), [
            'redirect' => '/',
        ]);

        $this->assertTrue($res instanceof Response, 'MUST be an HTTP response');
        $this->assertEquals(302, $res->getStatusCode(), 'MUST redirect with a 302');

        // (2) Error 404.

        $res = $responder->getResponse(new Response(), [
            'error' => [
                'code' => 404,
                'message' =>  'Not found.',
            ],
        ]);

        $this->assertTrue($res instanceof Response, 'MUST be an HTTP response');
        $this->assertEquals(404, $res->getStatusCode(), 'MUST fail with 404');
    }
}
