# Full text search service

Full text search service lets you index documents and then search for them.  Is available in the dependency container as `fts` (full text search).  Uses the built in full text indexing functions: [FULLTEXT indexes][2] for MySQL and [FTS5][1] for SQLite, other servers are not supported.  Uses the [Stemmer][3] service to normalize the text.


## Usage

Reindex a document:

```
$key = 'node:123';
$title = 'Hello World';
$body = 'Some document contents.';

$meta = [
    'id' => 123,
    'snippet' => 'This you normally show on the search page...',
    'image' => '/images/placeholder.jpg',
    'language' => 'en',
];

$this->fts->reindexDocument($key, $title, $body, $meta);
```

Search for documents:

```
$query = 'normal';
$results = $this->fts->search($query, 100);
```

Results have keys `key` (some document identifying string) and `meta` (a free form array).


## Aliases

Reads aliases from the `odict` table.  The table contains translations to be made before the text is normalized.  Used to normalize synonims, etc.  Fill it like this:

```
INSERT INTO odict (src, dst) VALUES ('ipod', 'apple');
INSERT INTO odict (src, dst) VALUES ('iphone', 'apple');
```


[1]: https://www.sqlite.org/fts5.html
[2]: https://dev.mysql.com/doc/refman/5.6/en/innodb-fulltext-index.html
[3]: docs/HOWTO-stemmer.md
