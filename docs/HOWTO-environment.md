# Environment based configuration

Most settings stay in the [config/settings.php][1] file.  You likely want to
store sensitive information, like passwords, in a different file, ignored by
the version control.  It is [believed to be a good idea][3].

For this, you'd usually use code like this in your `settings.php`:

```php
if (is_readable($fn = __DIR__ . '/../.env.' . $_ENV['APP_ENV'] . '.php')) {
    include $fn;
} elseif (is_readable($fn = __DIR__ . '/../.env.php')) {
    include $fn;
}
```

Then you tweak your settings in that file:

```php
$settings['dsn']['user'] = 'www';
```


## Ways to pass environment variables

When running from command line, you just set the environment variable:

```
$ APP_ENV=foobar php bin/some-command.php
```

When using PHP-FPM, you set it in your `pool.conf`:

```
env[APP_ENV] = staging
```

If you need to set it in `nginx.conf`, then use `fastcgi_param`.  However, this would go to the `$_SERVER` array instead, so you'd need to tweak your `settings.php`.

```
fastcgi_param APP_ENV prod;
```


## Alternatives

You migh want to use [phpdotenv][2], if you're OK with replacing 5 lines of code with 30+ files.


[1]: config/settings.php
[2]: https://github.com/vlucas/phpdotenv
[3]: https://www.12factor.net/config
