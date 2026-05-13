<?php
/**
 * PHPStan bootstrap — loads the pathfinder autoloader and suppresses
 * deprecation notices that would cause analysis to blow up.
 *
 * F3 registers its own error handler (which escalates E_DEPRECATED to fatal)
 * during autoload. We reinstall our handler after the autoload to override it.
 */
$suppressDeprecations = static function (int $errno): bool {
    return $errno === E_DEPRECATED;
};

set_error_handler($suppressDeprecations);
require_once __DIR__ . '/pathfinder/vendor/autoload.php';
// F3 may have overwritten our handler above — reinstall.
set_error_handler($suppressDeprecations);
