<?php

/**
 * Test what's related to the sudo action.
 **/

namespace Ufw1\Accounts\Tests;

use Slim\Http\Response;
use Ufw1\AbstractTest;
use Ufw1\Accounts\Actions\SudoAction;
use Ufw1\Accounts\Responders\SudoResponder;
use Ufw1\Accounts\AccountsDomain;

class SudoActionTests extends AbstractTest
{
    /**
     * Make sure that nonymous access works well.
     **/
    public function testAnonymousAccess(): void
    {
        $user = $this->getNobody();
        $res = $this->getDomain()->sudo(0, '', $user);
        $this->assertError(403, $res);
    }

    /**
     * Make sure that user access works well.
     **/
    public function testUserAccess(): void
    {
        $user = $this->getEditor();
        $res = $this->getDomain()->sudo(0, '', $user);
        $this->assertError(403, $res);
    }

    public function testBadSession(): void
    {
        $this->container->db->query('DELETE FROM sessions');

        $user = $this->getAdmin();
        $res = $this->getDomain()->sudo($user['id'], 'foobar', $user);
        $this->assertError(404, $res);
    }

    public function testSuccess(): void
    {
        $admin = $this->getAdmin();
        $editor = $this->getEditor();
        $sid = '12341234123412341234123412341234';

        $this->container->db->query('DELETE FROM sessions');

        $this->container->db->insert('sessions', [
            'id' => $sid,
            'updated' => strftime('%Y-%m-%d %H:%M:%S'),
            'data' => serialize([
                'user_id' => $admin['id'],
            ]),
        ]);

        $user = $this->getAdmin();
        $res = $this->getDomain()->sudo($user['id'], $sid, $user);
        $this->assertRedirect($res, '/account');

        $session = $this->container->db->fetchOne('SELECT * FROM sessions WHERE id = ?', [$sid]);
        $data = unserialize($session['data']);
        $this->assertEquals($user['id'], $data['user_id'], 'wrong user id in the session');
    }

    public function testResponder(): void
    {
        $responder = $this->getResponder();
        $this->checkResponderBasics($responder);
        // $this->checkJsonResponderBasics($responder);
    }

    protected function getDomain(): AccountsDomain
    {
        $domain = $this->getClassInstance(AccountsDomain::class);
        return $domain;
    }

    protected function getResponder(): SudoResponder
    {
        $responder = $this->getClassInstance(SudoResponder::class);
        return $responder;
    }
}
