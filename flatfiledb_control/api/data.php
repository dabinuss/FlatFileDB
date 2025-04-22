<?php
require_once '../init.php';
requireAjax();

$action = isset($_POST['action']) ? $_POST['action'] : '';

switch ($action) {
    case 'list':
        // Datensätze auflisten
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $pageSize = isset($_POST['pageSize']) ? intval($_POST['pageSize']) : PAGE_SIZE;
        $orderBy = isset($_POST['orderBy']) ? trim($_POST['orderBy']) : 'id';
        $order = isset($_POST['order']) ? trim($_POST['order']) : 'ASC';
        $filters = isset($_POST['filters']) ? $_POST['filters'] : [];

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
        }

        try {
            $data = getTableData($handler, $tableName, $page, $pageSize, $orderBy, $order, $filters);
            outputJSON(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
        }
        break;

    case 'get':
        // Einzelnen Datensatz abrufen
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
        }

        if ($id <= 0) {
            outputJSON(['error' => 'Gültige ID ist erforderlich']);
        }

        try {
            $record = $handler->table($tableName)
                ->where('id', '=', $id)
                ->first();

            if (!$record) {
                outputJSON(['error' => 'Datensatz nicht gefunden']);
            }

            outputJSON(['success' => true, 'record' => $record]);
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
        }
        break;

    case 'insert':
        // Datensatz einfügen
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';
        $jsonData = isset($_POST['data']) ? $_POST['data'] : null; // Hole den JSON-String

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
            exit; // Exit nach outputJSON
        }

        if (empty($jsonData)) {
            outputJSON(['error' => 'Keine Daten zum Einfügen']);
            exit; // Exit nach outputJSON
        }

        // JSON dekodieren
        $data = json_decode($jsonData, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            outputJSON(['error' => 'Ungültige Daten (JSON): ' . json_last_error_msg()]);
            exit;
        }
        // Prüfe, ob das Ergebnis ein nicht-leeres Array ist
        if (empty($data) || !is_array($data)) {
            outputJSON(['error' => 'Keine validen Daten zum Einfügen nach JSON-Decode']);
            exit;
        }

        try {
            // Die Hilfsfunktion insertRecord erwartet das dekodierte Array
            $result = insertRecord($handler, $tableName, $data);
            outputJSON($result);
        } catch (Exception $e) {
            error_log("API Fehler in api/data.php (Action: insert, Table: $tableName): " . $e->getMessage());
            outputJSON(['error' => 'Fehler beim Einfügen des Datensatzes.']);
        }
        break;

    case 'update':
        // Datensatz aktualisieren
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $jsonData = isset($_POST['data']) ? $_POST['data'] : null; // Hole den JSON-String

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
            exit;
        }
        if ($id <= 0) {
            outputJSON(['error' => 'Gültige ID ist erforderlich']);
            exit;
        }
        if (empty($jsonData)) {
            outputJSON(['error' => 'Keine Daten zum Aktualisieren']);
            exit;
        }

        // JSON dekodieren
        $data = json_decode($jsonData, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            outputJSON(['error' => 'Ungültige Daten (JSON): ' . json_last_error_msg()]);
            exit;
        }
        // Prüfe, ob das Ergebnis ein nicht-leeres Array ist
        if (empty($data) || !is_array($data)) {
            outputJSON(['error' => 'Keine validen Daten zum Aktualisieren nach JSON-Decode']);
            exit;
        }

        try {
            // Die Hilfsfunktion updateRecord erwartet das dekodierte Array
            $result = updateRecord($handler, $tableName, $id, $data);
            outputJSON($result);
        } catch (Exception $e) {
            error_log("API Fehler in api/data.php (Action: update, Table: $tableName, ID: $id): " . $e->getMessage());
            outputJSON(['error' => 'Fehler beim Aktualisieren des Datensatzes.']);
        }
        break;

    case 'delete':
        // Datensatz löschen
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
        }

        if ($id <= 0) {
            outputJSON(['error' => 'Gültige ID ist erforderlich']);
        }

        try {
            $result = deleteRecord($handler, $tableName, $id);
            outputJSON($result);
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
        }
        break;

    default:
        outputJSON(['error' => 'Ungültige Aktion']);
        break;
}
