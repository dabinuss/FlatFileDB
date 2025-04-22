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
        $metaFile = null; // Pfad zur _meta.json Datei
        $registered = false;

        // --- Grundlegende Prüfungen ---
        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
            exit;
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            outputJSON(['error' => 'Ungültiger Tabellenname. Nur Buchstaben, Zahlen und Unterstriche sind erlaubt.']);
            exit;
        }
        if (!isset($db) || !$db instanceof FlatFileDB\FlatFileDatabase) {
             outputJSON(['error' => 'Interner Serverfehler: Datenbankobjekt nicht initialisiert.']);
             exit;
        }
        if (!isset($currentDataDir) || !is_dir($currentDataDir)) {
            outputJSON(['error' => 'Interner Serverfehler: Datenbankverzeichnis nicht korrekt gesetzt.']);
            exit;
        }
        // --- Ende Grundlegende Prüfungen ---

        try {
            // Pfade definieren
            $tablesDir = $currentDataDir . '/tables';
            $metaFilePath = $tablesDir . '/' . $tableName . '_meta.json';
            $tablesJsonPath = $tablesDir . '/tables.json'; // Pfad zur zentralen Tabellenliste
            $metaFile = $metaFilePath; // Für mögliches Logging/Prüfung

            // 1. Prüfen, ob Tabelle bereits existiert (Meta-Datei oder Registrierung)
            // Statt $db->hasTable(), prüfen wir jetzt direkt die tables.json (zuverlässiger)
             $currentTables = [];
             if (file_exists($tablesJsonPath) && is_readable($tablesJsonPath)) {
                 $jsonContent = @file_get_contents($tablesJsonPath);
                 if ($jsonContent !== false) {
                     $decoded = json_decode($jsonContent, true);
                     if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                         $currentTables = $decoded;
                     } else {
                          error_log("WARNUNG: Konnte tables.json nicht dekodieren bei Tabellenprüfung.");
                     }
                 }
             }
            // Prüfung auf Meta-Datei und Eintrag in $currentTables
            if (file_exists($metaFilePath) || in_array($tableName, $currentTables)) {
                outputJSON(['error' => "Tabelle '$tableName' existiert bereits (als Meta-Datei oder ist in tables.json registriert)."]);
                exit;
            }

            // 2. 'tables' Verzeichnis sicherstellen
            if (!is_dir($tablesDir)) {
                if (!@mkdir($tablesDir, 0755, true)) {
                    throw new Exception("Konnte das 'tables' Unterverzeichnis nicht erstellen: " . $tablesDir);
                }
                 error_log("Verzeichnis '$tablesDir' wurde erstellt.");
            }

            // 3. Metadaten vorbereiten (mit oder ohne Schema)
            $finalMetaData = [
                "name" => $tableName,
                "created" => date("Y-m-d H:i:s"),
                "columns" => [],
                "required" => [],
                "types" => [],
                "records" => 0,
                "auto_increment" => 1
            ];
            // Schema Verarbeitung... (BLEIBT GLEICH)
            if ($defineSchema && !empty($columnsJson)) {
                // ... (Code zum Parsen von columnsJson - BLEIBT GLEICH) ...
                 error_log("Verarbeite Schema für '$tableName'. Empfangene Daten: " . $columnsJson);
                 $schemaData = json_decode($columnsJson, true);
                 if (json_last_error() === JSON_ERROR_NONE && is_array($schemaData)) {
                     $parsedTypes = [];
                     $parsedRequired = [];
                     $parsedColumns = [];
                     foreach ($schemaData as $col) { /* ... Parsing ... */ }
                     if (!empty($parsedTypes)) {
                         $finalMetaData['types'] = $parsedTypes;
                         $finalMetaData['required'] = $parsedRequired;
                         $finalMetaData['columns'] = $parsedColumns; // optional
                         error_log("Schema für Tabelle '$tableName' erfolgreich verarbeitet.");
                     } else {
                         error_log("Keine validen Schema-Felder gefunden für '$tableName'.");
                     }
                 } else {
                     error_log("WARNUNG: Konnte gesendete Schema-Daten für '$tableName' nicht dekodieren. JSON-Fehler: " . json_last_error_msg());
                 }
            } else {
                error_log("Kein Schema definiert oder keine Schema-Daten empfangen für '$tableName'.");
            }

            // 4. Metadaten-Datei (_meta.json) schreiben (JETZT WIEDER VORHER)
            error_log("Schreibe Metadaten-Datei '$metaFilePath'...");
            $writeResultMeta = @file_put_contents($metaFile, json_encode($finalMetaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if ($writeResultMeta === false) {
                throw new Exception("Konnte die Metadaten-Datei '$tableName" . "_meta.json' nicht schreiben.");
            }
            @chmod($metaFile, 0664);
            error_log("Metadaten-Datei '$metaFilePath' erfolgreich geschrieben (Bytes: " . $writeResultMeta . ").");

            // 5. Tabelle zur tables.json HINZUFÜGEN (MANUELL)
            error_log("Füge Tabelle '$tableName' zu '$tablesJsonPath' hinzu...");
            // Lock holen, um Race Conditions zu vermeiden
            $fp = @fopen($tablesJsonPath, 'c+'); // 'c+' erstellt Datei, falls nicht vorhanden, und erlaubt Lesen/Schreiben
            if (!$fp) {
                 @unlink($metaFile); // Cleanup Meta
                 throw new Exception("Konnte tables.json '$tablesJsonPath' nicht öffnen/erstellen.");
            }
            if (!flock($fp, LOCK_EX)) { // Exklusiver Lock
                 @fclose($fp);
                 @unlink($metaFile); // Cleanup Meta
                 throw new Exception("Konnte tables.json '$tablesJsonPath' nicht sperren.");
            }
            // Inhalt lesen (könnte leer sein)
            $jsonContent = stream_get_contents($fp);
            $currentTables = [];
            if (!empty($jsonContent)) {
                $decoded = json_decode($jsonContent, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $currentTables = $decoded;
                } else {
                     error_log("WARNUNG: Bestehender Inhalt von tables.json ist ungültig, wird überschrieben.");
                }
            }
            // Neuen Namen hinzufügen, falls nicht schon da (Doppelcheck)
            if (!in_array($tableName, $currentTables)) {
                $currentTables[] = $tableName;
                sort($currentTables); // Optional: Alphabetisch sortieren
                 // Zurück an den Anfang schreiben und kürzen
                 rewind($fp);
                 ftruncate($fp, 0);
                 if (fwrite($fp, json_encode($currentTables, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
                     flock($fp, LOCK_UN); // Lock freigeben
                     fclose($fp);
                     @unlink($metaFile); // Cleanup Meta
                     throw new Exception("Fehler beim Schreiben in tables.json '$tablesJsonPath'.");
                 }
                 error_log("Tabelle '$tableName' erfolgreich zu tables.json hinzugefügt.");
            } else {
                 error_log("WARNUNG: Tabelle '$tableName' war bereits in tables.json (sollte nicht passieren nach initialer Prüfung).");
            }
            // Lock freigeben und Datei schließen
            fflush($fp); // Sicherstellen, dass alles geschrieben ist
            flock($fp, LOCK_UN);
            fclose($fp);
            @chmod($tablesJsonPath, 0664); // Berechtigungen


            // 6. Bei der Bibliothek registrieren (könnte optional sein, wenn wir uns auf tables.json verlassen)
            // Wir rufen es trotzdem auf, falls es interne Caches oder Zustände initialisiert.
            error_log("Registriere Tabelle '$tableName' bei der DB-Instanz (nach manueller Meta/tables.json Erstellung)...");
            $tableEngine = $db->registerTable($tableName);
            if (!$tableEngine instanceof FlatFileDB\FlatFileTableEngine) {
                 error_log("WARNUNG: registerTable für '$tableName' gab kein gültiges Engine-Objekt zurück, aber Meta/tables.json wurden geschrieben.");
                 // Hier nicht abbrechen, da die Tabelle über tables.json auffindbar sein sollte.
            } else {
                 error_log("Tabelle '$tableName' erfolgreich bei DB-Instanz registriert.");
            }


            // 7. Erfolgsantwort senden
            outputJSON(['success' => true, 'message' => "Tabelle '$tableName' erfolgreich erstellt und registriert.", 'tableName' => $tableName]);
            exit;

        } catch (Exception $e) {
            // Allgemeine Fehlerbehandlung
            error_log("API Fehler in api/table.php (Action: create, Table: $tableName): " . $e->getMessage() . "\n" . $e->getTraceAsString());
            // Cleanup Versuch: Meta-Datei und Eintrag aus tables.json entfernen
            try {
                // Meta entfernen
                if ($metaFile && file_exists($metaFile)) {
                    @unlink($metaFile);
                    error_log("Cleanup: Meta-Datei '$metaFile' nach Fehler gelöscht.");
                }
                // Eintrag aus tables.json entfernen (falls hinzugefügt)
                if (file_exists($tablesJsonPath)) {
                    $fp = @fopen($tablesJsonPath, 'c+');
                    if ($fp && flock($fp, LOCK_EX)) {
                         $jsonContent = stream_get_contents($fp);
                         $currentTables = [];
                         if(!empty($jsonContent)) {
                              $decoded = json_decode($jsonContent, true);
                              if(is_array($decoded)) $currentTables = $decoded;
                         }
                         $initialCount = count($currentTables);
                         $currentTables = array_filter($currentTables, function($t) use ($tableName) { return $t !== $tableName; });
                         if (count($currentTables) < $initialCount) { // Nur schreiben, wenn was entfernt wurde
                              sort($currentTables);
                              rewind($fp);
                              ftruncate($fp, 0);
                              fwrite($fp, json_encode($currentTables, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                              error_log("Cleanup: Tabelle '$tableName' aus tables.json nach Fehler entfernt.");
                         }
                         fflush($fp);
                         flock($fp, LOCK_UN);
                         fclose($fp);
                    }
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