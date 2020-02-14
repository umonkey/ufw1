<?php

/**
 * Full text search API.
 *
 * PLEASE.  No custom code here.  This should be easily reusable.
 *
 * To find something, use search().
 * To update an index entry, use reindexDocument().
 * To update the whole database, use reindexAll().
 **/

declare(strict_types=1);

namespace Ufw1\Services;

use Psr\Log\LoggerInterface;

class Search
{
    /**
     * @var Database
     **/
    protected $database;

    /**
     * @var LoggerInterface
     **/
    protected $logger;

    /**
     * @var Stemmer
     **/
    protected $stemmer;

    /**
     * @var Wiki
     **/
    protected $wiki;

    private static $stopWords = ["а", "и", "о", "об", "в", "на", "под", "из"];

    public function __construct(Database $database, LoggerInterface $logger, Stemmer $stemmer, Wiki $wiki)
    {
        $this->database = $database;
        $this->logger = $logger;
        $this->stemmer = $stemmer;
        $this->wiki = $wiki;
    }

    public function search(string $query, int $limit = 100): array
    {
        // TODO: synonims
        $query = $this->normalizeText($query);

        switch ($this->database->getConnectionType()) {
            case "mysql":
                // https://dev.mysql.com/doc/refman/5.5/en/fulltext-boolean.html
                $query = "+" . str_replace(" ", " +", $query);
                $sql = "SELECT `key`, `meta`, MATCH(`title`) AGAINST (:query IN BOOLEAN MODE) * 10 AS `trel`, MATCH(`body`) AGAINST (:query IN BOOLEAN MODE) AS `brel` FROM `search` WHERE `key` NOT LIKE 'page:File:%' HAVING `trel` > 0 OR `brel` > 0 ORDER BY `trel` DESC, `brel` DESC LIMIT {$limit}";
                $params = [
                    ":query" => $query,
                ];
                break;
            case "sqlite":
                $sql = "SELECT `key`, `meta` FROM `search` WHERE `search` MATCH :query ORDER BY bm25(`search`, 0, 0, 50) * CASE WHEN `title` = :query THEN 100 ELSE 1 END LIMIT {$limit}";
                $params = [":query" => $query];
                break;
        }

        $rows = $this->database->fetch($sql, $params, function ($em) {
            return [
                "key" => $em["key"],
                "meta" => unserialize($em["meta"]),
            ];
        });

        return $rows;
    }

    public function suggest(string $query, int $limit = 10): array
    {
        // TODO: synonims
        $query = $this->normalizeText($query);
        $query2 = $query . '*';

        switch ($this->database->getConnectionType()) {
            case "mysql":
                // https://dev.mysql.com/doc/refman/5.5/en/fulltext-boolean.html

                $_q = $query . '*';
                $rows1 = $this->database->fetch("SELECT `key`, `title`, `meta`, MATCH(`title`) AGAINST (:query IN BOOLEAN MODE) * 10 AS `trel` FROM `search` HAVING `trel` > 0 ORDER BY `trel` DESC LIMIT {$limit}", [':query' => $_q]);

                $_q = "+" . str_replace(" ", " +", $this->normalizeText($query) . '*');
                $rows2 = $this->database->fetch("SELECT `key`, `title`, `meta`, MATCH(`title`) AGAINST (:query IN BOOLEAN MODE) * 10 AS `trel`, MATCH(`body`) AGAINST (:query IN BOOLEAN MODE) AS `brel` FROM `search` HAVING `trel` > 0 OR `brel` > 0 ORDER BY `trel` DESC, `brel` DESC LIMIT {$limit}", [':query' => $_q]);

                break;

            case "sqlite":
                $rows1 = $this->database->fetch("SELECT `key`, `title`, `meta` FROM `search` WHERE `search` MATCH ? OR `search` MATCH ? ORDER BY bm25(`search`, 0, 0, 50) LIMIT {$limit}", [$query, $query2]);
                $rows2 = [];
                break;
        }

        $rows = array_merge($rows1, $rows2);

        $rows = array_slice($rows, 0, 10);

        $rows = array_map(function ($em) {
            return [
                "key" => $em["key"],
                "meta" => unserialize($em["meta"]),
            ];
        }, $rows);

        return $rows;
    }

    /**
     * Reindex a document.
     *
     * @param string $key Document key.
     * @param string $title Document title.
     * @param string $body Document body (plain text).
     * @param array $meta Extra data, optional.
     * @return void
     **/
    public function reindexDocument(string $key, string $title, string $body, array $meta = []): void
    {
        $meta['words'] = count(preg_split('@\s+@', $body, -1, PREG_SPLIT_NO_EMPTY));
        $meta = $meta ? serialize($meta) : null;

        $this->database->query("DELETE FROM `search` WHERE `key` = ?", [$key]);

        if ($title and $body) {
            $title = $this->normalizeText($title);
            $body = $this->normalizeText($body);
            $this->database->query("INSERT INTO `search` (`key`, `meta`, `title`, `body`) VALUES (?, ?, ?, ?)", [$key, $meta, $title, $body]);

            $this->logger->debug("search: page [{key}] reindexed.", [
                "key" => $key,
            ]);
        } else {
            $this->logger->debug("search: page [{key}] removed from index.", [
                "key" => $key,
                "title" => $title,
            ]);
        }
    }

    /**
     * Reindex a single node.
     *
     * @param array $args Node spec, in 'id'.
     **/
    public function reindexNode(array $node): void
    {
        // TODO: only configured types.

        if ($node['type'] == 'wiki') {
            $this->reindexWikiNode($node);
        } else {
            $this->logger->debug("search: don't know how to reindex node of type {0}", [$node['type']]);
        }
    }

    protected function reindexWikiNode(array $node): void
    {
        $page = $this->wiki->renderPage($node);

        if (!empty($page['redirect']) or empty($page['source'])) {
            $title = $text = null;
            $meta = [];
        } else {
            $html = $page['html'];

            // strip_tags mishandles scripts, and we use them heavily for microdata,
            // so just strip them off in advance.
            $html = preg_replace('@<script.*?</script>@', '', $html);

            $html = str_replace("><", "> <", $html);
            $text = trim(strip_tags($html));

            $name = $page['name'];
            $title = $page['title'];
            $snippet = $page['snippet'];  // TODO

            $meta = [
                'title' => $title,
                'link' => '/wiki?name=' . urlencode($name),
                'snippet' => $snippet,
                'updated' => $node['updated'],
                'image' => null,
            ];
        }

        $this->reindexDocument("node:" . $node["id"], $title, $text, $meta);
    }

    /**
     * Reindex all documents.
     *
     * @todo Move transaction code outside.
     **/
    public function reindexAll(array $items): void
    {
        $this->logger->debug("search: normalizing {count} documents.", [
            "count" => count($items),
        ]);

        $items = array_map(function ($item) {
            $item["title"] = $this->normalizeText($item["title"]);
            $item["body"] = $this->normalizeText($item["body"]);
            return $item;
        }, $items);

        $this->logger->debug("search: adding {count} documents to the index.", [
            "count" => count($items),
        ]);

        $this->database->beginTransaction();
        $this->database->query("DELETE FROM `search`");

        foreach ($items as $item) {
            $this->logger->debug("search: adding {key} to the index.", [
                "key" => $item["key"],
            ]);

            $this->database->insert("search", [
                "key" => $item["key"],
                "meta" => serialize($item["meta"]),
                "title" => $item["title"],
                "body" => $item["body"],
            ]);
        }

        $this->database->commit();

        $this->logger->debug("search: index upddated, has {count} documents now.", [
            "count" => count($items),
        ]);
    }

    public function normalizeText(string $text): string
    {
        if ($words = $this->splitWords($text)) {
            if ($words = $this->normalizeWords($words)) {
                $words = array_unique($words);
                return implode(" ", $words);
            }
        }

        return "";
    }

    /**
     * Split text into separate words.
     *
     * TODO: don't, etc.
     *
     * @param string $text Source text.
     * @return array Words, lower case, excluding stop words.
     **/
    protected function splitWords(string $text): array
    {
        $text = mb_strtolower($text);
        $words = preg_split('@[^a-zабвгдеёжзийклмнопрстуфхцчшщыьэъюя0-9]+@u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $words = array_diff($words, self::$stopWords);
        return $words;
    }

    /**
     * Returns normalized words.
     *
     * First, reads aliases from the database.
     * Second, uses the porter stemmer.
     *
     * @param array $words Source words.
     * @return array Normalized words.
     **/
    protected function normalizeWords(array $words): array
    {
        static $aliases = null;

        if ($aliases === null) {
            $aliases = $this->database->fetchkv("SELECT `src`, `dst` FROM `odict` LIMIT 10000");
        }

        foreach ($words as $k => $word) {
            if (array_key_exists($word, $aliases)) {
                $words[$k] = $aliases[$word];
                continue;
            }

            if (strspn($word, "abcdefghijklmnopqrstuvwxyz") == strlen($word)) {
                // TODO: use english porter.
            } else {
                $base = $this->stemmer->getWordBase($word);
                $words[$k] = $base;
            }
        }

        return $words;
    }
}
