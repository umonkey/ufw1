<?php

/**
 * Test what's related to the update action.
 **/

namespace Ufw1\Errors\Tests;

use Slim\Http\Response;
use Ufw1\AbstractTest;
use Ufw1\Errors\Actions\UpdateAction;
use Ufw1\Errors\Responders\UpdateResponder;
use Ufw1\Errors\Errors;

class UpdateActionTests extends AbstractTest
{
    /**
     * Make sure that nonymous access works well.
     **/
    public function testAnonymousAccess(): void
    {
        $user = $this->getNobody();
        $res = $this->getDomain()->update($user);
        $this->assertError(403, $res);
    }

    /**
     * Make sure that user access works well.
     **/
    public function testUserAccess(): void
    {
        $user = $this->getEditor();
        $res = $this->getDomain()->update($user);
        $this->assertResponse($res);
    }

    public function testAdminAccess(): void
    {
        $user = $this->getAdmin();
        $res = $this->getDomain()->update($user);
        $this->assertResponse($res);
    }

    public function testResponder(): void
    {
        $responder = $this->getResponder();
        $this->checkResponderBasics($responder);
        // $this->checkJsonResponderBasics($responder);
    }

    protected function getDomain(): Errors
    {
        $domain = $this->getClassInstance(Errors::class);
        return $domain;
    }

    protected function getResponder(): UpdateResponder
    {
        $responder = $this->getClassInstance(UpdateResponder::class);
        return $responder;
    }
}