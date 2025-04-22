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
function getAllTables($db = null, $stats = null, $handler = null) // Handler wird hier nicht mehr benötigt
{
    // 1. Instanzen prüfen (wie vorher)
    $dbInstance = ($db instanceof FlatFileDB\FlatFileDatabase) ? $db : ($GLOBALS['db'] ?? null);
    $currentStats = ($stats instanceof FlatFileDB\FlatFileDBStatistics) ? $stats : ($GLOBALS['stats'] ?? null);

    if (!$dbInstance instanceof FlatFileDB\FlatFileDatabase || !$currentStats instanceof FlatFileDB\FlatFileDBStatistics) {
         // Loggen welche Instanz fehlt (wie vorher)
         $missing = [];
         if (!$dbInstance instanceof FlatFileDB\FlatFileDatabase) $missing[] = 'Database Object';
         if (!$currentStats instanceof FlatFileDB\FlatFileDBStatistics) $missing[] = 'Statistics Object';
         error_log('getAllTables FEHLER: Benötigte Objekte fehlen oder sind ungültig. Fehlend/Ungültig: ' . implode(', ', $missing));
         return [];
    }

    $logDbPath = $GLOBALS['currentDataDir'] ?? 'Unbekannt/Nicht gesetzt'; // Korrekte Variable verwenden
    error_log("getAllTables START für DB-Pfad: " . $logDbPath);

    $tableNames = [];
    $source = "Keine Quelle";

    // VERSUCH 1: $dbInstance->getTableNames() (PRIMÄRE QUELLE)
    try {
        $namesFromDb = $dbInstance->getTableNames();
        error_log("getAllTables: \$dbInstance->getTableNames() Roh-Ergebnis: " . print_r($namesFromDb, true));
        if (!empty($namesFromDb) && is_array($namesFromDb) && array_is_list($namesFromDb)) {
            $tableNames = $namesFromDb;
            $source = "dbInstance->getTableNames()";
            error_log("getAllTables: Tabellennamen über \$dbInstance->getTableNames() erhalten: " . print_r($tableNames, true));
        } else {
            error_log("getAllTables INFO: \$dbInstance->getTableNames() gab leeres oder ungültiges Ergebnis zurück.");
        }
    } catch (Exception $e) {
        error_log("getAllTables WARNUNG: Fehler beim Aufruf von \$dbInstance->getTableNames(): " . $e->getMessage());
    }

    // ENDGÜLTIGE PRÜFUNG
    if (empty($tableNames)) {
        error_log("getAllTables ENDGÜLTIG: Keine Tabellennamen gefunden (Quelle: $source). Rückgabe: Leeres Array.");
        return [];
    }
    error_log("getAllTables: Verarbeite endgültig gefundene Tabellennamen (Quelle: $source): " . print_r($tableNames, true));

    // --- Statistiksammlung (angepasst) ---
    $tables = [];
    $allStats = $currentStats->getOverallStatistics(); // <-- sollte jetzt mit neuen Pfaden funktionieren
    error_log("getAllTables: Gesamtstatistiken geladen für Verarbeitung.");

    foreach ($tableNames as $name) {
        $tableStatData = $allStats[$name] ?? null; // Hole die Stats für diese Tabelle

        if ($tableStatData === null || isset($tableStatData['error'])) {
            // Fehler oder keine Stats gefunden, Fallback
            error_log("getAllTables: Keine (gültigen) Statistik-Daten für Tabelle '$name' gefunden. Fallback wird versucht.");
            $tables[] = [
                'name' => $name,
                'created' => 'N/A', // Versuche ggf. ctime des Tabellenverzeichnisses
                'columns' => 'N/A',
                'records' => 'N/A',
                'record_count' => 'N/A'
            ];
            continue; // Nächste Tabelle
        }

        // Berechne Sekundärindexgröße (wie vorher)
        $secondaryIndexSize = 0;
        if (isset($tableStatData['secondary_index_files']) && is_array($tableStatData['secondary_index_files'])) {
            foreach ($tableStatData['secondary_index_files'] as $indexSize) {
                 if (is_int($indexSize)) { // Stelle sicher, dass es eine Zahl ist
                   $secondaryIndexSize += $indexSize;
                 }
            }
        }

         // Spaltenanzahl aus Schema holen (optional, braucht Anpassung in Engine)
         $columnCount = 'N/A';
         try {
             $engine = $dbInstance->table($name); // Engine holen
             if (method_exists($engine, 'getSchema')) { // Prüfen ob Methode existiert
                 $schema = $engine->getSchema();
                 if (isset($schema['types']) && is_array($schema['types'])) {
                     $columnCount = count($schema['types']);
                 }
             }
         } catch (Exception $e) {
             error_log("getAllTables WARNUNG: Fehler beim Holen des Schemas für Spaltenanzahl ($name): " . $e->getMessage());
         }


        $tables[] = [
            'name' => $name,
            'created' => $tableStatData['created'] ?? date("Y-m-d H:i:s", @filectime($GLOBALS['currentDataDir'].'/'.$name)) ?: 'N/A', // Fallback auf Verzeichnis-Zeit
            'columns' => $columnCount,
            'records' => (int) ($tableStatData['record_count'] ?? 0),
            'record_count' => (int) ($tableStatData['record_count'] ?? 0)
        ];
    }

    error_log("getAllTables ENDE: Rückgabe von " . count($tables) . " Tabellen mit Statistikdaten.");
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
    if (!$table instanceof FlatFileDB\FlatFileTableEngine) {
        error_log("getTableIndexNames FEHLER: Ungültiges Tabellen-Engine-Objekt übergeben.");
        return [];
    }
    try {
         // Korrekter Weg, um die Namen der Felder mit Sekundärindizes zu bekommen
        $indexBuilder = $table->getIndexBuilder(); // Holt den IndexBuilder
        return $indexBuilder->getManagedIndexedFields(); // Gibt list<string> zurück
    } catch (Exception $e) {
         error_log("Fehler beim Abrufen der Indexnamen über getIndexBuilder()->getManagedIndexedFields(): " . $e->getMessage());
         return []; // Fallback
    }
}

/**
 * Paginierte Datensätze aus einer Tabelle abrufen
 */
function getTableData($handler, $tableName, $page = 1, $pageSize = PAGE_SIZE, $orderBy = 'id', $order = 'ASC', $filters = [])
{
    $offset = ($page - 1) * $pageSize;

    $query = $handler->table($tableName);

    $allowedOperators = [
        '=',
        '!=',
        '>',
        '<',
        '>=',
        '<=',
        '===',
        '!==',
        'LIKE',
        'NOT LIKE',
        'IN',
        'NOT IN',
        'IS NULL',
        'IS NOT NULL'
    ];

    // Filter anwenden
    if (!empty($filters) && is_array($filters)) {
        foreach ($filters as $filter) {
            // Prüfe, ob alle nötigen Keys vorhanden sind UND ob $filter ein Array ist
            if (is_array($filter) && isset($filter['field'], $filter['operator']) && array_key_exists('value', $filter)) { // value kann null sein, daher array_key_exists
                $field = trim($filter['field']);
                $operator = strtoupper(trim($filter['operator'])); // Großschreibung für Konsistenz
                $value = $filter['value']; // Wert nicht trimmen, könnte beabsichtigt sein

                // 1. Validierung: Feldname (einfacher Check)
                // Erlaubt Buchstaben, Zahlen, Unterstrich. Anpassen, falls komplexere Namen erlaubt sind.
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
                    error_log("getTableData WARNUNG: Ungültiger Filter-Feldname '$field' für Tabelle '$tableName' übersprungen.");
                    continue; // Nächsten Filter prüfen
                }

                // 2. Validierung: Operator
                if (!in_array($operator, $allowedOperators)) {
                    error_log("getTableData WARNUNG: Ungültiger Filter-Operator '$operator' für Tabelle '$tableName' übersprungen.");
                    continue; // Nächsten Filter prüfen
                }

                // 3. Spezifische Validierung für IN / NOT IN
                if (($operator === 'IN' || $operator === 'NOT IN') && !is_array($value)) {
                    error_log("getTableData WARNUNG: Ungültiger Wert für '$operator'-Filter (Array erwartet) für Feld '$field' in Tabelle '$tableName' übersprungen.");
                    continue; // Nächsten Filter prüfen
                }

                // 4. Wert für IS NULL / IS NOT NULL ignorieren (wie in Doku)
                if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
                    $value = null; // Sicherstellen, dass value null ist
                }

                // Nur wenn alles validiert wurde, die Bedingung hinzufügen
                try {
                    $query->where($field, $operator, $value);
                } catch (Exception $e) {
                    // Fehler beim Anwenden des Filters loggen, aber weitermachen
                    error_log("getTableData FEHLER beim Anwenden von Filter (Field: $field, Op: $operator) für Tabelle '$tableName': " . $e->getMessage());
                }
            } else {
                error_log("getTableData WARNUNG: Unvollständiger Filter-Eintrag für Tabelle '$tableName' übersprungen: " . print_r($filter, true));
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
        throw new RuntimeException("Fehler beim Einfügen in '$tableName': " . $e->getMessage(), 0, $e);
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
        return $success;
    } catch (Exception $e) {
        throw new RuntimeException("Fehler beim Aktualisieren von ID $id in '$tableName': " . $e->getMessage(), 0, $e);
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