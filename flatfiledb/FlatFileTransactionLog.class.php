<?php
declare(strict_types=1);

namespace FlatFileDB;

use InvalidArgumentException;
use RuntimeException;
use JsonException;
use Throwable;

/**
 * Zeichnet Änderungen (Insert, Update, Delete) in einer Log-Datei im JSON Lines Format auf.
 */
class FlatFileTransactionLog
{
    private FlatFileConfig $config;
    private string $logFile;

    /**
     * @param FlatFileConfig $config Konfiguration der Tabelle
     * @throws RuntimeException Wenn das Log-Verzeichnis/Datei nicht erstellt/beschrieben werden kann.
     */
    public function __construct(FlatFileConfig $config)
    {
        $this->config = $config;
        $this->logFile = $this->config->getLogFile();
        $logDir = dirname($this->logFile);

        // Verzeichnis erstellen falls erforderlich
        if (!is_dir($logDir)) {
            if (!@mkdir($logDir, FlatFileDBConstants::DEFAULT_DIR_PERMISSIONS, true)) {
                if (!is_dir($logDir)) { // Check again after mkdir attempt
                    throw new RuntimeException("Log-Verzeichnis '$logDir' konnte nicht erstellt werden.");
                }
            }
        } elseif (!is_writable($logDir)) {
            throw new RuntimeException("Log-Verzeichnis '$logDir' ist nicht beschreibbar.");
        }

        // Log-Datei erstellen oder sicherstellen, dass sie beschreibbar ist
        if (!file_exists($this->logFile)) {
            // Use file_put_contents for atomic creation if possible, fallback to touch
            if (@file_put_contents($this->logFile, '', LOCK_EX | FILE_APPEND) === false) {
                if (!file_exists($this->logFile)) { // Check again
                    throw new RuntimeException("Log-Datei '{$this->logFile}' konnte nicht erstellt werden.");
                }
                if (!is_writable($this->logFile)) {
                    throw new RuntimeException("Log-Datei '{$this->logFile}' ist nicht beschreibbar (nach Erstellversuch).");
                }
            }
        } elseif (!is_writable($this->logFile)) {
            throw new RuntimeException("Log-Datei '{$this->logFile}' ist nicht beschreibbar.");
        }
    }

    /**
     * Schreibt einen Eintrag ins Transaktionslog.
     *
     * @param string $action Die Aktion (z.B. FlatFileDBConstants::LOG_ACTION_INSERT).
     * @param string|int $recordId ID des betroffenen Datensatzes.
     * @param array<mixed>|null $data Optionale Daten (z.B. der eingefügte/aktualisierte Datensatz). Null für Delete.
     * @throws InvalidArgumentException wenn die ID ungültig ist.
     * @throws RuntimeException wenn das Log nicht geschrieben werden kann.
     * @throws JsonException wenn die Daten nicht JSON-kodiert werden können.
     */
    public function writeLog(string $action, string|int $recordId, ?array $data = null): void
    {
        // Validate ID using the central validator
        if (!FlatFileValidator::isValidId($recordId)) {
            throw new InvalidArgumentException("Ungültige Record-ID '$recordId' für Log-Eintrag.");
        }
        $stringRecordId = (string) $recordId; // Use string in log entry

        // Validate Action? Optional, assume constants are used correctly.
        // if (!in_array($action, [FlatFileDBConstants::LOG_ACTION_INSERT, ...], true)) ...

        $entry = [
            // Use ISO 8601 format with microseconds for better precision and compatibility
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.uP'), // e.g., 2023-10-27T10:30:00.123456+00:00
            'action' => $action,
            'recordId' => $stringRecordId,
            // Include 'data' key even if null for consistent structure, unless it's DELETE action?
            // Let's keep it simple: include data if provided, otherwise key might be absent or null.
            // Explicitly setting null if data is null.
            'data' => $data,
        ];

        // Encode entry as JSON line first to catch errors early
        try {
            $encoded = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $line = $encoded . "\n";
        } catch (JsonException $e) {
            throw new RuntimeException("Fehler beim JSON-Codieren des Log-Eintrags für ID {$stringRecordId}: " . $e->getMessage(), 0, $e);
        }

        // Append to file with exclusive lock
        // FILE_APPEND flag for file_put_contents is atomic on many systems
        $result = @file_put_contents($this->logFile, $line, LOCK_EX | FILE_APPEND);

        if ($result === false) {
            $error = error_get_last();
            $errorMsg = $error ? " ({$error['message']})" : "";
            throw new RuntimeException("Fehler beim Schreiben des Log-Eintrags in '{$this->logFile}'{$errorMsg}.");
        }
        // Check if written bytes match line length? file_put_contents returns bytes written or false.
        // if ($result < strlen($line)) { ... } // Might indicate disk full etc.
    }

    /**
     * Liest das Transaktionslog zeilenweise mit einem Generator.
     *
     * @yield array Der dekodierte Log-Eintrag.
     * @throws RuntimeException Wenn die Log-Datei nicht gelesen werden kann.
     */
    public function readLogGenerator(): \Generator
    {
        if (!file_exists($this->logFile)) {
            return; // No log file, yield nothing
        }
        if (!is_readable($this->logFile)) {
            throw new RuntimeException("Log-Datei '{$this->logFile}' ist nicht lesbar.");
        }

        $handle = fopen($this->logFile, 'rb');
        if (!$handle) {
            $error = error_get_last();
            $errorMsg = $error ? " ({$error['message']})" : "";
            throw new RuntimeException("Log-Datei '{$this->logFile}' konnte nicht geöffnet werden{$errorMsg}.");
        }

        try {
            // Shared lock for reading
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException("Konnte keine Lesesperre für die Log-Datei '{$this->logFile}' erhalten.");
            }

            $lineNum = 0;
            while (($line = fgets($handle)) !== false) {
                $lineNum++;
                $trimmedLine = trim($line);
                if ($trimmedLine === '') {
                    continue; // Skip empty lines
                }

                // Try to decode the line
                try {
                    $entry = json_decode($trimmedLine, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($entry)) {
                        throw new JsonException("Dekodierter Log-Eintrag ist kein Array.");
                    }
                    yield $entry; // Yield the decoded entry

                } catch (JsonException $e) {
                    // Log error about corrupted line and continue to next line
                    error_log("Fehler beim Dekodieren von Zeile $lineNum in Log-Datei '{$this->logFile}': " . $e->getMessage() . " - Inhalt (gekürzt): " . substr($trimmedLine, 0, 100));
                    // Skip corrupted line
                    continue;
                }
            } // End while

        } finally {
            if ($handle) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }


    /**
     * Liest das Transaktionslog (verwendet jetzt den Generator).
     *
     * @param int $limit Maximale Anzahl der zurückgegebenen Einträge (0 = alle).
     * @param int $offset Überspringt die ersten n Einträge.
     * @return list<array> Liste der Log-Einträge (älteste zuerst).
     * @throws RuntimeException Wenn die Log-Datei nicht gelesen werden kann.
     * @throws InvalidArgumentException Wenn limit/offset negativ sind.
     */
    public function readLog(int $limit = 0, int $offset = 0): array
    {
        if ($limit < 0 || $offset < 0) {
            throw new InvalidArgumentException("Limit und Offset dürfen nicht negativ sein.");
        }

        $entries = [];
        $count = 0;
        $skipped = 0;

        try {
            foreach ($this->readLogGenerator() as $entry) {
                // Handle offset
                if ($offset > 0 && $skipped < $offset) {
                    $skipped++;
                    continue;
                }

                // Add entry
                $entries[] = $entry;
                $count++;

                // Check limit
                if ($limit > 0 && $count >= $limit) {
                    break;
                }
            }
        } catch (Throwable $e) {
            // Catch RuntimeException from generator
            throw new RuntimeException("Fehler beim Lesen des Logs: " . $e->getMessage(), 0, $e);
        }

        // Optional: Reverse array to get newest entries first?
        // return array_reverse($entries);
        return $entries;
    }

    /**
     * Rotiert das Transaktionslog: Verschiebt die aktuelle Log-Datei in ein Backup
     * und erstellt eine neue, leere Log-Datei. Verwendet Locks für mehr Sicherheit.
     *
     * @param string|null $backupDir Optional: Verzeichnis für das Backup. Wenn null, wird das Log nur geleert.
     *                               Das Verzeichnis muss existieren und beschreibbar sein.
     * @return string|null Pfad zur erstellten Backup-Datei oder null, wenn kein Backup erstellt/Log nicht rotiert wurde.
     * @throws RuntimeException Bei Fehlern während der Rotation oder wenn Backup-Verzeichnis ungültig ist.
     */
    public function rotateLog(?string $backupDir = null): ?string
    {
        $rotationLockFile = $this->logFile . '.rotate.lock';
        $backupPath = null;

        // Check if log file exists and has content > 0
        clearstatcache(true, $this->logFile);
        if (!file_exists($this->logFile) || @filesize($this->logFile) === 0) {
            // Also check for false return from filesize
            if (@filesize($this->logFile) === false && file_exists($this->logFile)) {
                error_log("Konnte Größe der Log-Datei '{$this->logFile}' nicht bestimmen für Rotation.");
            }
            return null; // Nothing to rotate or error getting size
        }

        // Validate backup directory if provided
        if ($backupDir !== null) {
            $trimmedBackupDir = rtrim($backupDir, '/\\');
            if (!is_dir($trimmedBackupDir) || !is_writable($trimmedBackupDir)) {
                throw new RuntimeException("Backup-Verzeichnis '$trimmedBackupDir' für Log-Rotation existiert nicht oder ist nicht beschreibbar.");
            }
            $realBackupDir = realpath($trimmedBackupDir);
            if ($realBackupDir === false) {
                throw new RuntimeException("Konnte realen Pfad für Log-Backup-Verzeichnis nicht auflösen: $trimmedBackupDir");
            }
            $backupDir = $realBackupDir; // Use real path

            // Create unique timestamped backup filename
            $timestamp = date('YmdHis') . '_' . bin2hex(random_bytes(4));
            $backupPath = $backupDir . DIRECTORY_SEPARATOR . basename($this->logFile) . '.bak.' . $timestamp;
        }


        // --- Acquire Rotation Lock ---
        $lockHandle = fopen($rotationLockFile, 'c');
        if (!$lockHandle) {
            @unlink($rotationLockFile); // Attempt to delete potentially stale lock file
            $lockHandle = fopen($rotationLockFile, 'c');
            if (!$lockHandle) {
                $error = error_get_last();
                $errorMsg = $error ? " ({$error['message']})" : "";
                throw new RuntimeException("Konnte Rotations-Lock-Datei '$rotationLockFile' nicht öffnen/erstellen{$errorMsg}.");
            }
        }
        // Use blocking exclusive lock
        if (!flock($lockHandle, LOCK_EX)) {
            fclose($lockHandle);
            @unlink($rotationLockFile);
            throw new RuntimeException("Konnte exklusive Sperre für Rotations-Lock '$rotationLockFile' nicht erhalten (läuft bereits?).");
        }
        // --- Rotation Lock Acquired ---

        try {
            // Use rename for atomicity if creating backup
            if ($backupPath !== null) {
                // Rename the current log file to the backup path
                if (!@rename($this->logFile, $backupPath)) {
                    $error = error_get_last();
                    $errorMsg = $error ? " ({$error['message']})" : "";
                    // Rename failed (e.g., cross-filesystem). Try copy & truncate instead.
                    error_log("Log-Rotation: Rename von '{$this->logFile}' nach '$backupPath' fehlgeschlagen{$errorMsg}, versuche Copy/Truncate...");

                    // Try copy
                    if (!@copy($this->logFile, $backupPath)) {
                        $copyError = error_get_last();
                        $copyErrorMsg = $copyError ? " ({$copyError['message']})" : "";
                        throw new RuntimeException("Log-Rotation: Backup nach '$backupPath' konnte nicht erstellt werden (Copy fehlgeschlagen{$copyErrorMsg}).");
                    }

                    // Copy succeeded, now truncate original file using file_put_contents for locking
                    if (@file_put_contents($this->logFile, '', LOCK_EX) === false) {
                        $truncateError = error_get_last();
                        $truncateErrorMsg = $truncateError ? " ({$truncateError['message']})" : "";
                        // VERY BAD: Copied but couldn't truncate! Restore backup? Or delete incomplete backup? Delete backup.
                        @unlink($backupPath);
                        throw new RuntimeException("Log-Rotation: Backup nach '$backupPath' erstellt, aber Original '{$this->logFile}' konnte nicht geleert werden!{$truncateErrorMsg}");
                    }
                    // Copy & truncate succeeded.
                } else {
                    // Rename succeeded. The original file is gone. Recreate it empty.
                    if (@file_put_contents($this->logFile, '', LOCK_EX) === false) {
                        $recreateError = error_get_last();
                        $recreateErrorMsg = $recreateError ? " ({$recreateError['message']})" : "";
                        // CRITICAL: Renamed old log, but couldn't create new one! Try to rename backup back.
                        if (!@rename($backupPath, $this->logFile)) {
                            $restoreError = error_get_last();
                            $restoreErrorMsg = $restoreError ? " ({$restoreError['message']})" : "";
                            throw new RuntimeException("KRITISCH: Log-Rotation: Log nach '$backupPath' verschoben, aber neue Log-Datei '{$this->logFile}' konnte nicht erstellt werden{$recreateErrorMsg} UND Backup konnte NICHT wiederhergestellt werden{$restoreErrorMsg}!");
                        } else {
                            throw new RuntimeException("Log-Rotation: Log nach '$backupPath' verschoben, aber neue Log-Datei '{$this->logFile}' konnte nicht erstellt werden{$recreateErrorMsg}. Backup wurde wiederhergestellt.");
                        }
                    }
                    // Rename and recreate succeeded.
                }
            } else {
                // No backup needed, just truncate the file using file_put_contents for locking
                if (@file_put_contents($this->logFile, '', LOCK_EX) === false) {
                    $error = error_get_last();
                    $errorMsg = $error ? " ({$error['message']})" : "";
                    throw new RuntimeException("Log-Datei '{$this->logFile}' konnte nicht geleert werden{$errorMsg}.");
                }
            }
            // --- Log File Rotated/Truncated ---

        } finally {
            // --- Release Rotation Lock ---
            if ($lockHandle) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
                @unlink($rotationLockFile); // Clean up lock file
            }
        } // End outer try/finally for rotationLockHandle

        return $backupPath;
    }
}