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
use Ufw1\ResponsePayload;
use Ufw1\Node\Entities\Node;

abstract class AbstractTest extends TestCase
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

    protected function assertError(int $code, ResponsePayload $response): void
    {
        $this->assertTrue($response->isError(), 'MUST be an error');
        $this->assertEquals($code, $response['error']['code'] ?? null, "MUST be an {$code} error");
        $this->assertFalse($response->isRedirect(), "MUST NOT redirect");
        $this->assertFalse($response->isOK(), "MUST NOT have response data");
    }

    protected function assertRedirect(ResponsePayload $response, ?string $target = null): void
    {
        $this->assertFalse($response->isError(), 'MUST NOT fail, MUST be a valid response');
        $this->assertTrue($response->isRedirect(), 'MUST redirect');
        $this->assertFalse($response->isOK(), 'MUST NOT return data');

        if (null !== $target) {
            $this->assertEquals($target, $response['redirect'], 'wrong redirect target');
        }
    }

    protected function assertResponse(ResponsePayload $response): void
    {
        $this->assertFalse($response->isError(), 'MUST NOT fail, MUST be a valid response');
        $this->assertFalse($response->isRedirect(), 'MUST NOT redirect');
        $this->assertTrue($response->isOK(), 'MUST be a valid response');
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

    protected function getNobody(): ?Node
    {
        $node = [
            'type' => 'user',
            'published' => 1,
            'deleted' => 0,
            'role' => 'nobody',
            'name' => 'Головач Елена',
        ];

        return $this->container->node->save(new Node($node));
    }

    protected function getEditor(array $props = []): Node
    {
        $node = array_merge([
            'type' => 'user',
            'published' => 1,
            'deleted' => 0,
            'role' => 'editor',
            'name' => 'Головач Елена',
        ], $props);

        return $this->container->node->save(new Node($node));
    }

    protected function getAdmin(): Node
    {
        return $this->container->node->save(new Node([
            'type' => 'user',
            'published' => 1,
            'deleted' => 0,
            'role' => 'admin',
            'name' => 'Сусанин Иван',
        ]));
    }

    protected function checkJsonResponderBasics(AbstractResponder $responder): void
    {
        // (1) Redirect.

        $res = $responder->getResponse(new Response(), ResponsePayload::redirect('/'));

        $this->assertTrue($res instanceof Response, 'MUST be an HTTP response');
        $this->assertEquals(200, $res->getStatusCode(), 'MUST redirect with a 302');

        $headers = $res->getHeaders();
        $this->assertEquals('application/json', $headers['Content-Type'][0] ?? null, 'MUST be an application/json');
        $body = json_decode((string)$res->getBody(), true);
        $this->assertTrue(isset($body['redirect']), 'MUST have the redirect property');

        // (2) Error 404.

        $res = $responder->getResponse(new Response(), ResponsePayload::error(404, 'Not found.'));

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

        $res = $responder->getResponse(new Response(), ResponsePayload::redirect('/'));

        $this->assertTrue($res instanceof Response, 'MUST be an HTTP response');
        $this->assertEquals(302, $res->getStatusCode(), 'MUST redirect with a 302');

        // (2) Error 404.

        $res = $responder->getResponse(new Response(), ResponsePayload::error(404, 'Not found.'));

        $this->assertTrue($res instanceof Response, 'MUST be an HTTP response');
        $this->assertEquals(404, $res->getStatusCode(), 'MUST fail with 404');
    }

    protected function saveNode(array $props): Node
    {
        return $this->container->node->save(new Node($props));
    }
}
