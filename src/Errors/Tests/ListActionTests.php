<?php

/**
 * Test what's related to the list action.
 **/

namespace Ufw1\Errors\Tests;

use Slim\Http\Response;
use Ufw1\AbstractTest;
use Ufw1\Errors\Actions\ListAction;
use Ufw1\Errors\Responders\ListResponder;
use Ufw1\Errors\Errors;

class ListActionTests extends AbstractTest
{
    /**
     * Make sure that nonymous access works well.
     **/
    public function testAnonymousAccess(): void
    {
        $user = $this->getNobody();
        $res = $this->getDomain()->listAction($user);
        $this->assertError(403, $res);
    }

    /**
     * Make sure that user access works well.
     **/
    public function testUserAccess(): void
    {
        $user = $this->getEditor();
        $res = $this->getDomain()->listAction($user);
        $this->assertError(403, $res);
    }

    public function testAdminAccess(): void
    {
        $user = $this->getAdmin();
        $res = $this->getDomain()->listAction($user);
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

    protected function getResponder(): ListResponder
    {
        $responder = $this->getClassInstance(ListResponder::class);
        return $responder;
    }
}
