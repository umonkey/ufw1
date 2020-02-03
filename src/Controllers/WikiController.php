<?php

/**
 * Wiki pages.
 **/

declare(strict_types=1);

namespace Ufw1\Controllers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;
use Ufw1\Util;

class WikiController extends CommonHandler
{
    /**
     * Display a single page.
     *
     * TODO: не кэшировать если вики закрытая.
     **/
    public function onRead(Request $request, Response $response, array $args): Response
    {
        $name = $request->getQueryParam("name");

        if (empty($name)) {
            $st = $this->settings['wiki']['home_page'] ?? 'Welcome';
            $next = $this->wiki->getWikiLink($st);
            return $response->withRedirect($next);
        }

        return $this->showPageByName($request, $response, $name);
    }

    protected function showPageByName(Request $request, Response $response, string $name): Response
    {
        $wiki = $this->wiki;

        $user = $this->auth->getUser($request);

        if (!$wiki->canReadPages($user)) {
            if ($user) {
                $this->forbidden();
            } else {
                $this->unauthorized();
            }
        }

        $canEdit = $wiki->canEditPages($user);

        if (preg_match('@^File:(\d+)$@', $name, $m)) {
            return $this->onReadFilePage($request, $name, (int)$m[1]);
        }

        $node = $wiki->getPageByName($name);

        // Fake deleted pages.
        if (empty($node['source'])) {
            $node = null;
        }

        if ($node) {
            $res = $wiki->renderPage($node);

            if (!empty($res['redirect'])) {
                $next = $this->wiki->getWikiLink($res['redirect']);
                return $response->withRedirect($next);
            }

            if (!($author = $res['author'] ?? null)) {
                $res['author'] = $this->container->get('settings')['wiki']['default_author'] ?? null;
            }

            $templates = [
                "pages/node-{$node['id']}.twig",
                "pages/node-wiki.twig",
                "pages/node.twig",
            ];

            return $this->render($request, $templates, [
                'user' => $user,
                'language' => $res['language'],
                'page' => $res,
                'node' => $node,
                'edit_link' => $canEdit ? '/wiki/edit?name=' . urlencode($name) : null,
                'jsdata' => json_encode([
                    'wiki_page' => $name,
                    'disqus_id' => $res['disqus_id'] ?? null,
                    'disqus_url' => $res['disqus_url'] ?? null,
                ]),
            ]);
        } else {
            return $this->render($request, 'pages/wiki-notfound.twig', [
                'user' => $user,
                'page' => ['name' => $name],
                'edit_link' => $canEdit ? '/wiki/edit?name=' . urlencode($name) : null,
            ]);
        }
    }

    protected function onReadFilePage(Request $request, string $pageName, int $fileId): Response
    {
        $file = $this->container->get('file')->get($fileId);

        if (empty($file) or $file['deleted'] == 1) {
            return $this->render($request, 'pages/wiki-notfound.twig', [
                'user' => $user,
                'page' => ['name' => $pageName],
                'edit_link' => "/wiki/edit?name=" . urlencode($pageName),
            ]);
        }

        return $this->render($request, 'pages/wiki-file.twig', [
            'file' => $file,
            'edit_link' => "/wiki/edit?name=" . urlencode($pageName),
        ]);
    }

    /**
     * Show page editor.
     **/
    public function onEdit(Request $request, Response $response, array $args): Response
    {
        $pageName = $request->getQueryParam("name");
        $sectionName = $request->getQueryParam("section");

        if (empty($pageName)) {
            $this->notfound();
        }

        $wiki = $this->container->get('wiki');

        $user = $this->auth->getUser($request);

        if (!$wiki->canEditPages($user)) {
            if ($user) {
                $this->forbidden();
            } else {
                $this->unauthorized();
            }
        }

        $source = $wiki->getPageSource($pageName, $sectionName);

        $settings = $this->settings['wiki'] ?? [];
        $settings['buttons'] = $settings['buttons'] ?? [[
            'name' => 'save',
            'label' => 'Сохранить',
            'icon' => null,
        ], [
            'name' => 'cancel',
            'icon' => 'times',
            'hint' => 'Отменить изменения',
        ], [
            'name' => 'help',
            'icon' => 'question-circle',
            'hint' => 'Открыть подсказку',
            'link' => $this->wiki->getWikiLink('Памятка редактора'),
        ], /* [
            'name' => 'map',
            'icon' => 'map-marker',
            'hint' => 'Вставить карту',
            'link' => $this->wiki->getWikiLink('Как вставить карту'),
        ], */ [
            'name' => 'toc',
            'icon' => 'list-ol',
            'hint' => 'Вставить оглавление',
        ], [
            'name' => 'upload',
            'icon' => 'image',
            'hint' => 'Вставить файл',
        ]];

        return $this->render($request, "pages/wiki-edit.twig", [
            "page_name" => $pageName,
            "page_section" => $sectionName,
            "page_source" => $source,
            "body_class" => "wiki_edit",
            "settings" => $settings,
        ]);
    }

    /**
     * Update page contents.
     **/
    public function onSave(Request $request, Response $response, array $args): Response
    {
        $name = $request->getParam("page_name");
        $source = $request->getParam("page_source");
        $section = $request->getParam("page_section");

        $wiki = $this->container->get('wiki');

        $user = $this->auth->getUser($request);

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

        $next = isset($node['_url'])
            ? $node['url']
            : $this->wiki->getWikiLink($node['name']);

        if ($section) {
            $next .= '#' . str_replace(' ', '_', mb_strtolower($section));
        }

        return $response->withJSON([
            "redirect" => $next,
        ]);
    }

    /**
     * Handle file uploads.
     **/
    public function onUpload(Request $request, Response $response, array $args): Response
    {
        $user = $this->auth->requireUser($request);

        $wiki = $this->wiki;

        if (!$wiki->canEditPages($user)) {
            return $this->forbidden();
        }

        $comment = null;

        if ($link = $request->getParam("link")) {
            $file = Util::fetch($link);
            if ($file["status"] == 200) {
                $name = basename(explode("?", $link)[0]);
                $type = $file["headers"]["content-type"];
                $body = $file["data"];

                $comment = "Файл загружен [по ссылке]({$link}).\n\n";

                $code = $this->addFile($name, $type, $body, $comment);
                if ($code) {
                    $callback = $request->getParam('callback') ?: 'editor_insert';
                    return $response->withJSON([
                        "callback" => $callback,
                        "callback_args" => $code,
                    ]);
                }
            } else {
                return $response->withJSON([
                    "message" => "Не удалось загрузить файл.",
                ]);
            }
        } elseif ($files = $request->getUploadedFiles()) {
            $items = [];

            if (!empty($files["files"]) and is_array($files["files"])) {
                $items = $files["files"];
            } elseif (!empty($files["file"])) {
                $items[] = $files["file"];
            }

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

                if ($tmp = $this->addFile($name, $type, $body)) {
                    $code[] = $tmp;
                }
            }

            $res = [];

            if (empty($code)) {
                $res["message"] = "Не удалось загурзить ни один файл.";
            } else {
                $res["callback"] = $request->getParam('callback') ?: 'editor_insert';
                $res["callback_args"] = implode("\n", $code);

                if ($errors) {
                    $res["message"] = "Не удалось принять некоторые файлы.";
                }
            }

            return $response->withJSON($res);
        }

        $file = $this->file->add($name, $type, $body);
        $fid = $file["id"];

        $pname = "File:" . $fid;
        if (!($page = $this->pageGet($pname))) {
            $text = "# {$name}\n\n";
            $text .= "[[image:{$fid}]]\n\n";
            if ($comment) {
                $text .= $comment;
            }

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
    protected function addFile(string $name, string $type, string $body, string $comment = null): string
    {
        $file = $this->file->add($name, $type, $body);
        $fid = $file["id"];

        if (isset($this->thumbnailer)) {
            $file = $this->thumbnailer->updateNode($file);
            $file = $this->node->save($file);
        }

        $this->S3->autoUploadNode($file);

        return "[[image:{$fid}]]";
    }

    public function onRecentRSS(Request $request, Response $response, array $args): Response
    {
        $items = $this->node->where("`type` = 'wiki' AND `published` = 1 ORDER BY `created` DESC LIMIT 100", [], function ($node) {
            if (preg_match('@^(File|wiki):@', $node["name"])) {
                return null;
            }

            $page = $this->pageProcess($node);

            return [
                "id" => $node["id"],
                "title" => $page["title"],
                "created" => $node["created"],
                "link" => $this->wiki->getWikiLink($node["name"]),
                "html" => $page["html"],
            ];
        });

        $lastUpdate = $this->db->fetchCell("SELECT MAX(updated) FROM `nodes` WHERE `type` = 'wiki' AND `published` = 1");

        return $this->renderRSS($request, [
            "link" => "/wiki",
            "title" => "Свежие страницы",
            "last_update" => $lastUpdate,
        ], $items);

        return $this->renderXML($request, "pages/wiki-pages-rss.twig", [
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
    public function onEmbedClipboard(Request $request, Response $response, array $args): Response
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
        } elseif ($text = $request->getParam("text")) {
            if (preg_match('@^https?://[^\s]+$@', $text, $m)) {
                $url = $m[0];
                $doc = Util::fetch($url);

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
                        }

                        $res["id"] = $id;
                        $res["type"] = "image";
                        $res["link"] = $url;
                        $res["code"] = "[[image:{$id}]]";
                        $res["image"] = "/node/{$id}/download/small";
                        $res["page"] = $this->wiki->getWikiLink("File:{$id}");
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
    public function onIndex(Request $request, Response $response, array $args): Response
    {
        $pages = array_filter($this->node->where("`type` = 'wiki'", [], function ($node) {
            $name = $node["name"];
            if (0 === strpos($name, "File:")) {
                return null;
            } elseif (0 === strpos($name, "wiki:")) {
                return null;
            }

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

        return $this->render($request, "pages/wiki-index.twig", [
            "pages" => $pages,
        ]);
    }

    /**
     * Display full list of wiki pages.
     **/
    public function onRecent(Request $request, Response $response, array $args): Response
    {
        $since = strftime("%Y-%m-%d %H:%M:%S", time() - 86400 * 30);

        $pages = $this->node->where("`type` = 'wiki' AND `updated` >= ? AND `published` = 1 ORDER BY `updated` DESC", [$since], function ($node) {
            if (preg_match('@^(File|wiki):@', $node["name"])) {
                return null;
            }

            if (!empty($node['redirect'])) {
                return null;
            }

            if (empty($node['source'])) {
                return null;
            }

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
        if (count($res) == 1) {
            $res = [];
        } else {
            krsort($res);
            foreach ($res as $k => $v) {
                sort($res[$k], SORT_NATURAL | SORT_FLAG_CASE);
            }
        }

        return $this->render($request, "pages/wiki-recent.twig", [
            "pages" => $res,
        ]);
    }

    /**
     * List recently uploaded files.
     *
     * Used in the file upload dialog.
     **/
    public function onRecentFiles(Request $request, Response $response, array $args): Response
    {
        $files = $this->node->where("`type` = 'file' AND `published` = 1 AND `deleted` = 0 ORDER BY `created` DESC LIMIT 50");

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
    public function onReindex(Request $request, Response $response, array $args): Response
    {
        $this->auth->requireAdmin($request);

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
    protected function refresh(Request $request): bool
    {
        if ($request->getParam("debug")) {
            return true;
        }

        $headers = $request->getHeaders();

        $cacheControl = @$headers["HTTP_CACHE_CONTROL"][0];

        // Refresh, Firefox
        if ($cacheControl == "max-age=0") {
            return true;
        }

        // Shift-Refresh, Firefox
        if ($cacheControl == "no-cache") {
            return true;
        }

        return false;
    }

    protected function pageGetText(array $page): string
    {
        $html = $page["html"];

        // strip_tags mishandles scripts, and we use them heavily for microdata,
        // so just strip them off in advance.
        $html = preg_replace('@<script.*?</script>@', '', $html);

        $html = str_replace("><", "> <", $html);
        $text = trim(strip_tags($html));
        return $text;
    }

    protected function pageGetSnippet(array $page): ?string
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

    protected function getPageImage(array $page): ?string
    {
        $image = null;

        if (!empty($page["image"])) {
            return $page["image"];
        }

        if (preg_match('@<img[^>]+/>@ms', $page["html"], $m)) {
            if (preg_match('@src="([^"]+)"@', $m[0], $n)) {
                $image = $n[1];
            } elseif (preg_match("@src='([^']+)'@", $m[0], $n)) {
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
    protected function pageGet(string $name): ?array
    {
        $key = md5(mb_strtolower(trim($name)));
        $node = $this->node->getByKey($key);
        return $node;
    }

    protected function pageGetTemplate(string $name): string
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
                            foreach ($m as $k => $v) {
                                $reps['{{' . $k . '}}'] = $v;
                            }

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
    public static function setupRoutes(&$app): void
    {
        $class = get_called_class();

        $app->get('/wiki', $class . ':onRead');
        $app->get('/wiki/edit', $class . ':onEdit');
        $app->post('/wiki/edit', $class . ':onSave');
        $app->post('/wiki/embed-clipboard', $class . ':onEmbedClipboard');
        $app->get('/wiki/index', $class . ':onIndex');
        $app->get('/wiki/recent', $class . ':onRecent');
        $app->get('/wiki/recent-files.json', $class . ':onRecentFiles');
        $app->get('/wiki/reindex', $class . ':onReindex');
        $app->any('/wiki/upload', $class . ':onUpload');
    }

    private function isS3AutoUploadEnabled(): bool
    {
        $st = $this->settings['S3']['auto_upload'] ?? false;
        return (bool)$st;
    }
}
