<?php

/**
 * Test what's related to the restorePassword action.
 **/

namespace Ufw1\Accounts\Tests;

use Slim\Http\Response;
use Ufw1\AbstractTest;
use Ufw1\Accounts\Actions\RestorePasswordAction;
use Ufw1\Accounts\Responders\RestorePasswordResponder;
use Ufw1\Accounts\Accounts;

class RestorePasswordActionTests extends AbstractTest
{
    /**
     * Make sure that nonymous access works well.
     **/
    public function testEmail(): void
    {
        $db = $this->container->db;
        $db->query('DELETE FROM taskq');

        $user = $this->getEditor();

        $res = $this->getDomain()->restorePasswordAction($user['email']);
        $this->assertResponse($res);
        $this->assertTrue(isset($res['response']['message']), 'success message not shown');

        $rows = $db->fetch('SELECT * FROM taskq');
        $this->assertEquals(1, count($rows), 'must have one task');
        $payload = unserialize($rows[0]['payload']);
        $this->assertEquals('accounts.sendRestoreEmailTask', $payload['__action'], 'wrong taskq action');
        $this->assertEquals($user['id'], $payload['id'], 'wrong user node id');
    }

    public function testResponder(): void
    {
        $responder = $this->getResponder();
        $this->checkJsonResponderBasics($responder);
    }

    protected function getDomain(): Accounts
    {
        $domain = $this->getClassInstance(Accounts::class);
        return $domain;
    }

    protected function getResponder(): RestorePasswordResponder
    {
        $responder = $this->getClassInstance(RestorePasswordResponder::class);
        return $responder;
    }
}
