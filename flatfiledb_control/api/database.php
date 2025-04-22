<?php
require_once '../init.php';
requireAjax();

$action = isset($_POST['action']) ? $_POST['action'] : '';

switch ($action) {
    case 'create':
        // Neue Datenbank erstellen
        $dbName = isset($_POST['name']) ? trim($_POST['name']) : '';

        if (empty($dbName)) {
            outputJSON(['error' => 'Datenbankname ist erforderlich']);
            exit;
        }

        try {
            $result = createDatabase($dbName);
            outputJSON($result);
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
            exit;
        }
        // break;

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