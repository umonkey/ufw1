<?php

class WikiTests extends \Ufw1\Tests\Base
{
    public function testWikiPropertiesParser()
    {
        $source = "url: /foo\n"
                . "splash_jpg: /images/splash.jpg\n"
                . "splash_webp: /images/splash.webp\n"
                . "---\n"
                . "# Some Title\n"
                . "\n"
                . "Hello, world.";

        $node = [
            'type' => 'wiki',
            'name' => 'sample page',
            'source' => $source,
        ];

        $wiki = $this->container->get('wiki');

        $page = $wiki->renderPage($node);

        $this->assertEquals('Some Title', $page['title'] ?? null);
        $this->assertEquals('Hello, world.', $page['snippet'] ?? null, 'wiki page snippet not ready');
        $this->assertEquals('/foo', $page['url'] ?? null, 'wiki page properties not parsed');
        $this->assertEquals('/images/splash.jpg', $page['splash_jpg'] ?? null, 'wiki page properties not parsed');
        $this->assertEquals('/images/splash.webp', $page['splash_webp'] ?? null, 'wiki page properties not parsed');
    }
}
