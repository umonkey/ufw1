<?php
/**
 * Wiki access code.
 *
 * Moved away from the andler to allow CLI usage.
 **/

namespace Ufw1;

class Wiki
{
    protected $container;

    public function __construct($c)
    {
        $this->container = $c;
    }

    public function updatePage($name, $source, array $user, $section = null)
    {
        if (!$this->canEditPages($user))
            throw new Errors\Forbidden;

        if (!($node = $this->getPageByName($name))) {
            $node = [
                'type' => 'wiki',
            ];
        }

        if ($section) {
            $parts = $this->findSection($node['source'] ?? '', $section);
            $parts['wanted'] = $source;

            $source = $parts['before'] . PHP_EOL . PHP_EOL . trim($parts['wanted']) . PHP_EOL . PHP_EOL . $parts['after'];
            $source = trim($source);
        }

        $node['name'] = $name;
        $node['key'] = $this->getPageKey($name);
        $node['source'] = $source;
        $node['deleted'] = 0;
        $node['published'] = 1;

        if ($props = $this->extractNodeProperties($source))
            $node = array_merge($node, $props);

        $node = $this->notifyEdits($node, $user);

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
    public function canReadPages(array $user = null)
    {
        $roles = $this->container->get('settings')['wiki']['reader_roles'] ?? [];
        if (empty($roles))
            return true;

        $role = $user['role'] ?? 'nobody';
        if (is_array($roles) and in_array($role, $roles))
            return true;

        return false;
    }

    public function canEditPages(array $user = null)
    {
        $roles = $this->container->get('settings')['wiki']['editor_roles'];
        if (empty($roles))
            return true;

        $role = $user['role'] ?? 'nobody';
        if (is_array($roles) and in_array($role, $roles))
            return true;

        return false;
    }

    /**
     * Returns page node, if any.
     *
     * @param string $name Page name.
     * @return array Page node or null.
     **/
    public function getPageByName($name)
    {
        $key = $this->getPageKey($name);
        $node = $this->container->get('node')->where('`type` = \'wiki\' AND `key` = ? ORDER BY `id` LIMIT 1', [$key]);
        return $node ? $node[0] : null;
    }

    /**
     * Returns source code of the page, if any.
     *
     * @param string $name Page name.
     * @param string $section Section name.
     * @return string Source code.
     **/
    public function getPageSource($name, $section = null)
    {
        $page = $this->getPageByName($name);
        if (empty($page))
            return null;

        $source = $page['source'];

        if ($section) {
            $parts = $this->findSection($source, $section);
            $source = $parts['wanted'] ?? null;
        } else {
            // TODO: apply page templates
        }

        return $source;
    }

    /**
     * Renders the HTML code of the page.
     *
     * Only the wiki page itself, not the actual HTML page with template stuff.
     *
     * @param array $node Wiki page node.
     * @return array Page properties and HTML code.
     **/
    public function renderPage(array $node)
    {
        if ($node['type'] != 'wiki')
            throw new \RuntimeException('not a wiki page');

        $res = [
            "name" => $node["name"],
            "title" => $node["name"],
            "image" => null,
            "images" => [],
            "summary" => null,
            "language" => "ru",
            "source" => $node["source"],
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
            }

            else {
                // wrong format
                $source = $node["source"];
                break;
            }
        }

        $source = $this->processPhotoAlbums($source);
        $source = $this->processWikiLinks($source);
        $source = $this->processImages($source);

        $html = \Ufw1\Common::renderMarkdown($source);
        $html = \Ufw1\Common::renderTOC($html);
        $html = $this->processHeader($html, $res);
        $html = $this->processSummary($html, $res);
        $html = $this->processImages($html);

        $html = \Ufw1\Util::cleanHtml($html);
        $res["html"] = $html;

        return $res;
    }

    protected function getPageKey($name)
    {
        return md5(mb_strtolower(trim($name)));
    }

    protected function processPhotoAlbums($source)
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

        if (count($album) == 1)
            $out[] = $album[0];
        elseif (count($album) > 1) {
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
    protected function processWikiLinks($source)
    {
        $source = preg_replace_callback('@\[\[([^]]+)\]\]@', function ($m) {
            // Embed images later.
            if (0 === strpos($m[1], "image:"))
                return $m[0];

            // Embed maps later.
            if (0 === strpos($m[1], "map:")) {
                return $m[0];

                /*
                $parts = explode(":", $m[1]);

                $id = mt_rand(1111, 9999);
                $tag = $parts[1];

                $html = "<div id='map_{$id}' class='map' data-src='/map/points.json?tag=" . $tag . "'></div>";
                return $html;
                */
            }

            $link = $m[1];
            $label = $m[1];

            if (count($parts = explode("|", $m[1], 2)) == 2) {
                $link = $parts[0];
                $label = $parts[1];
            }

            $cls = "good";
            $title = $link;

            if (!($tmp = $this->getPageByName($link))) {
                $cls = "broken";
                $title = "Нет такой страницы";
            }

            $html = sprintf("<a href='/wiki?name=%s' class='wiki %s' title='%s'>%s</a>", urlencode($link), $cls, htmlspecialchars($title), htmlspecialchars($label));

            return $html;
        }, $source);

        return $source;
    }

    protected function processImages($html)
    {
        $nodes = $this->container->get('node');

        $html = preg_replace_callback('@\[\[image:([^]]+)\]\]@', function ($m) use ($nodes, &$res) {
            $parts = explode(":", $m[1]);
            $fileId = array_shift($parts);

            $info = $this->container->get('file')->get($fileId);

            if (empty($info) or $info["type"] != "file")
                return "<!-- file {$fileId} does not exist -->";
            elseif (0 !== strpos($info["mime_type"], "image/"))
                return "<!-- file {$fileId} is not an image -->";

            $className = "image";
            $iw = "auto";
            $ih = "auto";

            list($w, $h) = $this->getImageSize($fileId);

            if (!$w or !$h)
                return "<!-- file {$fileId} does not exist -->";

            $rate = $w / $h;

            foreach ($parts as $part) {
                if (preg_match('@^width=(\d+)$@', $part, $m)) {
                    $iw = $m[1] . "px";
                    $ih = round($m[1] / $rate) . "px";
                }

                elseif (preg_match('@^height=(\d+)$@', $part, $m)) {
                    $ih = $m[1] . "px";
                    $iw = round($m[1] * $rate) . "px";
                }

                else {
                    $className .= " " . $part;
                }
            }

            if ($iw == "auto" and $ih == "auto") {
                $ih = "150px";
                $iw = round(150 * $rate) . "px";
            }

            if (isset($info['files']['medium']['url']))
                $small = $info['files']['medium']['url'];
            else
                $small = "/node/{$fileId}/download/small";

            if (isset($info['files']['original']['url']))
                $large = $info['files']['original']['url'];
            else
                $large = "/i/photos/{$fileId}.jpg";

            $page = "/wiki?name=File:{$fileId}";

            $res["images"][] = [
                "src" => $large,
                "width" => $w,
                "height" => $h,
            ];

            $title = $info['title'] ?? $info['name'];

            // TODO: add lazy loading

            $html = "<a class='{$className}' href='{$page}' data-src='{$large}' data-fancybox='gallery' title='{$title}'>";
            $html .= "<img src='{$small}' style='width: {$iw}; height: {$ih}' alt='{$title}'/>";
            $html .= "</a>";

            $html .= "<script type='application/ld+json'>" . json_encode([
                "@context" => "http://schema.org",
                "@type" => "ImageObject",
                "contentUrl" => $large,
                "name" => $title,
                "thumbnail" => $small,
            ]) . "</script>";

            return $html;
        }, $html);

        return $html;
    }

    protected function processHeader($html, array &$res)
    {
        $html = preg_replace_callback('@<h1>(.+)</h1>@', function ($m) use (&$res) {
            $res["title"] = $m[1];
            return "";
        }, $html);

        return $html;
    }

    protected function processSummary($html, array &$res)
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
    protected function findSection($text, $sectionName)
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
            }

            else {
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
    protected function extractNodeProperties($source)
    {
        $props = [];

        $lines = $source ? explode("\n", $source) : [];
        foreach ($lines as $line) {
            $line = trim($line);

            if (0 === strpos($line, '---'))
                return $props;

            if (preg_match('@^([a-z0-9_-]+):\s+(.+)$@i', $line, $m))
                $props[$m[1]] = $m[2];
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
    protected function notifyEdits(array $node, array $user)
    {
        if (!empty($node['last_editor']) and $node['last_editor'] != $user['id']) {
            $this->container->get('taskq')->add('notify-wiki-edit', [
                'page' => $node['id'],
                'last' => $node['last_editor'],
                'current' => $user['id'],
            ]);
        }

        $node['last_editor'] = $user['id'];
        return $node;
    }

    protected function getImageSize($fileId)
    {
        $files = $this->container->get('file');
        $logger = $this->container->get('logger');

        $file = $files->get($fileId);

        if ($file) {
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
}
