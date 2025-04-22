<?php
require_once '../init.php';
requireAjax();

$action = isset($_POST['action']) ? $_POST['action'] : '';

switch ($action) {
    case 'switch':
        $dbName = isset($_POST['database']) ? trim($_POST['database']) : '';

        if (empty($dbName)) {
            outputJSON(['error' => 'Datenbankname ist erforderlich']);
            exit;
        }

        try {
            // Prüfen, ob die Datenbank existiert
            $databases = getAllDatabases();
            $exists = false;

            foreach ($databases as $db) {
                if ($db['name'] === $dbName) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                outputJSON(['error' => "Datenbank '$dbName' existiert nicht"]);
                exit;
            }

            // Datenbank in Session speichern
            $_SESSION['current_db'] = $dbName;

            outputJSON([
                'success' => true,
                'message' => "Datenbank auf '$dbName' gewechselt"
            ]);
            
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
            exit;
        }
        // break;

    default:
        outputJSON(['error' => 'Ungültige Aktion']);
        exit;
        // break;
}