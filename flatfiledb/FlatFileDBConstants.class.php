<?php
declare(strict_types=1);

namespace FlatFileDB;

use InvalidArgumentException;
use RuntimeException;
use JsonException;
use Throwable;

/**
 * Konfigurationskonstanten für die FlatFile-Datenbank.
 */
class FlatFileDBConstants {
    public const DEFAULT_BASE_DIR    = 'data';
    public const DEFAULT_BACKUP_DIR  = 'data/backups';
    // Hier können ggf. weitere Konstanten definiert werden, z.B. für spezielle Datenverzeichnisse.

    public const LOG_ACTION_INSERT = 'INSERT';
    public const LOG_ACTION_UPDATE = 'UPDATE';
    public const LOG_ACTION_DELETE = 'DELETE';
}