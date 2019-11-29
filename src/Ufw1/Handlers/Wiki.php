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

        $wiki = $this->container->get('wiki');

        $user = $this->getUser($request);
        if (!$wiki->canReadPages($user)) {
            if ($user)
                throw new \Ufw1\Errors\Forbidden;
            else
                throw new \Ufw1\Errors\Unauthorized;
        }

        if (empty($name)) {
            $st = $this->container->get('settings')['wiki']['homePage'] ?? 'Welcome';
            $next = '/wiki?name=' . urlencode($st);
            return $response->withRedirect($next);
        }

        $canEdit = $wiki->canEditPages($user);

        if (preg_match('@^File:(\d+)$@', $name, $m))
            return $this->onReadFilePage($request, $name, $m[1]);

        $node = $wiki->getPageByName($name);

        // Fake deleted pages.
        if (empty($node['source']))
            $node = null;

        if ($node) {
            $res = $wiki->renderPage($node);

            if (!empty($res['redirect'])) {
                $next = '/wiki?name=' . urlencode($res['redirect']);
                return $response->withRedirect($next);
            }

            return $this->render($request, 'wiki-page.twig', [
                'user' => $user,
                'language' => $res['language'],
                'page' => $res,
                'edit_link' => $canEdit ? '/wiki/edit?name=' . urlencode($name) : null,
                'jsdata' => json_encode([
                    'wiki_page' => $name,
                ]),
            ]);
        } else {
            return $this->render($request, 'wiki-nopage.twig', [
                'user' => $user,
                'page' => ['name' => $name],
                'edit_link' => $canEdit ? '/wiki/edit?name=' . urlencode($name) : null,
            ]);
        }
    }

    protected function onReadFilePage(Request $request, $pageName, $fileId)
    {
        $file = $this->container->get('file')->get($fileId);

        if (empty($file) or $file['deleted'] == 1) {
            return $this->render($request, 'wiki-nopage.twig', [
                'user' => $user,
                'page' => ['name' => $pageName],
                'edit_link' => "/wiki/edit?name=" . urlencode($pageName),
            ]);
        }

        return $this->render($request, 'wiki-file.twig', [
            'file' => $file,
            'edit_link' => "/wiki/edit?name=" . urlencode($pageName),
        ]);
    }

    /**
     * Show page editor.
     **/
    public function onEdit(Request $request, Response $response, array $args)
    {
        $pageName = $request->getQueryParam("name");
        $sectionName = $request->getQueryParam("section");

        if (empty($pageName))
            $this->notfound();

        $wiki = $this->container->get('wiki');

        $user = $this->getUser($request);
        if (!$wiki->canEditPages($user))
            $this->forbidden();

        $source = $wiki->getPageSource($pageName, $sectionName);

        return $this->render($request, "wiki-edit.twig", [
            "page_name" => $pageName,
            "page_section" => $sectionName,
            "page_source" => $source,
            "body_class" => "wiki_edit",
        ]);
    }

    /**
     * Update page contents.
     **/
    public function onSave(Request $request, Response $response, array $args)
    {
        $name = $request->getParam("page_name");
        $source = $request->getParam("page_source");
        $section = $request->getParam("page_section");

        $wiki = $this->container->get('wiki');

        $user = $this->getUser($request);

        $node = $wiki->updatePage($name, $source, $user, $section);
        $node = $this->node->save($node);

        if ($section) {
            $this->container->get('logger')->info('wiki: user {uid} ({uname}) edited page "{page}" section "{section}"', [
                'uid' => $user['id'],
                'uname' => $user['name'],
                'page' => $name,
                'section' => $section,
            ]);
        } else {
            $this->container->get('logger')->info('wiki: user {uid} ({uname}) edited page "{page}"', [
                'uid' => $user['id'],
                'uname' => $user['name'],
                'page' => $name,
            ]);
        }

        $next = isset($node['url'])
            ? $node['url']
            : "/wiki?name=" . urlencode($name);

        if ($section)
            $next .= '#' . str_replace(' ', '_', mb_strtolower($section));

        return $response->withJSON([
            "redirect" => $next,
        ]);
    }

    /**
     * Handle file uploads.
     **/
    public function onUpload(Request $request, Response $response, array $args)
    {
        $user = $this->requireUser($request);

        $wiki = $this->container->get('wiki');
        if (!$wiki->canEditPages($user))
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

            $this->pageSave($pname, $text, $user);
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

        if ($this->container->has('thumbnailer')) {
            $tn = $this->container->get('thumbnailer');
            $file = $tn->updateNode($file);
            $file = $this->node->save($file);
        }

        $this->taskq('node-s3-upload', [
            'id' => (int)$fid,
        ]);

        return "[[image:{$fid}]]";
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
            // FIXME: moved to te file node.
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

                        if ($this->container->has('thumbnailer')) {
                            $tn = $this->container->get('thumbnailer');
                            $file = $tn->updateNode($file);
                            $this->node->save($file);
                            $this->taskq('node-s3-upload', ['id' => $id]);
                        }

                        $res["id"] = $id;
                        $res["type"] = "image";
                        $res["link"] = $url;
                        $res["code"] = "[[image:{$id}]]";
                        $res["image"] = "/node/{$id}/download/small";
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

        $pages = $this->node->where("`type` = 'wiki' AND `updated` >= ? AND `published` = 1 ORDER BY `updated` DESC", [$since], function ($node) {
            if (preg_match('@^(File|wiki):@', $node["name"]))
                return null;

            if (!empty($node['redirect']))
                return null;

            if (empty($node['source']))
                return null;

            return [
                "name" => $node["name"],
                "updated" => $node["updated"],
            ];
        });

        $pages = array_filter($pages);

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
     * List recently uploaded files.
     *
     * Used in the file upload dialog.
     **/
    public function onRecentFiles(Request $request, Response $response, array $args)
    {
        $files = $this->node->where("`type` = 'file' AND `published` = 1 AND `deleted` = 0 AND `id` IN (SELECT `id` FROM `nodes_file_idx` WHERE `kind` = 'photo') ORDER BY `created` DESC LIMIT 50");

        $files = array_map(function ($node) {
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
        }, $files);

        return $response->withJSON([
            "files" => $files,
        ]);
    }

    /**
     * Update the search index.
     **/
    public function onReindex(Request $request, Response $response, array $args)
    {
        $this->requireAdmin($request);

        $sel = $this->db->query("SELECT `id` FROM `nodes` WHERE `type` = 'wiki' ORDER BY `updated` DESC");
        while ($id = $sel->fetchColumn(0)) {
            $this->container->get('taskq')->add('fts.reindexNode', [
                'id' => $id,
            ]);
        }

        return $response->withRedirect("/admin/taskq");
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

    /**
     * Add handlers to the routing table.
     *
     * Call this from within src/routes.php
     **/
    public static function setupRoutes(&$app)
    {
        $class = get_called_class();

        $app->get ('/wiki',                   $class . ':onRead');
        $app->get ('/wiki/edit',              $class . ':onEdit');
        $app->post('/wiki/edit',              $class . ':onSave');
        $app->post('/wiki/embed-clipboard',   $class . ':onEmbedClipboard');
        $app->get ('/wiki/index',             $class . ':onIndex');
        $app->get ('/wiki/recent',            $class . ':onRecent');
        $app->get ('/wiki/recent-files.json', $class . ':onRecentFiles');
        $app->get ('/wiki/reindex',           $class . ':onReindex');
        $app->any ('/wiki/upload',            $class . ':onUpload');
    }
}
