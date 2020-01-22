# Full text search service

Full text search service lets you index documents and then search for them.  Is available in the dependency container as `fts` (full text search).  Uses the full text functions of MySQL and SQLite, other servers are not supported.


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
