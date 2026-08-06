<?php

/**
 * The document `phext start` serves, and a template for a real deployment's.
 *
 * Under PHP's built-in server this also has to answer for static files, which
 * `Server::handle()` does; behind Apache or nginx those never reach PHP at
 * all and this file only ever renders.
 */

declare(strict_types=1);

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

use PhpJs\PhextCli\Config;
use PhpJs\PhextCli\Server;

$config = Config::load((string)(getenv('PHEXT_ROOT') ?: getcwd()));
$app = $config->app(getenv('PHEXT_NO_CACHE') ? '' : null);

exit((new Server($app))->handle($_SERVER, $_GET) >= 500 ? 1 : 0);
