<?php

/**
 * Wiki access code.
 *
 * Moved away from the andler to allow CLI usage.
 **/

declare(strict_types=1);

namespace Ufw1\Wiki;

use Psr\Log\LoggerInterface;
use Ufw1\Util;
use Ufw1\Services\NodeRepository;

class WikiService
{
    /**
     * @var NodeRepository
     **/
    protected $node;

    /**
     * @var LoggerInterface
     **/
    protected $logger;

    /**
     * @var array
     **/
    protected $settings;

    public function __construct(array $settings, NodeRepository $node, LoggerInterface $logger)
    {
        $this->settings = $settings;
        $this->node = $node;
        $this->logger = $logger;
    }

    public function updatePage(string $name, string $source, array $user, string $section = null): array
    {
        if (!$this->canEditPages($user)) {
            throw new Errors\Forbidden();
        }

        if (!($node = $this->getPageByName($name))) {
            $node = [
                'type' => 'wiki',
            ];
        }

        if ($section) {
            $parts = $this->findSection($node['source'] ?? '', $section);
            $parts['wanted'] = $source;

            $source = $parts['before'] . trim($parts['wanted']) . PHP_EOL . PHP_EOL . $parts['after'];
            $source = trim($source);
        }

        $source = $this->fixSectionSpaces($source);

        $node['name'] = $name;
        $node['source'] = $source;

        if ($props = $this->extractNodeProperties($source)) {
            $node = array_merge($node, $props);
        }

        $node['deleted'] = trim($source) === '';
        $node['key'] = $this->getPageKey($node['name']);

        $node = $this->notifyEdits($node, $user);

        $node = $this->node->save($node);

        if (isset($this->fts)) {
            $this->fts->reindexNode([
                'id' => $node['id'],
            ]);
        }

        return $node;
    }

    /**
     * Returns TRUE if the user can read wiki pages.
     *
     * This is controlled by the wiki.reader_roles setting.
     * If it's empty, then the wiki is anonymous.
     * If it's an array, then it's a list of roles which can read pages.
     *
     * @param array $user User node.
     * @return bool True, if the user can read pages.
     **/
    public function canReadPages(array $user = null): bool
    {
        $roles = $this->settings['reader_roles'] ?? null;
        if (empty($roles)) {
            $this->logger->warning('wiki: reader_roles array not set.');
            return false;
        }

        $role = $user['role'] ?? 'nobody';
        if (is_array($roles) and in_array($role, $roles)) {
            return true;
        }

        return false;
    }

    public function canEditPages(array $user = null): bool
    {
        $roles = $this->settings['editor_roles'] ?? null;
        if (empty($roles)) {
            $this->logger->warning('wiki: editor_roles array not set.');
            return false;
        }

        $role = $user['role'] ?? 'nobody';
        if (is_array($roles) and in_array($role, $roles)) {
            return true;
        }

        return false;
    }

    /**
     * Returns page node, if any.
     *
     * @param string $name Page name.
     * @return array Page node or null.
     **/
    public function getPageByName(string $name): ?array
    {
        $name = explode('#', $name)[0];

        $key = $this->getPageKey($name);
        $node = $this->node->where('`type` = \'wiki\' AND `key` = ? ORDER BY `id` LIMIT 1', [$key]);

        if (empty($node)) {
            return null;
        } else {
            $node = $node[0];
        }

        if ($node['type'] != 'wiki') {
            return null;
        }

        if ((int)$node['deleted'] === 1) {
            return null;
        }

        if (empty($node['source'])) {
            return null;
        }

        return $node;
    }

    /**
     * Returns source code of the page, if any.
     *
     * FIXME: configure otherwise.
     *
     * @param string $name    Page name.
     * @param string $section Section name.
     *
     * @return string Source code.
     **/
    public function getPageSource(string $name, string $section = null): string
    {
        $page = $this->getPageByName($name);
        if (empty($page) or empty($page['source'])) {
            $text = "# {$name}\n\n";
            $text .= "**{$name}** -- это ...\n\n";
            $text .= "## Источники информации\n\n";
            $text .= "- [[w:{$name}|{$name}]] в Википедии";
            return $text;
        }

        $source = $page['source'];

        if ($section) {
            $parts = $this->findSection($source, $section);
            $source = $parts['wanted'] ?? '';
        } else {
            // TODO: apply page templates
        }

        return trim($source) . "\n";
    }

    public function getSearchMeta(array $node): ?array
    {
        if ((int)$node['deleted'] === 1) {
            return null;
        }

        if ((int)$node['published'] === 0) {
            return null;
        }

        if (empty($node['source'])) {
            return null;
        }

        if (!empty($page['redirect'])) {
            return null;
        }

        $page = $this->render($node['source']);

        return [
            'title' => $page['title'] ?? $node['name'] ?? null,
            'snippet' => $page['snippet'] ?? null,
            'link' => $this->getWikiLink($node['name']),
            'text' => $this->htmlToText($page['html']),
        ];
    }

    /**
     * Render wiki markup.
     *
     * Also extracts yaml properties.
     *
     * @param string $source Wiki source text.
     *
     * @return array Properties, including html.
     **/
    public function render(string $source): array
    {
        $res = [
            "source" => $source,
        ];

        $source = "";

        $lines = explode("\n", str_replace("\r", "", $res['source']));
        foreach ($lines as $idx => $line) {
            if ($line == "---") {
                $lines = array_slice($lines, $idx + 1);
                $source = implode("\n", $lines);
                break;
            }

            if (preg_match('@^([a-z0-9-_]+):\s+(.+)$@', $line, $m)) {
                $k = $m[1];
                $v = $m[2];

                if ($k == 'published' or $k == 'deleted') {
                    $v = (bool)(int)$v;
                }

                $res[$k] = $v;
            } else {
                // wrong format
                $source = $res['source'];
            }
        }

        $source = $this->processPhotoAlbums($source);
        $source = $this->processMaps($source);
        $source = $this->processWikiLinks($source);
        $source = $this->processImages($source);
        $source = $this->processYouTube($source);

        $html = Util::renderMarkdown($source);
        $html = Util::renderTOC($html);
        $html = $this->processHeader($html, $res);
        $html = $this->processSummary($html, $res);
        $html = $this->processImages($html);

        $html = Util::cleanHtml($html);
        $res["html"] = $html;

        $res['snippet'] = $this->getSnippet($html);

        return $res;
    }

    /**
     * Renders the HTML code of the page.
     *
     * Only the wiki page itself, not the actual HTML page with template stuff.
     *
     * TODO: rename to processPage(), return node with attributes added.
     *
     * @param array $node Wiki page node.
     *
     * @return array Page properties and HTML code.
     **/
    public function renderPage(array $node): array
    {
        if ($node['type'] != 'wiki') {
            throw new \RuntimeException('not a wiki page');
        }

        $res = [
            "name" => $node["name"],
            "title" => $node["name"],
            "image" => null,
            "images" => [],
            "summary" => null,
            "language" => "ru",
            "source" => $node["source"],
            "created" => $node['created'] ?? null,
        ];

        $source = "";

        $lines = explode("\n", str_replace("\r", "", $node["source"]));
        foreach ($lines as $idx => $line) {
            if ($line == "---") {
                $lines = array_slice($lines, $idx + 1);
                $source = implode("\n", $lines);
                break;
            }

            if (preg_match('@^([a-z0-9-_]+):\s+(.+)$@', $line, $m)) {
                $res[$m[1]] = $m[2];
            } else {
                // wrong format
                $source = $node["source"];
                break;
            }
        }

        $source = $this->processPhotoAlbums($source);
        $source = $this->processMaps($source);
        $source = $this->processWikiLinks($source);
        $source = $this->processImages($source);
        $source = $this->processYouTube($source);

        $html = Util::renderMarkdown($source);
        $html = Util::renderTOC($html);
        $html = $this->processHeader($html, $res);
        $html = $this->processSummary($html, $res);
        $html = $this->processImages($html);

        $html = Util::cleanHtml($html);
        $res["html"] = $html;

        $res['snippet'] = $this->getSnippet($html);

        return $res;
    }

    public function getPageKey(string $name): string
    {
        return md5(mb_strtolower(trim($name)));
    }

    /**
     * Convert wiki page name to a link.
     *
     * Example:
     * >> Hello World#foobar
     * << /wiki?name=Hello+World#foobar
     *
     * @param  string $link Page name, with section optionally.
     * @return string       Link to the page.
     **/
    public function getWikiLink(string $link): string
    {
        if (true) {
            $parts = explode('#', $link);
            $parts[0] = urlencode($parts[0]);
            $link = implode('#', $parts);
            $link = '/wiki?name=' . $link;
        } else {
            $parts = explode('#', $link);
            $parts[0] = str_replace(' ', '_', $parts[0]);
            $link = implode('#', $parts);
            $link = '/wiki/' . $link;
        }

        return $link;
    }

    protected function parseMapItems(string $source): array
    {
        $items = [];
        $last = [];

        $lines = explode("\n", $source);
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) == 2) {
                if ($parts[0][0] == '-') {
                    if (!empty($last['ll'])) {
                        $items[] = $last;
                    }
                    $last = [];
                    $parts[0] = substr($parts[0], 1);
                }

                $k = trim($parts[0]);
                $v = trim($parts[1]);

                if ($k == 'll') {
                    if (2 == count($parts = explode(',', $v, 2))) {
                        $v = [floatval($parts[0]), floatval($parts[1])];
                    } else {
                        continue;
                    }
                }

                $last[$k] = $v;
            }
        }

        if (!empty($last['ll'])) {
            $items[] = $last;
        }

        $items = array_map(function (array $em) {
            if (!empty($em['link'])) {
                $html = sprintf('<p><a href="%s">%s</a></p>', $em['link'], $em['title']);
            } else {
                $html = '<p>' . $em['title'] . '</p>';
            }

            if (!empty($em['description'])) {
                $html .= '<p>' . $em['description'] . '</p>';
            }

            if (!empty($em['image'])) {
                $html .= "<div><img src=\"{$em['image']}\" alt=\"\"/></div>";
            }

            $em['html'] = $html;
            return $em;
        }, $items);

        return $items;
    }

    /**
     * Рендеринг карт.
     **/
    protected function processMaps(string $source): string
    {
        $idx = 0;

        $source = preg_replace_callback('@```map(.+?)```@ms', function (array $m) use (&$idx) {
            $idx++;
            $items = $this->parseMapItems($m[1]);
            $json = json_encode($items);
            return sprintf("<div id='map_%u' class='map' data-items='%s'><p>Карта загружается...</p></div>", $idx, $json);
        }, $source);

        return $source;
    }

    protected function processPhotoAlbums(string $source): string
    {
        $out = [];
        $album = [];

        $lines = explode("\n", $source);
        foreach ($lines as $line) {
            if (preg_match('@^\s*\[\[image:[^]]+\]\]\s*$@', $line, $m)) {
                $album[] = trim($line);
            } else {
                if ($album) {
                    if (count($album) == 1) {
                        $out[] = $album[0];
                    } else {
                        $code = "<div class='photoalbum'>";
                        $code .= implode("", $album);
                        $code .= "</div>";
                        $out[] = $code;
                    }
                    $album = [];
                }
                $out[] = $line;
            }
        }

        if (count($album) == 1) {
            $out[] = $album[0];
        } elseif (count($album) > 1) {
            $code = "<div class='photoalbum'>";
            $code .= implode("", $album);
            $code .= "</div>";
            $out[] = $code;
        }

        $source = implode(PHP_EOL, $out);
        return $source;
    }

    /**
     * Replace wiki links with the actual HTML code.
     *
     * @param string $source Source code.
     * @return string Updated source code.
     **/
    protected function processWikiLinks(string $source): string
    {
        $interwiki = $this->settings['interwiki'] ?? [];

        $source = preg_replace_callback('@\[\[([^]]+)\]\]@', function ($m) use ($interwiki) {
            // Embed images later.
            if (0 === strpos($m[1], "image:")) {
                return $m[0];
            }

            // Embed maps later.
            if (0 === strpos($m[1], "map:")) {
                return $m[0];
            }

            $link = $m[1];
            $label = $m[1];

            if (count($parts = explode("|", $m[1], 2)) == 2) {
                $link = $parts[0];
                $label = $parts[1];
            }

            $cls = "wiki good";
            $title = $link;

            if ($this->processInterwiki($link, $label, $interwiki)) {
                $cls = 'external';
            } elseif ($tmp = $this->getPageByName($link)) {
                $title = $tmp['title'] ?? $tmp['name'];
                if (false and !empty($tmp['url'])) {
                    $link = $tmp['url'];
                } else {
                    $link = $this->getWikiLink($link);
                }
            } else {
                $cls = "wiki broken";
                $title = "Нет такой страницы";
                if ($cls != 'external') {
                    $link = $this->getWikiLink($link);
                }
            }

            $html = sprintf("<a href='%s' class='%s' title='%s'>%s</a>", $link, $cls, htmlspecialchars($title), htmlspecialchars($label));

            return $html;
        }, $source);

        return $source;
    }

    /**
     * Process the interwiki link.
     *
     * If the link matches a configured pattern -- apply that pattern.
     *
     * @param  string& $link      Linked page.
     * @param  string& $label     Link text, can be modified.
     * @param  array   $interwiki Interwiki patterns.
     * @return bool               True, if the link was processed.
     **/
    protected function processInterwiki(string &$link, string &$label, array $interwiki): bool
    {
        foreach ($interwiki as $re => $format) {
            if (preg_match($re, $link, $m)) {
                if ($link == $label) {
                    $label = $m[1];
                }

                $link = sprintf($format, $m[1]);

                return true;
            }
        }

        return false;
    }

    protected function processImages(string $html): string
    {
        $nodes = $this->node;

        $html = preg_replace_callback('@\[\[image:([^]]+)\]\]@', function ($m) use ($nodes, &$res) {
            $parts = explode(":", $m[1]);
            $fileId = (int)array_shift($parts);

            $info = $this->getFileInfo($fileId);

            $className = 'image';
            $iw = "auto";
            $ih = "auto";

            $w = $info['width'];
            $h = $info['height'];

            $rate = $w / $h;

            foreach ($parts as $part) {
                if (preg_match('@^width=(\d+)$@', $part, $m)) {
                    $iw = $m[1] . "px";
                    $ih = round($m[1] / $rate) . "px";
                } elseif (preg_match('@^height=(\d+)$@', $part, $m)) {
                    $ih = $m[1] . "px";
                    $iw = round($m[1] * $rate) . "px";
                } else {
                    $className .= " " . $part;
                }
            }

            if ($className == 'image large') {
                $info['small'] = $info['large'];
                $info['small_webp'] = $info['large_webp'] ?? null;
                $info['link'] = null;
            } elseif ($iw == "auto" and $ih == "auto") {
                $ih = "150px";
                $iw = round(150 * $rate) . "px";
            }

            $res["images"][] = [
                "src" => $info['large'],
                "width" => $info['width'],
                "height" => $info['height'],
            ];

            $title = $info['title'] ?? $info['name'];

            $info['class'] = $className;
            $html = $this->renderFigure($info);

            $html .= "<script type='application/ld+json'>" . json_encode([
                "@context" => "http://schema.org",
                "@type" => "ImageObject",
                "contentUrl" => $info['large'],
                "name" => $title,
                "thumbnail" => $info['small'],
            ]) . "</script>";

            return $html;
        }, $html);

        return $html;
    }

    protected function getFileInfo(int $id): array
    {
        $res = [
            'small' => '/images/placeholder.png',
            'large' => '/images/placeholder.png',
            'link' => $this->getWikiLink("File:{$id}"),
            'width' => 600,
            'height' => 600,
            'name' => 'placeholder',
            'title' => 'Image not found',
            'caption' => null,
        ];

        $file = $this->node->get($id);

        if (!empty($file)) {
            $res['title'] = $file['title'] ?? $file['name'];
            $res['caption'] = $file['caption'] ?? null;
        }

        foreach ($file['files'] as $k => $f) {
            $url = $f['storage'] == 'local'
                 ? "/node/{$id}/download/{$k}"
                 : $f['url'];

            switch ($k) {
                case 'large':
                    $res['width'] = $f['width'];
                    $res['height'] = $f['height'];
                    // continue...
                case 'small':
                case 'small_webp':
                case 'large_webp':
                    $res[$k] = $url;
                    break;
            }
        }

        if (empty($res['height'])) {
            $res['small'] = '/images/placeholder.png';
            $res['large'] = '/images/placeholder.png';
            $res['width'] = 600;
            $res['height'] = 600;
        }

        return $res;
    }

    /**
     * Embed YouTube links.
     **/
    protected function processYouTube(string $html): string
    {
        $lines = explode("\n", $html);

        $lines = array_map(function ($line) {
            if (preg_match('@^https://youtu\.be/([^ ]+)$@', $line, $m)) {
                $line = "<iframe width='560' height='315' src='https://www.youtube.com/embed/{$m[1]}' frameborder='0' allow='accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture' allowfullscreen></iframe>";
            }

            return $line;
        }, $lines);

        return implode("\n", $lines);
    }

    protected function processHeader(string $html, array &$res): string
    {
        $html = preg_replace_callback('@<h1>(.+)</h1>@', function ($m) use (&$res) {
            $res["title"] = $m[1];
            return "";
        }, $html);

        return $html;
    }

    protected function processSummary(string $html, array &$res): string
    {
        if (empty($res["summary"])) {
            if (preg_match('@<p>(.+?)</p>@', $html, $m)) {
                $res["summary"] = strip_tags($m[1]);
            }
        }

        return $html;
    }

    /**
     * Find specific section in page source.
     *
     * @param string $text Page source.
     * @param string $sectionName The name of desired section.
     * @return array Keys: before, wanted, after.
     **/
    protected function findSection(string $text, string $sectionName): array
    {
        // Simplify line endings.
        $text = str_replace("\r\n", "\n", $text);

        $before = null;
        $wanted = null;
        $after = null;

        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            if ($after !== null) {
                $after .= $line . PHP_EOL;
                continue;
            }

            $found = preg_match('@^#+\s*(.+)$@', $line, $m);

            if ($wanted !== null) {
                if ($found) {
                    $after .= $line . PHP_EOL;
                    continue;
                } else {
                    $wanted .= $line . PHP_EOL;
                }
            } else {
                if ($found and trim($m[1]) == $sectionName) {
                    $wanted .= $line . PHP_EOL;
                    continue;
                } else {
                    $before .= $line . PHP_EOL;
                }
            }
        }

        $res = [
            "before" => $before,
            "wanted" => $wanted,
            "after" => $after,
        ];

        return $res;
    }

    /**
     * Extract YAML properties from a wiki page source.
     *
     * @param string $source Page source code.
     * @return array Found properties.
     **/
    protected function extractNodeProperties(string $source): array
    {
        $props = [];

        if (preg_match('@^# (.+)$@m', $source, $m)) {
            $props['title'] = trim($m[1]);
        }

        $lines = $source ? explode("\n", $source) : [];
        foreach ($lines as $line) {
            $line = trim($line);

            if (0 === strpos($line, '---')) {
                return $props;
            }

            if (preg_match('@^([a-z0-9_-]+):\s+(.+)$@i', $line, $m)) {
                $props[$m[1]] = $m[2];
            }
        }

        return [];
    }

    /**
     * Send email notifications about edits overrule.
     *
     * @param array $node Edited page.
     * @param array $user Current editor.
     * @return array $node Modified node (saves editor info).
     **/
    protected function notifyEdits(array $node, array $user): array
    {
        if (!empty($node['last_editor']) and $node['last_editor'] != $user['id']) {
            $this->taskq->add('notify-wiki-edit', [
                'page' => $node['id'],
                'last' => $node['last_editor'],
                'current' => $user['id'],
            ]);
        }

        $node['last_editor'] = $user['id'] ?? null;
        return $node;
    }

    protected function getImageSize(int $fileId): array
    {
        $files = $this->file;
        $logger = $this->logger;

        $file = $files->get($fileId);

        if (!empty($file['files'])) {
            // We just need the proportions, so get the first one we have.
            foreach ($file['files'] as $k => $v) {
                if (isset($v['width']) and isset($v['height'])) {
                    return [$v['width'], $v['height']];
                }
            }

            if ($file['files']['original']['storage'] == 'local') {
                $fpath = $files->fsgetpath($file['files']['original']['path']);

                if (file_exists($fpath)) {
                    $body = file_get_contents($fpath);

                    $img = imagecreatefromstring($body);
                    $w = imagesx($img);
                    $h = imagesy($img);

                    return [$w, $h];
                }
            }

            $logger->warning("file {id} not found in the file system, path: {path}", [
                "id" => $fileId,
                "path" => $fpath,
            ]);

            return [null, null];
        }

        throw new \RuntimeException("file not found");
    }

    protected function getSnippet(string $html): ?string
    {
        // strip_tags mishandles scripts, and we use them heavily for microdata,
        // so just strip them off in advance.
        $html = preg_replace('@<script.*?</script>@', '', $html);

        if (preg_match_all('@<p>(.+?)</p>@ms', $html, $m)) {
            foreach ($m[0] as $_html) {
                if ($text = strip_tags($_html)) {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * Fix section spaces.
     *
     * Makes sure that sections are separated by double blank lines.
     *
     * @param  string $source Page source.
     *
     * @return string Updated source.
     **/
    protected function fixSectionSpaces(string $source): string
    {
        $dst = [];

        $src = explode("\n", $source);
        foreach ($src as $line) {
            $line = rtrim($line);

            if (preg_match('@^#{2,}\s+@', $line)) {
                while (!empty($dst)) {
                    if (($tmp = array_pop($dst)) != "") {
                        $dst[] = $tmp;
                        $dst[] = "";
                        $dst[] = "";
                        break;
                    }
                }

                $dst[] = $line;
            } else {
                $dst[] = $line;
            }
        }

        $source = implode("\n", $dst);

        return $source;
    }

    protected function htmlToText(string $html): string
    {
        // strip_tags mishandles scripts, and we use them heavily for microdata,
        // so just strip them off in advance.
        $html = preg_replace('@<script.*?</script>@', '', $html);

        $html = str_replace("><", "> <", $html);
        $text = trim(strip_tags($html));

        return $text;
    }

    protected function renderFigure(array $info): string
    {
        // TODO: add lazy loading.
        // Have a 8x8 version of image in the node,
        // embed it here with base64.

        $ratio = number_format($info['height'] / $info['width'] * 100, 2);

        $html = "<picture data-ratio='{$ratio}'>";
        if (!empty($info['small_webp'])) {
            $html .= "<source type='image/webp' srcset='{$info['small_webp']}'/>";
        }
        $html .= "<source type='image/jpeg' srcset='{$info['small']}'/>";
        $html .= "<img src='{$info['small']}' alt='{$info['title']}'/>";
        $html .= "</picture>";

        if (!empty($info['link'])) {
            $html = "<a href='{$info['link']}' data-src='{$info['large']}' data-fancybox='gallery' title='{$info['title']}'>{$html}</a>";
        }

        if (!empty($info['caption'])) {
            $html .= "<figcaption>{$info['caption']}</figcaption>";
        }

        $html = "<figure class='{$info['class']}'>{$html}</figure>";

        return $html;
    }
}
