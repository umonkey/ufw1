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
        $err = $this->getError();

        $user = $this->getNobody();
        $res = $this->getDomain()->updateAction($err, true, $user);

        $this->assertError(403, $res);
    }

    /**
     * Make sure that user access works well.
     **/
    public function testUserAccess(): void
    {
        $err = $this->getError();

        $user = $this->getEditor();
        $res = $this->getDomain()->updateAction($err, true, $user);

        $this->assertError(403, $res);
    }

    public function testAdminAccess(): void
    {
        $err = $this->getError();
        $user = $this->getAdmin();

        $res = $this->getDomain()->updateAction($err, true, $user);
        $this->assertRedirect($res);
        $this->assertEquals(true, $this->isErrorRead($err));

        $res = $this->getDomain()->updateAction($err, false, $user);
        $this->assertRedirect($res);
        $this->assertEquals(false, $this->isErrorRead($err));
    }

    public function testResponder(): void
    {
        $responder = $this->getResponder();
        $this->checkJsonResponderBasics($responder);
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

    /**
     * Creates a random error, returns its id.
     **/
    protected function getError(): int
    {
        $db = $this->container->db;

        $db->query('DELETE FROM errors');

        return $db->insert('errors', [
            'class' => 'RuntimeException',
            'message' => 'foobar',
            'file' => 'foobar.php',
            'line' => 13,
            'stack' => 'not available',
            'headers' => serialize(['server' => []]),
            'read' => 0,
        ]);
    }

    protected function isErrorRead(int $id): bool
    {
        $cell = $this->container->db->fetchcell('SELECT read FROM errors WHERE id = ?', [$id]);
        return (int)$cell !== 0;
    }
}
