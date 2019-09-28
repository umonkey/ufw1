<?php
/**
 * Wiki pages.
 **/

namespace Ufw1\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;


class Wiki extends CommonHandler
{
    /**
     * Display a single page.
     *
     * TODO: не кэшировать если вики закрытая.
     **/
    public function onRead(Request $request, Response $response, array $args)
    {
        $name = $request->getQueryParam("name");

        if (empty($name))
            return $response->withRedirect("/wiki?name=Welcome", 301);

        if (!$this->canReadPage($request))
            return $this->forbidden();

        $page = $this->pageGet($name);

        if ($page and $name != $page["name"]) {
            return $response->withRedirect("/wiki?name=" . urlencode($page["name"]), 301);
        }

        if (empty($page)) {
            return $this->render($request, "wiki-nopage.twig", [
                "title" => "Page not found",
                "page_name" => $name,
                "edit_link" => "/wiki/edit?name=" . urlencode($name),
            ]);
        }

        $age = round((time() - strtotime($page["updated"])) / 86400);
        $page = $this->pageProcess($page);

        if (!empty($page["redirect"])) {
            return $response->withRedirect("/wiki?name=" . urlencode($page["redirect"]));
        }

        $html = $this->renderHTML($request, "wiki-page.twig", [
            "language" => $page["language"],
            "page" => $page,
            "page_age" => $age,
            "canonical_link" => "/wiki?name=" . urlencode($name),
            "edit_link" => "/wiki/edit?name=" . urlencode($name),
            "jsdata" => json_encode([
                "wiki_page" => $name,
            ]),
        ]);

        $response = $response->withHeader("Content-Type", "text/html; charset=utf-8")
            ->withHeader("Content-Length", strlen($html));
        $response->getBody()->write($html);

        return $response;
    }

    public function onReadCached(Request $request, Response $response, array $args)
    {
        $name = $request->getQueryParam("name");

        if (empty($name))
            return $response->withRedirect("/wiki?name=Welcome", 301);

        if (!$this->canReadPage($request))
            return $this->forbidden();

        return $this->sendFromCache($request, function ($request) use ($name, $response) {
            $page = $this->pageGet($name);

            if ($page and $name != $page["name"]) {
                return $response->withRedirect("/wiki?name=" . urlencode($page["name"]), 301);
            }

            if (empty($page)) {
                return $this->render($request, "wiki-nopage.twig", [
                    "title" => "Page not found",
                    "page_name" => $name,
                    "edit_link" => "/wiki/edit?name=" . urlencode($name),
                ]);
            }

            $page = $this->pageProcess($page);

            if (!empty($page["redirect"])) {
                return $response->withRedirect("/wiki?name=" . urlencode($page["redirect"]));
            }

            $html = $this->renderHTML($request, "wiki-page.twig", [
                "language" => $page["language"],
                "page" => $page,
                "canonical_link" => "/wiki?name=" . urlencode($name),
                "edit_link" => "/wiki/edit?name=" . urlencode($name),
            ]);

            return ["text/html; charset=utf-8", $html];
        }, "wiki:" . $name);
    }

    public function onEdit(Request $request, Response $response, array $args)
    {
        $this->requireAdmin($request);

        $pageName = $request->getQueryParam("name");
        $sectionName = $request->getQueryParam("section");
        $canEdit = $this->canEditPage($request);

        if (empty($pageName))
            return $this->notfound($request);

        $page = $this->pageGet($pageName);

        if (empty($page)) {
            $contents = $this->pageGetTemplate($pageName);

            // TODO: configurable templates.  Read from wiki:templates, map name regexp to page names, e.g.
            // ^File:(.+)$ wiki:templates:file
        }

        else {
            $contents = $page["source"];
        }

        if ($sectionName) {
            $tmp = $this->findSection($contents, $sectionName);
            if (empty($tmp["wanted"]))
                $sectionName = "";
            else
                $contents = $tmp["wanted"];
        }

        $size = $this->file->getStorageSize();
        if ($size >= 1073741824)
            $size = sprintf("%.2f Гб", $size / 1073741824);
        else
            $size = sprintf("%.2f Мб", $size / 1048576);

        return $this->render($request, "wiki-edit.twig", [
            "page_name" => $pageName,
            "page_section" => $sectionName,
            "page_source" => $contents,
            "is_editable" => $canEdit,
            "body_class" => "wiki_edit",
            "storage_size" => $size,
        ]);
    }

    /**
     * Handle file uploads.
     **/
    public function onUpload(Request $request, Response $response, array $args)
    {
        if (!$this->canEditPage($request))
            return $this->forbidden();

        $comment = null;

        if ($link = $request->getParam("link")) {
            $file = \Ufw1\Common::fetch($link);
            if ($file["status"] == 200) {
                $name = basename(explode("?", $link)[0]);
                $type = $file["headers"]["content-type"];
                $body = $file["data"];

                $comment = "Файл загружен [по ссылке]({$link}).\n\n";

                $code = $this->addFile($name, $type, $body, $comment);
                if ($code) {
                    return $response->withJSON([
                        "callback" => "editor_insert",
                        "callback_args" => $code,
                    ]);
                }
            } else {
                return $response->withJSON([
                    "message" => "Не удалось загрузить файл.",
                ]);
            }
        }

        elseif ($files = $request->getUploadedFiles()) {
            $items = [];

            if (!empty($files["files"]) and is_array($files["files"]))
                $items = $files["files"];

            elseif (!empty($files["file"]))
                $items[] = $files["file"];

            $errors = 0;
            $code = [];

            foreach ($items as $item) {
                if ($item->getError()) {
                    $errors++;
                    continue;
                }

                $name = $item->getClientFilename();
                $type = $item->getClientMediaType();

                $tmpdir = $this->file->getStoragePath();
                $tmp = tempnam($tmpdir, "upload_");
                $item->moveTo($tmp);
                $body = file_get_contents($tmp);
                unlink($tmp);

                if ($tmp = $this->addFile($name, $type, $body))
                    $code[] = $tmp;
            }

            $res = [];

            if (empty($code)) {
                $res["message"] = "Не удалось загурзить ни один файл.";
            } else {
                $res["callback"] = "editor_insert";
                $res["callback_args"] = implode("\n", $code);

                if ($errors)
                    $res["message"] = "Не удалось принять некоторые файлы.";
            }

            return $response->withJSON($res);
        }

        $file = $this->file->add($name, $type, $body);
        $fid = $file["id"];

        $pname = "File:" . $fid;
        if (!($page = $this->pageGet($pname))) {
            $text = "# {$name}\n\n";
            $text .= "[[image:{$fid}]]\n\n";
            if ($comment)
                $text .= $comment;

            $this->pageSave($pname, $text);
        }
    }

    /**
     * Добавление файла в базу.
     *
     * Сохраняет файл, создаёт для него страницу, возвращает код для встраивания файла.
     *
     * @param string $name Имя файла.
     * @param string $type Тип файла.
     * @param string $body Содержимое файла.
     * @return string Код для встраивания, например: [[image:123]]
     **/
    protected function addFile($name, $type, $body, $comment = null)
    {
        $file = $this->file->add($name, $type, $body);
        $fid = $file["id"];

        $pname = "File:" . $fid;
        if (!($page = $this->pageGet($pname))) {
            $text = "# {$name}\n\n";
            $text .= "[[image:{$fid}]]\n\n";
            if ($comment)
                $text .= $comment;

            $this->pageSave($pname, $text);
        }

        return "[[image:{$fid}]]";
    }

    /**
     * Update page contents.
     **/
    public function onSave(Request $request, Response $response, array $args)
    {
        if (!$this->canEditPage($request)) {
            return $response->withJSON([
                "message" => "Страница не может быть изменена.",
            ]);
        }

        $name = $request->getParam("page_name");
        $text = $request->getParam("page_source");
        $section = $request->getParam("page_section");

        if ($section) {
            if ($page = $this->pageGet($name)) {
                $parts = $this->findSection($page["source"], $section);
                $parts["wanted"] = rtrim($text) . PHP_EOL . PHP_EOL;

                $text = implode("", $parts);
            }
        }

        $this->pageSave($name, $text);

        // TODO: flush related cache.

        return $response->withJSON([
            "redirect" => "/wiki?name=" . urlencode($name),
        ]);
    }

    public function onBacklinks(Request $request, Response $response, array $args)
    {
        if (!($name = $request->getParam("name")))
            return $this->notfound($request);

        if (!($page = $this->pageGet($name)))
            return $this->notfound($request);

        $names = $this->node->where("`type` = 'wiki' AND `id` IN (SELECT `tid` FROM `nodes_rel` WHERE `nid` = ?)", [$page["id"]], function ($node) {
            return $node["name"];
        });

        return $this->render($request, "wiki-backlinks.twig", [
            "name" => $name,
            "pages" => $names,
            "edit_link" => "/wiki/edit?name=" . urlencode($name),
        ]);
    }

    public function onRecentRSS(Request $request, Response $response, array $args)
    {
        $items = $this->node->where("`type` = 'wiki' AND `published` = 1 ORDER BY `created` DESC LIMIT 100", [], function ($node) {
            if (preg_match('@^(File|wiki):@', $node["name"]))
                return null;

            $page = $this->pageProcess($node);

            return [
                "id" => $node["id"],
                "title" => $page["title"],
                "created" => $node["created"],
                "link" => "/wiki?name=" . urlencode($node["name"]),
                "html" => $page["html"],
            ];
        });

        $lastUpdate = $this->db->fetchCell("SELECT MAX(updated) FROM `nodes` WHERE `type` = 'wiki' AND `published` = 1");

        return $this->renderRSS($request, [
            "link" => "/wiki",
            "title" => "Свежие страницы",
            "last_update" => $lastUpdate,
        ], $items);

        return $this->renderXML($request, "wiki-pages-rss.twig", [
            "site_name" => $settings["site_name"],
            "pages" => $pages,
            "last_update" => $lastUpdate,
        ]);
    }

    /**
     * Process clipboard text.
     *
     * Detects links to images, replaces with an [[image:N]], returns replacement text.
     **/
    public function onEmbedClipboard(Request $request, Response $response, array $args)
    {
        $res = [
            "type" => null,
            "link" => null,
            "code" => null,
            "image" => null,
            "title" => null,
            "id" => null,
        ];

        // Save the title, trigger the callback.
        if ($id = $request->getParam("id")) {
            $title = $request->getParam("title");
            $link = $request->getParam("link");

            $name = "File:" . $id;
            $page = $this->db->fetchOne("SELECT * FROM `pages` WHERE `name` = ?", [$name]);

            $now = time();

            if ($page) {
                // update title
            } else {
                $source = "# {$title}\n\n";
                $source .= "[[image:{$id}]]\n\n";
                $source .= "Source: {$link}\n";

                $this->db->insert("pages", [
                    "name" => $name,
                    "source" => $source,
                    "created" => $now,
                    "updated" => $now,
                ]);
            }

            $res = [
                "success" => true,
            ];
        }

        elseif ($text = $request->getParam("text")) {
            if (preg_match('@^https?://[^\s]+$@', $text, $m)) {
                $url = $m[0];
                $doc = \Ufw1\Common::fetch($url);

                if (!empty($doc["error"])) {
                    $this->logger->error("wiki: error embedding {url}, error={error}", [
                        "url" => $url,
                        "error" => $doc["error"],
                    ]);

                    return $response->withJSON([
                        "message" => "Не удалось загрузить файл.",
                    ]);
                }

                if ($type = @$doc["headers"]["content-type"]) {
                    if (0 === strpos($type, "image/")) {
                        $name = basename(explode("?", $url)[0]);
                        $type = explode(";", $type)[0];

                        $file = $this->file->add($name, $type, $doc["data"]);
                        $id = $file["id"];

                        $res["id"] = $id;
                        $res["type"] = "image";
                        $res["link"] = $url;
                        $res["code"] = "[[image:{$id}]]";
                        $res["image"] = "/i/thumbnails/{$id}.jpg";
                        $res["page"] = "/wiki?name=File:{$id}";
                        $res["title"] = $name;

                        if (!($tmp = $this->pageGet("File:{$id}"))) {
                            $res["open"][] = "/wiki/edit?name=File:{$id}";
                        }
                    }
                }
            } else {
                $this->logger->debug("clipboard: no links.");
            }
        }

        return $response->withJSON($res);
    }

    /**
     * Display full list of wiki pages.
     **/
    public function onIndex(Request $request, Response $response, array $args)
    {
        $pages = array_filter($this->node->where("`type` = 'wiki'", [], function ($node) {
            $name = $node["name"];
            if (0 === strpos($name, "File:"))
                return null;
            elseif (0 === strpos($name, "wiki:"))
                return null;

            return [
                "name" => $name,
                "updated" => $node["updated"],
                "length" => strlen($node["source"]),
            ];
        }));

        switch ($sort = $request->getQueryParam("sort")) {
            case "updated":
                usort($pages, function ($a, $b) {
                    return strcmp($a["updated"], $b["updated"]);
                });

                break;

            case "length":
                usort($pages, function ($a, $b) {
                    return $a["length"] - $b["length"];
                });

                break;

            default:
                usort($pages, function ($a, $b) {
                    $x = strnatcasecmp($a["name"], $b["name"]);
                    return $x;
                });
        }

        return $this->render($request, "wiki-index.twig", [
            "pages" => $pages,
        ]);
    }

    /**
     * Display full list of wiki pages.
     **/
    public function onRecent(Request $request, Response $response, array $args)
    {
        $since = strftime("%Y-%m-%d %H:%M:%S", time() - 86400 * 30);

        $pages = $this->node->fetch("`type` = 'wiki' AND `updated` >= ? AND `published` = 1", [$since], function ($node) {
            if (preg_match('@^(File|wiki):@', $node["name"]))
                return null;

            return [
                "name" => $node["name"],
                "updated" => $node["updated"],
            ];
        });

        $res = [];
        foreach ($pages as $page) {
            $date = explode(" ", $page["updated"])[0];
            $res[$date][] = $page["name"];
        }

        // One date = recent migration.
        if (count($res) == 1)
            $res = [];

        else {
            krsort($res);
            foreach ($res as $k => $v)
                sort($res[$k], SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $this->render($request, "wiki-recent.twig", [
            "pages" => $res,
        ]);
    }

    /**
     * Background task: reindex a page.
     **/
    public function onReindexPage(Request $request, Response $response, array $args)
    {
        if (empty($args["id"])) {
            $this->logger->warning("tasks: malformed query: path={path}, args={args}.", [
                "path" => $request->getUri()->getPath(),
                "args" => $args,
            ]);

            return "DONE";
        }

        $id = (int)$args["id"];

        if (!($node = $this->node->get($id))) {
            $this->logger->warning("wiki: cannot reindex node {id} -- not found.", [
                "id" => $id,
            ]);

            return "DONE";
        }

        // Skip special pages.
        if (preg_match('@^(wiki|File):@', $node["name"]))
            return "DONE";

        $this->pageReindex($node);

        return "DONE";
    }

    /**
     * Преобразование старой таблицы wiki в nodes.
     **/
    public function onMigrate(Request $request, Response $response, array $args)
    {
        $this->requireAdmin($request);

        $this->db->transact(function ($db) {
            $sel = $db->query("SELECT * FROM `pages`");
            while ($row = $sel->fetch(\PDO::FETCH_ASSOC)) {
                $key = md5(mb_strtolower(trim($row["name"])));

                if ($old = $this->node->getByKey($key)) {
                    $this->logger->warning("wiki: migrate: node with key {key} exists, id={id}, name={name}", [
                        "key" => $key,
                        "id" => $old["id"],
                        "name" => $row["name"],
                    ]);

                    continue;
                }

                $node = $this->node->save([
                    "type" => "wiki",
                    "key" => $key,
                    "name" => $row["name"],
                    "source" => $row["source"],
                    "created" => strftime("%Y-%m-%d %H:%M:%S", $row["created"]),
                    "updated" => strftime("%Y-%m-%d %H:%M:%S", $row["updated"]),
                    "published" => 1,
                ]);

                $db->query("DELETE FROM `pages` WHERE `id` = ?", [$row["id"]]);
            }
        });

        return "Done.";
    }

    public function onFlush(Request $request, Response $response, array $args)
    {
        if (!$this->canEditPage($request))
            return $this->forbidden();

        $this->db->query("DELETE FROM `cache` WHERE `key` LIKE 'wiki:%'");

        return $response->withRedirect("/wiki");
    }

    /**
     * Update the search index.
     **/
    public function onReindex(Request $request, Response $response, array $args)
    {
        $this->requireAdmin($request);

        $this->db->transact(function ($db) {
            $db->query("DELETE FROM `search` WHERE `key` LIKE 'wiki:%'");

            $sel = $this->db->query("SELECT `id` FROM `nodes` WHERE `type` = 'wiki' AND `published` = 1 ORDER BY `updated` DESC");
            while ($id = $sel->fetchColumn(0)) {
                $this->taskAdd("/tasks/reindex/wiki/{$id}");
            }
        });

        return $response->withRedirect("/admin/tasks");
    }

    /**
     * Check if the page needs to be served fresh.
     *
     * Detects F5 and Shift-F5.
     *
     * @param Request $request Request.
     * @return bool True if refresh was requested.
     **/
    protected function refresh(Request $request)
    {
        if ($request->getParam("debug"))
            return true;

        $headers = $request->getHeaders();

        $cacheControl = @$headers["HTTP_CACHE_CONTROL"][0];

        // Refresh, Firefox
        if ($cacheControl == "max-age=0")
            return true;

        // Shift-Refresh, Firefox
        if ($cacheControl == "no-cache")
            return true;

        return false;
    }

    protected function pageGetText(array $page)
    {
        $html = $page["html"];

        // strip_tags mishandles scripts, and we use them heavily for microdata,
        // so just strip them off in advance.
        $html = preg_replace('@<script.*?</script>@', '', $html);

        $html = str_replace("><", "> <", $html);
        $text = trim(strip_tags($html));
        return $text;
    }

    protected function pageGetSnippet(array $page)
    {
        $html = $page["html"];

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

    protected function getPageImage(array $page)
    {
        $image = null;

        if (!empty($page["image"]))
            return $page["image"];

        if (preg_match('@<img[^>]+/>@ms', $page["html"], $m)) {
            if (preg_match('@src="([^"]+)"@', $m[0], $n)) {
                $image = $n[1];
            }

            elseif (preg_match("@src='([^']+)'@", $m[0], $n)) {
                $image = $n[1];
            }
        }

        return $image;
    }

    /**
     * Process wiki page syntax.
     *
     * @param array $node Node contents.
     * @return array Page properties: title, html, etc.
     **/
    protected function pageProcess(array $node)
    {
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

        // Process wiki links.
        $interwiki = @$this->container->get("settings")["interwiki"];
        $source = preg_replace_callback('@\[\[([^]]+)\]\]@', function ($m) use ($interwiki) {
            // Embed images later.
            if (0 === strpos($m[1], "image:"))
                return $m[0];

            // Embed maps.
            if (0 === strpos($m[1], "map:")) {
                $parts = explode(":", $m[1]);

                $id = mt_rand(1111, 9999);
                $tag = $parts[1];

                $html = "<div id='map_{$id}' class='map' data-src='/map/points.json?tag=" . $tag . "'></div>";
                return $html;
            }

            $link = $m[1];
            $label = $m[1];

            if (count($parts = explode("|", $m[1], 2)) == 2) {
                $link = $parts[0];
                $label = $parts[1];
            }

            $cls = "good";
            $title = $link;

            if ($interwiki) {
                foreach ($interwiki as $pattern => $target) {
                    if (preg_match($pattern, $link, $m)) {
                        if ($link == $title)
                            $title = $m[1];
                        $link = str_replace('%s', urlencode($m[1]), $target);
                        $html = sprintf("<a class='interwiki' href='%s'>%s</a>", htmlspecialchars($link), htmlspecialchars($label));
                        return $html;
                    }
                }
            }

            if (!($fpage = $this->pageGet($link))) {
                $cls = "broken";
                $title = "Нет такой страницы";
            }

            $html = sprintf("<a href='/wiki?name=%s' class='wiki %s' title='%s'>%s</a>", urlencode($link), $cls, htmlspecialchars($title), htmlspecialchars($label));

            return $html;
        }, $source);

        $html = \Ufw1\Common::renderMarkdown($source);
        $html = \Ufw1\Common::renderTOC($html);

        // Embed images.
        $html = preg_replace_callback('@\[\[image:([^]]+)\]\]@', function ($m) use ($node, &$res) {
            $parts = explode(":", $m[1]);
            $fileId = array_shift($parts);

            $info = $this->node->get($fileId);
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

            $small = "/i/thumbnails/{$fileId}.jpg";
            $large = "/i/photos/{$fileId}.jpg";
            $page = "/wiki?name=File:{$fileId}";
            $title = "untitled";

            $res["images"][] = [
                "src" => $large,
                "width" => $w,
                "height" => $h,
            ];

            if ($tmp = $this->getFileTitle($fileId))
                $title = $tmp;

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

        $html = preg_replace_callback('@<h1>(.+)</h1>@', function ($m) use (&$res) {
            $res["title"] = $m[1];
            return "";
        }, $html);

        if (empty($res["summary"])) {
            if (preg_match('@<p>(.+?)</p>@', $html, $m)) {
                $res["summary"] = strip_tags($m[1]);
            }
        }

        if (preg_match_all('@<img[^>]+>@', $html, $m)) {
            foreach ($m[0] as $_img) {
                $attrs = \Ufw1\Util::parseHtmlAttrs($_img);
            }
        }

        $html = \Ufw1\Util::cleanHtml($html);
        $res["html"] = $html;

        return $res;
    }

    protected function getImageSize($fileId)
    {
        $file = $this->file->get($fileId);

        if ($file) {
            $storage = $this->file->getStoragePath();
            $fpath = $storage . "/" . $file["fname"];

            if (file_exists($fpath)) {
                $body = file_get_contents($fpath);

                $img = imagecreatefromstring($body);
                $w = imagesx($img);
                $h = imagesy($img);

                return [$w, $h];
            }

            $this->logger->warning("file {id} not found in the file system, path: {path}", [
                "id" => $fileId,
                "path" => $fpath,
            ]);

            return [null, null];
        }

        throw new \RuntimeException("file not found");
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
     * Reads a page by its name.
     *
     * @param string $name Page name.
     * @return array|null Node contents.
     **/
    protected function pageGet($name)
    {
        $key = md5(mb_strtolower(trim($name)));
        $node = $this->node->getByKey($key);
        return $node;
    }

    protected function pageGetTemplate($name)
    {
        if ($tmap = $this->pageGet("wiki:templates")) {
            $lines = explode("\n", str_replace("\r", "", $tmap["source"]));
            foreach ($lines as $line) {
                $parts = explode(": ", $line, 2);
                if (count($parts) == 2) {
                    if (preg_match("@{$parts[0]}@u", $name, $m)) {
                        if ($src = $this->pageGet($parts[1])) {
                            $reps = [];
                            $reps["{{name}}"] = $name;
                            foreach ($m as $k => $v)
                                $reps['{{' . $k . '}}'] = $v;

                            $src = str_replace(array_keys($reps), array_values($reps), $src);

                            return $src["source"];
                        }
                    }
                }
            }
        }

        return  "# {$name}\n\n**{$name}** is ....";
    }

    protected function pageSave($name, $source)
    {
        $node = $this->pageGet($name);

        if (empty($node)) {
            $node = [
                "type" => "wiki",
                "published" => 1,
                "name" => $name,
                "key" => md5(mb_strtolower(trim($name))),
            ];
        }

        $node["source"] = $source;
        $node = $this->node->save($node);

        $key = "wiki:" . $node["name"];
        $this->db->query("DELETE FROM `cache` WHERE `key` = ?", [$key]);

        $this->pageReindex($node);

        // TODO: update backlinks
        // TODO: flush backlinks cache

        return $node;
    }

    /**
     * Update page in the fulltext search index.
     **/
    protected function pageReindex(array $node)
    {
        $page = $this->pageProcess($node);
        $text = $this->pageGetText($page);

        $name = $page["name"];
        $title = $page["title"];
        $snippet = $this->pageGetSnippet($page);

        $meta = [
            "title" => $title,
            "link" => "/wiki?name=" . urlencode($name),
            "snippet" => $snippet,
            "updated" => $node["updated"],
            "words" => count(preg_split('@\s+@', $text, -1, PREG_SPLIT_NO_EMPTY)),
        ];

        if ($page["image"])
            $meta["image"] = $page["image"];

        $this->fts->reindexDocument("wiki:" . $node["id"], $title, $text, $meta);
    }

    protected function canReadPage(Request $request)
    {
        $settings = $this->container->get("settings");
        $roles = $settings["wiki"]["require_role"] ?? [];

        if (empty($roles))
            return true;

        $user = $this->getUser($request);

        if (empty($user))
            $this->unauthorized();

        if ($user["published"] == 0)
            $this->forbidden();

        if (!in_array($user["role"], $roles))
            $this->forbidden();

        return true;
    }

    protected function canEditPage(Request $request)
    {
        $settings = $this->container->get("settings");
        $role = $settings["wiki"]["edit_role"] ?? null;
        return $role ? $this->requireRole($request, $role) : true;
    }

    protected function findLinks($html)
    {
        $links = [];

        if (preg_match_all('@<a(.+?)>@', $html, $m)) {
            foreach ($m[0] as $tag) {
                $attrs = \Ufw1\Util::parseHtmlAttrs($tag);
                if (!empty($attrs["href"]))
                    $links[] = $attrs;
            }
        }

        return $links;
    }

    /**
     * Flush cache for linking pages.
     **/
    protected function backlinksFlush($name)
    {
        // TODO: add table wiki_backlinks, use it.
    }

    protected function backlinksUpdate($name, array $names)
    {
        // TODO
    }

    protected function getFileTitle($id)
    {
        if ($page = $this->pageGet("File:" . $id)) {
            if (preg_match('@^# (.+)$@m', $page["source"], $n)) {
                $title = htmlspecialchars($n[1]);
                return $title;
            }
        }

        return null;
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
     * Вывод списка последних загруженных картинок.
     **/
    public function onRecentFilesJson(Request $request, Response $response, array $args)
    {
        $files = $this->node->where("`type` = 'file' AND `published` = 1 AND `id` IN (SELECT `id` FROM `nodes_file_idx` WHERE `kind` = 'photo') ORDER BY `created` DESC LIMIT 50");

        $files = $this->fillNodes($files);

        return $response->withJSON([
            "files" => $files,
        ]);
    }

    protected function fillNodes(array $nodes)
    {
        $nodes = array_map(function ($node) {
            $res = [
                "id" => (int)$node["id"],
                "name" => $node["name"],
            ];

            $key = md5(mb_strtolower("File:" . $node["id"]));
            if ($desc = $this->node->getByKey($key)) {
                if (preg_match('@^# (.+)$@m', $desc["source"], $m)) {
                    $res["name"] = trim($m[1]);
                }
            }

            $res["name_html"] = htmlspecialchars($res["name"]);

            return $res;
        }, $nodes);

        return $nodes;
    }
}
