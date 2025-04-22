<?php
// Initialisierung der Anwendung
session_start();
error_log("--- init.php START ---");

ini_set('display_errors', '1'); // Fehler im Browser anzeigen (Unsicher für Produktion!)
ini_set('display_startup_errors', '1'); // Auch Startfehler anzeigen
ini_set('error_log', __DIR__ . '/error_log.txt');
ini_set('log_errors', '1');
error_reporting(E_ALL); // Alle Fehler melden

// Konfiguration laden
require_once 'config.php';

// Hilfsfunktionen einbinden
require_once 'includes/db_functions.php';

$currentDbName = null;
$currentDataDir = null;
$databaseExists = false; // Flag, ob die ausgewählte DB auch physisch existiert

error_log("init.php: Session 'current_db' Wert: " . ($_SESSION['current_db'] ?? 'Nicht gesetzt'));

// Aktuelle Datenbank prüfen
if (isset($_SESSION['current_db'])) {
    $potentialDbName = $_SESSION['current_db'];
    $potentialDataDir = DATA_DIR . '/' . $potentialDbName;
    if (is_dir($potentialDataDir)) {
        $currentDbName = $potentialDbName;
        $currentDataDir = $potentialDataDir;
        $databaseExists = true;
        error_log("init.php: DB '$currentDbName' (Pfad: $currentDataDir) aus Session geladen und Verzeichnis existiert."); // <-- Detaillierter
    } else {
        unset($_SESSION['current_db']);
        error_log("init.php: DB '$potentialDbName' aus Session existiert nicht mehr (Pfad: $potentialDataDir). Session-Eintrag gelöscht."); // <-- Detaillierter
    }
}

if (!$databaseExists) {
    error_log("Keine gültige DB in Session. Suche nach erster verfügbarer DB...");
    $databases = getAllDatabases(DATA_DIR); // Ruft die Funktion auf, die Verzeichnisse scannt
    error_log("init.php: getAllDatabases Ergebnis für Fallback: " . print_r($databases, true));
    if (!empty($databases)) {
        // Nimm die erste gefundene Datenbank
        $currentDbName = $databases[0]['name'];
        $currentDataDir = $databases[0]['path']; // Verwende den Pfad aus getAllDatabases
        $_SESSION['current_db'] = $currentDbName; // Setze Session für zukünftige Requests
        $databaseExists = true;
        error_log("Fallback: DB '$currentDbName' als aktiv gesetzt und in Session gespeichert.");
    } else {
        error_log("Keine Datenbanken im Verzeichnis '" . DATA_DIR . "' gefunden.");
        // Keine Datenbanken vorhanden
        $currentDbName = null; // Explizit null setzen
        $currentDataDir = null; // Explizit null setzen
        $databaseExists = false;
    }
}

// FlatFileDB-Klassen einbinden
require_once DB_BASE_PATH . '/FlatFileConfig.class.php';
require_once DB_BASE_PATH . '/FlatFileDatabase.class.php';
require_once DB_BASE_PATH . '/FlatFileDatabaseHandler.class.php';
require_once DB_BASE_PATH . '/FlatFileDBConstants.class.php';
require_once DB_BASE_PATH . '/FlatFileDBStatistics.class.php';
require_once DB_BASE_PATH . '/FlatFileFileManager.class.php';
require_once DB_BASE_PATH . '/FlatFileIndexBuilder.class.php';
require_once DB_BASE_PATH . '/FlatFileTableEngine.class.php';
require_once DB_BASE_PATH . '/FlatFileTransactionLog.class.php';
require_once DB_BASE_PATH . '/FlatFileValidator.class.php';

$db = null;
$handler = null;
$stats = null;

if ($databaseExists && $currentDataDir) {
    error_log("Versuche DB-Initialisierung für: $currentDataDir");
    try {
        $db = new FlatFileDB\FlatFileDatabase($currentDataDir);
        $handler = new FlatFileDB\FlatFileDatabaseHandler($db);
        $stats = new FlatFileDB\FlatFileDBStatistics($db);

        // Globale Variablen setzen
        $GLOBALS['db'] = $db;
        $GLOBALS['handler'] = $handler;
        $GLOBALS['stats'] = $stats;
        error_log("DB-Objekte erfolgreich für '$currentDbName' initialisiert.");

    } catch (\Exception $e) {
        $errorMessage = "FATAL: FlatFileDB Initialisierung fehlgeschlagen für '$currentDataDir': " . $e->getMessage();
        error_log($errorMessage);
        // Setze DB-Objekte explizit auf null im Fehlerfall
        $db = null;
        $handler = null;
        $stats = null;
        $GLOBALS['db'] = null;
        $GLOBALS['handler'] = null;
        $GLOBALS['stats'] = null;

        // Fehlerbehandlung (JSON oder HTML, mit exit) - wie in vorheriger Korrektur
        // Funktion zum sicheren Ausgeben von JSON, falls schon definiert
        if (!function_exists('outputJSON')) {
            function outputJSON($data)
            { // Minimal-Definition für den Notfall
                if (!headers_sent()) {
                    header('Content-Type: application/json');
                }
                echo json_encode($data);
                exit;
            }
        }
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        $isApiCall = (isset($_POST['action']) && strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false);
        if ($isAjax || $isApiCall) {
            http_response_code(500);
            outputJSON(['error' => 'Datenbank-Initialisierungsfehler. Server-Logs prüfen.']);
            exit;
        } else {
            http_response_code(500);
            echo "<!DOCTYPE html><html><head><title>Fehler</title><meta charset='UTF-8'></head><body>";
            echo "<h1>Interner Serverfehler</h1>";
            echo "<p>Die ausgewählte Datenbank konnte nicht initialisiert werden.</p>";
            if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
                echo "<pre style='background:#eee; border:1px solid #ccc; padding:10px;'>" . htmlspecialchars($errorMessage) . "\n\n" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
            }
            echo "</body></html>";
            exit;
        }
    }
} else {
    error_log("Keine gültige Datenbank ausgewählt oder gefunden. DB-Objekte bleiben null.");
    // Setze Globals explizit auf null, damit spätere Prüfungen fehlschlagen
    $GLOBALS['db'] = null;
    $GLOBALS['handler'] = null;
    $GLOBALS['stats'] = null;
}

$GLOBALS['currentDb'] = $currentDbName;
$GLOBALS['currentDataDir'] = $currentDataDir; // Kann null sein!

error_log("init.php ENDE: Globals gesetzt - currentDb: " . ($GLOBALS['currentDb'] ?? 'null') . ", db Objekt: " . (is_object($GLOBALS['db']) ? get_class($GLOBALS['db']) : 'null'));

// --- Hilfsfunktionen (JSON, AJAX Check) ---
// Funktion zum sicheren Ausgeben von JSON
if (!function_exists('outputJSON')) {
    function outputJSON($data)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode($data);
        exit;
    }
}
// Funktion zur Überprüfung von AJAX-Anfragen
function requireAjax()
{
    if (
        !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest'
    ) {
        outputJSON(['error' => 'Nur AJAX-Anfragen erlaubt']);
        exit;
    }
}