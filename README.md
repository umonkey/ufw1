# Website parts

This projects contains components which I frequently reuse on my websites.  It's based on [Slim Framework][1].


## Installing

    $ composer require umonkey/ufw1:dev-default


## Services

Custom services are configured in [config/services.php][2], standard services are set up with [Util::containerSetup()][3].

- [Database][5], key `db`: raw database access.  Wraps around PDO.  Has no query builder or anything, designed to use with SQL.  Has some fetch, insert and update helpers, transactional block.  Detailed docs are [here][4].
- [Logger][6], key `logger`: simple file logger, implements [PSR-3][8].  Files can have date based names.  Details [here][7].

| Key | Class | Description |
|-----|-------|-------------|
| `db` | [Database][5] | Raw database access.  Wraps around PDO.  Has no query builder or anything, designed to use with SQL.  Has some fetch, insert and update helpers, transactional block.  Detailed docs are [here][4]. |

## Folder structure

- `bin`: maintenance scripts.
- `config`: various settings.  File names are sound, comments explain details.
- `docs`: documentation on some components.
- `src`: all source files.
- `templates`: built in Twig templates, can be used as fallback in real applications.
- `tests`: phpunit files.
- `vendor`: third party components.


[1]: https://www.slimframework.com/
[2]: config/services.php
[3]: src/Util.php
[4]: docs/HOWTO-database.md
[5]: src/Services/Database.php
[6]: src/Services/Logger.php
[7]: docs/HOWTO-logger.md
[8]: https://www.php-fig.org/psr/psr-3/
