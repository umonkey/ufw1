<?php

namespace App;

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
            $html = \App\Common::renderMarkdown($src);
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
            return \App\Util::plural($number, $one, $two, $many);
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
            debug($data);

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
        $html = \App\Util::cleanHtml($html);
        return $html;
    }

    protected function parseTimestamp($value)
    {
        if (is_numeric($value))
            return $value;
        return strtotime($value);
    }
}
