<?php

/**
 * All logic related to a wiki site.
 *
 * Public methods are used by actions, normally one per action.
 * Other methods are protected, for internal use only.
 *
 * Handles user input, checks access, performs logical operations.
 * The lower level stuff is made in WikiService.
 **/

declare(strict_types=1);

namespace Ufw1\Wiki;

use Slim\Http\UploadedFile;
use Ufw1\Services\Logger;
use Ufw1\Services\NodeRepository;
use Ufw1\Services\TaskQueue;
use Ufw1\Services\Database;
use Ufw1\Services\FileRepository;

class WikiDomain
{
    /**
     * @var array Wiki settings.
     **/
    protected $settings;

    /**
     * @var NodeRepository
     **/
    protected $node;

    /**
     * @var TaskQueue
     **/
    protected $taskq;

    /**
     * @var Wiki
     **/
    protected $wiki;

    /**
     * @var FileRepository
     **/
    protected $file;

    /**
     * @var Database
     **/
    protected $db;

    /**
     * @var Logger
     **/
    protected $logger;

    public function __construct($settings, NodeRepository $node, WikiService $wiki, TaskQueue $taskq, Database $db, Logger $logger, FileRepository $file)
    {
        $this->node = $node;
        $this->wiki = $wiki;
        $this->logger = $logger;
        $this->taskq = $taskq;
        $this->db = $db;
        $this->file = $file;
        $this->settings = $this->getSettingsWithDefaults($settings['wiki'] ?? []);
    }

    /**
     * Returns data to display a wiki page.
     *
     * @param ?string $pageName Name of the page to render.
     * @param array   $user     User that requests the page.  For ACL purposes.
     *
     * @return array Response data: redirect, error or response.
     **/
    public function getShowPageByName(?string $pageName, ?array $user = null): array
    {
        if (null === $pageName) {
            $homePage = $this->settings['home_page'] ?? 'Welcome';

            return [
                'redirect' => $this->getPageLink($homePage),
            ];
        }

        $node = $this->wiki->getPageByName($pageName);

        if (null === $node) {
            return $this->getNotFound($pageName, $user);
        }

        if ($error = $this->getReadError($user, $node)) {
            return $error;
        }

        $response = [
            'node' => $node,
            'page' => $this->renderWikiSource($node['source']),
            'user' => $user,
            'language' => $page['language'] ?? null,
        ];

        if ($this->userCanEditPage($user, $node)) {
            $response['edit_link'] = '/wiki/edit?name=' . urlencode($node['name']);
        }

        return [
            'response' => $response,
        ];
    }

    /**
     * Returns data to display the wiki page editor.
     *
     * @param string $pageName The name of the page.
     * @param array  $user User inforamtion, to check access etc.
     *
     * @return array Response data.
     **/
    public function getPageEditorData(string $pageName, ?string $sectionName, ?array $user): array
    {
        if (empty($pageName)) {
            return $this->getNotFound($pageName, $user);
        }

        // Check permissions.
        if ($error = $this->getEditError($user)) {
            return $error;
        }

        $source = $this->wiki->getPageSource($pageName, $sectionName);

        return [
            'response' => [
                'page_name' => $pageName,
                'page_section' => $sectionName,
                'page_source' => $source,
                'wiki_buttons' => $this->settings['buttons'],
            ],
        ];
    }

    /**
     * Save changes to a page.
     *
     * TODO: notify previous authors on edit overrule.
     *
     * @param  string $pageName    The name of the page.
     * @param ?string $sectionName The section to update, null means whole page.
     * @param  string $source      Page source.
     * @param ?array  $user        User making the edit.
     *
     * @return array Response data.
     **/
    public function updatePage(string $pageName, ?string $sectionName, string $source, ?array $user): array
    {
        // Validate input.
        if (empty($pageName)) {
            return $this->getForbidden();
        }

        // Check permissions.
        if ($error = $this->getEditError($user)) {
            return $error;
        }

        // Perform the update.
        $this->wiki->updatePage($pageName, $source, $user, $sectionName);
        $this->logPageEdit($pageName, $sectionName, $user);

        return [
            'redirect' => $this->getPageLink($pageName, $sectionName),
        ];
    }

    /**
     * List all wiki pages.
     **/
    public function index(?string $sort, ?array $user): array
    {
        if ($error = $this->getReadError($user, null)) {
            return $error;
        }

        $pages = array_filter($this->node->where("`type` = 'wiki' AND deleted = 0 AND published = 1", [], function ($node) {
            $name = $node["name"];

            if (0 === strpos($name, "File:")) {
                return null;
            } elseif (0 === strpos($name, "wiki:")) {
                return null;
            }

            return [
                'id' => (int)$node['id'],
                'name' => $name,
                'title' => $node['title'] ?? $node['name'],
                'updated' => $node['updated'],
                'length' => isset($node['source']) ? strlen($node['source']) : 0,
            ];
        }));

        switch ($sort) {
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

        return [
            'response' => [
                'pages' => $pages,
                'user' => $user,
            ],
        ];
    }

    /**
     * List recently uploaded files.
     *
     * Used in the file upload dialog.
     *
     * TODO: add search.
     **/
    public function recentFiles(?array $user): array
    {
        if ($error = $this->getReadError($user, null)) {
            return $error;
        }

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

        return [
            'response' => [
                'files' => $files,
                'user' => $user,
            ],
        ];
    }

    /**
     * Schedule reindex of all wiki pages.
     **/
    public function reindex(?array $user): array
    {
        if ($error = $this->getEditError($user, null)) {
            return $error;
        }

        $sel = $this->db->query("SELECT `id` FROM `nodes` WHERE `type` = 'wiki' ORDER BY `updated` DESC");
        while ($id = $sel->fetchColumn(0)) {
            $this->taskq->add('fts.reindexNode', [
                'id' => $id,
            ]);
        }

        return [
            'redirect' => '/admin/taskq',
        ];
    }

    /**
     * Handle file upload.
     **/
    public function upload(?string $link, ?array $files, ?array $user): array
    {
        if ($error = $this->getEditError($user, null)) {
            return $error;
        }

        $errors = false;

        if (null !== $link) {
            if (!$this->fetchFileByLink($link, $user)) {
                $errors = true;
            }
        }

        if (null !== $files) {
            foreach ($files as $file) {
                if (!$this->saveUploadedFile($file, $user)) {
                    $errors = true;
                }
            }
        }

        return [
            'response' => [
                'message' => $errors ? 'Error saving some files.' : null,
                'callback' => 'ufw_upload_callback',
            ],
        ];
    }

    /**
     * Check if the user is allowed to edit the page.
     *
     * @param array $user User information.
     * @param array $node Wiki page.
     *
     * @return bool True if reading is allowed.
     **/
    protected function userCanEditPage(?array $user, ?array $node): bool
    {
        $userRole = $user['role'] ?? 'nobody';
        $allowedRoles = $this->settings['editor_roles'] ?? ['admin'];

        if (in_array($userRole, $allowedRoles)) {
            return true;
        }

        $this->logger->debug('user {user} cannot edit page "{name}": role is {role}, required: {allowed}.', [
            'uid' => $user['id'] ?? null ?: 'anonymous',
            'name' => $node['name'] ?? null,
            'role' => $userRole,
            'allowed' => $allowedRoles,
        ]);

        return false;
    }

    /**
     * Check if the user is allowed to read the page.
     *
     * @param array $user User information.
     * @param array $node Wiki page.
     *
     * @return bool True if reading is allowed.
     **/
    protected function userCanReadPage(?array $user, ?array $node): bool
    {
        if (null !== $node && (int)$node['published'] === 0) {
            $this->logger->debug('user {uid} cannot read page "{name}": unpublished.', [
                'uid' => $user['id'] ?? null ?: 'anonymous',
                'name' => $node['name'] ?? null,
            ]);

            return false;
        }

        $userRole = $user['role'] ?? 'nobody';
        $allowedRoles = $this->settings['reader_roles'] ?? [];

        if (!in_array($userRole, $allowedRoles)) {
            $this->logger->debug('user {uid} cannot read page "{name}": has role {role}, reader roles: {allowed}.', [
                'uid' => $user['id'] ?? null ?: 'anonymous',
                'name' => $node['name'] ?? null,
                'role' => $userRole,
                'allowed' => $allowedRoles,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Return a failure response if the user has no access.
     *
     * @param ?array $user User performing the edit.
     * @param ?array $page Page being edited.
     *
     * @return ?array Error response.
     **/
    protected function getEditError(?array $user, ?array $page = null): ?array
    {
        if (!$this->userCanEditPage($user, null)) {
            if ($user === null) {
                return $this->getUnauthorized();
            } else {
                return $this->getForbidden();
            }
        }

        return null;
    }

    protected function getFailure(int $statusCode, string $message): array
    {
        return [
            'error' => [
                'code' => $statusCode,
                'message' => $message,
            ],
        ];
    }

    protected function getForbidden(): array
    {
        return $this->getFailure(403, 'Page access forbidden.');
    }

    protected function getNotFound(?string $pageName, ?array $user): array
    {
        $canEdit = $this->userCanEditPage($user, null);

        $res = [
            'error' => [
                'code' => 404,
                'message' => 'Page not found',
                'user' => $user,
                'pageName' => $pageName,
                'edit_link' => $canEdit ? '/wiki/edit?name=' . urlencode($pageName) : null,
            ],
        ];

        if ($pageName !== null) {
            $res['error']['pageName'] = $pageName;
        }

        return $res;
    }

    protected function getPageKey(string $pageName): string
    {
        return md5(mb_strtolower(trim($pageName)));
    }

    protected function getPageLink(string $pageName, ?string $sectionName = null): string
    {
        $link = '/wiki?name=' . urlencode($pageName);

        if (null !== $sectionName) {
            $link .= '#' . str_replace(' ', '_', $sectionName);
        }

        return $link;
    }

    protected function getReadError(?array $user, ?array $node): ?array
    {
        if (!$this->userCanReadPage($user, $node)) {
            if ($user === null) {
                return $this->getUnauthorized();
            } else {
                return $this->getForbidden();
            }
        }

        return null;
    }

    protected function getSettingsWithDefaults(array $settings): array
    {
        return array_replace([
            'buttons' => [[
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
                'link' => $this->wiki->getWikiLink('howto-edit'),
            ], /* [
                'name' => 'map',
                'icon' => 'map-marker',
                'hint' => 'Вставить карту',
                'link' => $this->wiki->getWikiLink('howto-map'),
            ], */ [
                'name' => 'toc',
                'icon' => 'list-ol',
                'hint' => 'Вставить оглавление',
            ], [
                'name' => 'upload',
                'icon' => 'image',
                'hint' => 'Вставить файл',
            ]],
        ], $settings);
    }

    protected function getUnauthorized(): array
    {
        return $this->getFailure(401, 'You need to log in to access this page.');
    }

    protected function logPageEdit(string $pageName, ?string $sectionName, array $user): void
    {
        if (null !== $sectionName) {
            $this->logger->info('wiki: user {uid} ({uname}) edited page "{page}" section "{section}"', [
                'uid' => $user['id'] ?? null,
                'uname' => $user['name'] ?? '(anonymous)',
                'page' => $pageName,
                'section' => $sectionName,
            ]);
        } else {
            $this->logger->info('wiki: user {uid} ({uname}) edited page "{page}"', [
                'uid' => $user['id'] ?? null,
                'uname' => $user['name'] ?? '(anonymous)',
                'page' => $pageName,
            ]);
        }
    }

    /**
     * Render a wiki node.
     *
     * Reads wiki syntax from `source`, adds result to `html`.
     *
     * @param array $node Wiki source node.
     **/
    protected function renderWikiSource(string $source): array
    {
        $res = $this->wiki->render($source);
        return $res;
    }

    /**
     * Fetch remote file and save it.
     **/
    protected function fetchFileByLink(string $link, ?array $user): bool
    {
        $file = \Ufw1\Util::fetch($link);

        if ((int)$file['status'] === 200) {
            $name = basename(explode('?', $link)[0]);
            $type = $file['headers']['content-type'];
            $body = $file['data'];

            $comment = "Файл загружен [по ссылке]({$link}).\n\n";

            $this->file->add($name, $type, $body, [
                'uid' => $user['id'] ?? null,
            ]);

            return true;
        } else {
            $this->logger->error('file upload failed: {res}', [
                'res' => $file,
            ]);

            return false;
        }
    }

    protected function saveUploadedFile(UploadedFile $file, ?array $user): bool
    {
        if ($file->getError()) {
            return false;
        }

        $name = $file->getClientFilename();
        $type = $file->getClientMediaType();

        $tmpdir = $this->file->getStoragePath();
        $tmp = tempnam($tmpdir, "upload_");
        $file->moveTo($tmp);
        $body = file_get_contents($tmp);
        unlink($tmp);

        $this->file->add($name, $type, $body, [
            'uid' => $user['id'] ?? null,
        ]);

        return true;
    }
}
