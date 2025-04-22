<?php
require_once '../init.php';
requireAjax();

$action = isset($_POST['action']) ? $_POST['action'] : '';

switch ($action) {
    case 'create':
        // Index erstellen
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';
        $field = isset($_POST['field']) ? trim($_POST['field']) : '';

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
        }

        if (empty($field)) {
            outputJSON(['error' => 'Feldname ist erforderlich']);
        }

        try {
            // Überprüfen, ob Tabelle existiert
            if (!$db->hasTable($tableName)) {
                outputJSON(['error' => 'Tabelle existiert nicht']);
            }

            // Index erstellen
            $table = $db->table($tableName);
            $table->createIndex($field);

            outputJSON(['success' => true, 'message' => 'Index erfolgreich erstellt']);
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
        }
        break;

    case 'delete':
        // Index löschen
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';
        $field = isset($_POST['field']) ? trim($_POST['field']) : '';

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
        }

        if (empty($field)) {
            outputJSON(['error' => 'Feldname ist erforderlich']);
        }

        try {
            // Überprüfen, ob Tabelle existiert
            if (!$db->hasTable($tableName)) {
                outputJSON(['error' => 'Tabelle existiert nicht']);
            }

            // Index löschen
            $table = $db->table($tableName);
            $table->dropIndex($field);

            outputJSON(['success' => true, 'message' => 'Index erfolgreich gelöscht']);
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
        }
        break;

    case 'list':
        // Indizes einer Tabelle auflisten
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
            exit;
        }

        try {
            // Überprüfen, ob Tabelle existiert (korrekt)
            if (!$db->hasTable($tableName)) {
                outputJSON(['error' => 'Tabelle existiert nicht']);
                exit;
            }

            // Engine holen (korrekt)
            $table = $db->table($tableName);

            // Indizes über die dedizierte Hilfsfunktion abrufen (beste verfügbare Methode)
            $indexNames = getTableIndexNames($table);

            outputJSON(['success' => true, 'indexes' => $indexNames]);

        } catch (Exception $e) {
            error_log("Fehler beim Auflisten der Indizes für $tableName: " . $e->getMessage());
            outputJSON(['error' => $e->getMessage()]);
        }
        break;

    default:
        outputJSON(['error' => 'Ungültige Aktion']);
        break;
}