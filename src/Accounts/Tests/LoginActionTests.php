<?php

/**
 * Test what's related to the login action.
 **/

namespace Ufw1\Accounts\Tests;

use Slim\Http\Response;
use Ufw1\AbstractTest;
use Ufw1\ResponsePayload;
use Ufw1\Accounts\Actions\LoginAction;
use Ufw1\Accounts\Responders\LoginResponder;
use Ufw1\Accounts\Accounts;

class LoginActionTests extends AbstractTest
{
    public function testWrongUser(): void
    {
        $this->container->db->query('DELETE FROM nodes WHERE type = \'user\'');
        $res = $this->getDomain()->login(null, 'alice@example.com', 'foobar');
        $this->assertError(404, $res);
    }

    public function testWrongPassword(): void
    {
        $this->container->db->query('DELETE FROM nodes WHERE type = \'user\'');

        $login = 'alice@example.com';
        $password = 'foobar';

        $this->container->node->save([
            'type' => 'user',
            'published' => 1,
            'email' => $login,
            'password' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $res = $this->getDomain()->login(null, $login, $password . '-wrong');
        $this->assertError(403, $res);
    }

    public function testCorrectPassword(): void
    {
        $this->container->db->query('DELETE FROM nodes WHERE type = \'user\'');

        $login = 'alice@example.com';
        $password = 'foobar';

        $node = $this->container->node->save([
            'type' => 'user',
            'published' => 1,
            'email' => $login,
            'password' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $res = $this->getDomain()->login(null, $login, $password);
        $this->assertResponse($res);
        $this->assertFalse(empty($res['response']['sessionId']), 'MUST set session id');
        $this->assertFalse(empty($res['response']['redirect']), 'MUST redirect');

        $row = $this->container->db->fetchOne('SELECT * FROM sessions WHERE id = ?', [$res['response']['sessionId']]);
        $this->assertFalse(empty($row), 'MUST have a session record');
        $this->assertFalse(empty($row['data']), 'MUST have session data');

        $data = unserialize($row['data']);
        $this->assertEquals($node['id'], $data['user_id'] ?? null, 'MUST match user id');
    }

    public function testResponder(): void
    {
        $responder = $this->getResponder();
        $this->checkJsonResponderBasics($responder);

        $response = $responder->getResponse(new Response(), ResponsePayload::data([
            'sessionId' => 'foobar_123',
            'redirect' => '/profile',
        ]));

        $this->assertEquals(200, $response->getStatusCode());

        $headers = $response->getHeaders();
        $this->assertFalse(empty($headers['Set-Cookie']), 'MUST set a cookie');
        $this->assertEquals('session_id=foobar_123', $headers['Set-Cookie'][0], 'wrong cookie');
    }

    protected function getDomain(): Accounts
    {
        $domain = $this->getClassInstance(Accounts::class);
        return $domain;
    }

    protected function getResponder(): LoginResponder
    {
        $responder = $this->getClassInstance(LoginResponder::class);
        return $responder;
    }
}
