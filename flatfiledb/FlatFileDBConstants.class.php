<?php
declare(strict_types=1);

namespace FlatFileDB;

/**
 * Konfigurationskonstanten für die FlatFile-Datenbank.
 */
class FlatFileDBConstants
{
    // Default directory relative to the script or defined base path
    public const DEFAULT_BASE_DIR = __DIR__ . '/../data'; // More specific default

    // Using .jsonl for JSON Lines format is conventional and good
    public const DATA_FILE_EXTENSION = '.jsonl.gz'; // Suggest GZ explicitly in extension if compressed by default
    // Primary index often uses .json
    public const INDEX_FILE_EXTENSION = '.json';
    // Log can also be .jsonl
    public const LOG_FILE_EXTENSION = '.jsonl';

    // Use distinct values
    public const LOG_ACTION_INSERT = 'INSERT';
    public const LOG_ACTION_UPDATE = 'UPDATE';
    public const LOG_ACTION_DELETE = 'DELETE';

    // Default compression level (6 is a good balance)
    public const DEFAULT_COMPRESSION_LEVEL = 6;

    // Chunk size for reading uncompressed files
    public const READ_CHUNK_SIZE = 8192; // 8KB

    // *** NEU: Standard-Verzeichnisberechtigungen ***
    public const DEFAULT_DIR_PERMISSIONS = 0755; // Oder 0775 etc., je nach Bedarf
    public const DEFAULT_BACKUP_DIR = __DIR__ . '/../backups';

    public const MANIFEST_FILE = 'tables.json';
    public const MANIFEST_LOCK_FILE = 'tables.lock';

    public const TABLE_DATA_FILENAME = 'data';     // Wird zu data.jsonl.gz
    public const TABLE_INDEX_FILENAME = 'index';   // Wird zu index.json
    public const TABLE_LOG_FILENAME = 'log';       // Wird zu log.jsonl
}