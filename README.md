# Website parts

This projects contains components which I frequently reuse on my websites.  It's based on [Slim Framework][1].


## Concepts

Uses [Slim Framework][1], for it's very fast and simple.

Uses [dependency injection][11], named after [Symfony services][12].  Services are isolate parts of code which are loaded on demand.  Built in services include database, logger, node and file factory, S3, search engine, stemmer, task queue, Telegram notifications, Twig template renderer, a thumbnailer and a wiki engine.

Uses "nodes", individual pieces of content, such as a page, poll, article, forum topic, or a blog entry.  This is first [introduced by Drupal][13], works well and simplifies document management greatly.


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
[11]: https://en.wikipedia.org/wiki/Dependency_injection
[12]: https://symfony.com/doc/current/service_container.html
[13]: https://www.drupal.org/docs/7/nodes-content-types-and-fields/about-nodes
