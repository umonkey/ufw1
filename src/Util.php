<?php

declare(strict_types=1);

namespace Ufw1;

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

    public static function fetch(string $url): array
    {
        $context = stream_context_create(array(
            "http" => array(
                "method" => "GET",
                "ignore_errors" => true,
                ),
            ));

        $res = array(
            "status" => null,
            "status_text" => null,
            "headers" => array(),
            "data" => @file_get_contents($url, false, $context),
            );

        if (!empty($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('@^HTTP/[0-9.]+ (\d+) (.*)$@', $h, $m)) {
                    $res["status"] = $m[1];
                    $res["status_text"] = $m[2];
                } else {
                    $parts = explode(":", $h, 2);
                    $k = strtolower(trim($parts[0]));
                    $v = trim($parts[1]);
                    $res["headers"][$k] = $v;
                }
            }
        }

        if (false === $res["data"]) {
            $res["error"] = error_get_last();
        }

        return $res;
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

    public static function plural(int $number, string $single, string $double, string $multiple): string
    {
        $titles = array($single, $double, $multiple);
        $cases = array(2, 0, 1, 1, 1, 2);
        return $titles[($number % 100 > 4 && $number % 100 < 20) ? 2 : $cases[min($number % 10, 5)]];
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

    public static function renderMarkdown(string $source): string
    {
        if (empty($source)) {
            return "";
        }

        $environment = \League\CommonMark\Environment::createCommonMarkEnvironment();
        $environment->addExtension(new \League\CommonMark\Ext\Table\TableExtension());

        if (class_exists('League\CommonMark\Ext\TaskList\TaskListExtension')) {
            $environment->addExtension(new \League\CommonMark\Ext\TaskList\TaskListExtension());
        }

        $dp = new \League\CommonMark\DocParser($environment);
        $re = new \League\CommonMark\HtmlRenderer($environment);

        $converter = new \League\CommonMark\Converter($dp, $re);

        $html = $converter->convertToHtml($source);

        return $html;
    }

    public static function renderTOC(string $html): string
    {
        $toc = "<h2>Содержание</h2>";
        $level = 1;

        $html = preg_replace_callback('@<h([12345])>(.+)</h([12345])>@', function ($m) use (&$level, &$toc) {
            list($str, $openLevel, $titleText, $closeLevel) = $m;

            if ($openLevel != $closeLevel) {
                return $str;
            }

            if ($openLevel == 1) {
                return $str;
            }

            if ($openLevel > $level) {
                $toc .= str_repeat("<ul>", $openLevel - $level);
            } elseif ($openLevel < $level) {
                $toc .= str_repeat("</ul>", $level - $openLevel);
            }

            $level = (int)$openLevel;

            $anchor = mb_strtolower(trim(preg_replace('@\s+@', '_', $m[2])));

            $toc .= sprintf("<li><a href='#%s'>%s</a></li>", $anchor, $titleText);

            $code = sprintf("<h%u id='%s'>%s</h%u>", $openLevel, $anchor, $titleText, $openLevel);
            return $code;
        }, $html);

        if ($level > 1) {
            $toc .= str_repeat("</ul>", $level - 1);
        }

        $html = str_replace("<div id=\"toc\"></div>", "<div id=\"toc\">{$toc}</div>", $html);

        return $html;
    }

    /**
     * Generate unique id.
     *
     * Not really an UUID, but kind of.  Includes server hash, time hash and content hash.
     *
     * @param string $body File contents.
     * @return string Unique identifier.
     **/
    public static function uuid($body = null): string
    {
        $part1 = substr(sha1($_SERVER["DOCUMENT_ROOT"]), 0, 10);
        $part2 = substr(sha1($salt), 0, 10);
        $part3 = sprintf("%x", time());

        $uuid = sprintf("%s_%s_%s", $part1, $part2, $part3);
        return $uuid;
    }
}
