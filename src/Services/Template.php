<?php

declare(strict_types=1);

namespace Ufw1\Services;

use Psr\Container\ContainerInterface;
use Slim\Http\Response;
use Ufw1\Util;

class Template
{
    /**
     * Настройки рендеринга.
     *
     * @var array
     **/
    protected $settings;

    /**
     * Движок Twig.
     *
     * @var Twig\Environment
     **/
    protected $twig;

    /**
     * Данные для шаблонов.
     *
     * @var array
     **/
    protected $defaults;

    public function __construct(array $settings)
    {
        $this->settings = $settings;

        $this->defaults = isset($settings["defaults"])
            ? $settings["defaults"]
            : [];

        $templates = $settings["template_path"];
        $loader = new \Twig\Loader\FilesystemLoader($templates);

        $this->twig = new \Twig\Environment($loader);

        if (!empty($settings['cache_dir'])) {
            $this->twig->setCache($settings['cache_dir']);
        }

        $this->setupFilters();
    }

    /**
     * Рендеринг шаблона.
     *
     * Находит первый существующий шаблон и использует его.
     *
     * @param string|array $templates Список шаблонов, в порядке предпочтения.
     * @param array        $data      Данные для шаблона.
     *
     * @return string Результат обработки.
     **/
    public function render($templates, array $data = array()): string
    {
        if (!is_array($templates)) {
            $templates = [$templates];
        }

        foreach ($templates as $template) {
            foreach ($this->settings['template_path'] as $root) {
                $path = $root . '/' . $template;
                if (is_readable($path)) {
                    return $this->renderFile($template, $data);
                }
            }
        }

        $names = implode(", ", $templates);
        throw new \Twig\Error\LoaderError("unable to find template {$names}");
    }

    public function renderFile(string $fileName, array $data): string
    {
        $data = $this->addDefaults($data);
        $data = array_merge($this->defaults, $data);

        if (($data['request']['get']['debug'] ?? null) == 'tpl') {
            debug([
                'template' => $fileName,
                'variables' => $data,
            ]);
        }

        $template = $this->twig->load($fileName);
        $html = $template->render($data);
        $html = $this->fixHTML($html);

        return $html;
    }

    public static function extractProperties(string $pageName, string $text): array
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

    /**
     * Adds default strings, read from the settings.
     **/
    protected function addDefaults(array $data): array
    {
        $lang = $data['language'] ?? 'en';
        $strings = $this->settings['strings'][$lang] ?? [];
        $data['strings'] = $strings;
        return $data;
    }

    protected function fixHTML(string $html): string
    {
        $html = Util::cleanHtml($html);
        return $html;
    }

    protected function parseTimestamp(string $value): int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }
        return strtotime($value);
    }

    protected function processTypography(string $text): string
    {
        $patterns = [
            '@<p>(.+?)</p>@ms',
            '@<td>(.+?)</td>@ms',
            '@<li>(.+?)</li>@ms'
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function ($m) {
                return Util::processTypography($m[0]);
            }, $text);
        }

        return $text;
    }

    protected function setupFilters(): void
    {
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
            if ($year == $now) {
                $date = sprintf("%u %s", $day, $months[$month - 1]);
            } else {
                $date = sprintf("%u %s %u года", $day, $months[$month - 1], $year);
            }
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
            if ($size > 1048576) {
                return sprintf("%.02f MB", $size / 1048576);
            } else {
                return sprintf("%.02f KB", $size / 1024);
            }
        }));

        // Link to the specified subfile.
        $this->twig->addFunction(new \Twig\TwigFunction("file_link", function ($node, $version = 'original', $missing = '') {
            if ($node["type"] != "file") {
                return $missing;
            }

            if (empty($node["files"][$version])) {
                return $missing;
            }

            $ver = array_merge([
                "storage" => "local",
                "path" => null,
                "url" => null,
            ], $node["files"][$version]);

            if (!empty($ver["url"])) {
                return $ver["url"];
            } elseif ($ver["storage"] == "local") {
                return "/node/{$node["id"]}/download/{$version}";
            }

            return $missing;
        }));

        $this->twig->addFilter(new \Twig\TwigFilter("human_date", function ($dt) {
            if (preg_match('@^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2}) (?<hour>\d{2}):(?<min>\d{2}):(?<sec>\d{2})$@', $dt, $m)) {
                $months = ["января", "февраля", "марта", "апреля", "мая", "июня", "июля", "августа", "сентября", "октября", "ноября", "декабря"];
                $now = strftime("%Y");
                if ($m["year"] == $now) {
                    $date = sprintf("%u %s", $m["day"], $months[$m["month"] - 1]);
                } else {
                    $date = sprintf("%u %s %u", $m["day"], $months[$m["month"] - 1], $m["year"]);
                }
                return $date;
            }

            return $dt;
        }));

        // Convert markdown to html
        $this->twig->addFilter(new \Twig\TwigFilter("markdown", function ($src) {
            $html = Util::renderMarkdown($src);
            return $html;
        }, array("is_safe" => array("html"))));

        $this->twig->addFilter(new \Twig\TwigFilter("megabytes", function ($size) {
            return sprintf("%.02f MB", $size / 1048576);
        }));

        $this->twig->addFilter(new \Twig\TwigFilter("phone", function ($value) {
            $value = preg_replace('@[^0-9]+@', '', $value);

            if ($value[0] == '7') {
                $phone = '+7 (' . substr($value, 1, 3);
                $phone .= ') ' . substr($value, 4, 3);
                $phone .= '-' . substr($value, 7, 2);
                $phone .= '-' . substr($value, 9, 2);
                $value = $phone;
            }

            return $value;
        }));

        $this->twig->addFilter(new \Twig\TwigFilter("price", function ($value) {
            return number_format((float)$value, 0, ',', ' ');
        }));

        // Короткое имя.
        // > Иван Иванов => Инва И.
        // > {{ node.length }} {{ node.length|sklo('байт', 'байта', 'байтов') }}
        $this->twig->addFilter(new \Twig\TwigFilter("short_name", function ($name) {
            $parts = preg_split('@\s+@', trim($name), 2, PREG_SPLIT_NO_EMPTY);
            $name = $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '.';
            return $name;
        }));

        // Чистительные.
        // > {{ node.length }} {{ node.length|sklo('байт', 'байта', 'байтов') }}
        $this->twig->addFilter(new \Twig\TwigFilter("sklo", function ($number, $one, $two, $many) {
            return Util::plural($number, $one, $two, $many);
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
}
