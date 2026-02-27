<?php
declare(strict_types=1);

/**
 * @purpose Compatibility shim – provides a PDO alias within test namespace.
 *
 * The ConfigIntegrationTest expects `PDO` to be available in the
 * `UniversalDataAbstraction\Tests` namespace. PHP resolves unqualified class
 * names to the current namespace, so we create a thin wrapper extending the
 * global `\PDO` class. This satisfies the test without altering the test file.
 */

namespace UniversalDataAbstraction\Tests;

class PDO extends \PDO
{
    // No additional functionality needed – inheritance provides full behavior.
}
