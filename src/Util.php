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
