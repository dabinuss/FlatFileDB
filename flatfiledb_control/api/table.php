<?php
require_once '../init.php';
requireAjax();

$action = isset($_POST['action']) ? $_POST['action'] : '';
error_log("Table API called with action: " . $action . " for DB: " . ($GLOBALS['currentDb'] ?? 'NONE'));

// Prüfen, ob eine DB ausgewählt ist - wichtig für alle Aktionen außer 'list'? (list sollte DBs auflisten)
if ($action !== 'list' && (!isset($db) || !$db instanceof FlatFileDB\FlatFileDatabase)) {
    outputJSON(['error' => 'Keine gültige Datenbank ausgewählt oder initialisiert.']);
    exit;
}


switch ($action) {
    case 'create':
        // Tabelle erstellen
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';
        $defineSchema = isset($_POST['schema']) && $_POST['schema'] === 'true';
        $columnsJson = ($defineSchema && isset($_POST['columns'])) ? $_POST['columns'] : null;
        $schemaToSet = null; // Vorbereiten für späteres Setzen
    
        // --- Grundlegende Prüfungen ---
        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
            exit;
        }
        // Regex Prüfung im Handler/DB ist robuster, aber hier ok
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $tableName)) { // Angepasste Regex
            outputJSON(['error' => 'Ungültiger Tabellenname. Nur Buchstaben, Zahlen, Unterstrich, Punkt, Bindestrich erlaubt.']);
            exit;
        }
        if (!isset($db) || !$db instanceof FlatFileDB\FlatFileDatabase) {
            outputJSON(['error' => 'Interner Serverfehler: Datenbankobjekt nicht initialisiert.']);
            exit;
        }
        // --- Ende Grundlegende Prüfungen ---
    
        try {
            // 1. Prüfen, ob Tabelle bereits existiert (jetzt über die DB-Instanz)
            if ($db->hasTable($tableName)) {
                outputJSON(['error' => "Tabelle '$tableName' existiert bereits."]);
                exit;
            }
    
            // 2. Schema vorbereiten (falls definiert)
            if ($defineSchema && !empty($columnsJson)) {
                error_log("Verarbeite Schema für '$tableName'. Empfangene Daten: " . $columnsJson);
                $schemaData = json_decode($columnsJson, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($schemaData)) {
                    $parsedTypes = [];
                    $parsedRequired = [];
                    foreach ($schemaData as $col) {
                         if (isset($col['name'], $col['type']) && is_string($col['name']) && !empty(trim($col['name'])) && is_string($col['type'])) {
                            $fieldName = trim($col['name']);
                             // Hier einfache Validierung, FlatFileValidator wäre besser
                             if (preg_match('/^[a-zA-Z0-9_]+$/', $fieldName)) {
                                $parsedTypes[$fieldName] = $col['type'];
                                if (isset($col['required']) && $col['required'] === true) {
                                    $parsedRequired[] = $fieldName;
                                }
                             } else {
                                 error_log("WARNUNG: Ungültiger Feldname im Schema übersprungen: " . $fieldName);
                             }
                        }
                    }
                    if (!empty($parsedTypes)) {
                        $schemaToSet = ['requiredFields' => $parsedRequired, 'fieldTypes' => $parsedTypes];
                        error_log("Schema für Tabelle '$tableName' erfolgreich vorbereitet.");
                    } else {
                        error_log("Keine validen Schema-Felder gefunden für '$tableName'.");
                    }
                } else {
                    error_log("WARNUNG: Konnte gesendete Schema-Daten für '$tableName' nicht dekodieren. JSON-Fehler: " . json_last_error_msg());
                }
            } else {
                error_log("Kein Schema definiert oder keine Schema-Daten empfangen für '$tableName'.");
            }
    
    
            // 3. Tabelle über die Bibliothek registrieren/erstellen
            // Dies erstellt das Verzeichnis, die Dateien und aktualisiert das Manifest.
            error_log("Registriere Tabelle '$tableName' über DB-Instanz...");
            $tableEngine = $db->registerTable($tableName);
            if (!$tableEngine instanceof FlatFileDB\FlatFileTableEngine) {
                // Sollte nicht passieren, wenn keine Exception geworfen wird
                throw new Exception("registerTable für '$tableName' gab kein gültiges Engine-Objekt zurück.");
            }
            error_log("Tabelle '$tableName' erfolgreich bei DB-Instanz registriert.");
    
            // 4. Schema setzen (falls vorbereitet)
            if ($schemaToSet !== null) {
                error_log("Setze vorbereitetes Schema für Tabelle '$tableName'...");
                // Verwende den Handler, um das Schema zu setzen
                if (!isset($handler) || !$handler instanceof FlatFileDB\FlatFileDatabaseHandler) {
                    throw new Exception("Handler-Objekt nicht verfügbar zum Setzen des Schemas.");
                }
                $handler->table($tableName)->setSchema($schemaToSet['requiredFields'], $schemaToSet['fieldTypes']);
                error_log("Schema für Tabelle '$tableName' erfolgreich gesetzt.");
                // HINWEIS: Abhängig davon, ob setSchema der Bibliothek persistent ist!
            }
    
            // 5. Erfolgsantwort senden
            outputJSON(['success' => true, 'message' => "Tabelle '$tableName' erfolgreich erstellt und registriert.", 'tableName' => $tableName]);
            exit;
    
        } catch (Exception $e) {
            // Allgemeine Fehlerbehandlung
            error_log("API Fehler in api/table.php (Action: create, Table: $tableName): " . $e->getMessage() . "\n" . $e->getTraceAsString());
            // Cleanup Versuch: NUR wenn Tabelle registriert wurde, wieder entfernen
            try {
                if ($db->hasTable($tableName)) {
                     error_log("Cleanup: Versuche Tabelle '$tableName' nach Fehler zu deregistrieren...");
                     $db->unregisterTable($tableName); // Entfernt aus Instanz und Manifest
                     error_log("Cleanup: Tabelle '$tableName' deregistriert.");
                     // Optional: Verzeichnis löschen (kann fehlschlagen, wenn nicht leer)
                     $tableDir = ($GLOBALS['currentDataDir'] ?? DATA_DIR) . '/' . $tableName;
                     if(is_dir($tableDir)) @rmdir($tableDir);
                }
            } catch (Exception $cleanupEx) {
                 error_log("Cleanup Fehler nach Exception beim Erstellen von '$tableName': " . $cleanupEx->getMessage());
            }
    
            outputJSON(['error' => "Fehler beim Erstellen der Tabelle '$tableName': " . $e->getMessage()]);
            exit;
        }
        // break; // Nicht mehr erreichbar

    case 'schema':
        // Schema einer Tabelle setzen oder aktualisieren
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';
        $requiredFieldsJson = isset($_POST['required_fields']) ? $_POST['required_fields'] : null; // Erwartet JSON String ['feld1', 'feld2']
        $schemaJson = isset($_POST['schema']) ? $_POST['schema'] : null; // Erwartet JSON String {'feld1':'typ1', 'feld2':'typ2'}

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
            exit;
        }

        // Dekodiere required_fields JSON
        $requiredFields = [];
        if ($requiredFieldsJson !== null) {
            $decoded = json_decode($requiredFieldsJson, true);
            // Prüfe auf JSON Fehler UND ob das Ergebnis ein Array ist
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $requiredFields = $decoded;
            } else {
                outputJSON(['error' => 'Ungültiges Format für required_fields (JSON-Array erwartet). Fehler: ' . json_last_error_msg()]);
                exit;
            }
        } else {
            outputJSON(['error' => 'Parameter required_fields fehlt.']);
            exit;
        }


        // Dekodiere schema JSON
        $fieldTypes = []; // Variable umbenannt für Klarheit in setSchema
        if ($schemaJson !== null) {
            $decoded = json_decode($schemaJson, true);
            // Prüfe auf JSON Fehler UND ob das Ergebnis ein Array ist (assoziative Arrays sind Arrays in PHP)
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $fieldTypes = $decoded; // $fieldTypes ist jetzt das assoziative Array ['feld'=>'typ', ...]
            } else {
                outputJSON(['error' => 'Ungültiges Format für schema (JSON-Objekt erwartet). Fehler: ' . json_last_error_msg()]);
                exit;
            }
        } else {
            outputJSON(['error' => 'Parameter schema fehlt.']);
            exit;
        }

        // Wichtige Prüfung: Wenn Schema deaktiviert wurde, kommen leere Arrays/Objekte
        // $table->setSchema([], []) sollte das Schema löschen/deaktivieren

        try {
            if (!$db->hasTable($tableName)) {
                outputJSON(['error' => "Tabelle '$tableName' existiert nicht"]);
                exit;
            }

            $table = $db->table($tableName);
            // Übergibt die nun korrekt verarbeiteten Arrays/Objekte
            // Die setSchema Methode in der Bibliothek muss diese korrekt verarbeiten und die _meta.json aktualisieren!
            $table->setSchema($requiredFields, $fieldTypes);
            error_log("Schema für Tabelle '$tableName' gesetzt. Required: " . json_encode($requiredFields) . ", Types: " . json_encode($fieldTypes));

            outputJSON(['success' => true, 'message' => 'Schema erfolgreich aktualisiert.']);
            exit;
        } catch (Exception $e) {
            error_log("API Fehler in api/table.php (Action: schema, Table: $tableName): " . $e->getMessage());
            outputJSON(['error' => 'Fehler beim Speichern des Schemas: ' . $e->getMessage()]);
            exit;
        }
        // break; // Ende case 'schema'


    case 'list':
        // Alle Tabellen auflisten
        try {
            // Stelle sicher, dass $db initialisiert ist, auch für 'list'
            if (!isset($db) || !$db instanceof FlatFileDB\FlatFileDatabase) {
                // Versuche, die erste verfügbare DB zu laden, wenn keine ausgewählt ist
                // Diese Logik ist jetzt in init.php, aber als Fallback hier:
                $databases = getAllDatabases(DATA_DIR);
                if (!empty($databases)) {
                    try {
                        $db = new FlatFileDB\FlatFileDatabase($databases[0]['path']);
                        // Initialisiere auch $stats und $handler neu, falls benötigt
                        $stats = new FlatFileDB\FlatFileDBStatistics($db);
                        $handler = new FlatFileDB\FlatFileDatabaseHandler($db);
                        error_log("Table API 'list': Fallback-DB geladen: " . $databases[0]['name']);
                    } catch (Exception $initEx) {
                        error_log("Table API 'list': Fehler beim Laden der Fallback-DB: " . $initEx->getMessage());
                        outputJSON(['error' => 'Keine Datenbank initialisiert und Fallback fehlgeschlagen.', 'tables' => []]);
                        exit;
                    }
                } else {
                    outputJSON(['error' => 'Keine Datenbank verfügbar.', 'tables' => []]);
                    exit;
                }
            }
            // Nun sollten $db, $stats, $handler gültig sein
            $tables = getAllTables($db, $stats, $handler);
            outputJSON(['success' => true, 'tables' => $tables]);
            exit;
        } catch (Exception $e) {
            error_log("API Fehler in api/table.php (Action: list): " . $e->getMessage());
            outputJSON(['error' => 'Fehler beim Auflisten der Tabellen: ' . $e->getMessage(), 'tables' => []]);
            exit;
        }
        // break;

    // ... andere Cases (delete, clear, compact, info, backup) bleiben wie im vorherigen Code ...
    case 'delete':
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
            exit;
        }

        // Stellen Sie sicher, dass der Handler verfügbar ist
        if (!isset($handler) || !$handler instanceof FlatFileDB\FlatFileDatabaseHandler) {
            error_log("API Fehler in api/table.php (Action: delete, Table: $tableName): Handler-Objekt nicht verfügbar.");
            outputJSON(['error' => 'Interner Serverfehler: Datenbank-Handler nicht initialisiert.']);
            exit;
        }


        try {
            // 1. Prüfen, ob Tabelle existiert (optional, dropTable könnte das selbst tun)
            //    Wir können die Prüfung beibehalten, um eine spezifischere Fehlermeldung zu geben,
            //    oder uns auf den try-catch verlassen. Behalten wir sie zur Sicherheit.
            if (!$db->hasTable($tableName)) { // Prüft die Registrierung über $db
                outputJSON(['error' => "Tabelle '$tableName' ist nicht registriert oder existiert nicht."]);
                exit;
            }

            // 2. Tabelle löschen über den Handler - KORREKTE METHODE
            //    Der Tabellenname muss zweimal übergeben werden.
            $success = $handler->table($tableName) // Wähle die Tabelle
                ->dropTable($tableName); // Führe die Löschaktion aus

            // Die Methode gibt laut Doku true bei Erfolg zurück und wirft eine Exception bei Fehler.
            // Wenn wir hier ankommen ohne Exception, war es erfolgreich.
            if ($success === true) { // Explizite Prüfung auf true
                error_log("Tabelle '$tableName' erfolgreich über Handler gelöscht.");
                outputJSON(['success' => true, 'message' => "Tabelle '$tableName' erfolgreich gelöscht."]);
                exit;
            } else {
                // Dieser Fall sollte eigentlich nicht eintreten, wenn bei Fehler eine Exception geworfen wird.
                // Aber sicherheitshalber loggen und Fehler melden.
                error_log("Tabelle '$tableName' konnte nicht gelöscht werden (dropTable gab nicht true zurück).");
                outputJSON(['error' => "Unbekannter Fehler beim Löschen der Tabelle '$tableName'."]);
                exit;
            }

        } catch (RuntimeException $e) { // Fange die spezifische Exception ab
            error_log("API Fehler (RuntimeException) in api/table.php (Action: delete, Table: $tableName): " . $e->getMessage());
            outputJSON(['error' => 'Fehler beim Löschen der Tabelle: ' . $e->getMessage()]);
            exit;
        } catch (Exception $e) { // Fange andere mögliche Exceptions ab
            error_log("API Allgemeiner Fehler in api/table.php (Action: delete, Table: $tableName): " . $e->getMessage());
            outputJSON(['error' => 'Allgemeiner Fehler beim Löschen der Tabelle: ' . $e->getMessage()]);
            exit;
        }
        // break; // Ende case 'delete'


    case 'clear':
        // Tabelle leeren
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
            exit;
        }

        try {
            if (!$db->hasTable($tableName)) {
                outputJSON(['error' => "Tabelle '$tableName' existiert nicht"]);
                exit;
            }

            // Tabelle leeren
            $table = $db->table($tableName);
            // Die clearTable Methode sollte die Datendatei leeren und Metadaten (records) aktualisieren
            $table->clearTable();

            outputJSON(['success' => true, 'message' => "Tabelle '$tableName' erfolgreich geleert."]);
            exit;
        } catch (Exception $e) {
            error_log("API Fehler in api/table.php (Action: clear, Table: $tableName): " . $e->getMessage());
            outputJSON(['error' => 'Fehler beim Leeren der Tabelle: ' . $e->getMessage()]);
            exit;
        }
        // break;


    case 'compact':
        // Tabelle kompaktieren (Code scheint ok)
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
            exit;
        }

        try {
            if (!$db->hasTable($tableName)) {
                outputJSON(['error' => "Tabelle '$tableName' existiert nicht"]);
                exit;
            }

            $table = $db->table($tableName);
            $table->compactTable();

            outputJSON(['success' => true, 'message' => "Tabelle '$tableName' erfolgreich kompaktiert."]);
            exit;
        } catch (Exception $e) {
            error_log("API Fehler in api/table.php (Action: compact, Table: $tableName): " . $e->getMessage());
            outputJSON(['error' => 'Fehler beim Kompaktieren der Tabelle: ' . $e->getMessage()]);
            exit;
        }
        // break;

    case 'info':
        // Tabellen-Informationen abrufen (Code scheint ok, benötigt $stats)
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
            exit;
        }

        try {
            if (!$db->hasTable($tableName)) {
                outputJSON(['error' => "Tabelle '$tableName' existiert nicht"]);
                exit;
            }
            if (!isset($stats) || !$stats instanceof FlatFileDB\FlatFileDBStatistics) {
                throw new Exception("Statistik-Objekt nicht verfügbar.");
            }

            $tableStats = $stats->getTableStatistics($tableName);

            if ($tableStats === null) {
                throw new Exception("Tabellen-Informationen nicht gefunden für '$tableName'");
            }
            outputJSON(['success' => true, 'info' => $tableStats]);
            exit;
        } catch (Exception $e) {
            error_log("Fehler beim Abrufen der Tabelleninfo für $tableName: " . $e->getMessage());
            outputJSON(['error' => $e->getMessage()]);
            exit;
        }
        // break;

    case 'backup':
        // Tabelle sichern (Code scheint ok, benötigt BACKUP_DIR Konstante)
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
            exit;
        }

        try {
            if (!$db->hasTable($tableName)) {
                outputJSON(['error' => "Tabelle '$tableName' existiert nicht"]);
                exit;
            }
            if (!defined('BACKUP_DIR')) {
                throw new Exception("Backup-Verzeichnis (BACKUP_DIR) ist nicht definiert.");
            }
            $backupDir = BACKUP_DIR; // Verwende die Konstante
            if (!is_dir($backupDir)) {
                if (!@mkdir($backupDir, 0755, true)) {
                    throw new Exception("Backup-Verzeichnis konnte nicht erstellt werden: " . $backupDir);
                }
            }

            $table = $db->table($tableName);
            $backupFiles = $table->backup($backupDir);

            outputJSON(['success' => true, 'message' => "Backup für Tabelle '$tableName' erstellt.", 'files' => $backupFiles]);
            exit;
        } catch (Exception $e) {
            error_log("API Fehler in api/table.php (Action: backup, Table: $tableName): " . $e->getMessage());
            outputJSON(['error' => 'Fehler beim Sichern der Tabelle: ' . $e->getMessage()]);
            exit;
        }
        // break;


    default:
        outputJSON(['error' => 'Ungültige Aktion: ' . $action]);
        exit;
        // break;
}