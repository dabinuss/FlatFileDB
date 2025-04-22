<?php
declare(strict_types=1);

namespace FlatFileDB;

use InvalidArgumentException;
use RuntimeException;
use JsonException;
use Throwable;

/**
 * Hauptklasse zur Verwaltung mehrerer Tabellen.
 */
class FlatFileDatabase
{
    private string $baseDir;
    private array $tables = [];

    private string $manifestFile;
    private string $manifestLockFile;
    private $manifestLockHandle = null;

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

        $this->manifestFile = $this->baseDir . DIRECTORY_SEPARATOR . FlatFileDBConstants::MANIFEST_FILE;
        $this->manifestLockFile = $this->baseDir . DIRECTORY_SEPARATOR . FlatFileDBConstants::MANIFEST_LOCK_FILE;
        $this->loadManifestAndRegisterTables();
    }

    public function __destruct()
    {
        $this->closeManifestLock();
    }

    // --- NEU: Manifest Lock Handling Methoden ---
    /** @throws RuntimeException */
    private function acquireManifestLock(int $lockType): void
    {
        if ($this->manifestLockHandle === null || !is_resource($this->manifestLockHandle)) {
            $this->manifestLockHandle = @fopen($this->manifestLockFile, 'c');
            if (!$this->manifestLockHandle) {
                @unlink($this->manifestLockFile); // Versuch, alten Lock zu löschen
                $this->manifestLockHandle = @fopen($this->manifestLockFile, 'c');
                if (!$this->manifestLockHandle) {
                    $error = error_get_last();
                    $errorMsg = $error ? " ({$error['message']})" : "";
                    throw new RuntimeException("Manifest-Lock-Datei '{$this->manifestLockFile}' konnte nicht geöffnet/erstellt werden{$errorMsg}.");
                }
            }
        }
        if (!flock($this->manifestLockHandle, $lockType)) {
            $lockName = ($lockType === LOCK_EX) ? 'exklusive' : 'geteilte';
            // Lock nicht schließen, da der Fehler unerwartet ist
            throw new RuntimeException("Konnte keine {$lockName} Sperre für Manifest-Lock '{$this->manifestLockFile}' erhalten.");
        }
    }

    private function releaseManifestLock(): void
    {
        if ($this->manifestLockHandle !== null && is_resource($this->manifestLockHandle)) {
            fflush($this->manifestLockHandle); // Wichtig vor Freigabe
            flock($this->manifestLockHandle, LOCK_UN);
        }
    }

    private function closeManifestLock(): void
    {
        if ($this->manifestLockHandle !== null && is_resource($this->manifestLockHandle)) {
            $this->releaseManifestLock(); // Sicherstellen, dass freigegeben
            @fclose($this->manifestLockHandle);
            $this->manifestLockHandle = null;
        }
    }

    /**
     * Liest das Manifest und registriert die darin enthaltenen Tabellen.
     * @throws RuntimeException Bei Lese-/JSON-Fehlern im Manifest.
     */
    private function loadManifestAndRegisterTables(): void
    {
        $tableNames = [];
        $this->acquireManifestLock(LOCK_SH); // Lesesperre
        try {
            clearstatcache(true, $this->manifestFile);
            if (file_exists($this->manifestFile)) {
                if (!is_readable($this->manifestFile)) {
                    throw new RuntimeException("Manifest-Datei '{$this->manifestFile}' ist nicht lesbar.");
                }
                $content = file_get_contents($this->manifestFile);
                if ($content === false) {
                    throw new RuntimeException("Fehler beim Lesen der Manifest-Datei '{$this->manifestFile}'.");
                }
                $trimmedContent = trim($content);
                if ($trimmedContent !== '' && $trimmedContent !== '[]') {
                    try {
                        $decoded = json_decode($trimmedContent, true, 512, JSON_THROW_ON_ERROR);
                        if (!is_array($decoded) || !array_is_list($decoded)) {
                            throw new JsonException("Manifest-Inhalt ist keine Liste.");
                        }
                        // Filtere ungültige Namen (sollten nicht vorkommen, aber sicher ist sicher)
                        $tableNames = array_filter($decoded, fn($name) => is_string($name) && trim($name) !== '');
                    } catch (JsonException $e) {
                        throw new RuntimeException("Fehler beim Dekodieren der Manifest-Datei '{$this->manifestFile}': " . $e->getMessage(), 0, $e);
                    }
                }
            }
            // Wenn Datei nicht existiert oder leer ist, $tableNames bleibt []
        } finally {
            $this->releaseManifestLock();
        }

        // Registriere die gefundenen Tabellen
        foreach ($tableNames as $tableName) {
            try {
                // Verwende die interne Registrierungsmethode, ohne Manifest erneut zu schreiben
                $this->performTableRegistration($tableName);
            } catch (Throwable $e) {
                error_log("Fehler beim automatischen Registrieren der Tabelle '{$tableName}' aus Manifest: " . $e->getMessage());
                // Fehler hier nicht weiterwerfen, damit die DB auch mit einer defekten Tabelle initialisiert werden kann
            }
        }
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
        // Validierung des Tabellennamens
        $tableName = $this->validateTableName($tableName);

        // Prüfen, ob bereits in dieser Instanz geladen
        if (isset($this->tables[$tableName])) {
            return $this->tables[$tableName];
        }

        // Führe die eigentliche Registrierung/Ladung durch
        // Diese Methode erstellt auch Verzeichnisse etc.
        $engine = $this->performTableRegistration($tableName);

        // Nur wenn die Tabelle vorher *nicht* bekannt war (also neu erstellt wurde),
        // müssen wir sie zum Manifest hinzufügen.
        // Wir prüfen dies, indem wir schauen, ob der Tabellenname im aktuell gelesenen Manifest *fehlt*.
        // (performTableRegistration fügt sie nur zum In-Memory $this->tables hinzu)

        // Prüfe Manifest erneut unter Sperre
        $this->acquireManifestLock(LOCK_SH);
        $currentManifestTables = [];
        try {
            if (file_exists($this->manifestFile)) {
                $content = file_get_contents($this->manifestFile);
                if ($content !== false && trim($content) !== '' && trim($content) !== '[]') {
                    try {
                        $decoded = json_decode(trim($content), true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decoded) && array_is_list($decoded)) {
                            $currentManifestTables = array_filter($decoded, fn($name) => is_string($name) && trim($name) !== '');
                        }
                    } catch (JsonException $e) {
                        // Ignoriere Fehler hier, da wir nur prüfen wollen, ob der Name fehlt
                        error_log("Hinweis: Fehler beim Lesen des Manifests während registerTable-Check: " . $e->getMessage());
                    }
                }
            }
        } finally {
            $this->releaseManifestLock();
        }


        // Wenn die Tabelle noch nicht im (frisch gelesenen) Manifest war, hinzufügen
        if (!in_array($tableName, $currentManifestTables, true)) {
            $this->addTableToManifest($tableName); // Diese Methode sperrt selbst (LOCK_EX)
        }


        return $engine;
    }

    /**
     * Validiert den Tabellennamen.
     * @throws InvalidArgumentException
     */
    private function validateTableName(string $tableName): string
    {
        $trimmedTableName = trim($tableName);
        if (
            empty($trimmedTableName)
            || strpbrk($trimmedTableName, "/\\:*?\"<>|") !== false
            || preg_match('/^[ .]+$/', $trimmedTableName) // Avoid names like '.', '..', '...'
            // Erlaube nur Zeichen, die sicher für Verzeichnisnamen sind
            || !preg_match('/^[a-zA-Z0-9_.-]+$/', $trimmedTableName)
        ) {
            throw new InvalidArgumentException("Ungültiger Tabellenname: '$tableName'. Nur Alphanumerisch, _, -, . erlaubt. Darf nicht leer oder nur Punkte/Leerzeichen sein und keine Pfadtrennzeichen enthalten.");
        }
        return $trimmedTableName;
    }

    /**
     * Führt die Kernlogik der Registrierung aus: Pfade erstellen, Engine instanziieren.
     * Fügt die Engine zu $this->tables hinzu.
     * @throws InvalidArgumentException | RuntimeException
     */
    private function performTableRegistration(string $tableName): FlatFileTableEngine
    {
        // Validiere Namen erneut (obwohl von außen schon geschehen)
        $tableName = $this->validateTableName($tableName);

        // Wenn schon in dieser Instanz geladen, direkt zurückgeben
        if (isset($this->tables[$tableName])) {
            return $this->tables[$tableName];
        }

        // --- NEUE Pfadlogik mit Unterverzeichnis pro Tabelle ---
        $tableDir = $this->baseDir . DIRECTORY_SEPARATOR . $tableName;

        // Stelle sicher, dass das Tabellenverzeichnis existiert
        if (!is_dir($tableDir)) {
            if (!@mkdir($tableDir, FlatFileDBConstants::DEFAULT_DIR_PERMISSIONS, true)) {
                if (!is_dir($tableDir)) { // Erneuter Check
                    $error = error_get_last();
                    $errorMsg = $error ? " ({$error['message']})" : "";
                    throw new RuntimeException("Tabellenverzeichnis '{$tableDir}' konnte nicht erstellt werden{$errorMsg}.");
                }
            }
        }
        if (!is_writable($tableDir)) {
            throw new RuntimeException("Tabellenverzeichnis '{$tableDir}' ist nicht beschreibbar.");
        }

        // Erstelle Pfade innerhalb des Tabellenverzeichnisses
        $dataFile = $tableDir . DIRECTORY_SEPARATOR . FlatFileDBConstants::TABLE_DATA_FILENAME . FlatFileDBConstants::DATA_FILE_EXTENSION;
        $indexFile = $tableDir . DIRECTORY_SEPARATOR . FlatFileDBConstants::TABLE_INDEX_FILENAME . FlatFileDBConstants::INDEX_FILE_EXTENSION;
        $logFile = $tableDir . DIRECTORY_SEPARATOR . FlatFileDBConstants::TABLE_LOG_FILENAME . FlatFileDBConstants::LOG_FILE_EXTENSION;
        // --- Ende NEUE Pfadlogik ---


        $config = new FlatFileConfig($dataFile, $indexFile, $logFile);

        try {
            // Engine instanziieren (Konstruktoren von Engine/FileManager etc. prüfen/erstellen Dateien)
            $engine = new FlatFileTableEngine($config);
            $this->tables[$tableName] = $engine;
            return $engine;
        } catch (Throwable $e) {
            throw new RuntimeException("Fehler beim Initialisieren der Tabelle '$tableName': " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Fügt einen Tabellennamen zum Manifest hinzu (atomar).
     * @throws RuntimeException
     */
    private function addTableToManifest(string $tableName): void
    {
        $this->acquireManifestLock(LOCK_EX); // Exklusive Sperre zum Schreiben
        $updated = false;
        try {
            $currentTables = [];
            // Lese aktuellen Inhalt (innerhalb der Sperre!)
            clearstatcache(true, $this->manifestFile);
            if (file_exists($this->manifestFile)) {
                $content = file_get_contents($this->manifestFile);
                if ($content !== false && trim($content) !== '' && trim($content) !== '[]') {
                    try {
                        $decoded = json_decode(trim($content), true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decoded) && array_is_list($decoded)) {
                            $currentTables = array_filter($decoded, fn($name) => is_string($name) && trim($name) !== '');
                        } else {
                            error_log("Warnung: Manifest '{$this->manifestFile}' hat ungültiges Format, wird überschrieben.");
                        }
                    } catch (JsonException $e) {
                        error_log("Warnung: Manifest '{$this->manifestFile}' ist korrupt, wird überschrieben: " . $e->getMessage());
                    }
                }
            }

            // Füge Namen hinzu, falls nicht vorhanden
            if (!in_array($tableName, $currentTables, true)) {
                $currentTables[] = $tableName;
                sort($currentTables); // Optional: Alphabetisch sortieren

                // Schreibe Manifest atomar über temporäre Datei
                $tmpFile = $this->manifestFile . '.tmp_' . bin2hex(random_bytes(4));
                $encoded = json_encode($currentTables, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

                if (@file_put_contents($tmpFile, $encoded) === false) {
                    @unlink($tmpFile); // Clean up temp file
                    throw new RuntimeException("Temporäre Manifest-Datei '$tmpFile' konnte nicht geschrieben werden.");
                }
                if (!@rename($tmpFile, $this->manifestFile)) {
                    @unlink($tmpFile);
                    throw new RuntimeException("Temporäre Manifest-Datei '$tmpFile' konnte nicht nach '{$this->manifestFile}' umbenannt werden.");
                }
                $updated = true;
            }
        } catch (Throwable $e) {
            // Fehler weiterwerfen, aber Lock freigeben
            throw new RuntimeException("Fehler beim Aktualisieren der Manifest-Datei '{$this->manifestFile}': " . $e->getMessage(), 0, $e);
        } finally {
            $this->releaseManifestLock();
        }
    }

    /**
     * Entfernt einen Tabellennamen aus dem Manifest (atomar).
     * @throws RuntimeException
     */
    private function removeTableFromManifest(string $tableName): void
    {
        $this->acquireManifestLock(LOCK_EX); // Exklusive Sperre zum Schreiben
        $updated = false;
        try {
            $currentTables = [];
            // Lese aktuellen Inhalt (innerhalb der Sperre!)
            clearstatcache(true, $this->manifestFile);
            if (file_exists($this->manifestFile)) {
                $content = file_get_contents($this->manifestFile);
                if ($content !== false && trim($content) !== '' && trim($content) !== '[]') {
                    try {
                        $decoded = json_decode(trim($content), true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decoded) && array_is_list($decoded)) {
                            $currentTables = array_filter($decoded, fn($name) => is_string($name) && trim($name) !== '');
                        } else {
                            error_log("Warnung: Manifest '{$this->manifestFile}' hat ungültiges Format beim Entfernen von '$tableName'.");
                            // Nicht weitermachen, da wir nicht wissen, was wir entfernen sollen
                            return;
                        }
                    } catch (JsonException $e) {
                        error_log("Warnung: Manifest '{$this->manifestFile}' ist korrupt beim Entfernen von '$tableName': " . $e->getMessage());
                        // Nicht weitermachen
                        return;
                    }
                }
            }

            // Entferne Namen, falls vorhanden
            $initialCount = count($currentTables);
            $currentTables = array_filter($currentTables, fn($name) => $name !== $tableName);

            // Nur schreiben, wenn sich etwas geändert hat
            if (count($currentTables) < $initialCount) {
                sort($currentTables); // Optional: Sortieren

                // Schreibe Manifest atomar über temporäre Datei
                $tmpFile = $this->manifestFile . '.tmp_' . bin2hex(random_bytes(4));
                // Verwende leeres Array [] wenn leer, nicht {}
                $encoded = json_encode(array_values($currentTables), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

                if (@file_put_contents($tmpFile, $encoded) === false) {
                    @unlink($tmpFile);
                    throw new RuntimeException("Temporäre Manifest-Datei '$tmpFile' konnte nicht geschrieben werden (beim Entfernen).");
                }
                if (!@rename($tmpFile, $this->manifestFile)) {
                    @unlink($tmpFile);
                    throw new RuntimeException("Temporäre Manifest-Datei '$tmpFile' konnte nicht nach '{$this->manifestFile}' umbenannt werden (beim Entfernen).");
                }
                $updated = true;
            }
        } catch (Throwable $e) {
            throw new RuntimeException("Fehler beim Aktualisieren der Manifest-Datei '{$this->manifestFile}' (beim Entfernen): " . $e->getMessage(), 0, $e);
        } finally {
            $this->releaseManifestLock();
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
        // Zuerst alle Tabellen leeren
        foreach (array_keys($this->tables) as $tableName) { // Kopiere Keys, um Array-Änderung zu erlauben
            try {
                // Engine holen (könnte durch vorherigen Fehler fehlen)
                if (isset($this->tables[$tableName])) {
                    $this->tables[$tableName]->clearTable();
                }
                // Tabelle deregistrieren (aus In-Memory UND Manifest)
                $this->unregisterTable($tableName);

                // Zusätzlich Tabellenverzeichnis löschen (clearTable löscht nur Inhalt)
                $tableDir = $this->baseDir . DIRECTORY_SEPARATOR . $tableName;
                if (is_dir($tableDir)) {
                    // Einfaches Löschen, wenn leer. Robuster wäre rekursives Löschen.
                    if (!@rmdir($tableDir)) {
                         // Versuche es erneut nach kurzer Pause (Dateisystem-Latenz?)
                         usleep(10000); // 10ms
                         if (!@rmdir($tableDir)) {
                            $error = error_get_last();
                            $errors[$tableName . '_dir'] = "Konnte Tabellenverzeichnis '$tableDir' nicht löschen" . ($error ? ": {$error['message']}" : '.');
                         }
                    }
                }

            } catch (Throwable $e) {
                $errors[$tableName] = $e->getMessage();
                error_log("Fehler beim Leeren/Deregistrieren der Tabelle '$tableName': " . $e->getMessage());
                 // Versuche trotzdem, aus Manifest zu entfernen
                try { $this->removeTableFromManifest($tableName); } catch(Throwable $ignore) {}
            }
        }

        // $this->tables sollte jetzt leer sein
        $this->tables = [];

        // Manifest explizit leeren (falls removeTableFromManifest Fehler hatte oder Tabellen fehlten)
        try {
             $this->acquireManifestLock(LOCK_EX);
             if (@file_put_contents($this->manifestFile, "[]", LOCK_EX) === false) {
                 @unlink($this->manifestFile); // Versuch, alte Datei zu löschen, falls Schreiben scheitert
                 if (@file_put_contents($this->manifestFile, "[]", LOCK_EX) === false) {
                    $errors['manifest_clear'] = "Manifest-Datei '{$this->manifestFile}' konnte nicht geleert werden.";
                 }
             }
        } catch (Throwable $e) {
             $errors['manifest_clear'] = "Fehler beim Leeren der Manifest-Datei: " . $e->getMessage();
        } finally {
            $this->releaseManifestLock();
        }


        if (!empty($errors)) {
            // Report all errors
            $errorMessages = ["Fehler beim Leeren der Datenbank '{$this->baseDir}':"];
            foreach ($errors as $context => $message) {
                $errorMessages[] = "- [$context]: $message";
            }
            throw new RuntimeException(implode("\n", $errorMessages));
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
            // Prüfe auch das Manifest, falls die Instanz inkonsistent ist
            // (Sollte nicht passieren, aber sicherheitshalber)
             $this->acquireManifestLock(LOCK_SH);
             $foundInManifest = false;
             try {
                if (file_exists($this->manifestFile)) {
                    $content = file_get_contents($this->manifestFile);
                    if ($content !== false && trim($content) !== '') {
                         try {
                            $decoded = json_decode(trim($content), true, 512, JSON_THROW_ON_ERROR);
                            if (is_array($decoded) && array_is_list($decoded)) {
                                $foundInManifest = in_array($tableName, $decoded, true);
                            }
                         } catch (JsonException $ignore) {}
                    }
                }
             } finally {
                $this->releaseManifestLock();
             }
             if (!$foundInManifest) return false; // Existierte weder In-Memory noch im Manifest
        }

        // Ressourcen freigeben (Cache leeren etc.)
        try {
            if (isset($this->tables[$tableName])) {
                $engine = $this->tables[$tableName];
                $engine->clearCache(); // Wichtig
                // TODO: Engine sollte vielleicht eine __destruct oder close Methode haben,
                // die intern Handles schließt, hier aufrufen? Momentan macht Engine das nicht explizit.
                $engine->getIndexBuilder()->closeIdLockHandle(); // ID Lock der Tabelle schließen
            }
        } catch (Throwable $e) {
            error_log("Warnung: Fehler beim Freigeben von Ressourcen für Tabelle '$tableName' während unregisterTable: " . $e->getMessage());
        }

        // Aus der In-Memory Tabellenliste entfernen
        unset($this->tables[$tableName]);

        // Aus dem Manifest entfernen
        try {
            $this->removeTableFromManifest($tableName);
        } catch (RuntimeException $e) {
             // Fehler loggen, aber da die Tabelle aus der Instanz entfernt wurde,
             // geben wir trotzdem true zurück, auch wenn das Manifest nicht aktualisiert werden konnte.
             error_log("Fehler beim Entfernen von Tabelle '$tableName' aus dem Manifest: " . $e->getMessage());
        }

        return true;
    }
}