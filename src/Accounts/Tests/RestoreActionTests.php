<?php

/**
 * Test what's related to the restore action.
 **/

namespace Ufw1\Accounts\Tests;

use Slim\Http\Response;
use Ufw1\AbstractTest;
use Ufw1\Accounts\Actions\RestoreAction;
use Ufw1\Accounts\Responders\RestoreResponder;
use Ufw1\Accounts\Accounts;

class RestoreActionTests extends AbstractTest
{
    /**
     * Make sure that nonymous access works well.
     **/
    public function testDomain(): void
    {
        $user = $this->getEditor([
            'otp_hash' => 'foobar',
        ]);

        $uid = (int)$user['id'];
        $code = $user['otp_hash'];

        $res = $this->getDomain()->restoreAction($uid, $code, null);
        $this->assertTrue(isset($res['response']['redirect']), '/profile');
        $this->assertTrue(isset($res['response']['sessionId']), 'sessionId must be set');
    }

    /**
     * Make sure that nonymous access works well.
     **/
    public function testAction(): void
    {
        $user = $this->getEditor([
            'otp_hash' => 'foobar',
        ]);

        $uid = (int)$user['id'];
        $code = $user['otp_hash'];

        $res = $this->GET("/account/restore/{$uid}/{$code}");
        $this->assertEquals(302, $res->getStatusCode(), 'wrong redirect status');
        $this->assertEquals('/profile', $res->getHeaders()['Location'][0], 'wrong redirect target');
        $this->assertEquals(0, strpos('session_id=', $res->getHeaders()['Set-Cookie'][0]), 'wrong cookie');
    }

    public function testResponder(): void
    {
        $responder = $this->getResponder();
        $this->checkResponderBasics($responder);
        // $this->checkJsonResponderBasics($responder);
    }

    protected function getDomain(): Accounts
    {
        $domain = $this->getClassInstance(Accounts::class);
        return $domain;
    }

    protected function getResponder(): RestoreResponder
    {
        $responder = $this->getClassInstance(RestoreResponder::class);
        return $responder;
    }
}
