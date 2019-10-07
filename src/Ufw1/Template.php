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
        $loader = new \Twig\Loader\FilesystemLoader($root);
        $this->twig = new \Twig\Environment($loader);

        $this->twig->addFilter(new \Twig\TwigFilter("markdown", function ($src) {
            $html = \Ufw1\Common::renderMarkdown($src);
            return $html;
        }, array("is_safe" => array("html"))));

        $this->twig->addFilter(new \Twig\TwigFilter("filesize", function ($size) {
            if ($size > 1048576)
                return sprintf("%.02f MB", $size / 1048576);
            else
                return sprintf("%.02f KB", $size / 1024);
        }));

        $this->twig->addFilter(new \Twig\TwigFilter("date", function ($ts, $fmt) {
            $ts = $this->parseTimestamp($ts);
            return strftime($fmt, $ts);
        }));

        $this->twig->addFilter(new \Twig\TwigFilter("date_rfc", function ($ts) {
            $ts = $this->parseTimestamp($ts);
            return date(DATE_RSS, $ts);
        }));

        $this->twig->addFilter(new \Twig\TwigFilter("date_simple", function ($ts) {
            $ts = $this->parseTimestamp($ts);
            return strftime("%d.%m.%y, %H:%M", $ts);
        }));

        $this->twig->addFilter(new \Twig\TwigFilter("megabytes", function ($size) {
            return sprintf("%.02f MB", $size / 1048576);
        }));

        $this->twig->addFilter(new \Twig\TwigFilter("sklo", function ($number, $one, $two, $many) {
            return \Ufw1\Util::plural($number, $one, $two, $many);
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

        $this->twig->addFunction(new \Twig\TwigFunction("file_link", function ($node, $version = "original") {
            if ($node["type"] != "file")
                return "";

            if (empty($node["files"][$version]))
                return "";

            $ver = array_merge([
                "storage" => "local",
                "path" => null,
            ], $node["files"][$version]);

            if (empty($ver["path"]))
                return "";

            if ($ver["storage"] == "s3") {
                $settings = $this->container->get("settings");
                if (!empty($settings["S3"]["bucket"])) {
                    $link = "https://{$settings["S3"]["bucket"]}.s3.amazonaws.com/{$ver["path"]}";
                    return $link;
                }
            }

            elseif ($ver["storage"] == "local") {
                return "/node/{$node["id"]}/download/{$version}";
            }

            return "";
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

        if (@$_GET["debug"] == "tpl")
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
}
