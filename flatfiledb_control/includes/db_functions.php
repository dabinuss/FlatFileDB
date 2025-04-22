<?php
// Hilfsfunktionen für Datenbankoperationen

/**
 * Liste aller Tabellen mit Statistiken abrufen (Verwendet die Bibliotheks-API)
 *
 * @param FlatFileDB\FlatFileDatabase|null $db Die Datenbankinstanz.
 * @param FlatFileDB\FlatFileDBStatistics|null $stats Die Statistikinstanz.
 * @param FlatFileDB\FlatFileDatabaseHandler|null $handler Die Handlerinstanz.
 * @return array Liste der Tabelleninformationen.
 */
function getAllTables($db = null, $stats = null, $handler = null) {
    // 1. Instanzen und Pfad prüfen
    $dbInstance = ($db instanceof FlatFileDB\FlatFileDatabase) ? $db : ($GLOBALS['db'] ?? null);
    $currentStats = ($stats instanceof FlatFileDB\FlatFileDBStatistics) ? $stats : ($GLOBALS['stats'] ?? null);
    $currentHandler = ($handler instanceof FlatFileDB\FlatFileDatabaseHandler) ? $handler : ($GLOBALS['handler'] ?? null);

    if (!isset($GLOBALS['currentDataDir']) || !is_string($GLOBALS['currentDataDir']) || !is_dir($GLOBALS['currentDataDir'])) { // Prüfe auch ob Verzeichnis existiert
        error_log('getAllTables FEHLER: Globale Variable $currentDataDir ist nicht gesetzt, kein String oder kein gültiges Verzeichnis.');
        return [];
    }
    $currentDataDir = $GLOBALS['currentDataDir'];
    $tablesDir = $currentDataDir . '/tables'; // Definieren des korrekten Unterverzeichnisses

    // 2. Strikte Prüfung der Objekte
    if (!$dbInstance instanceof FlatFileDB\FlatFileDatabase ||
        !$currentStats instanceof FlatFileDB\FlatFileDBStatistics ||
        !$currentHandler instanceof FlatFileDB\FlatFileDatabaseHandler) {
        // Loggen, welche Instanz fehlt... (wie vorher)
        $missing = [];
        if (!$dbInstance instanceof FlatFileDB\FlatFileDatabase) $missing[] = 'Database Object (Type: ' . gettype($dbInstance) . ')';
        if (!$currentStats instanceof FlatFileDB\FlatFileDBStatistics) $missing[] = 'Statistics Object (Type: ' . gettype($currentStats) . ')';
        if (!$currentHandler instanceof FlatFileDB\FlatFileDatabaseHandler) $missing[] = 'Handler Object (Type: ' . gettype($currentHandler) . ')';
        error_log('getAllTables FEHLER: Benötigte Objekte fehlen oder sind ungültig. Fehlend/Ungültig: ' . implode(', ', $missing));
        return [];
    }

    $logDbPath = isset($GLOBALS['currentDataDir']) && !empty($GLOBALS['currentDataDir']) ? $GLOBALS['currentDataDir'] : 'Unbekannt/Nicht gesetzt';
    error_log("getAllTables START für DB-Pfad: " . $logDbPath); // <-- Korrigiert
    
    $tableNames = [];
    $source = "Keine Quelle"; // Debugging-Info

    // VERSUCH 1: tables.json direkt lesen (wahrscheinlichste Quelle)
    $tablesJsonPath = $tablesDir . '/tables.json';
    if (file_exists($tablesJsonPath) && is_readable($tablesJsonPath)) {
        $content = @file_get_contents($tablesJsonPath);
        if ($content !== false) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // Nur nicht-leere Arrays verwenden
                if (!empty($decoded)) {
                    $tableNames = $decoded;
                    $source = "tables.json";
                    error_log("getAllTables: Tabellennamen erfolgreich aus '$tablesJsonPath' gelesen: " . print_r($tableNames, true));
                } else {
                    error_log("getAllTables INFO: '$tablesJsonPath' ist leer oder enthält leeres JSON-Array '[]'.");
                }
            } else {
                error_log("getAllTables WARNUNG: '$tablesJsonPath' konnte nicht als JSON-Array dekodiert werden. Fehler: " . json_last_error_msg());
            }
        } else {
            error_log("getAllTables WARNUNG: Konnte '$tablesJsonPath' nicht lesen (file_get_contents fehlgeschlagen).");
        }
    } else {
        error_log("getAllTables INFO: '$tablesJsonPath' nicht gefunden oder nicht lesbar.");
    }

    // VERSUCH 2: $dbInstance->getTableNames() (falls tables.json nicht erfolgreich war ODER leer war)
    if (empty($tableNames)) { // Prüft weiterhin, ob $tableNames noch leer ist
        error_log("getAllTables: Versuche Tabellennamen via \$dbInstance->getTableNames()..."); // <-- NEU
        try {
            $namesFromDb = $dbInstance->getTableNames();
            // Loggen, was genau zurückkommt, auch wenn es leer ist
            error_log("getAllTables: \$dbInstance->getTableNames() Roh-Ergebnis: " . print_r($namesFromDb, true)); // <-- NEU
            if (!empty($namesFromDb) && is_array($namesFromDb)) { // Zusätzliche is_array Prüfung
                $tableNames = $namesFromDb;
                $source = "dbInstance->getTableNames()";
                error_log("getAllTables: Tabellennamen über \$dbInstance->getTableNames() erhalten und verwendet: " . print_r($tableNames, true));
            } else {
                 error_log("getAllTables INFO: \$dbInstance->getTableNames() gab leeres oder ungültiges Ergebnis zurück. Verwende es nicht.");
            }
        } catch (Exception $e) {
             error_log("getAllTables WARNUNG: Fehler beim Aufruf von \$dbInstance->getTableNames(): " . $e->getMessage());
        }
    }

    // VERSUCH 3: Fallback-Scan (wenn immer noch keine Namen gefunden wurden)
    if (empty($tableNames)) { // Prüft weiterhin, ob $tableNames noch leer ist
        error_log("getAllTables: Weder tables.json noch getTableNames() erfolgreich. Versuche Fallback-Scan in '$tablesDir'...");
        // ... (Code für Fallback-Scan bleibt gleich) ...
        if (!empty($foundNames)) { // Nur wenn Scan erfolgreich war
             error_log("getAllTables Fallback: Gefundene Tabellennamen durch Scan: " . print_r($foundNames, true));
             $tableNames = $foundNames; // Verwende die gescannten Namen
             $source = "Fallback Dateisystem-Scan";
        } else {
             error_log("getAllTables Fallback: Keine Tabellennamen durch Scan gefunden."); // <-- NEU
        }
    }


    // ENDGÜLTIGE PRÜFUNG: Wenn immer noch leer, gibt es wirklich keine Tabellen
    if (empty($tableNames)) {
        error_log("getAllTables ENDGÜLTIG: Keine Tabellennamen gefunden (Endgültige Quelle: $source). Rückgabe: Leeres Array."); // <-- NEU
        return [];
    }
    error_log("getAllTables: Verarbeite endgültig gefundene Tabellennamen (Quelle: $source): " . print_r($tableNames, true)); // <-- NEU

    // --- Ab hier Verarbeitung der gefundenen $tableNames ---
    $tables = [];
    // Prüfen, ob $currentStats gültig ist, BEVOR die Schleife beginnt
    if (!$currentStats instanceof FlatFileDB\FlatFileDBStatistics) {
        error_log("getAllTables FEHLER bei Statistiksammlung: Statistik-Objekt ungültig.");
        // Fallback: Tabellen ohne Stats zurückgeben? Oder leeres Array?
        // Hier geben wir Namen ohne Stats zurück
        foreach ($tableNames as $name) {
             $tables[] = ['name' => $name, 'created' => 'N/A', 'columns' => 'N/A', 'records' => 'N/A', 'record_count' => 'N/A'];
        }
        error_log("getAllTables WARNUNG: Gebe Tabellennamen ohne Statistikdaten zurück, da Statistik-Objekt fehlt.");
        return $tables;
    }
    // Hole Gesamtstatistiken nur einmal
    $allStats = $currentStats->getOverallStatistics();
    error_log("getAllTables: Gesamtstatistiken geladen für Verarbeitung."); // <-- NEU


    foreach ($tableNames as $name) {
        // Verwende $tablesDir für Pfade zu den Dateien!
         $tableFilePath = $tablesDir.'/'.$name.'.json';
         $metaFilePath = $tablesDir.'/'.$name.'_meta.json';

         // Initialize with defaults
         $tableStat = [
             'name' => $name, // Name hinzufügen für leichtere Zuordnung
             'record_count' => 0,
             'created' => 'N/A',
             'columns' => 0 // Standard auf 0 statt 'N/A'
         ];

         if (isset($allStats[$name]) && is_array($allStats[$name])) { // Prüfe, ob Statistik-Eintrag existiert und ein Array ist
             $tableStat['record_count'] = (int)($allStats[$name]['record_count'] ?? 0);
             $tableStat['created'] = $allStats[$name]['created'] ?? date("Y-m-d H:i:s", @filectime($tableFilePath)) ?: 'N/A';
             if (isset($allStats[$name]['columns']) && is_array($allStats[$name]['columns'])) { // Prüfe, ob 'columns' existiert und ein Array ist
                 $tableStat['columns'] = count($allStats[$name]['columns']);
             } elseif (isset($allStats[$name]['types']) && is_array($allStats[$name]['types'])) { // Fallback auf 'types'
                 $tableStat['columns'] = count($allStats[$name]['types']);
             }
         } else {
              error_log("getAllTables: Keine Statistik-Daten für Tabelle '$name' gefunden. Versuche Fallbacks.");
              // Fallback für Erstellungsdatum
              if (file_exists($tableFilePath)) {
                   $tableStat['created'] = date("Y-m-d H:i:s", @filectime($tableFilePath));
              }
              // Fallback für Spaltenanzahl (wie vorher)
              try {
                  // Prüfe, ob Handler gültig ist
                  if (!$currentHandler instanceof FlatFileDB\FlatFileDatabaseHandler) {
                      throw new Exception("Handler-Objekt ungültig für Spaltenanzahl-Fallback.");
                  }
                  // Hier könnte man direkt die Meta-Datei lesen, statt einen Datensatz zu holen
                  if (file_exists($metaFilePath)) {
                       $metaContent = @file_get_contents($metaFilePath);
                       $metaData = $metaContent ? json_decode($metaContent, true) : null;
                       if ($metaData && isset($metaData['types']) && is_array($metaData['types'])) {
                           $tableStat['columns'] = count($metaData['types']);
                       } elseif ($metaData && isset($metaData['columns']) && is_array($metaData['columns'])) { // Fallback auf 'columns' in meta
                           $tableStat['columns'] = count($metaData['columns']);
                       }
                  }
                  // Wenn immer noch 0, versuche Datensatz (wie vorher)
                  if ($tableStat['columns'] === 0) {
                      $sampleRecord = $currentHandler->table($name)->limit(1)->find();
                      if (!empty($sampleRecord) && isset($sampleRecord[0]) && is_array($sampleRecord[0])) {
                          $tableStat['columns'] = count($sampleRecord[0]);
                      }
                  }

              } catch (Exception $e) {
                  error_log("getAllTables: Konnte Spaltenanzahl für Tabelle '$name' nicht ermitteln (Fallback): " . $e->getMessage());
                  // Bleibt bei Default 0
              }
         }

         $tables[] = [
             'name' => $name,
             'created' => $tableStat['created'],
             'columns' => $tableStat['columns'],
             'records' => $tableStat['record_count'],
             'record_count' => $tableStat['record_count'] // Doppelt, aber wird im Frontend verwendet
         ];
    }
    error_log("getAllTables ENDE: Rückgabe von " . count($tables) . " Tabellen mit Statistikdaten."); // <-- NEU
    return $tables;
}

/**
 * Tabellenschema abrufen (falls vorhanden)
 */
function getTableSchema($handler, $tableName)
{
    // Prüfen, ob Handler gültig ist (optional, aber gut zur Fehlersuche)
    if (!$handler instanceof FlatFileDB\FlatFileDatabaseHandler) {
         error_log("getTableSchema FEHLER: Ungültiges Handler-Objekt übergeben für Tabelle '$tableName'. Typ: " . gettype($handler));
         return [];
    }
    // Prüfung auf Tabellenexistenz erfolgt implizit durch den Handler oder wirft Exception

    try {
        // Direkter Aufruf der dokumentierten Handler-Methode
        $schema = $handler->table($tableName)->getSchema();

        // Sicherstellen, dass immer ein Array zurückgegeben wird
        if (!is_array($schema)) {
             error_log("getTableSchema WARNUNG: Handler->getSchema() für Tabelle '$tableName' gab keinen Array zurück. Typ: " . gettype($schema));
             return [];
        }
        // Sicherstellen, dass die erwarteten Keys existieren (optional)
        if (!isset($schema['required']) || !isset($schema['types'])) {
             error_log("getTableSchema WARNUNG: Schema-Array für Tabelle '$tableName' hat nicht die erwarteten Keys 'required' und 'types'.");
             // Eventuell leere Defaults setzen, wenn Keys fehlen?
             $schema['required'] = $schema['required'] ?? [];
             $schema['types'] = $schema['types'] ?? [];
        }

        return $schema; // Gibt Array ['required' => [...], 'types' => [...]] zurück
    } catch (Exception $e) {
        // Loggen Sie den Fehler, geben Sie aber ein leeres Array zurück, um Frontend-Fehler zu vermeiden
        error_log("Fehler beim Holen des Schemas via Handler für Tabelle '$tableName': " . $e->getMessage());
        return []; // Leeres Array bei Fehler
    }
}

/**
 * Tabellenschema abrufen über Tabellen-Objekt
 * 
 * @param FlatFileDB\FlatFileTableEngine $table Das Tabellen-Objekt
 * @return array Das Schema
 */
function getTableSchemaFromTable($table)
{
    $schema = [];

    if (method_exists($table, 'getRequiredFields')) {
        $schema['required'] = $table->getRequiredFields();
    } else {
        $schema['required'] = []; // Default
    }

    if (method_exists($table, 'getFieldTypes')) {
        $schema['types'] = $table->getFieldTypes();
    } else {
        $schema['types'] = []; // Default
    }

    // Kein findOne/first Check mehr

    if (empty($schema['required']) && empty($schema['types'])) {
        return [];
    }

    return $schema;
}

/**
 * Index-Namen einer Tabelle abrufen
 * 
 * @param FlatFileDB\FlatFileTableEngine $table Das Tabellen-Objekt
 * @return array Die Liste der Index-Namen
 */
function getTableIndexNames($table)
{
    // Methoden testen, die wahrscheinlich existieren könnten
    if (method_exists($table, 'getIndices')) {
        return $table->getIndices();
    }

    if (method_exists($table, 'getAllIndices')) {
        return array_keys($table->getAllIndices());
    }

    if (method_exists($table, 'listIndices')) {
        return $table->listIndices();
    }

    if (method_exists($table, 'getIndexedFields')) {
        return $table->getIndexedFields();
    }

    // Fallback: Leeres Array zurückgeben
    return [];
}

/**
 * Paginierte Datensätze aus einer Tabelle abrufen
 */
function getTableData($handler, $tableName, $page = 1, $pageSize = PAGE_SIZE, $orderBy = 'id', $order = 'ASC', $filters = [])
{
    $offset = ($page - 1) * $pageSize;

    $query = $handler->table($tableName);

    // Filter anwenden
    if (!empty($filters) && is_array($filters)) {
        foreach ($filters as $filter) {
            if (isset($filter['field']) && isset($filter['operator']) && isset($filter['value'])) {
                $query->where($filter['field'], $filter['operator'], $filter['value']);
            }
        }
    }

    // Paginierung und Sortierung
    $query->orderBy($orderBy, $order)
        ->limit($pageSize)
        ->offset($offset);

    // Gesamtanzahl (kann bei großen Tabellen langsam sein)
    $totalCountQuery = clone $query;
    $totalCount = $totalCountQuery->count();

    // Datensätze abrufen
    $records = $query->find();

    return [
        'records' => $records,
        'total' => $totalCount,
        'page' => $page,
        'pageSize' => $pageSize,
        'totalPages' => ceil($totalCount / $pageSize)
    ];
}

/**
 * Datensatz in Tabelle einfügen
 */
function insertRecord($handler, $tableName, $data)
{
    try {
        $id = $handler->table($tableName)
            ->data($data)
            ->insert();
        return ['success' => true, 'id' => $id];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Datensatz in Tabelle aktualisieren
 */
function updateRecord($handler, $tableName, $id, $data)
{
    try {
        $success = $handler->table($tableName)
            ->where('id', '=', $id)
            ->data($data)
            ->update();
        return ['success' => $success];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Datensatz in Tabelle löschen
 */
function deleteRecord($handler, $tableName, $id)
{
    try {
        $success = $handler->table($tableName)
            ->where('id', '=', $id)
            ->delete();
        return ['success' => $success];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Formatiert Bytes in lesbare Größe
 */
function formatBytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Tabelle kompaktieren
 */
function compactTable($db, $tableName)
{
    try {
        $table = $db->table($tableName);
        $success = $table->compactTable();
        return ['success' => $success];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Backup einer Tabelle erstellen
 */
function backupTable($db, $tableName)
{
    try {
        if (!is_dir(BACKUP_DIR)) {
            mkdir(BACKUP_DIR, 0755, true);
        }

        $table = $db->table($tableName);
        $backupFiles = $table->backup(BACKUP_DIR);
        return ['success' => true, 'files' => $backupFiles];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Transaktionslog einer Tabelle abrufen
 */
function getTableLog($db, $tableName, $limit = MAX_LOG_ENTRIES)
{
    try {
        if (!$db->hasTable($tableName)) {
            return ['error' => "Tabelle '$tableName' nicht gefunden für Log-Abruf."];
        }
        $table = $db->table($tableName);
        // Erstelle neue Instanz über Config (konsistent und sicher)
        $log = new FlatFileDB\FlatFileTransactionLog($table->getConfig());
        $entries = $log->readLog($limit);
        return ['success' => true, 'entries' => $entries];
    } catch (Exception $e) {
        error_log("Fehler beim Lesen des Logs für $tableName: " . $e->getMessage());
        return ['error' => 'Fehler beim Lesen des Transaktionslogs.'];
    }
}

/**
 * Erstellt eine neue Datenbank (erstellt das benötigte Verzeichnis und minimale Struktur)
 *
 * @param string $dbName Der Name der Datenbank (wird als Verzeichnisname verwendet)
 * @param string $basePath Optional: Der Basispfad, unter dem die Datenbank erstellt werden soll
 * @return array Erfolgs- oder Fehlermeldung
 */
function createDatabase($dbName, $basePath = DATA_DIR)
{
    // Überprüfen, ob der Name gültig ist (nur Buchstaben, Zahlen, Unterstriche)
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
        return ['error' => 'Ungültiger Datenbankname. Nur Buchstaben, Zahlen und Unterstriche sind erlaubt.'];
    }

    // Pfad zum Datenbankverzeichnis
    $dbPath = rtrim($basePath, '/') . '/' . $dbName;

    // Prüfen, ob Verzeichnis bereits existiert
    if (is_dir($dbPath)) {
        return ['error' => 'Eine Datenbank mit diesem Namen existiert bereits.'];
    }

    // Verzeichnis erstellen
    if (!mkdir($dbPath, 0755, true)) {
        // Versuch eines Cleanups, falls teilweise erstellt
        if (is_dir($dbPath))
            @rmdir($dbPath);
        return ['error' => 'Fehler beim Erstellen des Haupt-Datenbankverzeichnisses. Prüfen Sie die Berechtigungen für: ' . $basePath];
    }

    // Unterverzeichnisse erstellen
    $directories = ['tables', 'indexes', 'logs', 'backups'];
    $createdDirs = []; // Zum Aufräumen im Fehlerfall
    foreach ($directories as $dir) {
        $subDirPath = $dbPath . '/' . $dir;
        if (!mkdir($subDirPath, 0755, true)) {
            // Cleanup bei Fehler
            foreach ($createdDirs as $createdDir)
                @rmdir($createdDir);
            @rmdir($dbPath);
            return ['error' => "Fehler beim Erstellen des Unterverzeichnisses '$dir'. Prüfen Sie die Berechtigungen für: " . $dbPath];
        }
        $createdDirs[] = $subDirPath; // Erfolgreich erstelltes Verzeichnis merken
    }

    // Leere Konfigurationsdatei erstellen
    $configFile = $dbPath . '/config.json';
    $config = [
        'name' => $dbName,
        'created' => date('Y-m-d H:i:s'),
        'version' => '1.0' // Beispiel-Version
    ];
    if (@file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT)) === false) {
        // Cleanup bei Fehler
        foreach ($createdDirs as $createdDir)
            @rmdir($createdDir);
        @rmdir($dbPath);
        return ['error' => 'Fehler beim Erstellen der Konfigurationsdatei (config.json).'];
    }
    @chmod($configFile, 0664); // Berechtigungen anpassen


    // *** NEU: Erstelle eine leere tables.json im 'tables' Unterverzeichnis ***
    $tablesListFile = $dbPath . '/tables/tables.json';
    if (@file_put_contents($tablesListFile, '[]') === false) { // Leeres JSON-Array
        // Cleanup bei Fehler
        @unlink($configFile);
        foreach ($createdDirs as $createdDir)
            @rmdir($createdDir);
        @rmdir($dbPath);
        return ['error' => 'Fehler beim Erstellen der Tabellenliste (tables/tables.json).'];
    }
    @chmod($tablesListFile, 0664); // Berechtigungen anpassen


    // Erfolg
    return [
        'success' => true,
        'message' => "Datenbank '$dbName' wurde erfolgreich erstellt.",
        'path' => $dbPath
    ];
}

/**
 * Löscht eine Datenbank vollständig (löscht das gesamte Verzeichnis)
 * 
 * @param string $dbName Der Name der zu löschenden Datenbank
 * @param string $basePath Optional: Der Basispfad, in dem die Datenbank liegt
 * @return array Erfolgs- oder Fehlermeldung
 */
function deleteDatabase($dbName, $basePath = DATA_DIR)
{
    // Sicherheitsüberprüfung für den Datenbanknamen
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
        return ['error' => 'Ungültiger Datenbankname.'];
    }

    // Pfad zum Datenbankverzeichnis
    $dbPath = rtrim($basePath, '/') . '/' . $dbName;

    // Prüfen, ob Verzeichnis existiert
    if (!is_dir($dbPath)) {
        return ['error' => 'Die angegebene Datenbank existiert nicht.'];
    }

    // Rekursives Löschen des Verzeichnisses
    if (!deleteDirectory($dbPath)) {
        return ['error' => 'Fehler beim Löschen der Datenbank.'];
    }

    return [
        'success' => true,
        'message' => "Datenbank '$dbName' wurde erfolgreich gelöscht."
    ];
}

/**
 * Hilfsfunktion zum rekursiven Löschen eines Verzeichnisses
 * 
 * @param string $dir Das zu löschende Verzeichnis
 * @return bool True bei Erfolg, False bei Fehler
 */
function deleteDirectory($dir)
{
    if (!file_exists($dir)) {
        return true;
    }

    if (!is_dir($dir)) {
        return unlink($dir);
    }

    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }

        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }

    return rmdir($dir);
}

/**
 * Listet alle verfügbaren Datenbanken auf (scannt nur Verzeichnisse und config.json).
 * Versucht, Dateisystem-Caching zu umgehen.
 *
 * @param string $basePath Optional: Der Basispfad, in dem die Datenbanken liegen
 * @return array Liste der Datenbanken mit Name, Pfad, Erstelldatum und Größe
 */
function getAllDatabases($basePath = DATA_DIR)
{
    $databases = [];
    // Cache für Dateistatus löschen, um sicherzustellen, dass wir aktuelle Infos bekommen
    clearstatcache(); // <-- NEU

    if (!is_dir($basePath)) {
        error_log("getAllDatabases: Basisverzeichnis nicht gefunden oder kein Verzeichnis: $basePath");
        return $databases;
    }
    if (!is_readable($basePath)) {
        error_log("getAllDatabases: Basisverzeichnis nicht lesbar: $basePath");
        return $databases;
    }

    // Sicherere Methode, Verzeichnisse zu lesen
    $items = @scandir($basePath); // <-- NEU: Fehler unterdrücken und prüfen
    if ($items === false) {
        error_log("getAllDatabases: Fehler beim Lesen des Verzeichnisses mit scandir: $basePath");
        return $databases; // <-- NEU: Abbruch bei Fehler
    }

    $directories = [];
    foreach ($items as $item) {
        // Überspringe '.' und '..' sowie versteckte Dateien/Verzeichnisse
        if ($item === '.' || $item === '..' || strpos($item, '.') === 0) {
            continue;
        }
        $fullPath = $basePath . DIRECTORY_SEPARATOR . $item;
        // Explizit prüfen, ob es ein Verzeichnis ist
        if (is_dir($fullPath)) { // <-- Stellt sicher, dass es ein Verzeichnis ist
            $directories[] = $fullPath;
        }
    }
    // Debug: Gefundene Verzeichnisse loggen
    // error_log("getAllDatabases: Gefundene Verzeichnisse in $basePath: " . print_r($directories, true));


    foreach ($directories as $dir) {
        // Erneutes Cache-Clearing vor jeder Prüfung kann helfen, ist aber langsam
        // clearstatcache(true, $dir);
        $name = basename($dir);
        $configFile = $dir . '/config.json';
        $tablesDir = $dir . '/tables'; // <-- KORRIGIERT: Pfad zum Unterverzeichnis

        // Prüfe, ob notwendige Dateien/Ordner existieren, um es als gültige DB zu werten
        // Mindestens config.json oder tables/tables.json sollte da sein? Oder nur das Verzeichnis?
        // Hier nehmen wir an, dass das Verzeichnis selbst reicht, wenn es im DATA_DIR liegt.

        $info = [
            'name' => $name,
            'path' => $dir, // <-- Wichtig: Korrekter Pfad für die DB-Instanz
            'size' => 'N/A',
            'tables' => 0,
            'created' => 'N/A' // Geändert zu N/A statt leerem String
        ];

        // Größe (optional, bleibt bei Fehler auf N/A)
        try {
            if (function_exists('directorySize')) { // <-- Prüfen ob Funktion existiert
                $info['size'] = formatBytes(directorySize($dir));
            }
        } catch (Exception $e) {
            error_log("Fehler bei directorySize für '$dir': " . $e->getMessage());
        }

        // Konfig lesen
        if (file_exists($configFile) && is_readable($configFile)) {
            $content = @file_get_contents($configFile);
            if ($content !== false) {
                $config = json_decode($content, true);
                if ($config && isset($config['created'])) {
                    $info['created'] = $config['created'];
                } else {
                    error_log("getAllDatabases: Konnte 'created' nicht aus config.json für '$name' lesen oder JSON ungültig.");
                }
            } else {
                error_log("getAllDatabases: Konnte config.json für '$name' nicht lesen (file_get_contents fehlgeschlagen).");
            }
        } else {
            error_log("getAllDatabases: config.json für '$name' nicht gefunden oder nicht lesbar.");
            // Optional: Lese Erstellungsdatum des Verzeichnisses als Fallback
            $info['created'] = date("Y-m-d H:i:s", filectime($dir));
        }

        // Tabellenzählung via glob() im Unterverzeichnis 'tables'
        $info['tables'] = 0; // Reset für jeden Durchlauf
        if (is_dir($tablesDir) && is_readable($tablesDir)) { // <-- Prüfe 'tables' Unterverzeichnis
            // Zähle nur *.json Dateien, die KEINE Meta-Dateien sind und NICHT tables.json
            $tableFiles = glob($tablesDir . '/*.json');
            if ($tableFiles !== false) {
                $count = 0;
                foreach ($tableFiles as $file) {
                    // Zähle nur .json Dateien, die NICHT _meta.json oder tables.json sind
                    if (basename($file) !== 'tables.json' && strpos(basename($file), '_meta.json') === false) {
                        $count++;
                    }
                }
                $info['tables'] = $count;
            } else {
                error_log("getAllDatabases: Fehler beim Lesen des 'tables'-Verzeichnisses für '$name' mit glob().");
                $info['tables'] = 'Fehler'; // Oder 0?
            }
        } else {
            error_log("getAllDatabases: 'tables'-Verzeichnis für '$name' nicht gefunden oder nicht lesbar: $tablesDir");
        }


        $databases[] = $info;
    } // Ende foreach

    // Debug: Ergebnis loggen
    // error_log("getAllDatabases: Ergebnis: " . print_r($databases, true));

    return $databases;
}

/**
 * Berechnet die Größe eines Verzeichnisses
 * 
 * @param string $dir Das Verzeichnis
 * @return int Größe in Bytes
 */
function directorySize($dir)
{
    $size = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $size += $file->getSize();
    }

    return $size;
}