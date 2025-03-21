<?php
declare(strict_types=1);

// ===========================================================
// PHP-Fehleranzeige für Entwicklungsumgebungen aktivieren
// (Bei Live-Systemen unbedingt abschalten!)
// ===========================================================
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// ===========================================================
// Alle benötigten Klassen einbinden
// Passe die Pfade ggf. an deinen Ordner an.
// ===========================================================
require_once __DIR__ . '/../flatfiledb/FlatFileConfig.class.php';
require_once __DIR__ . '/../flatfiledb/FlatFileDatabase.class.php';
require_once __DIR__ . '/../flatfiledb/FlatFileDBConstants.class.php';
require_once __DIR__ . '/../flatfiledb/FlatFileFileManager.class.php';
require_once __DIR__ . '/../flatfiledb/FlatFileIndexBuilder.class.php';
require_once __DIR__ . '/../flatfiledb/FlatFileTableEngine.class.php';
require_once __DIR__ . '/../flatfiledb/FlatFileTransactionLog.class.php';
require_once __DIR__ . '/../flatfiledb/FlatFileValidator.class.php';

// Namespace-Importe
use FlatFileDB\FlatFileDatabase;
use FlatFileDB\FlatFileDBConstants;

// ===========================================================
// Datenbank-Initialisierung
// ===========================================================
$db = new FlatFileDatabase(
    FlatFileDBConstants::DEFAULT_BASE_DIR, // Basis-Ordner für Daten
    false                                  // autoCommitIndex = false (Index wird manuell gespeichert)
);

// Tabellen registrieren
$db->registerTables(['users', 'products']);

// Schema für "users" definieren (Pflichtfelder & Typen)
$db->table('users')->setSchema(
    ['name', 'email'],                     // Pflichtfelder
    ['name' => 'string', 'email' => 'string', 'age' => 'int']  // erwartete Typen
);

// ===========================================================
// Formular-Verarbeitung
// ===========================================================
$message = '';
$searchResults = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Aktion auslesen
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            // -----------------------------------------------------------
            // Neuen Benutzer einfügen
            // -----------------------------------------------------------
            case 'insert_user':
                $id    = trim($_POST['user_id'] ?? '');
                $name  = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $age   = (int)($_POST['age'] ?? 0);

                $success = $db->table('users')->insertRecord($id, [
                    'name'  => $name,
                    'email' => $email,
                    'age'   => $age
                ]);

                $message = $success
                    ? "Benutzer mit der ID <strong>{$id}</strong> wurde erfolgreich eingefügt."
                    : "Fehler: Ein Benutzer mit der ID <strong>{$id}</strong> existiert bereits.";

                // Index (manuell) speichern
                $db->commitAllIndexes();
                break;

            // -----------------------------------------------------------
            // Benutzer aktualisieren
            // -----------------------------------------------------------
            case 'update_user':
                $id    = trim($_POST['update_user_id'] ?? '');
                $name  = trim($_POST['update_name'] ?? '');
                $email = trim($_POST['update_email'] ?? '');
                $age   = (int)($_POST['update_age'] ?? 0);

                $success = $db->table('users')->updateRecord($id, [
                    'name'  => $name,
                    'email' => $email,
                    'age'   => $age
                ]);

                $message = $success
                    ? "Benutzer mit der ID <strong>{$id}</strong> wurde erfolgreich aktualisiert."
                    : "Fehler: Benutzer mit der ID <strong>{$id}</strong> konnte nicht gefunden werden.";

                // Index speichern
                $db->commitAllIndexes();
                break;

            // -----------------------------------------------------------
            // Benutzer löschen
            // -----------------------------------------------------------
            case 'delete_user':
                $id = trim($_POST['delete_user_id'] ?? '');
                $success = $db->table('users')->deleteRecord($id);

                $message = $success
                    ? "Benutzer mit der ID <strong>{$id}</strong> wurde erfolgreich gelöscht."
                    : "Fehler: Benutzer mit der ID <strong>{$id}</strong> konnte nicht gefunden werden.";

                // Index speichern
                $db->commitAllIndexes();
                break;

            // -----------------------------------------------------------
            // Benutzer suchen
            // -----------------------------------------------------------
            case 'search_user':
                $searchTerm = trim($_POST['search_term'] ?? '');

                // Beispiel: Suche nach Benutzern, deren Name den Suchbegriff enthält (Groß-/Kleinschreibung ignoriert)
                $searchResults = $db->table('users')->findRecords(
                    function($record) use ($searchTerm) {
                        return stripos($record['name'], $searchTerm) !== false;
                    }
                );

                $message = "Suche nach Benutzern mit dem Begriff <strong>{$searchTerm}</strong> durchgeführt.";
                break;

            // -----------------------------------------------------------
            // Tabelle "users" kompaktieren
            // -----------------------------------------------------------
            case 'compact_table':
                $db->table('users')->compactTable();
                $message = "Tabelle 'users' wurde kompaktiert.";
                break;

            // -----------------------------------------------------------
            // Komplette Datenbank leeren
            // -----------------------------------------------------------
            case 'clear_database':
                $db->clearDatabase();
                $message = "Die Datenbank wurde geleert.";
                break;

            // -----------------------------------------------------------
            // Backup erstellen
            // -----------------------------------------------------------
            case 'backup_db':
                $db->createBackup(FlatFileDBConstants::DEFAULT_BACKUP_DIR);
                $message = "Backup wurde erstellt.";
                break;

            // -----------------------------------------------------------
            // Standardfall (unbekannte Aktion)
            // -----------------------------------------------------------
            default:
                $message = "Unbekannte Aktion.";
        }
    } catch (\Exception $e) {
        $message = "Fehler: " . $e->getMessage();
    }
}

// Änderungen sichern (zur Sicherheit immer kurz vor dem Ende)
$db->commitAllIndexes();

// Lese alle aktiven Benutzer-Datensätze
$users = $db->table('users')->selectAllRecords();

/*
 * FRONTEND LADEN
 */

include('layout.php');