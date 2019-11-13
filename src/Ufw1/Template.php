<?php

namespace Ufw1;

use \Slim\Http\Response;

class Template
{
    protected $twig;

    protected $defaults;

    protected $container;

    public function __construct($container)
    {
        $this->container = $container;
        $settings = $container->get("settings")["templates"];

        $this->defaults = isset($settings["defaults"])
            ? $settings["defaults"]
            : [];

        $root = $settings["template_path"];
        $fallback = __DIR__ . '/../../templates';
        $loader = new \Twig\Loader\FilesystemLoader([$root, $fallback]);
        $this->twig = new \Twig\Environment($loader);

        // Custom date format:
        // {{ node.created|date('%Y-%m-%d %H:%M:%S') }}
        $this->twig->addFilter(new \Twig\TwigFilter("date", function ($ts, $fmt) {
            $ts = $this->parseTimestamp($ts);
            return strftime($fmt, $ts);
        }));

        // Human readable date:
        // 5 января -- за тот же год,
        // 5 января 2019 -- за другой год.
        $this->twig->addFilter(new \Twig\TwigFilter("date_human", function ($dt) {
            $ts = $this->parseTimestamp($dt);

            $year = strftime('%Y', $ts);
            $month = strftime('%m', $ts);
            $day = strftime('%d', $ts);

            $months = ["января", "февраля", "марта", "апреля", "мая", "июня", "июля", "августа", "сентября", "октября", "ноября", "декабря"];
            $now = strftime("%Y");
            if ($year == $now)
                $date = sprintf("%u %s", $day, $months[$month - 1]);
            else
                $date = sprintf("%u %s %u", $day, $months[$month - 1], $year);
            return $date;
        }));

        // RFC date:
        // Wed, 30 Oct 2019 05:01:51 +0000
        $this->twig->addFilter(new \Twig\TwigFilter("date_rfc", function ($ts) {
            $ts = $this->parseTimestamp($ts);
            return date(DATE_RSS, $ts);
        }));

        // Simple date:
        // 12.04.1961, 09:07
        $this->twig->addFilter(new \Twig\TwigFilter("date_simple", function ($ts) {
            $ts = $this->parseTimestamp($ts);
            return strftime("%d.%m.%y, %H:%M", $ts);
        }));

        $this->twig->addFilter(new \Twig\TwigFilter("filesize", function ($size) {
            if ($size > 1048576)
                return sprintf("%.02f MB", $size / 1048576);
            else
                return sprintf("%.02f KB", $size / 1024);
        }));

        // Link to the specified subfile.
        $this->twig->addFunction(new \Twig\TwigFunction("file_link", function ($node, $version = 'original', $missing = '') {
            if ($node["type"] != "file")
                return $missing;

            if (empty($node["files"][$version]))
                return $missing;

            $ver = array_merge([
                "storage" => "local",
                "path" => null,
                "url" => null,
            ], $node["files"][$version]);

            if (!empty($ver["url"]))
                return $ver["url"];

            elseif ($ver["storage"] == "local") {
                return "/node/{$node["id"]}/download/{$version}";
            }

            return $missing;
        }));

        $this->twig->addFilter(new \Twig\TwigFilter("human_date", function ($dt) {
            if (preg_match('@^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2}) (?<hour>\d{2}):(?<min>\d{2}):(?<sec>\d{2})$@', $dt, $m)) {
                $months = ["января", "февраля", "марта", "апреля", "мая", "июня", "июля", "августа", "сентября", "октября", "ноября", "декабря"];
                $now = strftime("%Y");
                if ($m["year"] == $now)
                    $date = sprintf("%u %s", $m["day"], $months[$m["month"] - 1]);
                else
                    $date = sprintf("%u %s %u", $m["day"], $months[$m["month"] - 1], $m["year"]);
                return $date;
            }

            return $dt;
        }));

        // Convert markdown to html
        $this->twig->addFilter(new \Twig\TwigFilter("markdown", function ($src) {
            $html = \Ufw1\Common::renderMarkdown($src);
            return $html;
        }, array("is_safe" => array("html"))));

        $this->twig->addFilter(new \Twig\TwigFilter("megabytes", function ($size) {
            return sprintf("%.02f MB", $size / 1048576);
        }));

        // Чистительные.
        // > {{ node.length }} {{ node.length|sklo('байт', 'байта', 'байтов') }}
        $this->twig->addFilter(new \Twig\TwigFilter("sklo", function ($number, $one, $two, $many) {
            return \Ufw1\Util::plural($number, $one, $two, $many);
        }));

        // Типографика.
        $this->twig->addFilter(new \Twig\TwigFilter("typo", function ($text) {
            return $this->processTypography($text);
        }));

        // Uppercase first letter.
        // "foo bar" => "Foo bar", for wiki.
        $this->twig->addFilter(new \Twig\TwigFilter("ucfirst", function ($text) {
            $res = mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
            return $res;
        }));
    }

    public function render($fileName, array $data = array())
    {
        $html = $this->renderFile($fileName, $data);
        return $html;
    }

    public function renderFile($fileName, array $data)
    {
        $data = $this->addDefaults($data);
        $data = array_merge($this->defaults, $data);

        if (isset($_GET['debug']) and $_GET['debug'] == 'tpl')
            debug($fileName, $data);

        $template = $this->twig->load($fileName);
        $html = $template->render($data);
        $html = $this->fixHTML($html);

        return $html;
    }

    public static function extractProperties($pageName, $text)
    {
        $props = array(
            "language" => "ru",
            "title" => $pageName,
            );

        $lines = preg_split('@(\r\n|\n)@', $text);
        foreach ($lines as $idx => $line) {
            if (preg_match('@^([a-z0-9_]+):\s+(.+)$@', $line, $m)) {
                $props[$m[1]] = $m[2];
            } elseif ($line == "---") {
                $lines = array_slice($lines, $idx + 1);
                $text = implode("\r\n", $lines);
                break;
            }
        }

        return [$props, $text];
    }

    protected function addDefaults(array $data)
    {
        $fn = $_SERVER["DOCUMENT_ROOT"] . "/settings.php";
        if (file_exists($fn)) {
            $settings = include $fn;
            $lang = $settings["default_language"];
            $defaults = $settings["template_{$lang}"];

            $data = array_merge($defaults, $data);
        }

        return $data;
    }

    protected function fixHTML($html)
    {
        $html = \Ufw1\Util::cleanHtml($html);
        return $html;
    }

    protected function parseTimestamp($value)
    {
        if (is_numeric($value))
            return $value;
        return strtotime($value);
    }

    protected function processTypography($text)
    {
        $handler = function ($m) {
            $text = $m[0];

            // Some typography.
            $text = preg_replace('@\s+--\s+@', '&nbsp;— ', $text);
            $text = preg_replace('@\.  @', '.&nbsp; ', $text);

            // Use nbsp with some words.
            $text = preg_replace('@ (а|В|в|Для|и|из|на|о|от|с)\s+@u', ' \1&nbsp;', $text);
            $text = preg_replace('@\s+(году|год)([.,])@u', '&nbsp;\1\2', $text);

            return $text;
        };

        $text = preg_replace_callback('@<p>(.+?)</p>@', $handler, $text);
        $text = preg_replace_callback('@<td>(.+?)</td>@', $handler, $text);
        $text = preg_replace_callback('@<li>(.+?)</li>@', $handler, $text);

        return $text;
    }
}
