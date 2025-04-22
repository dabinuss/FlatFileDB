<?php
declare(strict_types=1);

namespace FlatFileDB;

use InvalidArgumentException;
use RuntimeException;
use Throwable; // Import Throwable for broader exception catching

/**
 * Hauptklasse zur Verwaltung mehrerer Tabellen.
 */
class FlatFileDatabase
{
    private string $baseDir;
    /** @var array<string, FlatFileTableEngine> */
    private array $tables = [];

    /**
     * @param string $baseDir Basisverzeichnis für die Datenbankdateien (Standard: FlatFileDBConstants::DEFAULT_BASE_DIR)
     * @throws RuntimeException Wenn das Basisverzeichnis nicht existiert/erstellt oder beschrieben werden kann.
     */
    public function __construct(string $baseDir = FlatFileDBConstants::DEFAULT_BASE_DIR)
    {
        // Trim trailing slashes early
        $trimmedBaseDir = rtrim($baseDir, '/\\'); // Handle both slashes

        // Check if directory exists or can be created
        if (!is_dir($trimmedBaseDir)) {

            if (!@mkdir($trimmedBaseDir, FlatFileDBConstants::DEFAULT_DIR_PERMISSIONS, true)) {

                if (!is_dir($trimmedBaseDir)) {

                    $error = error_get_last();
                    $errorMsg = $error ? " (Error: {$error['message']})" : "";
                    throw new RuntimeException("Datenbank-Verzeichnis '{$trimmedBaseDir}' konnte nicht erstellt werden.{$errorMsg}");
                }
            }
            // mkdir succeeded or directory already exists now
        }

        // Resolve the real path AFTER ensuring it exists
        $realBaseDir = realpath($trimmedBaseDir);
        if ($realBaseDir === false) {
            // Should not happen if is_dir or mkdir succeeded, but check anyway
            throw new RuntimeException("Konnte den realen Pfad für das Basisverzeichnis '{$trimmedBaseDir}' nicht auflösen.");
        }

        // Ensure the path is writable
        if (!is_writable($realBaseDir)) {
            throw new RuntimeException("Basisverzeichnis '{$realBaseDir}' ist nicht beschreibbar.");
        }

        $this->baseDir = $realBaseDir;
    }

    /**
     * Registriert eine Tabelle und erzeugt die zugehörige Engine.
     *
     * @param string $tableName Name der Tabelle
     * @return FlatFileTableEngine Die erstellte Engine-Instanz
     * @throws InvalidArgumentException wenn der Tabellenname ungültig ist
     * @throws RuntimeException wenn die Engine nicht erstellt werden kann
     */
    public function registerTable(string $tableName): FlatFileTableEngine
    {
        // Validate table name: not empty, no invalid file system characters
        $trimmedTableName = trim($tableName);
        // Check for common invalid chars, emptiness, and path traversal attempts
        if (
            empty($trimmedTableName)
            || strpbrk($trimmedTableName, "/\\:*?\"<>|") !== false
            || preg_match('/^[ .]+$/', $trimmedTableName) // Avoid names like '.', '..', '...'
            || $trimmedTableName !== preg_replace('/[^-._a-zA-Z0-9]/', '_', $trimmedTableName) // Ensure reasonable characters
        ) {
            throw new InvalidArgumentException("Ungültiger Tabellenname: '$tableName'. Nur alphanumerische Zeichen, -, _, . erlaubt. Darf nicht leer oder nur Punkte/Leerzeichen sein.");
        }
        // Use the validated name from now on
        $tableName = $trimmedTableName;

        if (isset($this->tables[$tableName])) {
            return $this->tables[$tableName]; // Already registered
        }

        $baseName = $this->baseDir . DIRECTORY_SEPARATOR . $tableName;
        $dataFile = $baseName . '_data' . FlatFileDBConstants::DATA_FILE_EXTENSION;
        $indexFile = $baseName . '_index' . FlatFileDBConstants::INDEX_FILE_EXTENSION;
        $logFile = $baseName . '_log' . FlatFileDBConstants::LOG_FILE_EXTENSION;

        $config = new FlatFileConfig($dataFile, $indexFile, $logFile);

        try {
            $engine = new FlatFileTableEngine($config);
            $this->tables[$tableName] = $engine;
            return $engine; // Return the engine instance
        } catch (Throwable $e) { // Catch any Throwable during engine init
            throw new RuntimeException("Fehler beim Initialisieren der Tabelle '$tableName': " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Gibt die Engine für eine Tabelle zurück.
     *
     * @param string $tableName Name der Tabelle
     * @return FlatFileTableEngine Engine für die angegebene Tabelle
     * @throws RuntimeException wenn die Tabelle nicht registriert ist
     */
    public function table(string $tableName): FlatFileTableEngine
    {
        if (!isset($this->tables[$tableName])) {
            // Option: Auto-register if not found? Currently requires explicit registration.
            // return $this->registerTable($tableName);
            throw new RuntimeException("Tabelle '$tableName' wurde nicht registriert. Rufen Sie zuerst registerTable() auf.");
        }
        return $this->tables[$tableName];
    }

    /**
     * Prüft, ob eine Tabelle registriert ist.
     *
     * @param string $tableName Name der Tabelle
     * @return bool True wenn registriert, sonst false
     */
    public function hasTable(string $tableName): bool
    {
        return isset($this->tables[$tableName]);
    }

    /**
     * Registriert mehrere Tabellen.
     *
     * @param list<string> $tableNames Liste der Tabellennamen
     * @return array<string, FlatFileTableEngine> Array der registrierten Engines
     */
    public function registerTables(array $tableNames): array
    {
        if (!array_is_list($tableNames)) {
            throw new InvalidArgumentException("registerTables erwartet eine Liste (list) von Tabellennamen.");
        }

        $registeredEngines = [];
        foreach ($tableNames as $tableName) {
            // Ensure it's a string before calling registerTable
            if (!is_string($tableName)) {
                trigger_error("Ungültiger Tabellenname im Array gefunden (kein String), wird übersprungen.", E_USER_WARNING);
                continue;
            }
            try {
                $registeredEngines[$tableName] = $this->registerTable($tableName);
            } catch (Throwable $e) {
                // Log or trigger error if registration fails for one table in the batch
                trigger_error("Fehler beim Registrieren der Tabelle '$tableName': " . $e->getMessage(), E_USER_WARNING);
                // Optionally: collect errors and throw an aggregate exception at the end
            }
        }
        return $registeredEngines;
    }

    /**
     * Kommittiert alle Index-Dateien (Primär- und Sekundärindizes) aller registrierten Tabellen.
     */
    public function commitAllIndexes(): void
    {
        foreach ($this->tables as $tableName => $engine) {
            try {
                // Commit primary index first (might be slightly more critical)
                $engine->commitIndex();
                // Then commit all dirty secondary indexes
                $engine->commitAllSecondaryIndexes();
            } catch (Throwable $e) {
                error_log("Fehler beim Committen der Indizes für Tabelle '$tableName': " . $e->getMessage());
                // Consider re-throwing or collecting errors to report later
            }
        }
    }

    /**
     * Kompaktiert alle registrierten Tabellen.
     *
     * @return array<string, bool|string> Status der Kompaktierung für jede Tabelle (true bei Erfolg, Fehlermeldung bei Fehler)
     */
    public function compactAllTables(): array
    {
        $results = [];
        foreach ($this->tables as $tableName => $engine) {
            try {
                $engine->compactTable();
                $results[$tableName] = true;
            } catch (Throwable $e) {
                $errMsg = "Kompaktierung fehlgeschlagen: " . $e->getMessage();
                $results[$tableName] = $errMsg;
                error_log("Fehler bei Kompaktierung der Tabelle '$tableName': " . $errMsg);
            }
        }
        return $results;
    }

    /**
     * Leert alle Caches aller registrierten Tabellen.
     */
    public function clearAllCaches(): void
    {
        foreach ($this->tables as $engine) {
            $engine->clearCache();
        }
    }

    /**
     * Gibt die Namen aller registrierten Tabellen zurück.
     *
     * @return list<string> Liste der Tabellennamen
     */
    public function getTableNames(): array
    {
        // Use array_values to ensure it's a list<string>
        return array_values(array_keys($this->tables));
    }

    /**
     * Erstellt ein Backup aller registrierten Tabellen.
     *
     * @param string $backupDir Verzeichnis für die Sicherungen
     * @return array<string, array|array{'error': string}> Status der Backups für jede Tabelle
     * @throws RuntimeException Wenn das Backup-Verzeichnis nicht erstellt/beschrieben werden kann.
     */
    public function createBackup(string $backupDir): array
    {
        $results = [];

        // Ensure backup directory exists and is writable
        $trimmedBackupDir = rtrim($backupDir, '/\\');
        if (!is_dir($trimmedBackupDir)) {
            if (!@mkdir($trimmedBackupDir, FlatFileDBConstants::DEFAULT_DIR_PERMISSIONS, true)) {
                if (!is_dir($trimmedBackupDir)) { // Check again after mkdir attempt
                    $error = error_get_last();
                    $errorMsg = $error ? " ({$error['message']})" : "";
                    throw new RuntimeException("Backup-Verzeichnis '{$trimmedBackupDir}' konnte nicht erstellt werden.{$errorMsg}");
                }
            }
        }
        // Resolve real path AFTER ensuring existence
        $realBackupDir = realpath($trimmedBackupDir);
        if ($realBackupDir === false) {
            throw new RuntimeException("Konnte den realen Pfad für das Backup-Verzeichnis '{$trimmedBackupDir}' nicht auflösen.");
        }
        if (!is_writable($realBackupDir)) {
            throw new RuntimeException("Backup-Verzeichnis '{$realBackupDir}' ist nicht beschreibbar.");
        }

        foreach ($this->tables as $tableName => $engine) {
            try {
                // Pass the validated, real path to the backup method
                $backupFiles = $engine->backup($realBackupDir);
                $results[$tableName] = $backupFiles;
            } catch (Throwable $e) {
                $errMsg = "Backup fehlgeschlagen: " . $e->getMessage();
                $results[$tableName] = ['error' => $errMsg];
                error_log("Fehler beim Backup der Tabelle '$tableName': " . $errMsg);
            }
        }
        return $results;
    }

    /**
     * Leert alle Daten und Indizes aller registrierten Tabellen.
     * ACHTUNG: Diese Operation löscht alle Daten in den registrierten Tabellen!
     *
     * @throws RuntimeException Wenn beim Leeren einer oder mehrerer Tabellen Fehler auftreten.
     */
    public function clearDatabase(): void
    {
        $errors = [];
        foreach ($this->tables as $tableName => $engine) {
            try {
                $engine->clearTable();
            } catch (Throwable $e) {
                $errors[$tableName] = $e->getMessage();
                error_log("Fehler beim Leeren der Tabelle '$tableName': " . $e->getMessage());
            }
        }

        if (!empty($errors)) {
            // Report all errors
            $errorMessages = [];
            foreach ($errors as $tableName => $message) {
                $errorMessages[] = "Tabelle '$tableName': $message";
            }
            throw new RuntimeException("Fehler beim Leeren der Datenbank:\n" . implode("\n", $errorMessages));
        }
    }

    /**
     * Entfernt eine Tabelle aus der Registrierung.
     *
     * @param string $tableName Name der zu entfernenden Tabelle
     * @return bool True wenn die Tabelle entfernt wurde, false wenn sie nicht existierte
     */
    public function unregisterTable(string $tableName): bool
    {
        if (!isset($this->tables[$tableName])) {
            return false; // Tabelle existiert nicht
        }
        
        // Ressourcen freigeben
        try {
            $engine = $this->tables[$tableName];
            $engine->clearCache();
            
            // Weitere Ressourcen-Freigabe (falls nötig)
            // ...
        } catch (Throwable $e) {
            error_log("Warnung: Fehler beim Freigeben von Ressourcen für Tabelle '$tableName': " . $e->getMessage());
        }
        
        // Aus der Tabellenliste entfernen
        unset($this->tables[$tableName]);
        return true;
    }
}