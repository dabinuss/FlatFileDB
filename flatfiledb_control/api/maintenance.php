<?php
require_once '../init.php';
requireAjax();

$action = isset($_POST['action']) ? $_POST['action'] : '';

switch ($action) {
    case 'compact':
        // Tabelle kompaktieren
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
        }

        try {
            $result = compactTable($db, $tableName);
            outputJSON($result);
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
        }
        break;

    case 'compact_all':
        // Alle Tabellen kompaktieren
        try {
            $results = $db->compactAllTables();
            outputJSON(['success' => true, 'results' => $results]);
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
        }
        break;

    case 'backup':
        // Tabelle sichern
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
        }

        try {
            $result = backupTable($db, $tableName);
            outputJSON($result);
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
        }
        break;

    case 'backup_all':
        // Alle Tabellen sichern
        try {
            if (!is_dir(BACKUP_DIR)) {
                mkdir(BACKUP_DIR, 0755, true);
            }

            $result = $db->createBackup(BACKUP_DIR);
            outputJSON(['success' => true, 'files' => $result]);
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
        }
        break;

    case 'clear_cache':
        // Cache leeren
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';

        if (!empty($tableName)) {
            try {
                // Cache einer Tabelle leeren
                $table = $db->table($tableName);
                $table->clearCache();
                outputJSON(['success' => true, 'message' => 'Cache erfolgreich geleert']);
            } catch (Exception $e) {
                outputJSON(['error' => $e->getMessage()]);
            }
        } else {
            try {
                // Alle Caches leeren
                $db->clearAllCaches();
                outputJSON(['success' => true, 'message' => 'Alle Caches erfolgreich geleert']);
            } catch (Exception $e) {
                outputJSON(['error' => $e->getMessage()]);
            }
        }
        break;

    case 'log':
        // Transaktionslog abrufen
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : MAX_LOG_ENTRIES;

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
        }

        try {
            $result = getTableLog($db, $tableName, $limit);
            outputJSON($result);
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
        }
        break;

    case 'rotate_log':
        // Transaktionslog rotieren
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
        }

        try {
            $table = $db->table($tableName);
            // Verwende eine Getter-Methode oder erstelle eine neue Instanz
            // Variante 1: Falls ein Getter existiert
            // $log = $table->getTransactionLog();

            // Variante 2: Neue Instanz erstellen
            $log = new FlatFileDB\FlatFileTransactionLog($table->getConfig());

            if (!is_dir(BACKUP_DIR)) {
                mkdir(BACKUP_DIR, 0755, true);
            }

            $backupLogPath = $log->rotateLog(BACKUP_DIR);
            outputJSON(['success' => true, 'backup_path' => $backupLogPath]);
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
        }
        break;

    default:
        outputJSON(['error' => 'Ungültige Aktion']);
        break;
}