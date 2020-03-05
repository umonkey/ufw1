<?php

/**
 * Test what's related to the showError action.
 **/

namespace Ufw1\Errors\Tests;

use Slim\Http\Response;
use Ufw1\AbstractTest;
use Ufw1\Errors\Actions\ShowErrorAction;
use Ufw1\Errors\Responders\ShowErrorResponder;
use Ufw1\Errors\Errors;

class ShowErrorActionTests extends AbstractTest
{
    /**
     * Make sure that nonymous access works well.
     **/
    public function testAnonymousAccess(): void
    {
        $user = $this->getNobody();
        $res = $this->getDomain()->showError(1, $user);
        $this->assertError(403, $res);
    }

    /**
     * Make sure that user access works well.
     **/
    public function testUserAccess(): void
    {
        $user = $this->getEditor();
        $res = $this->getDomain()->showError(1, $user);
        $this->assertError(403, $res);
    }

    public function testAdminAccess(): void
    {
        $this->container->db->query('DELETE FROM errors');

        $user = $this->getAdmin();
        $res = $this->getDomain()->showError(1, $user);
        $this->assertError(404, $res);
    }

    public function testCorrecr(): void
    {
        $this->container->db->query('DELETE FROM errors');

        $id = $this->container->db->insert('errors', [
            'date' => '2020-01-01 12:34:56',
            'class' => 'RuntimeException',
            'message' => 'It Works!',
            'file' => 'foobar.php',
            'line' => 1,
            'stack' => 'not available',
            'headers' => serialize([
                'foo' => 'bar',
            ]),
            'read' => 0,
        ]);

        $user = $this->getAdmin();
        $res = $this->getDomain()->showError($id, $user);
        $this->assertResponse($res);
        $this->assertFalse(empty($res['response']['error']), 'MUST return error description');
        $this->assertEquals('bar', $res['response']['error']['headers']['foo'] ?? null, 'wrong headers');
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

    protected function getResponder(): ShowErrorResponder
    {
        $responder = $this->getClassInstance(ShowErrorResponder::class);
        return $responder;
    }
}
