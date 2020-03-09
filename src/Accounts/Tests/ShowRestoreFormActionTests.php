<?php

/**
 * Test what's related to the showRestoreForm action.
 **/

namespace Ufw1\Accounts\Tests;

use Slim\Http\Response;
use Ufw1\AbstractTest;
use Ufw1\Accounts\Actions\ShowRestoreFormAction;
use Ufw1\Accounts\Responders\ShowRestoreFormResponder;
use Ufw1\Accounts\Accounts;

class ShowRestoreFormActionTests extends AbstractTest
{
    /**
     * Make sure that nonymous access works well.
     **/
    public function testAnonymousAccess(): void
    {
        $user = null;
        $res = $this->getDomain()->showRestoreFormAction($user);
        $this->assertResponse($res);
    }

    /**
     * Make sure that user access works well.
     **/
    public function testUserAccess(): void
    {
        $user = $this->getEditor();
        $res = $this->getDomain()->showRestoreFormAction($user);
        $this->assertRedirect($res, '/profile');
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

    protected function getResponder(): ShowRestoreFormResponder
    {
        $responder = $this->getClassInstance(ShowRestoreFormResponder::class);
        return $responder;
    }
}
