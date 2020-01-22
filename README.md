# Website parts

This projects contains components which I frequently reuse on my websites.  It's based on [Slim Framework][1].


## Installing

    $ composer require umonkey/ufw1:dev-default


## Services

Custom services are configured in [config/services.php][2], standard services are set up with [Util::containerSetup()][3]

- [Database][5]: raw database access.  Wrapped around PDO.  Has some fetch, insert and update helpers, transactional block.  Detailed docs are [here][4].

[1]: https://www.slimframework.com/
[2]: config/services.php
[3]: src/Util.php
[4]: docs/HOWTO-database.md
[5]: src/Services/Database.php
