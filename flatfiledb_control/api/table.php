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
        // Empfange 'columns' als JSON-String vom Frontend
        $columnsJson = ($defineSchema && isset($_POST['columns'])) ? $_POST['columns'] : null;
        $tableFile = null;
        $metaFile = null;
        $registered = false; // Flag, ob Registrierung erfolgreich war

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
            exit;
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            outputJSON(['error' => 'Ungültiger Tabellenname. Nur Buchstaben, Zahlen und Unterstriche sind erlaubt.']);
            exit;
        }

        try {
            // 1. Prüfen, ob Tabelle bereits existiert (sowohl in der DB-Instanz als auch physisch)
            $tableFilePath = $currentDataDir . '/tables/' . $tableName . '.json'; // Pfad zur Datendatei
            if ($db->hasTable($tableName) || file_exists($tableFilePath)) { // <-- Prüfe beides
                outputJSON(['error' => "Tabelle '$tableName' existiert bereits (entweder registriert oder als Datei)."]);
                exit;
            }

            // 2. Physische Dateien sicherstellen und initialisieren
            $tablesDir = $currentDataDir . '/tables'; // Pfad zum 'tables' Unterverzeichnis
            if (!is_dir($tablesDir)) {
                if (!@mkdir($tablesDir, 0755, true)) {
                    throw new Exception("Konnte das 'tables' Unterverzeichnis nicht erstellen: " . $tablesDir);
                }
            }
            $tableFile = $tablesDir . '/' . $tableName . '.json'; // Korrekter Pfad
            $metaFile = $tablesDir . '/' . $tableName . '_meta.json'; // Korrekter Pfad

            // 3a. Datendatei (.json) initialisieren mit '[]' - ROBUSTER CHECK
            $writeResultJson = @file_put_contents($tableFile, '[]');
            if ($writeResultJson === false) {
                throw new Exception("Konnte die Datendatei '$tableName.json' nicht erstellen/schreiben. Prüfen Sie die Verzeichnisberechtigungen für '$tablesDir'.");
            }
            // Zusätzlicher Check, ob die Datei jetzt existiert und nicht leer ist
            if (!file_exists($tableFile) /*|| filesize($tableFile) === 0*/) { // filesize=0 ist ok für '[]'
                error_log("WARNUNG: Datendatei '$tableFile' wurde geschrieben, ist aber nicht vorhanden!");
                // Entscheiden, ob dies ein harter Fehler sein soll
                throw new Exception("Fehler nach dem Schreiben der Datendatei '$tableName.json' - Datei ist nicht vorhanden.");
            } else {
                @chmod($tableFile, 0664);
                error_log("Datendatei '$tableFile' erfolgreich initialisiert (Bytes: " . $writeResultJson . ").");
            }

            // 3b. Metadaten vorbereiten (inklusive Schema, falls vorhanden)
            $finalMetaData = [
                "name" => $tableName,
                "created" => date("Y-m-d H:i:s"),
                "columns" => [], // Bleibt leer oder wird aus 'types' abgeleitet? Füllen wir es mal mit Namen.
                "required" => [], // Wird unten ggf. gefüllt
                "types" => [],   // Wird unten ggf. gefüllt
                "records" => 0,
                "auto_increment" => 1 // Standard-Startwert
            ];

            // Verarbeite Schema-Daten, wenn sie gesendet wurden
            if ($defineSchema && !empty($columnsJson)) {
                error_log("Verarbeite Schema für '$tableName'. Empfangene Daten: " . $columnsJson); // Logge die Rohdaten
                $schemaData = json_decode($columnsJson, true);

                if (json_last_error() !== JSON_ERROR_NONE || !is_array($schemaData)) {
                    error_log("WARNUNG: Konnte gesendete Schema-Daten (columns) für Tabelle '$tableName' nicht dekodieren oder es war kein Array. JSON-Fehler: " . json_last_error_msg());
                } else {
                    // Debug: Logge die dekodierten Daten
                    // error_log("Dekodierte Schema-Daten: " . print_r($schemaData, true));

                    $parsedTypes = [];
                    $parsedRequired = [];
                    $parsedColumns = []; // Array für die (redundante) 'columns' Sektion

                    foreach ($schemaData as $col) {
                        // Prüfe expliziter auf Existenz der Keys
                        if (isset($col['name']) && isset($col['type'])) {
                            $fieldName = trim($col['name']);
                            $fieldType = trim($col['type']);
                            if (!empty($fieldName) && $fieldName !== 'id') { // Leere Feldnamen und 'id' ignorieren
                                if (!preg_match('/^[a-zA-Z0-9_]+$/', $fieldName)) {
                                    error_log("Ignoriere ungültigen Feldnamen '$fieldName' im Schema für '$tableName'.");
                                    continue;
                                }
                                $parsedTypes[$fieldName] = $fieldType;
                                $parsedColumns[] = $fieldName; // Nur Namen in 'columns'
                                // Prüfe 'required' - muss explizit true sein
                                if (isset($col['required']) && $col['required'] === true) {
                                    $parsedRequired[] = $fieldName;
                                }
                            } else {
                                error_log("Ignoriere leeren Feldnamen oder 'id' im Schema für '$tableName'.");
                            }
                        } else {
                            error_log("WARNUNG: Unvollständiger Schema-Eintrag für '$tableName' ignoriert: " . print_r($col, true));
                        }
                    } // Ende foreach schemaData

                    // Füge die geparsten Schema-Infos ZUVERLÄSSIG zu den Metadaten hinzu
                    if (!empty($parsedTypes)) {
                        // Überschreibe die leeren Defaults in $finalMetaData
                        $finalMetaData['types'] = $parsedTypes;
                        $finalMetaData['required'] = $parsedRequired;
                        $finalMetaData['columns'] = $parsedColumns; // Befülle auch 'columns' mit Namen
                        error_log("Schema für Tabelle '$tableName' erfolgreich verarbeitet und zu Metadaten hinzugefügt.");
                    } else {
                        error_log("Keine validen Schema-Felder gefunden nach dem Parsen für '$tableName'.");
                    }
                } // Ende else (JSON decode erfolgreich)
            } else {
                error_log("Kein Schema definiert oder keine Schema-Daten empfangen für '$tableName'.");
            }


            // 3c. Metadaten-Datei (_meta.json) schreiben (mit oder ohne Schema)
            $writeResultMeta = @file_put_contents($metaFile, json_encode($finalMetaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if ($writeResultMeta === false) {
                // Versuch eines Cleanups für die Datendatei
                if (file_exists($tableFile))
                    @unlink($tableFile);
                throw new Exception("Konnte die Metadaten-Datei '$tableName" . "_meta.json' nicht schreiben. Prüfen Sie die Verzeichnisberechtigungen für '$tablesDir'.");
            }
            @chmod($metaFile, 0664);
            error_log("Metadaten-Datei '$metaFile' erfolgreich geschrieben (Bytes: " . $writeResultMeta . "). Inhalt: " . json_encode($finalMetaData));

            // 4. Bei der Bibliothek registrieren (aktualisiert interne Liste / tables.json)
            // Wichtig: Muss NACH dem Erstellen der Dateien erfolgen!
            $tableEngine = $db->registerTable($tableName);
            if (!$tableEngine) {
                // Cleanup, da Registrierung fehlgeschlagen
                if (file_exists($tableFile))
                    @unlink($tableFile);
                if (file_exists($metaFile))
                    @unlink($metaFile);
                throw new Exception("Fehler beim Registrieren der Tabelle '$tableName' in der Datenbank-Instanz NACH Dateierstellung.");
            }
            $registered = true; // Registrierung war erfolgreich
            error_log("Tabelle '$tableName' erfolgreich bei DB-Instanz registriert.");


            // 5. Schema NICHT mehr über die Bibliothek setzen (ist jetzt in _meta.json)
            // $tableEngine->setSchema(...) // <<< ENTFERNT

            outputJSON(['success' => true, 'message' => "Tabelle '$tableName' erfolgreich erstellt.", 'tableName' => $tableName]); // Namen zurückgeben

        } catch (Exception $e) {
            error_log("API Fehler in api/table.php (Action: create, Table: $tableName): " . $e->getMessage() . "\n" . $e->getTraceAsString());
            // Cleanup Versuch (wie vorher)
            try {
                if ($tableFile && file_exists($tableFile))
                    @unlink($tableFile);
                if ($metaFile && file_exists($metaFile))
                    @unlink($metaFile);
                // Versuche, die Tabelle wieder aus der Registrierung zu entfernen, falls sie schon drin war
                if ($registered && $db->hasTable($tableName)) {
                    // Annahme: Es gibt eine unregisterTable Methode o.ä.
                    // $db->unregisterTable($tableName); // Beispiel
                }
            } catch (Exception $cleanupEx) {
                error_log("Cleanup Fehler nach Exception: " . $cleanupEx->getMessage());
            }

            outputJSON(['error' => "Fehler beim Erstellen der Tabelle: " . $e->getMessage()]);
        }
        break; // Ende case 'create'

    // --- Andere Cases ---

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
        } catch (Exception $e) {
            error_log("API Fehler in api/table.php (Action: schema, Table: $tableName): " . $e->getMessage());
            outputJSON(['error' => 'Fehler beim Speichern des Schemas: ' . $e->getMessage()]);
        }
        break; // Ende case 'schema'


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
        } catch (Exception $e) {
            error_log("API Fehler in api/table.php (Action: list): " . $e->getMessage());
            outputJSON(['error' => 'Fehler beim Auflisten der Tabellen: ' . $e->getMessage(), 'tables' => []]);
        }
        break;

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
            } else {
                // Dieser Fall sollte eigentlich nicht eintreten, wenn bei Fehler eine Exception geworfen wird.
                // Aber sicherheitshalber loggen und Fehler melden.
                error_log("Tabelle '$tableName' konnte nicht gelöscht werden (dropTable gab nicht true zurück).");
                outputJSON(['error' => "Unbekannter Fehler beim Löschen der Tabelle '$tableName'."]);
            }

        } catch (RuntimeException $e) { // Fange die spezifische Exception ab
            error_log("API Fehler (RuntimeException) in api/table.php (Action: delete, Table: $tableName): " . $e->getMessage());
            outputJSON(['error' => 'Fehler beim Löschen der Tabelle: ' . $e->getMessage()]);
        } catch (Exception $e) { // Fange andere mögliche Exceptions ab
            error_log("API Allgemeiner Fehler in api/table.php (Action: delete, Table: $tableName): " . $e->getMessage());
            outputJSON(['error' => 'Allgemeiner Fehler beim Löschen der Tabelle: ' . $e->getMessage()]);
        }
        break; // Ende case 'delete'


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
        } catch (Exception $e) {
            error_log("API Fehler in api/table.php (Action: clear, Table: $tableName): " . $e->getMessage());
            outputJSON(['error' => 'Fehler beim Leeren der Tabelle: ' . $e->getMessage()]);
        }
        break;


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
        } catch (Exception $e) {
            error_log("API Fehler in api/table.php (Action: compact, Table: $tableName): " . $e->getMessage());
            outputJSON(['error' => 'Fehler beim Kompaktieren der Tabelle: ' . $e->getMessage()]);
        }
        break;

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

        } catch (Exception $e) {
            error_log("Fehler beim Abrufen der Tabelleninfo für $tableName: " . $e->getMessage());
            outputJSON(['error' => $e->getMessage()]);
        }
        break;

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
        } catch (Exception $e) {
            error_log("API Fehler in api/table.php (Action: backup, Table: $tableName): " . $e->getMessage());
            outputJSON(['error' => 'Fehler beim Sichern der Tabelle: ' . $e->getMessage()]);
        }
        break;


    default:
        outputJSON(['error' => 'Ungültige Aktion: ' . $action]);
        break;
}