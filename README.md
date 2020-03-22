# Website parts

This projects contains components which I frequently reuse on my websites.  It's based on [Slim Framework][1], because it's clean, simple and easy to understand.


## Concepts

Uses the ADR pattern (action-domain-responder).  Actions are light-weight controllers, with only one action and no logic: the job is to parse input data and pass it to the domain.  Domain is a service which handles the input data, does the job (probably invoking other services) and returns the response payload.  Reponder renders payload to the actual HTTP response, depending on its contents and other circumstances (XHR, etc).  This all makes the application open for unit testing.

Uses [dependency injection][11], named after [Symfony services][12].  Services are isolate parts of code which are loaded on demand.  Controllers receive arguments extracted from the container by name.  Built in services include database, logger, node and file factory, S3, search engine, stemmer, task queue, Telegram notifications, Twig template renderer, a thumbnailer and a wiki engine.

Uses "nodes", individual pieces of content, such as a page, poll, article, forum topic, or a blog entry.  This is inspired by [Drupal][13], works well and simplifies document management greatly.

Uses [task queue](docs/HOWTO-taskq.md) for background task execution.  There is a separate daemon which monitors the taskq queue table and runs the task handlers.  This lets the application respond quickly, offloading long tasks to the queue worker and serializing tasks.


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
