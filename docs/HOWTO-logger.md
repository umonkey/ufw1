# Message logger

Simple logger service, implements [PSR-3][1].  Logs to the error log or a configured file.


## Configuration

Simple configuration:

```
// config/settings.php:

return ['settings' => [
    'logger' => [
        'path' => __DIR__ . '/../var/logs/php.log',
    ],
]];
```

Files can have date and hour elements, using special sequences `%Y`, `%m`, `%d` and `%H`.  In this case, a symlink is useful for automation purposes.  Example:

```
// config/settings.php:

return ['settings' => [
    'logger' => [
        'path' => __DIR__ . '/../var/logs/php-%Y%m%d-%H00.log',
        'symlink' => __DIR__ . '/../var/logs/php.log',
    ],
]];
```

[1]: https://www.php-fig.org/psr/psr-3/
