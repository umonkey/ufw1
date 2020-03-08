<?php

/**
 * Test all aspects of the wiki domain.
 *
 * Uses transaction to maintain database integrity an speed thing up radically.
 *
 * @todo Replace the wiki service with a pre-configure one, independent on site settings.
 **/

namespace Ufw1\Tests;

use Slim\Http\Response;
use Ufw1\ResponsePayload;
use Ufw1\Wiki\WikiDomain;
use Ufw1\AbstractTest;
use Ufw1\Node\Entities\Node;

class WikiTests extends AbstractTest
{
    public const DOMAIN_CLASS = WikiDomain::class;

    /**
     * Make sure the wiki redirects to the default page.
     **/
    public function testHomePageRedirect(): void
    {
        $domain = $this->getDomain();

        $responseData = $domain->getShowPageByName(null);

        $this->assertTrue(!empty($responseData['redirect']), 'redirect to home page not happening');
    }

    /**
     * Make sure that accessing missing pages results in error 404.
     **/
    public function testPageDoesNotExist(): void
    {
        $pageName = __FUNCTION__;

        $responseData = $this->getDomain()->getShowPageByName($pageName);

        $this->assertEquals(404, $responseData['error']['code'] ?? null, 'Missing page not reported.');
    }

    /**
     * Make sure that page are only accessible by configured readers.
     **/
    public function testReadForbidden(): void
    {
        $node = $this->savePage('test-page', file_get_contents(__DIR__ . '/sample-page-01.md'));

        $responseData = $this->getDomain()->getShowPageByName('test-page');

        $this->assertEquals(401, $responseData['error']['code'] ?? null, 'Must NOT allow anonymous reading.');
    }

    public function testSuccessfullPageDisplay(): void
    {
        $pageName = 'test-page';

        $node = $this->savePage('test-page', file_get_contents(__DIR__ . '/sample-page-01.md'));
        $this->assertFalse(empty($node['id']), 'Wiki page not assigned an id.');

        $responseData = $this->getDomain()->getShowPageByName($pageName, $this->getAdmin());

        $this->assertTrue(empty($responseData['error']), 'Wiki page MUST be readable.');
        $this->assertFalse(empty($responseData['response']), 'Wiki page not properly rendered.');

        $res = $responseData['response'];

        $this->assertEquals((int)$node['id'], (int)$res['node']['id'], 'Wrong wiki page displayed.');

        $this->assertEquals('It Works!', $res['page']['title'] ?? null, 'Wiki page title not properly extracted.');
        $this->assertEquals('<p>Hello, world.</p>', $res['page']['html'], 'Wiki page not converted to HTML.');
        $this->assertEquals('ru', $res['page']['language'] ?? null, 'Wiki page language not extracted.');
    }

    public function testShowPageResponder(): void
    {
        $responder = $this->getClassInstance('Ufw1\Wiki\Responders\ShowPageResponder');

        // (1) Unauthorized.
        $response = $responder->getResponse(new Response(), ResponsePayload::error(401, 'Need to authorize.'));
        $this->assertEquals(401, $response->getStatusCode(), 'MUST fail with 401.');

        // (2) Forbidden.
        $response = $responder->getResponse(new Response(), ResponsePayload::error(403, 'Forbidden.'));
        $this->assertEquals(403, $response->getStatusCode(), 'MUST fail with 403.');

        // (3) Not found.
        $response = $responder->getResponse(new Response(), ResponsePayload::error(404, 'Not found.', [
            'pageName' => 'foobar',
            'edit_link' => '/wiki/edit?name=foobar',
        ]));
        $this->assertEquals(404, $response->getStatusCode(), 'MUST fail with 403.');

        // (4) Redirect.
        $response = $responder->getResponse(new Response(), ResponsePayload::redirect('/wiki?name=Welcome'));
        $this->assertEquals(302, $response->getStatusCode(), 'MUST redirect.');

        // (5) OK.
        $response = $responder->getResponse(new Response(), ResponsePayload::data([
            'node' => [
                'id' => 1,
                'created' => '2020-01-01 12:34:56',
                'source' => '',
            ],
            'page' => [
                'name' => 'foobar',
                'html' => '',
            ],
        ]));
        $this->assertEquals(200, $response->getStatusCode(), 'MUST redirect.');
    }

    /**
     * Make sure that a page can be unpublished using YAML.
     **/
    public function testPerPageUnpublish(): void
    {
        $user = $this->getAdmin();

        $this->savePage('test-page', "published: 1\n"
            . "---\n"
            . "Test page.\n");

        $res = $this->getDomain()->getShowPageByName('test-page', $user);
        $this->assertTrue(empty($res['error']), 'Page should be readable.');
        $this->assertFalse(empty($res['response']), 'Proper response missing.');

        $this->savePage('test-page', "published: no\n"
            . "---\n"
            . "Test page.\n");

        $res = $this->getDomain()->getShowPageByName('test-page', $user);
        $this->assertEquals(403, $res['error']['code'] ?? null, 'Page MUST NOT be readable.');
        $this->assertTrue(empty($res['response']), 'Page MUST NOT be readable.');
    }

    /**
     * Make sure the edit link is added to editors.
     **/
    public function testWikiEditLink(): void
    {
        $pageName = 'testWikiEditLink';

        $node = $this->savePage($pageName, "published: 1\n"
            . "---\n"
            . "Test page.\n");

        $res = $this->getDomain()->getShowPageByName($pageName, $this->getReaderUser());
        $this->assertResponse($res);
        $this->assertTrue(empty($res['response']['edit_link']), 'This page MUST NOT have edit_link.');

        $res = $this->getDomain()->getShowPageByName($pageName, $this->getWriterUser());
        $this->assertResponse($res);
        $this->assertFalse(empty($res['response']['edit_link']), 'This page MUST have edit_link.');
    }

    /**
     * Make sure the wiki page editor is working properly.
     *
     * (1) Test 403 for readers.
     **/
    public function testWikiEditor(): void
    {
        $pageName = __FUNCTION__;
        $pageSource = file_get_contents(__DIR__ . '/sample-page-02.md');

        $domain = $this->getDomain();

        $node = $this->savePage($pageName, $pageSource);

        $res = $domain->getPageEditorData($pageName, null, null);
        $this->assertEquals(401, $res['error']['code'] ?? null, 'Login request expected.');

        $res = $domain->getPageEditorData($pageName, null, $this->getReaderUser());
        $this->assertEquals(403, $res['error']['code'] ?? null, 'Forbidden error expected.');

        $res = $domain->getPageEditorData($pageName, null, $this->getWriterUser());
        $this->assertTrue(empty($res['error']), 'Page MUST be editable.');
        $this->assertEquals($node['name'], $res['response']['page_name'], 'Wrong page_name in form data.');
        $this->assertEquals($node['source'], $res['response']['page_source'], 'Wrong page_source in form data.');

        $res = $domain->getPageEditorData($pageName, 'Section', $this->getWriterUser());
        $this->assertTrue(empty($res['error']), 'Page MUST be editable.');
        $this->assertEquals($node['name'], $res['response']['page_name'], 'Wrong page_name in form data.');
        $this->assertEquals("## Section\n\nThis is a page section.\n", $res['response']['page_source'], 'Wrong page_source in form data.');

        // Update failure: anonymous.
        $res = $domain->updatePage($pageName, null, 'Hello, world.', null);
        $this->assertEquals(401, $res['error']['code'] ?? null, 'MUST ask to log in.');
        $this->assertTrue(empty($res['response']), 'MUST NOT return a response.');

        // Update failure: no accecess.
        $res = $domain->updatePage($pageName, null, 'Hello, world.', $this->getReaderUser());
        $this->assertEquals(403, $res['error']['code'] ?? null, 'MUST disallow the update.');
        $this->assertTrue(empty($res['response']), 'MUST NOT return a response.');

        // Update success.
        $res = $domain->updatePage($pageName, null, $source = "# Foobar\n\nUpdate works.\n", $this->getWriterUser());
        $this->assertTrue(empty($res['error']), 'MUST NOT fail.');
        $this->assertTrue(empty($res['response']), 'MUST NOT display a response.');
        $this->assertFalse(empty($res['redirect']), 'MUST redirect.');
        $this->assertEquals('/wiki?name=' . $pageName, $res['redirect'], 'MUST redirect to the edited page.');
        $node = $this->container->wiki->getPageByName($pageName);
        $this->assertEquals($source, $node['source'], 'Page source not really updated.');

        // Update successful for a section.
        $res = $domain->updatePage($pageName, 'Some section', $source = "# Some section\n\nHello.\n", $this->getWriterUser());
        $this->assertEquals("/wiki?name={$pageName}#Some_section", $res['redirect'] ?? null, 'MUST redirect to the section.');
    }

    /**
     * Make sure single section editing works properly.
     **/
    public function testWikiSectionEditing(): void
    {
        $pageName = __FUNCTION__;
        $pageSource = file_get_contents(__DIR__ . '/sample-page-02.md');

        $domain = $this->getDomain();

        $node = $this->savePage($pageName, $pageSource);

        // Access checked already in testWikiEditor().

        // (1) Display the editor.
        $res = $domain->getPageEditorData($pageName, 'Section', $this->getWriterUser());
        $this->assertTrue(empty($res['error']), 'Page MUST be editable.');
        $this->assertEquals($node['name'], $res['response']['page_name'], 'Wrong page_name in form data.');
        $this->assertEquals("## Section\n\nThis is a page section.\n", $res['response']['page_source'], 'Wrong page_source in form data.');

        // (2) Update the section.
    }

    public function testWikiIndex(): void
    {
        $pageName = __FUNCTION__;

        $domain = $this->getDomain();

        // (1) Delete all pages.
        $this->container->db->query("DELETE FROM nodes WHERE type = 'wiki'");

        // (2) Create some pages.
        $this->savePage('page-01', 'Hello.');
        $this->savePage('page-02', 'Good bye.');

        // (3) Test unauthorized.
        $rd = $domain->index(null, null);
        $this->assertEquals(401, $rd['error']['code'] ?? null, 'MUST fail with 401.');

        // (4) Test forbidden.
        $rd = $domain->index(null, $this->getNobody());
        $this->assertEquals(403, $rd['error']['code'] ?? null, 'MUST fail with 403.');

        // (5) OK.
        $rd = $domain->index(null, $this->getReaderUser());
        $this->assertTrue(empty($rd['error']), 'MUST NOT fail.');
        $this->assertEquals(2, count($rd['response']['pages']), 'MUST list 2 pages.');
        $this->assertEquals('page-01', $rd['response']['pages'][0]['name'], 'MUST have page-01');
        $this->assertEquals('page-02', $rd['response']['pages'][1]['name'], 'MUST have page-02');
    }

    public function testRecentFiles(): void
    {
        $pageName = __FUNCTION__;
        $domain = $this->getDomain();

        // (1) Delete all pages.
        $this->container->db->query("DELETE FROM nodes WHERE type IN ('wiki', 'file')");

        // (2) Create some files.
        $this->saveNode([
            'type' => 'file',
            'published' => 1,
            'deleted' => 0,
            'name' => 'foo.jpg',
        ]);
        $this->saveNode([
            'type' => 'file',
            'published' => 1,
            'deleted' => 0,
            'name' => 'bar.png',
        ]);

        // (3) Test unauthorized.
        $rd = $domain->recentFiles($user = null);
        $this->assertError(401, $rd);

        // (4) Test forbidden.
        $rd = $domain->recentFiles($user = $this->getNobody());
        $this->assertError(403, $rd);

        // (5) OK.
        $rd = $domain->recentFiles($user = $this->getReaderUser());
        $this->assertResponse($rd);
        $this->assertFalse(empty($rd['response']['files']), 'MUST contain response data.');
        $this->assertEquals(2, count($rd['response']['files']), 'MUST list 2 files.');
        $this->assertEquals('foo.jpg', $rd['response']['files'][0]['name'], 'File foo.jpg not listed.');
        $this->assertEquals('bar.png', $rd['response']['files'][1]['name'], 'File bar.png not listed.');
    }

    public function testReindex(): void
    {
        $pageName = __FUNCTION__;
        $db = $this->container->db;
        $domain = $this->getDomain();

        // (1) Delete related ata.
        $db->query("DELETE FROM nodes WHERE type IN ('wiki', 'file')");
        $db->query("DELETE FROM taskq");

        // (2) Create some pages.
        $this->saveNode([
            'type' => 'wiki',
            'published' => 1,
            'deleted' => 0,
            'name' => 'Foo',
        ]);
        $this->saveNode([
            'type' => 'wiki',
            'published' => 1,
            'deleted' => 0,
            'name' => 'Bar',
        ]);

        // (3) Test unauthorized.
        $rd = $domain->reindex($user = null);
        $this->assertError(401, $rd);

        // (4) Test forbidden.
        $rd = $domain->reindex($user = $this->getReaderUser());
        $this->assertError(403, $rd);

        // (5) OK.
        $rd = $domain->reindex($user = $this->getWriterUser());
        $this->assertRedirect($rd, '/admin/taskq');
        $this->assertEquals(2, $db->fetchcell('SELECT COUNT(1) FROM taskq'), 'MUST have 2 taskq entries.');
    }

    public function testUpload(): void
    {
        $pageName = __FUNCTION__;
        $db = $this->container->db;
        $domain = $this->getDomain();

        // (1) Unautorized.
        $rd = $domain->upload(null, null, null);
        $this->assertError(401, $rd);

        // (2) Forbidden.
        $rd = $domain->upload(null, null, $this->getReaderUser());
        $this->assertError(403, $rd);

        // (3) OK, but empty.
        $rd = $domain->upload(null, null, $this->getWriterUser());
        $this->assertResponse($rd);
    }

    protected function getDomain(): WikiDomain
    {
        $domain = $this->getClassInstance(self::DOMAIN_CLASS);
        return $domain;
    }

    /**
     * Create or update a page.
     **/
    protected function savePage(string $name, string $source): Node
    {
        $node = $this->container->wiki->updatePage($name, $source, $this->getWriterUser());

        $this->assertFalse(empty($node['id']), 'Could not save a wiki page.');

        return $node;
    }

    protected function getReaderUser(): Node
    {
        return new Node([
            'type' => 'user',
            'role' => 'reader',
        ]);
    }

    protected function getWriterUser(): Node
    {
        return new Node([
            'type' => 'user',
            'role' => 'writer',
        ]);
    }
}
