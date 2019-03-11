<?php

namespace App;

use League\CommonMark\CommonMarkConverter;

class Parser
{
    public static function parse($name, $source)
    {
        list($props, $source) = self::extractProperties($name, $source);

        $md = new CommonMarkConverter();
        $html = $md->convertToHtml($source);

        // Extract page title.
        $html = preg_replace_callback('@<h1>(.+)</h1>@', function ($m) use (&$props) {
            $props["title"] = $m[1];
            return "";
        }, $html, 1);

        // Process wiki links.
        $html = preg_replace_callback('@\[\[(.+?)\]\]@', function ($m) {
            $parts = explode("|", $m[1], 2);

            if (count($parts) == 1) {
                $target = $parts[0];
                $title = $parts[0];
            } else {
                $target = $parts[0];
                $title = $parts[1];
            }

            $link = sprintf("<a class=\"wiki\" href=\"/wiki?name=%s\" title=\"%s\">%s</a>", urlencode($target), htmlspecialchars($title), htmlspecialchars($title));

            return $link;
        }, $html);

        // Some typography.
        $html = preg_replace('@\s+--\s+@', '&nbsp;— ', $html);

        return [$props, $html];
    }

    public static function extractProperties($pageName, $text)
    {
        $props = array(
            "lang" => "en",
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
}
