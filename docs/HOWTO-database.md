# Database access

Database access code lies in the `Database` service class, usually accessible with the "db" key in the dependency container.

In controllers, it's accessible using `$this->db`.

Normally you don't need to subclass this.


## Configuration

DSN is defined in the `dsn` section of the settings, e.g.:

```
// config/settings.php:

return ['settings' => [
    // SQLite:
    'dsn' => [
        'name' => 'sqlite:' . __DIR__ . '/../var/database.sqlite',
    ],

    // MySQL:
    'dsn' => [
        'name' => 'mysql:dbname=example',
        'user' => 'www',
        'password' => 'www',
    ],
]];
```


## Fetching data

Multiple rows:

```
$rows = $db->fetch('SELECT * FROM table WHERE id = ?', [1]);
```

You can map the rows with a callback:

```
$rows = $db->fetch('SELECT * FROM table WHERE id > ?', [1], function (array $row): array {
    return [
        'id' => "record nr. {$row['id']}",
    ];
});
```

Key-value fetch helper:

```
$prices = $db->fetchkv('SELECT id, price FROM products');
```

Fetch the first record only:

```
$row = $db->fetchOne('SELECT * FROM products ORDER BY date LIMIT 1');
```

Fetch only the first cell of the first row:

```
$id = $db->fetchCel('SELECT id FROM products ORDER BY price DESC LIMIT 1');
```


## Manipulating data

Raw query, to fetch huge amounts of data or perform other query types:

```
$sel = $db->query('SELECT * FROM products');
while ($row = $sel->fetch(\PDO::FETCH_ASSOC)) {
    var_dump($row);
}
```

Insert a record in the database without constructing the statement:

```
$id = $db->insert('products', [
    'name' => 'foo',
]);
```

Update rows without constructing the statement:

```
$count = $db->update('products', [
    'name' => 'new name',
], [
    'id' => 1,
]);
```

Prepare a statement for later execution:

```
$st = $db->prepare('INSERT INTO products (id) VALUES (?)');
foreach ($products as $id) {
    $st->execute([$id]);
}
```


## Transactions

Manual transactions:

```
$db->beginTransaction();
if (do_something()) {
    $db->commit();
} else {
    $db->rollback();
}
```

Callback wrapper, commits on no exception:

```
$db->transact(function ($db) {
    // do something
});
```


# Other actions

Get connection type:

```
$type = $db->getConnectionType();
```

Get database statistics, rows and bytes per table:

```
$stats = $db->getStats();
```
