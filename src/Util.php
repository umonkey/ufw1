<?php

declare(strict_types=1);

namespace Ufw1;

use Psr\Container\ContainerInterface;
use Slim\App;

class Util
{
    public static function cleanHtml(string $html): string
    {
        // Closing tags should never have leading space.
        $html = preg_replace('@\s+</([a-z0-9]+)>@', '</\1>', $html);

        // Clean up.
        $all = "html|head|body|header|section|main|footer|aside|nav|div|p|ul|ol|li|input|select|option|label|textarea|button|meta|title|h1|h2|h3|h4|h5|script|style|link|table|thead|tfoot|tbody|tr|th|td|img|form|source|picture";
        $html = preg_replace($re = '@\s*<(' . $all . '|!--)([^>]*>)\s*@', '<\1\2', $html);
        $html = preg_replace('@\s*</(' . $all . ')>\s*@ms', '</\1>', $html);

        $html = preg_replace('@</a>\s+@', '</a> ', $html);
        $html = preg_replace('@\s+</a>@', ' </a>', $html);

        return $html;
    }

    public static function parseHtmlAttrs(string $tag): array
    {
        $res = [];

        if (preg_match_all('@([a-z-]+)="([^"]+)"@', $tag, $m)) {
            foreach ($m[1] as $idx => $key) {
                $res[$key] = trim($m[2][$idx]);
            }
        }

        if (preg_match_all("@([a-z-]+)='([^']+)'@", $tag, $m)) {
            foreach ($m[1] as $idx => $key) {
                $res[$key] = trim($m[2][$idx]);
            }
        }

        return $res;
    }

    /**
     * Add some stylistical sugar to the text.
     *
     * Converts double dashes, adds nbsp after the dot, etc.
     *
     * @param  string $text Source text.
     * @return string       Updated text.
     **/
    public static function processTypography(string $text): string
    {
        // Some typography.
        $text = preg_replace('@\s+--\s+@', '&nbsp;— ', $text);
        $text = preg_replace('@\.  @', '.&nbsp; ', $text);

        // Use nbsp with some words.
        $text = preg_replace('@ (а|В|в|Для|и|из|на|о|от|с)\s+@u', ' \1&nbsp;', $text);
        $text = preg_replace('@\s+(году|год)([.,])@u', '&nbsp;\1\2', $text);

        return $text;
    }

    public static function hyphenate(string $text, int $len = 10): string
    {
        $text = preg_replace_callback('@[а-я]+@ui', function ($m) use ($len) {
            $word = $m[0];
            if (mb_strlen($word) < $len) {
                return $word;
            }

            $word = preg_replace('@([бвгджзйклмнпрстфхцчшщ]+[аеёиоуыьэюя]+(?:ль)?)@ui', '\1|', $word);

            // й не может быть отдельным последним слогом, склеиваем с предыдущим ("Се|беж|ски|й").
            $word = preg_replace('@\|(й)$@ui', '\1', $word);

            // fix "ра|йо|н"
            $word = preg_replace('@^ра\|йо\|н@u', 'рай|он', $word);

            // Исправляем последний слог из согласных.
            $word = preg_replace('@\|([бвгджзйклмнпрстфхцчшщьъ]+)\|?$@u', '\1', $word);

            // Разделяем двойные согласные: "Ко|рса|ков" => "Кор|са|ков"
            // В том числе несколько: "Но|ви|нско|го" => "Но|вин|ско|го"
            $word = preg_replace('@\|([бвгджзйклмнпрстфхцчшщьъ])([бвгджзйклмнпрстфхцчшщьъ]+[аеёиоуыьэюя])@ui', '\1|\2', $word);

            $word = preg_replace('@\|(ч)(ш)@ui', '\1|\2', $word);
            $word = preg_replace('@\|(й)(ств)@ui', '\1|\2', $word);
            $word = preg_replace('@\|(р)(ко)@ui', '\1|\2', $word);

            $word = trim($word, "|");
            return $word;
        }, $text);

        return $text;
    }

    public static function plural(int $number, string $single, string $double, string $multiple): string
    {
        $titles = array($single, $double, $multiple);
        $cases = array(2, 0, 1, 1, 1, 2);
        return $titles[($number % 100 > 4 && $number % 100 < 20) ? 2 : $cases[min($number % 10, 5)]];
    }

    public static function containerSetup(ContainerInterface $container): void
    {
        $container['database'] = function ($c) {
            $dsn = $c->get('settings')['dsn'];
            return new Services\Database($dsn);
        };

        $container['file'] = function ($c) {
            return new Services\FileFactory($c);
        };

        $container['fts'] = function ($c) {
            return new Services\Search($c);
        };

        $container['logger'] = function ($c) {
            return new Services\Logger($c);
        };

        $container['notFoundHandler'] = function ($c) {
            return function ($request, $response) use ($c) {
                throw new \Ufw1\Errors\NotFound();
            };
        };

        $container['node'] = function ($c) {
            return new Services\NodeFactory($c);
        };

        $container['S3'] = function ($c) {
            return new Services\S3($c);
        };

        $container['stemmer'] = function ($c) {
            return new Services\Stemmer($c);
        };

        $container['taskq'] = function ($c) {
            return new Services\TaskQueue($c);
        };

        $container['telega'] = function ($c) {
            return new Services\Telega($c);
        };

        $container['template'] = function ($c) {
            return new Services\Template($c);
        };

        $container['thumbnailer'] = function ($c) {
            if (class_exists('\Imagickx')) {
                return new Services\Thumbnailer2($c);
            } else {
                return new Services\Thumbnailer($c);
            }
        };

        $container['wiki'] = function ($c) {
            $t = new Services\Wiki($c);
            return $t;
        };

        self::installErrorHandler($container);
    }

    public static function installAccount(App $app): void
    {
        Handlers\Account::setupRoutes($app);
    }

    public static function installAdmin(App $app): void
    {
        Handlers\Admin::setupRoutes($app);
    }

    public static function installErrorHandler(ContainerInterface $container): void
    {
        $container['errorHandler'] = function ($c) {
            return function ($request, $response, $e) use ($c) {
                $h = new Handlers\Error($c);
                return $h($request, $response, ['exception' => $e]);
            };
        };
    }

    public static function installFiles(App $app): void
    {
        Handlers\Files::setupRoutes($app);
    }

    public static function installSearch(App $app): void
    {
        $app->get('/search', '\Ufw1\Handlers\Search:onGet');
        $app->get('/search.xml', '\Ufw1\Handlers\Search:onGetXML');
        $app->get('/search/suggest', '\Ufw1\Handlers\Search:onSuggest');
    }

    public static function installTaskQ(App $app): void
    {
        $class = Handlers\TaskQ::class;

        $app->get('/taskq/list', $class . ':onList');
        $app->any('/taskq/{id:[0-9]+}/run', $class . ':onRun');
    }

    public static function installWiki(App $app): void
    {
        $class = Handlers\Wiki::class;

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

    /**
     * Adds admin UI to the touring table.
     **/
    public static function addAdminRoutes(App &$app): void
    {
        if (class_exists('\App\Handlers\TaskQ')) {
            $app->get('/taskq/list', '\App\Handlers\TaskQ:onList');
            $app->any('/taskq/{id:[0-9]+}/run', '\App\Handlers\TaskQ:onRun');
        } else {
            $app->get('/taskq/list', '\Ufw1\Handlers\TaskQ:onList');
            $app->any('/taskq/{id:[0-9]+}/run', '\Ufw1\Handlers\TaskQ:onRun');
        }
    }

    /**
     * Perform actions after the package is updated.
     **/
    public static function postUpdate(): void
    {
        if (!file_exists('docs')) {
            if (file_exists('vendor/umonkey/ufw1/docs')) {
                symlink('vendor/umonkey/ufw1/docs', 'docs');
                printf("+dir docs");
            }
        }
    }
}
