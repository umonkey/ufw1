<?php

/**
 * Test what's related to the profile action.
 **/

namespace Ufw1\Accounts\Tests;

use Slim\Http\Response;
use Ufw1\AbstractTest;
use Ufw1\Accounts\Actions\ProfileAction;
use Ufw1\Accounts\Responders\ProfileResponder;
use Ufw1\Accounts\Accounts;

class ProfileActionTests extends AbstractTest
{
    /**
     * Make sure that nonymous access works well.
     **/
    public function testAnonymousAccess(): void
    {
        $user = $this->getNobody();
        $res = $this->getDomain()->profile($user);
        $this->assertError(403, $res);
    }

    /**
     * Make sure that user access works well.
     **/
    public function testUserAccess(): void
    {
        $user = $this->getEditor();
        $res = $this->getDomain()->profile($user);
        $this->assertResponse($res);
    }

    public function testAdminAccess(): void
    {
        $user = $this->getAdmin();
        $res = $this->getDomain()->profile($user);
        $this->assertResponse($res);
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

    protected function getResponder(): ProfileResponder
    {
        $responder = $this->getClassInstance(ProfileResponder::class);
        return $responder;
    }
}
