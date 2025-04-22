<?php
require_once '../init.php';
requireAjax();

$action = isset($_POST['action']) ? $_POST['action'] : '';

switch ($action) {
    case 'create':
        // Neue Datenbank erstellen
        $dbName = isset($_POST['name']) ? trim($_POST['name']) : '';
        $basePath = DATA_DIR; // Basispfad verwenden
    
        if (empty($dbName)) {
            outputJSON(['error' => 'Datenbankname ist erforderlich']);
            exit;
        }
        // Gültigkeitsprüfung
         if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $dbName)) { // Angepasste Regex
            outputJSON(['error' => 'Ungültiger Datenbankname. Nur Buchstaben, Zahlen, Unterstrich, Punkt, Bindestrich erlaubt.']);
            exit;
        }
    
        $dbPath = rtrim($basePath, '/') . '/' . $dbName;
    
        try {
             // Prüfen, ob Verzeichnis bereits existiert
            if (is_dir($dbPath)) {
                outputJSON(['error' => 'Eine Datenbank mit diesem Namen existiert bereits.']);
                exit;
            }
    
            // Haupt-Verzeichnis erstellen
            if (!@mkdir($dbPath, FlatFileDB\FlatFileDBConstants::DEFAULT_DIR_PERMISSIONS, true)) {
                 if (!is_dir($dbPath)){ // Prüfen ob wirklich nicht erstellt
                    throw new Exception("Fehler beim Erstellen des Haupt-Datenbankverzeichnisses '$dbPath'. Prüfen Sie die Berechtigungen.");
                 }
                 // Existiert doch - race condition? Egal, weiter...
            }
             @chmod($dbPath, FlatFileDB\FlatFileDBConstants::DEFAULT_DIR_PERMISSIONS); // Sicherstellen
    
             // Optional, aber empfohlen: Leeres Manifest im ROOT der DB erstellen
             $manifestFilePath = $dbPath . '/' . FlatFileDB\FlatFileDBConstants::MANIFEST_FILE;
             if (@file_put_contents($manifestFilePath, '[]', LOCK_EX) === false) {
                 // Cleanup: Hauptverzeichnis löschen, wenn Manifest nicht erstellt werden kann
                 @rmdir($dbPath);
                 throw new Exception("Fehler beim Erstellen der leeren Manifest-Datei '$manifestFilePath'.");
             }
             @chmod($manifestFilePath, 0664);
    
    
            // Erfolg
            outputJSON([
                'success' => true,
                'message' => "Datenbank '$dbName' wurde erfolgreich initialisiert.",
                'path' => $dbPath
            ]);
            exit;
    
        } catch (Exception $e) {
            // Sicherstellen, dass das Verzeichnis gelöscht wird, wenn etwas schiefgeht
            if (is_dir($dbPath)) {
                // Versuche, das Manifest zu löschen, bevor das Verzeichnis gelöscht wird
                $manifestFilePath = $dbPath . '/' . FlatFileDB\FlatFileDBConstants::MANIFEST_FILE;
                 if(file_exists($manifestFilePath)) @unlink($manifestFilePath);
                 @rmdir($dbPath); // rmdir löscht nur leere Verzeichnisse
            }
            outputJSON(['error' => $e->getMessage()]);
            exit;
        }
        // break; // Nicht erreichbar

    case 'delete':
        // Datenbank löschen
        $dbName = isset($_POST['name']) ? trim($_POST['name']) : '';

        if (empty($dbName)) {
            outputJSON(['error' => 'Datenbankname ist erforderlich']);
            exit;
        }

        try {
            $result = deleteDatabase($dbName);
            outputJSON($result);
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
            exit;
        }
        // break;

    case 'list':
        // Alle Datenbanken auflisten
        try {
            $databases = getAllDatabases();
            outputJSON(['success' => true, 'databases' => $databases]);
            exit;
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
            exit;
        }
        // break;

    case 'backup':
        // Datenbank sichern
        $dbName = isset($_POST['name']) ? trim($_POST['name']) : '';

        if (empty($dbName)) {
            outputJSON(['error' => 'Datenbankname ist erforderlich']);
            exit;
        }

        // Stelle sicher, dass wir auf die richtige DB-Instanz operieren
        // HINWEIS: Die aktuelle $db Instanz in init.php zeigt auf $_SESSION['current_db']
        // Wenn wir eine ANDERE DB sichern wollen, müssen wir eine neue Instanz erstellen
        if ($dbName !== $GLOBALS['currentDb']) {
            try {
                $dbToBackup = new FlatFileDB\FlatFileDatabase(DATA_DIR . '/' . $dbName);
            } catch (Exception $e) {
                outputJSON(['error' => "Konnte Datenbank '$dbName' nicht laden: " . $e->getMessage()]);
                exit;
            }
        } else {
            $dbToBackup = $db; // Verwende die globale Instanz
        }


        try {
            // Backup-Verzeichnis aus Konstante verwenden
            if (!is_dir(BACKUP_DIR)) {
                if (!mkdir(BACKUP_DIR, 0755, true)) {
                    throw new Exception("Backup-Verzeichnis konnte nicht erstellt werden: " . BACKUP_DIR);
                }
            }

            // Backup über die Bibliotheksmethode erstellen
            // Diese Methode sichert die Daten der $dbToBackup Instanz
            $backupResults = $dbToBackup->createBackup(BACKUP_DIR);

            outputJSON([
                'success' => true,
                'message' => "Backup der Datenbank '$dbName' wurde erfolgreich erstellt.",
                'files' => $backupResults // Gibt die Liste der erstellten Backup-Dateien zurück
            ]);
        } catch (Exception $e) {
            error_log("Fehler beim Backup der Datenbank $dbName: " . $e->getMessage());
            outputJSON(['error' => "Fehler beim Backup: " . $e->getMessage()]);
            exit;
        }
        // break;

    default:
        outputJSON(['error' => 'Ungültige Aktion']);
        exit;
        // break;
}