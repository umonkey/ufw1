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
    public function testAnonymousAccess(): void
    {
        $user = $this->getNobody();
        $res = $this->getDomain()->restore($user);
        $this->assertError(403, $res);
    }

    /**
     * Make sure that user access works well.
     **/
    public function testUserAccess(): void
    {
        $user = $this->getEditor();
        $res = $this->getDomain()->restore($user);
        $this->assertResponse($res);
    }

    public function testAdminAccess(): void
    {
        $user = $this->getAdmin();
        $res = $this->getDomain()->restore($user);
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

    protected function getResponder(): RestoreResponder
    {
        $responder = $this->getClassInstance(RestoreResponder::class);
        return $responder;
    }
}