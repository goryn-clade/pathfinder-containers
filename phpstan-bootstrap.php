<?php
/**
 * PHPStan bootstrap — loads the pathfinder autoloader and ensures a
 * deprecation-suppressing error handler is active for the entire analysis.
 *
 * F3 registers its own error handler (which escalates E_DEPRECATED to fatal)
 * during autoload. We reinstall our handler after the autoload to override it.
 *
 * Known deprecations we swallow:
 *   - react/promise v2 `case x;` semicolon syntax (deprecated PHP 8.0)
 *   - implicit nullable parameter syntax e.g. `Type $x = null` (deprecated PHP 8.4)
 */
$suppressDeprecations = static function (int $errno): bool {
    return $errno === E_DEPRECATED;
};

set_error_handler($suppressDeprecations);
require_once __DIR__ . '/pathfinder/vendor/autoload.php';
// F3 may have overwritten our handler above — reinstall to override it.
set_error_handler($suppressDeprecations);
