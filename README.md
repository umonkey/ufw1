# Website parts

This projects contains components which I frequently reuse on my websites.  It's based on [Slim Framework][1].


## Concepts

Uses [Slim Framework][1].  It's clean, simple, very fast and easy to understand how everything works.

Uses [dependency injection][11], named after [Symfony services][12].  Services are isolate parts of code which are loaded on demand.  Controllers receive arguments extracted from the container by name.  Built in services include database, logger, node and file factory, S3, search engine, stemmer, task queue, Telegram notifications, Twig template renderer, a thumbnailer and a wiki engine.

Uses "nodes", individual pieces of content, such as a page, poll, article, forum topic, or a blog entry.  This is first [introduced by Drupal][13], works well and simplifies document management greatly.


## Services

Custom services are configured in [config/services.php][2], standard services are set up with [Util::containerSetup()][3].

| Key | Class | Description |
|-----|-------|-------------|
| `db` | [Database][5] | Raw database access.  Wraps around PDO.  Has no query builder or anything, designed to use with SQL.  Has some fetch, insert and update helpers, transactional block.  Detailed docs are [here][4]. |
| `file` | [FileRepository][14] | File storage interface.  Represents files stored in the database (as nodes).  Files can have multiple versions (thumbnails), can be stored locally or on S3.  Detailed docs are [here][15]. |
| `fts` | [Search][16] | Full text document index.  Usess MySQL and SQLite built in functions and a custom stemmer.  Can translate aliases.  Detailed docs are [here][17]. |
| `logger` | [Logger][6] | Simple file logger, implements [PSR-3][8].  Files can have date based names.  Details [here][7]. |
| `node` | [NodeRepository][9] | Document storage.  Stores documents as "nodes", in the `nodes` table, Drupal style.  Nodes are of various types, have indexes etc.  Detailed docs are [here][10]. |

## Folder structure

- `bin`: maintenance scripts.
- `config`: various settings.  File names are sound, comments explain details.
- `docs`: documentation on some components.
- `src`: all source files.
- `templates`: built in Twig templates, can be used as fallback in real applications.
- `tests`: phpunit files.
- `vendor`: third party components.


## Installing

    $ composer require umonkey/ufw1:dev-default


## TODO

Major:

- [ ] Switch to action-domain-responder.
- [ ] Uncouple wiki, admin UI, blog etc to separate packages.

Minor:

- [ ] Some common CLI functions.
- [ ] Better unit test coverage.


## Recommended reading

- [The twelve-factor app](https://www.12factor.net/), best practices for developing web applications.


[1]: https://www.slimframework.com/
[2]: config/services.php
[3]: src/Util.php
[4]: docs/HOWTO-database.md
[5]: src/Services/Database.php
[6]: src/Services/Logger.php
[7]: docs/HOWTO-logger.md
[8]: https://www.php-fig.org/psr/psr-3/
[9]: src/Services/NodeRepository.php
[10]: docs/HOWTO-nodes.php
[11]: https://en.wikipedia.org/wiki/Dependency_injection
[12]: https://symfony.com/doc/current/service_container.html
[13]: https://www.drupal.org/docs/7/nodes-content-types-and-fields/about-nodes
[14]: src/Services/FileRepository.php
[15]: docs/HOWTO-files.php
[16]: src/Services/Search.php
[17]: docs/HOWTO-search.md
[srp]: https://en.wikipedia.org/wiki/Single_responsibility_principle
[slp]: https://en.wikipedia.org/wiki/Service_locator_pattern
