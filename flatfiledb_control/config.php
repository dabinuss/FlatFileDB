<?php
// Konfiguration für das FlatFileDB Control Center
define('DB_BASE_PATH', dirname(__DIR__) . '/flatfiledb'); // Pfad zu deiner FlatFileDB-Bibliothek
define('DATA_DIR', dirname(__DIR__) . '/data'); // Pfad zum Datenverzeichnis
define('BACKUP_DIR', dirname(__DIR__) . '/backups'); // Pfad für Backups
define('PAGE_SIZE', 25); // Standard-Seitengröße für Datensatzanzeige
define('MAX_LOG_ENTRIES', 100); // Maximale Anzahl der anzuzeigenden Log-Einträge
define('DEBUG_MODE', true); // Debug-Modus aktivieren/deaktivieren