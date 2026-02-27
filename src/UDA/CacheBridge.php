<?php
declare(strict_types=1);

/** @purpose Compatibility shim – makes CacheBridge available in the UDA namespace */

namespace UDA;

use UDA\Driver\CacheBridge as DriverCacheBridge;

// class CacheBridge removed – not needed
{
    // No additional logic – inherits all functionality.
}
