<?php

namespace Ufw1;

class Util
{
    public static function cleanHtml($html)
    {
        // Some typography.
        $html = preg_replace('@\s+--\s+@', '&nbsp;— ', $html);
        $html = preg_replace('@\.  @', '.&nbsp; ', $html);

        // Clean up.
        $html = preg_replace('@\s*<((html|head|body|div|ul|li|p|header|footer|meta|title|aside|form|input|main|h1|h2|h3|h4|h5|table|thead|tbody|tr|td|link|script|!--)(\s+[^>]+)?)>\s*@', '<\\1>', $html);
        $html = preg_replace('@\s*</(html|head|body|div|ul|ol|li|form|input|aside|main|header|footer|table|thead|tbody|tr|td)>\s*@', '</\\1>', $html);

        return $html;
    }

    public static function parseHtmlAttrs($tag)
    {
        $res = [];

        if (preg_match_all('@([a-z-]+)="([^"]+)"@', $tag, $m)) {
            foreach ($m[1] as $idx => $key)
                $res[$key] = trim($m[2][$idx]);
        }

        if (preg_match_all("@([a-z-]+)='([^']+)'@", $tag, $m)) {
            foreach ($m[1] as $idx => $key)
                $res[$key] = trim($m[2][$idx]);
        }

        return $res;
    }

    public static function hyphenate($text, $len = 10)
    {
        $text = preg_replace_callback('@[а-я]+@ui', function ($m) use ($len) {
            $word = $m[0];
            if (mb_strlen($word) < $len)
                return $word;

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

    public static function plural($number, $single, $double, $multiple)
    {
        $titles = array($single, $double, $multiple);
        $cases = array(2, 0, 1, 1, 1, 2);
        return $titles[($number % 100 > 4 && $number % 100 < 20) ? 2 : $cases[min($number % 10, 5)]];
    }

    public static function containerSetup(&$container)
    {
        $container["logger"] = function ($c) {
            $settings = (array)$c->get("settings")["logger"];
            $logger = new \Ufw1\Logger($settings);
            return $logger;
        };

        $container["database"] = function ($c) {
            return new \Ufw1\Database($c->get("settings")["dsn"]);
        };

        $container["errorHandler"] = function ($c) {
            return function ($request, $response, $e) use ($c) {
                $h = new \Ufw1\Handlers\Error($c);
                return $h($request, $response, ["exception" => $e]);
            };
        };

        $container["notFoundHandler"] = function ($c) {
            return function ($request, $response) use ($c) {
                $h = new \Ufw1\Handlers\NotFound($c);
                return $h($request, $response, []);
            };
        };

        $container["node"] = function ($c) {
            return new \Ufw1\NodeFactory($c);
        };

        $container["file"] = function ($c) {
            return new \Ufw1\FileFactory($c);
        };

        $container["template"] = function ($c) {
            $settings = $c->get("settings")["templates"];
            $tpl = new \Ufw1\Template($c);
            return $tpl;
        };
    }
}
