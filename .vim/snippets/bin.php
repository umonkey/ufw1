#!/usr/bin/env php
<?php

/**
 * Some console script.
 **/

declare(strict_types=1);

$ts = microtime(true);

$container = require __DIR__ . '/../config/bootstrap.php';

fail_on_errors();

// do something ...

$container->logger->info(sprintf("bin/%s: finished in %.2f seconds.\n", basename(__FILE__), microtime(true) - $ts));

die("OK\n");
