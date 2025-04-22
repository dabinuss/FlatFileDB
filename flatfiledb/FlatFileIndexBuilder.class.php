<?php
declare(strict_types=1);

namespace FlatFileDB;

use RuntimeException;
use JsonException;
use InvalidArgumentException;
use Throwable;
use stdClass;

class FlatFileIndexBuilder
{

    private array $indexData = [];
    private array $secondaryIndexes = [];
    private array $secondaryIndexesDirty = [];

    private FlatFileConfig $config;
    private int $nextId = 1;
    private bool $indexDirty = false; // Primary index dirty flag

    private $idLockHandle = null;
    private string $idLockFile = '';
    private string $tableDir = '';

    /**
     * @param FlatFileConfig $config
     * @throws RuntimeException
     */
    public function __construct(FlatFileConfig $config)
    {
        $this->config = $config;
        $indexFile = $this->config->getIndexFile(); // Ist jetzt z.B. /path/to/db/users/index.json

        // --- NEU: Tabellenverzeichnis ableiten ---
        $this->tableDir = dirname($indexFile);
        // --- Ende NEU ---

        // ID Lock file path (im Tabellenverzeichnis!)
        $this->idLockFile = $this->tableDir . DIRECTORY_SEPARATOR . '.id.lock'; // Einfacherer Name

        // Ensure table directory exists and is writable (sollte durch DB-Konstruktor passiert sein, aber sicher ist sicher)
        if (!is_dir($this->tableDir)) {
             // Direkt Fehler werfen, da es existieren *muss*
             throw new RuntimeException("Tabellen-Index-Verzeichnis '{$this->tableDir}' existiert nicht.");
        }
        if (!is_writable($this->tableDir)) {
             throw new RuntimeException("Tabellen-Index-Verzeichnis '{$this->tableDir}' ist nicht beschreibbar.");
        }

        // Load primary index immediately, handle potential corruption
        try {
            $this->loadIndex(); // loadIndex prüft Existenz/Lesbarkeit von indexFile
        } catch (Throwable $e) {
            error_log("Fehler beim initialen Laden des Primärindex ({$indexFile}): " . $e->getMessage());
            if (!is_array($this->indexData)) $this->indexData = [];
            $this->recalculateNextId();
        }
        // Discover secondary indexes based on files *within the tableDir*
        $this->discoverSecondaryIndexes();
    }

    /**
     * Close the ID lock file handle on destruction.
     */
    public function __destruct()
    {
        if ($this->idLockHandle !== null && is_resource($this->idLockHandle)) {
            // Release lock before closing
            @flock($this->idLockHandle, LOCK_UN);
            @fclose($this->idLockHandle);
        }
        $this->idLockHandle = null;
    }

    /**
     * Recalculates the next available ID based on the current highest ID in the index.
     */
    private function recalculateNextId(): void
    {
        if (empty($this->indexData)) {
            $this->nextId = 1;
        } else {
            // Use max(array_keys(...)) which is efficient for integer keys
            $this->nextId = max(array_keys($this->indexData)) + 1;
        }
    }

    /**
     * Returns a copy of the current primary index data.
     * @return array<int, array{offset: int, length: int}>
     */
    public function getCurrentIndex(): array
    {
        return $this->indexData; // Return shallow copy
    }

    /**
     * Loads the primary index from its file. Handles file not found, corruption, and locking.
     * Resets index and attempts backup on corruption.
     * @throws RuntimeException If the file exists but cannot be read/locked.
     */
    private function loadIndex(): void
    {
        $indexFile = $this->config->getIndexFile();
        clearstatcache(true, $indexFile);

        // Case 1: Index file doesn't exist
        if (!file_exists($indexFile)) {
            $this->indexData = [];
            $this->indexDirty = false; // Not dirty, it's just empty
            $this->recalculateNextId();
            return;
        }

        // Case 2: Index file exists, try to read it
        if (!is_readable($indexFile)) {
            throw new RuntimeException("Indexdatei '$indexFile' existiert, ist aber nicht lesbar.");
        }

        $handle = @fopen($indexFile, 'rb');
        if (!$handle) {
            $error = error_get_last();
            $errorMsg = $error ? " ({$error['message']})" : "";
            throw new RuntimeException("Indexdatei '$indexFile' konnte nicht geöffnet werden ('rb'){$errorMsg}.");
        }

        try {
            // Acquire shared lock
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException("Konnte keine Lesesperre (LOCK_SH) für die Indexdatei '$indexFile' erhalten.");
            }

            // Read the entire file content
            $content = '';
            while (!feof($handle)) {
                $chunk = fread($handle, 8192); // Read in chunks
                if ($chunk === false) {
                    $error = error_get_last();
                    $errorMsg = $error ? " ({$error['message']})" : "";
                    throw new RuntimeException("Fehler beim Lesen (fread) der Indexdatei '$indexFile'{$errorMsg}.");
                }
                $content .= $chunk;
            }
            // Release lock early after reading
            flock($handle, LOCK_UN);
            fclose($handle); // Close handle after reading
            $handle = null; // Mark handle as closed

            // Trim whitespace
            $trimmedContent = trim($content);

            // Handle empty or effectively empty file
            if ($trimmedContent === '' || $trimmedContent === '{}' || $trimmedContent === '[]') {
                $this->indexData = [];
                $this->indexDirty = false;
                $this->recalculateNextId();
                return;
            }

            // Decode JSON
            try {
                $decodedData = json_decode($trimmedContent, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                // JSON Syntax Error - Corrupted file
                throw new JsonException("Fehler beim Dekodieren der Indexdatei '$indexFile': " . $e->getMessage(), 0, $e);
            }

            // Validate structure
            if (!is_array($decodedData)) {
                throw new RuntimeException("Indexdaten in '$indexFile' sind nach dem Dekodieren kein Array (Typ: " . get_debug_type($decodedData) . ").");
            }

            // Validate individual entries
            $validatedIndex = [];
            foreach ($decodedData as $key => $value) {
                // Validate key: Must be positive integer
                $intKey = filter_var($key, FILTER_VALIDATE_INT);
                if ($intKey === false || $intKey <= 0) {
                    error_log("Ungültiger oder nicht-positiver Schlüssel '$key' in Indexdatei '$indexFile' gefunden, wird übersprungen.");
                    continue;
                }

                // Validate value: Must be array with 'offset' and 'length' (positive or zero offset, positive length)
                if (
                    !is_array($value)
                    || !isset($value['offset']) || !is_int($value['offset']) || $value['offset'] < 0
                    || !isset($value['length']) || !is_int($value['length']) || $value['length'] <= 0
                ) {
                    error_log("Index-Eintrag für Record ID $intKey in '$indexFile' hat nicht das erwartete Format {offset: int>=0, length: int>0}, wird übersprungen. Gefunden: " . json_encode($value));
                    continue;
                }

                $validatedIndex[$intKey] = ['offset' => $value['offset'], 'length' => $value['length']];
            }

            // Update index data
            $this->indexData = $validatedIndex;
            $this->indexDirty = false; // Index loaded successfully, not dirty
            $this->recalculateNextId();

        } catch (Throwable $e) {
            // General error handling during load (includes JsonException, RuntimeException from validation)
            if ($handle && is_resource($handle)) { // Ensure lock is released and handle closed on error
                flock($handle, LOCK_UN);
                fclose($handle);
            }

            // Attempt to backup corrupted file
            $backupFile = $indexFile . '.corrupted_' . date('YmdHis');
            $renameSuccess = false;
            if (file_exists($indexFile)) {
                // Use @rename and check result
                if (@rename($indexFile, $backupFile)) {
                    $renameSuccess = true;
                    error_log("Beschädigte Indexdatei '$indexFile' wurde nach '$backupFile' verschoben. Index wird zurückgesetzt.");
                } else {
                    $error = error_get_last();
                    $errorMsg = $error ? " ({$error['message']})" : "";
                    error_log("Konnte beschädigte Indexdatei '$indexFile' nicht nach '$backupFile' umbenennen{$errorMsg}. Index wird zurückgesetzt.");
                }
            }

            // Reset index state
            $this->indexData = [];
            $this->indexDirty = true; // Mark as dirty because we reset it, needs saving
            $this->recalculateNextId();

            // Rethrow a wrapping exception providing context
            throw new RuntimeException(
                "Fehler beim Laden/Verarbeiten des Index aus '$indexFile': " . $e->getMessage()
                . ". Index wurde zurückgesetzt" . ($renameSuccess ? "" : " (Backup fehlgeschlagen!)") . ".",
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Gets the next available record ID atomically using a lock file.
     * Reloads index inside lock if necessary.
     *
     * @return int The next available ID.
     * @throws RuntimeException If locking fails or ID cannot be determined.
     */
    public function getNextId(): int
    {
        // Ensure lock file handle is open
        if ($this->idLockHandle === null || !is_resource($this->idLockHandle)) {
            $this->idLockHandle = @fopen($this->idLockFile, 'c'); // 'c' mode: open or create, pointer at beginning
            if (!$this->idLockHandle) {
                // Attempt to delete potentially stale lock file and retry
                @unlink($this->idLockFile);
                $this->idLockHandle = @fopen($this->idLockFile, 'c');
                if (!$this->idLockHandle) {
                    $error = error_get_last();
                    $errorMsg = $error ? " ({$error['message']})" : "";
                    throw new RuntimeException("Konnte ID-Lock-Datei '{$this->idLockFile}' nicht öffnen/erstellen{$errorMsg}.");
                }
            }
        }

        // Acquire exclusive lock (blocking)
        if (!flock($this->idLockHandle, LOCK_EX)) {
            // This shouldn't happen with a blocking lock unless there's a system issue
            throw new RuntimeException("Konnte exklusive Sperre (LOCK_EX) für ID-Lock-Datei '{$this->idLockFile}' nicht erhalten.");
        }

        // --- Critical Section ---
        try {
            // Option: Reload index *inside* the lock to ensure we have the absolute latest state?
            // This adds overhead but increases safety if multiple processes might update the index
            // without necessarily getting a new ID. Let's add it for robustness.
            // However, current logic mostly updates index *after* getting ID, so it might be overkill.
            // Let's keep it simple for now and rely on recalculateNextId being called after load/update.
            // $this->loadIndex(); // Potentially reload index here if high contention is expected

            // Get the current nextId
            $idToReturn = $this->nextId;

            // Increment for the next call
            $this->nextId++;

            // Optional: Write nextId to lock file? Not really necessary as state is in memory and main index.
            // ftruncate($this->idLockHandle, 0);
            // fwrite($this->idLockHandle, (string)$this->nextId);

        } catch (Throwable $e) {
            // Catch any error during the process within the lock
            throw new RuntimeException("Fehler beim Ermitteln der nächsten ID innerhalb der Sperre: " . $e->getMessage(), (int) $e->getCode(), $e);
        } finally {
            // Release the lock
            fflush($this->idLockHandle); // Ensure any potential writes are flushed
            flock($this->idLockHandle, LOCK_UN);
        }
        // --- End Critical Section ---

        return $idToReturn;
    }

    /**
     * Saves the primary index to its file if it's marked as dirty.
     * Uses a temporary file and rename for atomicity.
     * @throws RuntimeException If saving fails (write, rename, lock).
     * @throws JsonException If encoding fails.
     */
    public function commitIndex(): void
    {
        // Only save if changes were made
        if (!$this->indexDirty) {
            return;
        }

        $indexFile = $this->config->getIndexFile();
        $tmpFile = $indexFile . '.tmp_' . bin2hex(random_bytes(4));

        // Ensure temp file doesn't exist from previous failed attempts
        if (file_exists($tmpFile)) {
            @unlink($tmpFile);
        }

        try {
            // Encode the index data. Use stdClass for empty array to get "{}" instead of "[]".
            $dataToEncode = empty($this->indexData) ? new stdClass : $this->indexData;
            // Use PRETTY_PRINT for readability, remove if performance is critical
            $encoded = json_encode($dataToEncode, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // Write to temporary file with exclusive lock
            // file_put_contents with LOCK_EX handles fopen, flock, fwrite, fclose atomically (mostly)
            $result = @file_put_contents($tmpFile, $encoded, LOCK_EX);
            if ($result === false) {
                // Writing failed
                @unlink($tmpFile); // Clean up failed temp file
                $error = error_get_last();
                $errorMsg = $error ? " ({$error['message']})" : "";
                throw new RuntimeException("Indexdatei '$tmpFile' konnte nicht geschrieben werden{$errorMsg}.");
            }
            // Check if correct amount of data was written (optional sanity check)
            if ($result < strlen($encoded)) {
                @unlink($tmpFile);
                throw new RuntimeException("Unvollständiger Schreibvorgang für Indexdatei '$tmpFile'.");
            }


            // Atomically replace the old index file with the new one
            if (!@rename($tmpFile, $indexFile)) {
                @unlink($tmpFile); // Clean up temp file if rename fails
                $error = error_get_last();
                $errorMsg = $error ? " ({$error['message']})" : "";
                // On failure, the original index file (if any) remains untouched.
                throw new RuntimeException("Temporäre Indexdatei '$tmpFile' konnte nicht nach '$indexFile' umbenannt werden{$errorMsg}. Änderungen nicht gespeichert.");
            }

            // Success! Mark index as no longer dirty.
            $this->indexDirty = false;

        } catch (Throwable $e) {
            // General error handling: Clean up temp file
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }

            // Re-throw specific exceptions or a general runtime exception
            if ($e instanceof JsonException) {
                throw new RuntimeException("Fehler beim JSON-Codieren des Primärindex: " . $e->getMessage(), 0, $e);
            }
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException("Fehler beim Speichern des Primärindex '$indexFile': " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Adds or updates an entry in the primary index.
     * Marks the index as dirty. Adjusts nextId if needed.
     *
     * @param int $recordId
     * @param array{offset: int, length: int} $blockInfo
     * @throws InvalidArgumentException
     */
    public function setIndex(int $recordId, array $blockInfo): void
    {
        // Validate ID
        if ($recordId <= 0) {
            throw new InvalidArgumentException("Record ID muss positiv sein, war: $recordId.");
        }
        // Validate block info
        if (
            !isset($blockInfo['offset']) || !is_int($blockInfo['offset']) || $blockInfo['offset'] < 0
            || !isset($blockInfo['length']) || !is_int($blockInfo['length']) || $blockInfo['length'] <= 0
        ) {
            throw new InvalidArgumentException("Ungültige Block-Informationen für Record ID $recordId: " . json_encode($blockInfo));
        }

        // Check if data actually changed to avoid unnecessary dirty flag
        if (!isset($this->indexData[$recordId]) || $this->indexData[$recordId] !== $blockInfo) {
            $this->indexData[$recordId] = $blockInfo;
            $this->indexDirty = true;

            // Update nextId if this ID is higher than the current nextId
            if ($recordId >= $this->nextId) {
                $this->nextId = $recordId + 1;
            }
        }
    }

    /**
     * Removes an entry from the primary index. Marks index as dirty if removal occurred.
     *
     * @param int $recordId
     * @throws InvalidArgumentException
     */
    public function removeIndex(int $recordId): void
    {
        if ($recordId <= 0)
            throw new InvalidArgumentException("Record ID muss positiv sein, war: $recordId.");

        // Only mark dirty if the key actually existed
        if (isset($this->indexData[$recordId])) {
            unset($this->indexData[$recordId]);
            $this->indexDirty = true;
            // Note: We don't decrement nextId here. IDs are typically not reused immediately.
        }
    }

    /**
     * Retrieves the index entry (offset and length) for a given record ID.
     *
     * @param int $recordId
     * @return array{offset: int, length: int}|null Null if ID not found.
     */
    public function getIndexEntry(int $recordId): ?array
    {
        if ($recordId <= 0)
            return null; // Or throw InvalidArgumentException? Consistent with others.
        // throw new InvalidArgumentException("Record ID muss positiv sein, war: $recordId.");
        return $this->indexData[$recordId] ?? null;
    }

    /**
     * Returns all record IDs currently in the primary index.
     * @return list<int>
     */
    public function getAllKeys(): array
    {
        // Use array_keys and ensure list type hint
        return array_keys($this->indexData);
    }

    /**
     * Checks if a record ID exists in the primary index.
     * @param int $recordId
     * @return bool
     */
    public function hasKey(int $recordId): bool
    {
        if ($recordId <= 0)
            return false; // Invalid IDs are never present
        return isset($this->indexData[$recordId]);
    }

    /**
     * Returns the number of records currently in the primary index.
     * @return int
     */
    public function count(): int
    {
        return count($this->indexData);
    }

    /**
     * Completely replaces the primary index data, usually after compaction.
     * Validates the new index data structure. Marks index as dirty if changed.
     *
     * @param array $newIndex New index data [recordId => ['offset' => int, 'length' => int]].
     * @throws RuntimeException If the new index structure is invalid.
     */
    public function updateIndex(array $newIndex): void
    {
        $validatedIndex = [];
        foreach ($newIndex as $key => $value) {
            // Validate key
            $intKey = filter_var($key, FILTER_VALIDATE_INT);
            if ($intKey === false || $intKey <= 0) {
                throw new RuntimeException("Ungültiger Index-Schlüssel beim Update: $key.");
            }
            // Validate value structure
            if (
                !is_array($value)
                || !isset($value['offset']) || !is_int($value['offset']) || $value['offset'] < 0
                || !isset($value['length']) || !is_int($value['length']) || $value['length'] <= 0
            ) {
                throw new RuntimeException("Ungültiger Indexeintrag für ID $intKey beim Update: " . json_encode($value));
            }
            $validatedIndex[$intKey] = ['offset' => $value['offset'], 'length' => $value['length']];
        }

        // Check if the index data actually changed before marking dirty
        if ($this->indexData != $validatedIndex) { // Array comparison works here
            $this->indexData = $validatedIndex;
            $this->indexDirty = true;
            $this->recalculateNextId(); // Recalculate next ID based on new index
        }
        // Make sure index is not marked dirty if content is identical
        else if ($this->indexDirty && $this->indexData === $validatedIndex) {
            $this->indexDirty = false;
        }
    }

    // --- Secondary Index Methods ---

    /**
     * Determines the file path for a secondary index based on the field name.
     * Sanitizes the field name for use in the file system.
     *
     * @param string $fieldName
     * @return string The full path to the secondary index file.
     * @throws InvalidArgumentException If the field name is empty or results in an invalid file name component.
     */
    public function getSecondaryIndexFilePath(string $fieldName): string
    {
        $trimmedFieldName = trim($fieldName);
        if ($trimmedFieldName === '') {
            throw new InvalidArgumentException("Feldname für Index darf nicht leer sein.");
        }

        // Sanitize field name
        $sanitizedFieldName = preg_replace('/[^-._a-zA-Z0-9]/', '_', $trimmedFieldName);
        if (trim($sanitizedFieldName, '._') === '') {
            throw new InvalidArgumentException("Feldname '$fieldName' für Index ergibt nach Bereinigung einen ungültigen String ('$sanitizedFieldName').");
        }

        // --- NEU: Pfad im Tabellenverzeichnis konstruieren ---
        // Beispiel: /path/to/db/users/index_secondary_email.json
        return $this->tableDir . DIRECTORY_SEPARATOR . FlatFileDBConstants::TABLE_INDEX_FILENAME . '_secondary_' . $sanitizedFieldName . FlatFileDBConstants::INDEX_FILE_EXTENSION;
        // --- Ende NEU ---
    }

    /**
     * Discovers existing secondary index files based on filename convention
     * and populates the internal managed list, but doesn't load the data yet.
     */
    private function discoverSecondaryIndexes(): void
    {
        $this->secondaryIndexes = [];
        $this->secondaryIndexesDirty = [];

        // --- NEU: Suche im Tabellenverzeichnis ---
        $pattern = $this->tableDir . DIRECTORY_SEPARATOR . FlatFileDBConstants::TABLE_INDEX_FILENAME . '_secondary_*' . FlatFileDBConstants::INDEX_FILE_EXTENSION;
        // --- Ende NEU ---

        $indexFiles = glob($pattern);
        if ($indexFiles === false) {
            error_log("Fehler beim Suchen nach sekundären Indexdateien mit Muster: $pattern");
            return;
        }

        // --- NEU: Präfix/Suffix an neue Benennung anpassen ---
        $prefix = FlatFileDBConstants::TABLE_INDEX_FILENAME . '_secondary_';
        $suffix = FlatFileDBConstants::INDEX_FILE_EXTENSION;
        // --- Ende NEU ---

        foreach ($indexFiles as $filePath) {
            $fileName = basename($filePath);
            // Prüfe, ob Dateiname passt (glob könnte mehr liefern als erwartet)
            if (str_starts_with($fileName, $prefix) && str_ends_with($fileName, $suffix)) {
                $fieldName = substr($fileName, strlen($prefix), -strlen($suffix));
                if ($fieldName !== '') {
                    $this->secondaryIndexes[$fieldName] = []; // Load on demand
                    $this->secondaryIndexesDirty[$fieldName] = false;
                } else {
                    error_log("Ungültiger sekundärer Indexdateiname gefunden (leerer Feldname): $filePath");
                }
            }
        }
    }

    /**
     * Creates the file for a secondary index if it doesn't exist.
     * Marks the index as potentially dirty if file was created.
     * Initializes the in-memory structure for the index.
     *
     * @param string $fieldName
     * @throws InvalidArgumentException If field name is invalid.
     * @throws RuntimeException If index file/directory cannot be created/written.
     */
    public function createIndex(string $fieldName): void
    {
        // Validation done by getSecondaryIndexFilePath
        $indexFile = $this->getSecondaryIndexFilePath($fieldName); // Ensures valid field name
        $indexDir = dirname($indexFile);

        // Ensure directory exists (should be covered by constructor, but double check)
        if (!is_dir($indexDir)) {
            if (!@mkdir($indexDir, FlatFileDBConstants::DEFAULT_DIR_PERMISSIONS, true) && !is_dir($indexDir)) { // Check again
                $error = error_get_last();
                $errorMsg = $error ? " ({$error['message']})" : "";
                throw new RuntimeException("Verzeichnis für sekundären Index '$indexDir' konnte nicht erstellt werden{$errorMsg}.");
            }
        } elseif (!is_writable($indexDir)) {
            throw new RuntimeException("Verzeichnis für sekundären Index '$indexDir' ist nicht beschreibbar.");
        }

        $createdNewFile = false;
        // Create index file if it doesn't exist
        if (!file_exists($indexFile)) {
            // Write empty JSON object "{}"
            if (@file_put_contents($indexFile, '{}', LOCK_EX) === false) {
                if (!file_exists($indexFile)) { // Check again
                    $error = error_get_last();
                    $errorMsg = $error ? " ({$error['message']})" : "";
                    throw new RuntimeException("Leere Indexdatei '$indexFile' für Feld '$fieldName' konnte nicht erstellt/geschrieben werden{$errorMsg}.");
                }
                if (!is_writable($indexFile)) {
                    throw new RuntimeException("Indexdatei '$indexFile' ist nicht beschreibbar (nach Erstellversuch).");
                }
            }
            $createdNewFile = true;
        } elseif (!is_writable($indexFile)) {
            throw new RuntimeException("Indexdatei '$indexFile' für Feld '$fieldName' existiert, ist aber nicht beschreibbar.");
        }

        // Initialize in-memory structure if not already present
        if (!array_key_exists($fieldName, $this->secondaryIndexes)) {
            $this->secondaryIndexes[$fieldName] = [];
            // Mark dirty only if we created the file OR if it wasn't known before.
            // If file existed but wasn't known (e.g., discovered later), treat as clean initially.
            $this->secondaryIndexesDirty[$fieldName] = $createdNewFile;
        }
    }

    /**
     * Lazily loads the data for a specific secondary index from its file.
     * Handles file not found, corruption, locking, and validation.
     *
     * @param string $fieldName The field name of the index to load.
     * @throws RuntimeException If loading fails (read, lock, corruption).
     * @throws InvalidArgumentException If field name is invalid.
     */
    private function loadSecondaryIndex(string $fieldName): void
    {
        // Check if already loaded (or being loaded to prevent recursion)
        // We use array_key_exists because the value might be an empty array []
        if (array_key_exists($fieldName, $this->secondaryIndexes)) {
            // It's initialized, maybe empty, maybe loaded. Return.
            return;
        }

        // Get file path (also validates fieldName)
        $indexFile = $this->getSecondaryIndexFilePath($fieldName);
        clearstatcache(true, $indexFile);

        // Case 1: Index file doesn't exist
        if (!file_exists($indexFile)) {
            // Initialize as empty, mark as clean (doesn't need saving)
            $this->secondaryIndexes[$fieldName] = [];
            $this->secondaryIndexesDirty[$fieldName] = false;
            return;
        }

        // Case 2: Index file exists, try to read
        if (!is_readable($indexFile)) {
            // File exists but cannot be read - problematic state
            // Initialize empty, mark dirty? Or throw? Let's throw.
            $this->secondaryIndexes[$fieldName] = []; // Initialize empty
            $this->secondaryIndexesDirty[$fieldName] = false; // Not dirty, can't load
            throw new RuntimeException("Sekundäre Indexdatei '$indexFile' für Feld '$fieldName' existiert, ist aber nicht lesbar.");
        }

        // --- Read and process file content ---
        $handle = @fopen($indexFile, 'rb');
        if (!$handle) {
            $error = error_get_last();
            $errorMsg = $error ? " ({$error['message']})" : "";
            throw new RuntimeException("Sekundäre Indexdatei '$indexFile' konnte nicht geöffnet werden ('rb'){$errorMsg}.");
        }

        try {
            // Acquire shared lock
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException("Konnte keine Lesesperre (LOCK_SH) für sekundäre Indexdatei '$indexFile' erhalten.");
            }

            // Read content
            $content = '';
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false) {
                    $error = error_get_last();
                    $errorMsg = $error ? " ({$error['message']})" : "";
                    throw new RuntimeException("Fehler beim Lesen (fread) der sek. Indexdatei '$indexFile'{$errorMsg}.");
                }
                $content .= $chunk;
            }
            // Release lock and close handle after read
            flock($handle, LOCK_UN);
            fclose($handle);
            $handle = null;

            $trimmedContent = trim($content);

            // Handle empty or effectively empty file
            if ($trimmedContent === '' || $trimmedContent === '{}' || $trimmedContent === '[]') {
                $this->secondaryIndexes[$fieldName] = [];
                $this->secondaryIndexesDirty[$fieldName] = false;
                return;
            }

            // Decode JSON
            try {
                $indexData = json_decode($trimmedContent, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new JsonException("Fehler beim Dekodieren der sek. Indexdatei '$indexFile': " . $e->getMessage(), 0, $e);
            }

            // Validate structure (must be an array/object)
            if (!is_array($indexData)) {
                throw new RuntimeException("Formatfehler: Sekundärindexdaten in '$indexFile' für Feld '$fieldName' sind kein Array (Typ: " . get_debug_type($indexData) . ").");
            }

            // Validate individual entries (value => [id1, id2, ...])
            $validatedIndexData = [];
            foreach ($indexData as $valueKey => $ids) {
                // Key must be string (JSON object keys are always strings)
                $stringValue = (string) $valueKey; // Ensure it's a string key

                // Value must be an array of IDs
                if (!is_array($ids)) {
                    error_log("Formatfehler in '$indexFile' für Feld '$fieldName', Wert '$stringValue': Zugehörige IDs sind kein Array (Typ: " . get_debug_type($ids) . "), wird übersprungen.");
                    continue; // Skip this value
                }

                // Validate IDs within the array: must be positive integers
                $validIds = [];
                $seenIds = []; // For deduplication
                foreach ($ids as $id) {
                    $intId = filter_var($id, FILTER_VALIDATE_INT);
                    // Ensure positive integer and not already added
                    if ($intId !== false && $intId > 0 && !isset($seenIds[$intId])) {
                        $validIds[] = $intId;
                        $seenIds[$intId] = true; // Mark as seen
                    } else {
                        error_log("Ungültige oder doppelte ID '$id' im Sekundärindex '$indexFile' für Feld '$fieldName', Wert '$stringValue' gefunden, wird übersprungen.");
                    }
                }

                // Only add if there are valid IDs for this value
                if (!empty($validIds)) {
                    // Sort IDs? Optional, but can help consistency.
                    // sort($validIds, SORT_NUMERIC);
                    $validatedIndexData[$stringValue] = $validIds;
                }
            } // End foreach index entry

            // Update in-memory index
            $this->secondaryIndexes[$fieldName] = $validatedIndexData;
            $this->secondaryIndexesDirty[$fieldName] = false; // Loaded successfully, not dirty

        } catch (Throwable $e) {
            // General error handling during load
            if ($handle && is_resource($handle)) { // Ensure close on error
                flock($handle, LOCK_UN);
                fclose($handle);
            }

            // Attempt to backup corrupted file
            $backupFile = $indexFile . '.corrupted_' . date('YmdHis');
            $renameSuccess = false;
            if (file_exists($indexFile)) {
                if (@rename($indexFile, $backupFile)) {
                    $renameSuccess = true;
                    error_log("Beschädigte sek. Indexdatei '$indexFile' wurde nach '$backupFile' verschoben. Index für '$fieldName' wird zurückgesetzt.");
                } else {
                    $error = error_get_last();
                    $errorMsg = $error ? " ({$error['message']})" : "";
                    error_log("Konnte beschädigte sek. Indexdatei '$indexFile' nicht nach '$backupFile' umbenennen{$errorMsg}. Index für '$fieldName' wird zurückgesetzt.");
                }
            }

            // Reset index state for this field
            $this->secondaryIndexes[$fieldName] = []; // Reset in memory
            $this->secondaryIndexesDirty[$fieldName] = true; // Mark as dirty (needs saving if used)

            // Rethrow a wrapping exception
            throw new RuntimeException(
                "Fehler beim Laden/Verarbeiten des sekundären Index '$fieldName' aus '$indexFile': " . $e->getMessage()
                . ". Index zurückgesetzt" . ($renameSuccess ? "" : " (Backup fehlgeschlagen!)") . ".",
                (int) $e->getCode(),
                $e
            );
        }
    }


    /**
     * Saves a specific secondary index to its file if it's marked as dirty.
     * Uses temporary file and rename for atomicity.
     *
     * @param string $fieldName The field name of the index to commit.
     * @throws RuntimeException If saving fails (load, write, rename, lock).
     * @throws JsonException If encoding fails.
     * @throws InvalidArgumentException If field name is invalid.
     */
    public function commitSecondaryIndex(string $fieldName): void
    {
        // 1. Check if the index is managed and actually dirty
        if (!isset($this->secondaryIndexesDirty[$fieldName]) || $this->secondaryIndexesDirty[$fieldName] !== true) {
            return; // Not dirty or not managed, nothing to commit
        }

        // 2. Ensure the index data is loaded (should be if it's dirty, but double check)
        if (!array_key_exists($fieldName, $this->secondaryIndexes)) {
            try {
                // Attempt to load it. If load fails, we cannot commit.
                $this->loadSecondaryIndex($fieldName);
                // Check dirty flag again after loading (load might reset it if file was corrupt/empty)
                if (!isset($this->secondaryIndexesDirty[$fieldName]) || $this->secondaryIndexesDirty[$fieldName] !== true) {
                    return; // Loading resolved the state, not dirty anymore
                }
            } catch (Throwable $e) {
                // If loading fails, we cannot proceed with commit.
                throw new RuntimeException("Kann sekundären Index '$fieldName' nicht speichern, da er nicht geladen werden konnte: " . $e->getMessage(), 0, $e);
            }
        }

        // 3. Prepare for writing
        $indexFile = $this->getSecondaryIndexFilePath($fieldName); // Validates name again
        $tmpFile = $indexFile . '.tmp_' . bin2hex(random_bytes(4));

        // Ensure temp file doesn't exist
        if (file_exists($tmpFile)) {
            @unlink($tmpFile);
        }


        try {
            // 4. Get data and encode
            $indexData = $this->secondaryIndexes[$fieldName];
            // Ensure keys are strings for JSON object keys
            $stringKeyData = [];
            foreach ($indexData as $key => $value) {
                // Ensure value is a list of unique positive integers (should be by this point)
                if (!is_array($value) || (!empty($value) && !array_is_list($value))) {
                    // This indicates an internal state corruption if validation during load/update worked
                    error_log("Inkonsistenz beim Speichern des sek. Index '$fieldName': Wert für Schlüssel '$key' ist kein list-Array. Überspringe Schlüssel.");
                    continue;
                }
                $stringKeyData[(string) $key] = $value; // Cast key to string
            }

            // Use stdClass for empty data to ensure "{}" output
            $dataToEncode = empty($stringKeyData) ? new stdClass : $stringKeyData;
            // Use PRETTY_PRINT for debuggability
            $encoded = json_encode($dataToEncode, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // 5. Write to temporary file atomically
            $result = @file_put_contents($tmpFile, $encoded, LOCK_EX);
            if ($result === false) {
                @unlink($tmpFile); // Clean up failed temp file
                $error = error_get_last();
                $errorMsg = $error ? " ({$error['message']})" : "";
                throw new RuntimeException("Sekundäre Indexdatei '$tmpFile' für Feld '$fieldName' konnte nicht geschrieben werden{$errorMsg}.");
            }
            // Optional: Check bytes written
            if ($result < strlen($encoded)) {
                @unlink($tmpFile);
                throw new RuntimeException("Unvollständiger Schreibvorgang für sek. Indexdatei '$tmpFile' (Feld '$fieldName').");
            }

            // 6. Atomically replace old file with new one
            if (!@rename($tmpFile, $indexFile)) {
                @unlink($tmpFile); // Clean up temp file if rename fails
                $error = error_get_last();
                $errorMsg = $error ? " ({$error['message']})" : "";
                throw new RuntimeException("Temporäre sekundäre Indexdatei '$tmpFile' konnte nicht nach '$indexFile' (Feld '$fieldName') umbenannt werden{$errorMsg}.");
            }

            // 7. Success! Mark index as clean.
            $this->secondaryIndexesDirty[$fieldName] = false;

        } catch (Throwable $e) {
            // General error handling: Clean up temp file
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
            // Re-throw specific exceptions or a general runtime exception
            if ($e instanceof JsonException) {
                throw new RuntimeException("Fehler beim JSON-Codieren des sekundären Index '$fieldName': " . $e->getMessage(), 0, $e);
            }
            if ($e instanceof RuntimeException || $e instanceof InvalidArgumentException) {
                throw $e;
            }
            throw new RuntimeException("Fehler beim Speichern des sekundären Index '$fieldName' nach '$indexFile': " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Adds a record ID to the secondary index for a given field and value.
     * Loads the index if not already loaded. Marks index as dirty.
     *
     * @param string $fieldName The field being indexed.
     * @param mixed $value The value of the field in the record. Scalars and null are supported.
     * @param int $recordId The ID of the record.
     * @throws InvalidArgumentException If record ID is invalid or value type is unsuitable for indexing.
     * @throws RuntimeException If index cannot be loaded.
     */
    public function setSecondaryIndex(string $fieldName, mixed $value, int $recordId): void
    {
        if ($recordId <= 0)
            throw new InvalidArgumentException("Record ID muss positiv sein, war: $recordId.");

        // Ensure value is suitable for key (scalar or null)
        if (!is_scalar($value) && $value !== null) {
            $type = get_debug_type($value);
            error_log("Warnung: Versuch, nicht-skalaren Wert (Typ: $type) für Feld '$fieldName' zu indizieren. Wird ignoriert.");
            // Or throw: throw new InvalidArgumentException("Nur skalare Werte oder null können indiziert werden (Feld '$fieldName', Typ '$type').");
            return; // Ignore non-scalar values for standard secondary indexes
        }

        // Load index data for this field if not already loaded
        $this->loadSecondaryIndex($fieldName); // Throws on error

        // Convert value to string key (handle null as empty string, bools as '1'/'0')
        if ($value === null) {
            $stringValue = "";
        } elseif (is_bool($value)) {
            $stringValue = $value ? "1" : "0";
        } else {
            $stringValue = (string) $value; // Cast other scalars to string
        }


        // Ensure the entry for the value exists and is an array
        if (!isset($this->secondaryIndexes[$fieldName][$stringValue])) {
            $this->secondaryIndexes[$fieldName][$stringValue] = [];
            $this->secondaryIndexesDirty[$fieldName] = true; // New value added, mark dirty
        } elseif (!is_array($this->secondaryIndexes[$fieldName][$stringValue])) {
            // Data corruption? Reset and log error.
            error_log("Warnung: Sekundärindex '$fieldName' für Wert '$stringValue' ist intern kein Array, wird zurückgesetzt.");
            $this->secondaryIndexes[$fieldName][$stringValue] = [];
            $this->secondaryIndexesDirty[$fieldName] = true;
        }

        // Add the record ID if it's not already present (avoid duplicates)
        // Use array_flip for quick check, then append if needed
        $idMap = array_flip($this->secondaryIndexes[$fieldName][$stringValue]);
        if (!isset($idMap[$recordId])) {
            $this->secondaryIndexes[$fieldName][$stringValue][] = $recordId;
            // Optional: Keep the list sorted? Might impact performance slightly.
            // sort($this->secondaryIndexes[$fieldName][$stringValue], SORT_NUMERIC);
            $this->secondaryIndexesDirty[$fieldName] = true; // ID added, mark dirty
        }
    }

    /**
     * Removes a record ID from the secondary index for a given field and value.
     * Loads the index if needed. Marks index as dirty if removal occurs.
     *
     * @param string $fieldName The field being indexed.
     * @param mixed $value The value of the field in the record.
     * @param int $recordId The ID of the record to remove.
     * @throws InvalidArgumentException If record ID is invalid or value type mismatch.
     * @throws RuntimeException If index cannot be loaded.
     */
    public function removeSecondaryIndex(string $fieldName, mixed $value, int $recordId): void
    {
        if ($recordId <= 0)
            throw new InvalidArgumentException("Record ID muss positiv sein, war: $recordId.");
        if (!is_scalar($value) && $value !== null) {
            // Ignore removal attempts for non-indexable types, consistent with setSecondaryIndex
            error_log("Warnung: Versuch, Indexeintrag für nicht-skalaren Wert (Feld '$fieldName') zu entfernen. Wird ignoriert.");
            return;
        }

        // Load index data if needed
        $this->loadSecondaryIndex($fieldName); // Throws on error

        // Convert value to string key
        if ($value === null) {
            $stringValue = "";
        } elseif (is_bool($value)) {
            $stringValue = $value ? "1" : "0";
        } else {
            $stringValue = (string) $value;
        }

        // Check if the value exists in the index and is an array
        if (isset($this->secondaryIndexes[$fieldName][$stringValue]) && is_array($this->secondaryIndexes[$fieldName][$stringValue])) {
            $ids = $this->secondaryIndexes[$fieldName][$stringValue];

            // Find the index of the record ID in the array
            $index = array_search($recordId, $ids, true); // Use strict comparison

            // If found, remove it
            if ($index !== false) {
                array_splice($this->secondaryIndexes[$fieldName][$stringValue], $index, 1);
                $this->secondaryIndexesDirty[$fieldName] = true; // Mark dirty as modification occurred

                // If the list for this value becomes empty, remove the value key itself
                if (empty($this->secondaryIndexes[$fieldName][$stringValue])) {
                    unset($this->secondaryIndexes[$fieldName][$stringValue]);
                    // Still dirty because the value key was removed
                }
            }
            // If ID not found, do nothing, index remains clean (unless already dirty)
        }
        // If value not found, do nothing.
    }

    /**
     * Retrieves a list of record IDs associated with a specific field value.
     * Loads the index if needed.
     *
     * @param string $fieldName The field name.
     * @param mixed $value The value to search for.
     * @return list<int> List of matching record IDs (can be empty).
     * @throws RuntimeException If index cannot be loaded.
     * @throws InvalidArgumentException If field name is invalid or value type mismatch.
     */
    public function getRecordIdsByFieldValue(string $fieldName, mixed $value): array
    {
        if (!is_scalar($value) && $value !== null) {
            // Return empty list for non-indexable types
            error_log("Warnung: Suche im Sekundärindex ('$fieldName') für nicht-skalaren Wert. Ergebnis ist leer.");
            return [];
        }

        // Load index data if needed
        $this->loadSecondaryIndex($fieldName); // Throws on error

        // Convert value to string key
        if ($value === null) {
            $stringValue = "";
        } elseif (is_bool($value)) {
            $stringValue = $value ? "1" : "0";
        } else {
            $stringValue = (string) $value;
        }

        // Get the list of IDs or an empty array if not found
        $result = $this->secondaryIndexes[$fieldName][$stringValue] ?? [];

        // Ensure it's an array (should be, due to load/update validation)
        // and return as a list (array_values just in case keys are not sequential)
        return is_array($result) ? array_values($result) : [];
    }

    /**
     * Removes all references to a specific record ID from *all managed* secondary indexes.
     * Loads indexes as needed. Marks modified indexes as dirty.
     *
     * @param int $recordId The record ID to remove.
     * @throws InvalidArgumentException If record ID is invalid.
     */
    public function removeAllSecondaryIndexesForRecord(int $recordId): void
    {
        if ($recordId <= 0)
            throw new InvalidArgumentException("Record ID muss positiv sein, war: $recordId.");

        // Iterate through all fields known to have secondary indexes
        // Use array_keys to avoid issues if loop modifies the array during iteration (though it shouldn't here)
        $managedFields = $this->getManagedIndexedFields(); // Gets keys of $this->secondaryIndexes

        foreach ($managedFields as $fieldName) {
            $indexModifiedForField = false;
            try {
                // Load index for this field if not already loaded
                $this->loadSecondaryIndex($fieldName);

                // Check if field is still managed after potential load error/reset
                if (!isset($this->secondaryIndexes[$fieldName]) || !is_array($this->secondaryIndexes[$fieldName])) {
                    continue; // Skip if index for this field is not a valid array
                }

                // Iterate through values in this field's index
                // Use reference (&) to modify the $ids array directly
                foreach ($this->secondaryIndexes[$fieldName] as $valueKey => &$ids) {
                    if (!is_array($ids))
                        continue; // Skip corrupted entries

                    // Find the record ID in the list for this value
                    $index = array_search($recordId, $ids, true);

                    if ($index !== false) {
                        // Found it - remove using splice
                        array_splice($ids, $index, 1);
                        $indexModifiedForField = true; // Mark field as modified

                        // If the list for this value is now empty, remove the value key
                        if (empty($ids)) {
                            unset($this->secondaryIndexes[$fieldName][$valueKey]);
                        }
                    }
                }
                unset($ids); // Unset reference to last element

                // Mark the entire field's index as dirty if any modification occurred
                if ($indexModifiedForField) {
                    $this->secondaryIndexesDirty[$fieldName] = true;
                }

            } catch (Throwable $e) {
                // Log error loading/processing index for this field, but continue with others
                error_log("Fehler beim Laden/Verarbeiten des Sekundärindex '$fieldName' während removeAllSecondaryIndexesForRecord für ID $recordId: " . $e->getMessage());
                continue;
            }
        } // End foreach managed field
    }

    /**
     * Commits all secondary indexes that are marked as dirty.
     * Logs errors for individual commits but tries to commit all.
     * @throws RuntimeException if any *fatal* error occurs during the process (less likely).
     */
    public function commitAllSecondaryIndexes(): void
    {
        // Get fields marked as dirty
        $dirtyFields = array_keys(array_filter($this->secondaryIndexesDirty));

        foreach ($dirtyFields as $fieldName) {
            try {
                // Attempt to commit the specific dirty index
                $this->commitSecondaryIndex($fieldName);
            } catch (Throwable $e) {
                // Log error for this specific index commit, but continue with others
                error_log("Fehler beim Speichern des sekundären Index '$fieldName': " . $e->getMessage());
                // Optionally: Collect errors and throw an aggregate exception at the end
            }
        }
    }

    /**
     * Replaces the entire data for a specific secondary index.
     * Typically used after rebuilding an index (e.g., during createIndex or compaction).
     * Validates the new index data. Marks the index as dirty if changed.
     *
     * @param string $fieldName The field name of the index to update.
     * @param array<string, list<int>> $newIndexData New index data [valueString => [id1, id2, ...]].
     * @throws InvalidArgumentException If field name is invalid.
     * @throws RuntimeException If the new index data structure is invalid.
     */
    public function updateSecondaryIndex(string $fieldName, array $newIndexData): void
    {
        // Validate field name via get path (throws if invalid)
        $this->getSecondaryIndexFilePath($fieldName);

        // Load existing data if not already loaded, to allow comparison
        // Don't load if field isn't even managed yet
        if (!array_key_exists($fieldName, $this->secondaryIndexes)) {
            $this->secondaryIndexes[$fieldName] = []; // Initialize if new
        } else {
            try {
                $this->loadSecondaryIndex($fieldName); // Load if needed
            } catch (Throwable $e) {
                // If loading failed, we cannot reliably compare or update. Reset and mark dirty.
                error_log("Fehler beim Laden des Sek. Index '$fieldName' vor Update: " . $e->getMessage() . ". Index wird mit neuen Daten überschrieben.");
                $this->secondaryIndexes[$fieldName] = []; // Reset before applying new data
                $this->secondaryIndexesDirty[$fieldName] = true; // Must save the new data
            }
        }

        // Validate the incoming new data structure
        $validatedIndexData = [];
        foreach ($newIndexData as $valueKey => $ids) {
            $stringValue = (string) $valueKey; // Key must be string

            // Value must be a list of positive integers
            if (!is_array($ids) || (!empty($ids) && !array_is_list($ids))) {
                throw new RuntimeException("Formatfehler in updateSecondaryIndex für '$fieldName', Wert '$stringValue': IDs sind keine Liste (list).");
            }

            $validIds = [];
            $seenIds = [];
            foreach ($ids as $id) {
                $intId = filter_var($id, FILTER_VALIDATE_INT);
                if ($intId === false || $intId <= 0) {
                    throw new RuntimeException("Ungültige ID '$id' in updateSecondaryIndex für '$fieldName', Wert '$stringValue' gefunden.");
                }
                if (!isset($seenIds[$intId])) {
                    $validIds[] = $intId;
                    $seenIds[$intId] = true;
                }
            }
            // Optional: Sort IDs for consistency
            // sort($validIds, SORT_NUMERIC);
            if (!empty($validIds)) {
                $validatedIndexData[$stringValue] = $validIds;
            }
        }

        // Compare new validated data with current data (if loaded)
        $currentData = $this->secondaryIndexes[$fieldName] ?? [];
        if ($currentData != $validatedIndexData) { // Array comparison works
            $this->secondaryIndexes[$fieldName] = $validatedIndexData;
            $this->secondaryIndexesDirty[$fieldName] = true; // Data changed, mark dirty
        } else if ($this->secondaryIndexesDirty[$fieldName] && $currentData === $validatedIndexData) {
            // If it was dirty but content is now identical, mark as clean
            $this->secondaryIndexesDirty[$fieldName] = false;
        }
    }


    /**
     * Deletes the secondary index file and removes it from management.
     *
     * @param string $fieldName The field name of the index to drop.
     * @throws InvalidArgumentException If field name is invalid.
     * @throws RuntimeException If file deletion fails.
     */
    public function dropIndex(string $fieldName): void
    {
        // Get path (validates name)
        $indexFile = $this->getSecondaryIndexFilePath($fieldName);

        // Remove from in-memory structures first
        unset($this->secondaryIndexes[$fieldName]);
        unset($this->secondaryIndexesDirty[$fieldName]);

        clearstatcache(true, $indexFile);
        // Attempt to delete the file if it exists
        if (file_exists($indexFile)) {
            if (!@unlink($indexFile)) {
                $error = error_get_last();
                $errorMsg = $error ? " ({$error['message']})" : "";
                // Log error, but don't necessarily throw? Or should we? Let's throw.
                throw new RuntimeException("Konnte sekundäre Indexdatei '$indexFile' für Feld '$fieldName' nicht löschen{$errorMsg}.");
            }
        }
        // If file didn't exist, that's fine.
    }

    /**
     * Returns a list of field names for which secondary indexes are currently managed (loaded or discovered).
     * @return list<string>
     */
    public function getManagedIndexedFields(): array
    {
        // Use array_values to ensure list<string>
        return array_values(array_keys($this->secondaryIndexes));
    }

    /**
     * Checks if a specific secondary index is marked as dirty.
     * @param string $fieldName
     * @return bool
     */
    public function isSecondaryIndexDirty(string $fieldName): bool
    {
        return $this->secondaryIndexesDirty[$fieldName] ?? false;
    }

    /**
     * Checks if the primary index is marked as dirty.
     * @return bool
     */
    public function isPrimaryIndexDirty(): bool
    {
        return $this->indexDirty;
    }

    public function resetSecondaryIndexes(): void
    {
        $this->secondaryIndexes = []; // Leert das Array der geladenen Indexdaten
        $this->secondaryIndexesDirty = []; // Setzt die "dirty"-Flags zurück
        // Es ist nicht nötig, discoverSecondaryIndexes() aufzurufen, da die Dateien physisch
        // gelöscht werden (oder werden sollten) von der aufrufenden Methode (clearTable).
    }

    /**
     * Schließt den Datei-Handle für die ID-Generierungs-Sperrdatei, falls er offen ist.
     * Gibt die Sperre frei, bevor der Handle geschlossen wird.
     */
    public function closeIdLockHandle(): void
    {
        if ($this->idLockHandle !== null && is_resource($this->idLockHandle)) {
            // Sperre freigeben (ignoriere Fehler, wenn nicht gesperrt)
            @flock($this->idLockHandle, LOCK_UN);
            // Handle schließen (ignoriere Fehler)
            @fclose($this->idLockHandle);
        }
        // Handle zurücksetzen, damit er nicht wiederverwendet wird
        $this->idLockHandle = null;
    }

    /**
     * Gibt den Pfad zur ID-Lock-Datei zurück.
     * Nützlich, damit externe Prozesse (wie clearTable) die Datei ggf. löschen können.
     * @return string
     */
    public function getIdLockFilePath(): string
    {
        // Stelle sicher, dass der Pfad initialisiert wurde (sollte im Konstruktor geschehen)
        if (empty($this->idLockFile)) {
             // Rekonstruiere den Pfad, falls nicht gesetzt (Fallback)
             $indexFile = $this->config->getIndexFile();
             $this->tableDir = dirname($indexFile); // Leite Tabellenverzeichnis ab
             $this->idLockFile = $this->tableDir . DIRECTORY_SEPARATOR . '.id.lock';
        }
        return $this->idLockFile;
    }
}