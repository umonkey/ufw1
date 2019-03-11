<?php

namespace Ufw1;


class Common
{
    public static function fetch($url)
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

        if (false === $res["data"])
            $res["error"] = error_get_last();

        return $res;
    }

    /**
     * Generate unique id.
     *
     * Not really an UUID, but kind of.  Includes server hash, time hash and content hash.
     *
     * @param string $body File contents.
     * @return string Unique identifier.
     **/
    public static function uuid($body = null)
    {
        $part1 = substr(sha1($_SERVER["DOCUMENT_ROOT"]), 0, 10);
        $part2 = substr(sha1($salt), 0, 10);
        $part3 = sprintf("%x", time());

        $uuid = sprintf("%s_%s_%s", $part1, $part2, $part3);
        return $uuid;
    }

    public static function wikiName($name)
    {
        $res = mb_strtoupper(mb_substr($name, 0, 1))
             . mb_substr($name, 1);
        return $res;
    }

    public static function renderTOC($html)
    {
        $toc = "<h2>Содержание</h2>";
        $level = 1;

        $html = preg_replace_callback('@<h([12345])>(.+)</h([12345])>@', function ($m) use (&$level, &$toc) {
            list($str, $openLevel, $titleText, $closeLevel) = $m;

            if ($openLevel != $closeLevel)
                return $str;

            if ($openLevel == 1)
                return $str;

            if ($openLevel > $level)
                $toc .= str_repeat("<ul>", $openLevel - $level);
            elseif ($openLevel < $level)
                $toc .= str_repeat("</ul>", $level - $openLevel);

            $level = (int)$openLevel;

            $anchor = mb_strtolower(trim(preg_replace('@\s+@', '_', $m[2])));

            $toc .= sprintf("<li><a href='#%s'>%s</a></li>", $anchor, $titleText);

            $code = sprintf("<h%u id='%s'>%s</h%u>", $openLevel, $anchor, $titleText, $openLevel);
            return $code;
        }, $html);

        if ($level > 1)
            $toc .= str_repeat("</ul>", $level - 1);

        $html = str_replace("<div id=\"toc\"></div>", "<div id=\"toc\">{$toc}</div>", $html);

        return $html;
    }

    public static function renderMarkdown($source)
    {
        $environment = \League\CommonMark\Environment::createCommonMarkEnvironment();
        $environment->addExtension(new \Webuni\CommonMark\TableExtension\TableExtension());

        $dp = new \League\CommonMark\DocParser($environment);
        $re = new \League\CommonMark\HtmlRenderer($environment);

        $converter = new \League\CommonMark\Converter($dp, $re);

        $html = $converter->convertToHtml($source);

        return $html;
    }
}
