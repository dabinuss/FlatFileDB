<?php
declare(strict_types=1);

$startTime = microtime(true);

// Fehleranzeige aktivieren (nur für Entwicklungszwecke)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Alle benötigten Klassen einbinden – passe die Pfade ggf. an
require_once __DIR__ . '/../flatfiledb/FlatFileConfig.class.php';
require_once __DIR__ . '/../flatfiledb/FlatFileDatabase.class.php';
require_once __DIR__ . '/../flatfiledb/FlatFileDBConstants.class.php';
require_once __DIR__ . '/../flatfiledb/FlatFileFileManager.class.php';
require_once __DIR__ . '/../flatfiledb/FlatFileIndexBuilder.class.php';
require_once __DIR__ . '/../flatfiledb/FlatFileTableEngine.class.php';
require_once __DIR__ . '/../flatfiledb/FlatFileTransactionLog.class.php';
require_once __DIR__ . '/../flatfiledb/FlatFileValidator.class.php';
require_once __DIR__ . '/../flatfiledb/FlatFileDBStatistics.class.php';

use FlatFileDB\FlatFileDatabase;
use FlatFileDB\FlatFileDBConstants;
use FlatFileDB\FlatFileDBStatistics;

// Startzeit der Seite (serverseitig)
$pageStartTime = microtime(true);

// Datenbank initialisieren und Tabelle "users" registrieren
$db = new FlatFileDatabase(FlatFileDBConstants::DEFAULT_BASE_DIR);
$db->registerTables(['users', 'products']);
$usersTable = $db->table('users');
$usersTable->setSchema(
    ['name', 'email'],
    ['name' => 'string', 'email' => 'string', 'age' => 'int']
);
$usersTable->createIndex('name');
$usersTable->createIndex('email');

$message = '';
$searchResults = '';
$operationPerformance = '';

// Formular-Verarbeitung
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'insert_user':
                $name  = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $age   = (int)($_POST['age'] ?? 0);
                $perf = FlatFileDBStatistics::measurePerformance(function() use ($usersTable, $name, $email, $age) {
                    return $usersTable->insertRecord([
                        'name'  => $name,
                        'email' => $email,
                        'age'   => $age
                    ]);
                });
                $newId = $perf['result'];
                $operationPerformance = "Insert: " . number_format($perf['duration'], 4) . " s";
                $message = $newId !== false
                    ? "Benutzer erfolgreich eingefügt. ID: <strong>{$newId}</strong>"
                    : "Fehler beim Einfügen.";
                break;
            case 'update_user':
                $id    = (int)trim($_POST['update_user_id'] ?? '');
                $name  = trim($_POST['update_name'] ?? '');
                $email = trim($_POST['update_email'] ?? '');
                $age   = (int)($_POST['update_age'] ?? 0);
                $perf = FlatFileDBStatistics::measurePerformance(function() use ($usersTable, $id, $name, $email, $age) {
                    return $usersTable->updateRecord($id, [
                        'name'  => $name,
                        'email' => $email,
                        'age'   => $age
                    ]);
                });
                $success = $perf['result'];
                $operationPerformance = "Update: " . number_format($perf['duration'], 4) . " s";
                $message = $success
                    ? "Benutzer-ID <strong>{$id}</strong> aktualisiert."
                    : "Benutzer-ID <strong>{$id}</strong> nicht gefunden.";
                break;
            case 'delete_user':
                $id = (int)trim($_POST['delete_user_id'] ?? '');
                $perf = FlatFileDBStatistics::measurePerformance(function() use ($usersTable, $id) {
                    return $usersTable->deleteRecord($id);
                });
                $success = $perf['result'];
                $operationPerformance = "Delete: " . number_format($perf['duration'], 4) . " s";
                $message = $success
                    ? "Benutzer-ID <strong>{$id}</strong> gelöscht."
                    : "Benutzer-ID <strong>{$id}</strong> nicht gefunden.";
                break;
            case 'search_user':
                $searchTerm = trim($_POST['search_term'] ?? '');
                $searchId = trim($_POST['search_id'] ?? '');
                if (!empty($searchId)) {
                    $perf = FlatFileDBStatistics::measurePerformance(function() use ($usersTable, $searchId) {
                        return $usersTable->findRecords([], id: (int)$searchId);
                    });
                    $searchResults = $perf['result'];
                    $operationPerformance = "Search by ID: " . number_format($perf['duration'], 4) . " s";
                    $message = "Suche nach ID <strong>{$searchId}</strong> durchgeführt.";
                } else {
                    $perf = FlatFileDBStatistics::measurePerformance(function() use ($usersTable, $searchTerm) {
                        return $usersTable->findRecords([
                            ['field' => 'name', 'operator' => 'LIKE', 'value' => $searchTerm]
                        ]);
                    });
                    $searchResults = $perf['result'];
                    $operationPerformance = "Search by Name: " . number_format($perf['duration'], 4) . " s";
                    $message = "Suche nach Namen <strong>{$searchTerm}</strong> durchgeführt.";
                }
                break;
            case 'compact_table':
                $perf = FlatFileDBStatistics::measurePerformance(function() use ($usersTable) {
                    $usersTable->compactTable();
                    return true;
                });
                $operationPerformance = "Compact: " . number_format($perf['duration'], 4) . " s";
                $message = "Tabelle 'users' kompaktiert.";
                break;
            case 'clear_database':
                $perf = FlatFileDBStatistics::measurePerformance(function() use ($db) {
                    $db->clearDatabase();
                    return true;
                });
                $operationPerformance = "Clear DB: " . number_format($perf['duration'], 4) . " s";
                $message = "Datenbank geleert.";
                break;
            case 'backup_db':
                $perf = FlatFileDBStatistics::measurePerformance(function() use ($db) {
                    return $db->createBackup(FlatFileDBConstants::DEFAULT_BACKUP_DIR);
                });
                $operationPerformance = "Backup: " . number_format($perf['duration'], 4) . " s";
                $message = "Backup erstellt.";
                break;
            default:
                $message = "Unbekannte Aktion.";
        }
    } catch (\Exception $e) {
        $message = "Fehler: " . $e->getMessage();
    }
}

// Alle Benutzer-Datensätze laden und Ladezeit der DB-Daten messen
$usersStartTime = microtime(true);
$users = $db->table('users')->selectAllRecords();
$loadUsersDuration = microtime(true) - $usersStartTime;

// Zusätzliche Metriken:

// Dateigrößen (Daten-, Index- und Log-Datei)
$config = $db->table('users')->getConfig();
$dataFileSize  = file_exists($config->getDataFile())  ? filesize($config->getDataFile())  : 0;
$indexFileSize = file_exists($config->getIndexFile()) ? filesize($config->getIndexFile()) : 0;
$logFileSize   = file_exists($config->getLogFile())   ? filesize($config->getLogFile())   : 0;

// Memory Usage
$currentMemory = memory_get_usage(true);
$peakMemory    = memory_get_peak_usage(true);

// PHP-Ausführungszeit (serverseitig gemessen)
$totalExecutionTime = microtime(true) - $startTime;
$overallPerformance = "Seitenladezeit: " . number_format($totalExecutionTime, 4) . " s";

/*
 * FRONTEND LADEN
 */

 

include('layout.php');