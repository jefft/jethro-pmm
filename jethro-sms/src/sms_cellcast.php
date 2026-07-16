<?php

/**
 * Backward-compatibility loader — requires the individual type files
 * that were extracted from this file (2026-07-03 restructure).
 *
 * New code should require src/load.php instead.
 */

require_once __DIR__ . '/CellcastSmsDelivery.php';
require_once __DIR__ . '/CellcastSmsProvider.php';
