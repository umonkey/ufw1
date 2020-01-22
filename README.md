# Website parts

This projects contains components which I frequently reuse on my websites.  It's based on [Slim Framework][1].


## Installing

    $ composer require umonkey/ufw1:dev-default


## Services

Custom services are configured in [config/services.php][2], standard services are set up with [Util::containerSetup()][3].

| Key | Class | Description |
|-----|-------|-------------|
| `db` | [Database][5] | Raw database access.  Wraps around PDO.  Has no query builder or anything, designed to use with SQL.  Has some fetch, insert and update helpers, transactional block.  Detailed docs are [here][4]. |
| `logger` | [Logger][6] | Simple file logger, implements [PSR-3][8].  Files can have date based names.  Details [here][7]. |
| `node` | [NodeFactory][9] | Document storage.  Stores documents as "nodes", in the `nodes` table, Drupal style.  Nodes are of various types, have indexes etc.  Detailed docs are [here][10]. |

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
[9]: src/Services/NodeFactory.php
[10]: docs/HOWTO-nodes.php
