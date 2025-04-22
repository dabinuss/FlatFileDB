<?php
declare(strict_types=1);

namespace FlatFileDB;

use Generator;
use InvalidArgumentException;
use RuntimeException;
use JsonException;
use Throwable;

class FlatFileFileManager
{
    private FlatFileConfig $config;
    private int $compressionLevel;
    // Add index builder reference needed for the corrected generator
    private FlatFileIndexBuilder $indexBuilder;

    /** @var resource|null */
    private $readHandle = null;
    private ?int $readHandleMTime = null;
    /** @var resource|null */
    private $writeHandle = null;
    private ?int $writeHandleMTime = null;

    /**
     * @param FlatFileConfig $config
     * @param FlatFileIndexBuilder $indexBuilder // Pass the index builder
     * @param int $compressionLevel
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    public function __construct(
        FlatFileConfig $config,
        FlatFileIndexBuilder $indexBuilder, // Add dependency
        int $compressionLevel = FlatFileDBConstants::DEFAULT_COMPRESSION_LEVEL
    ) {
        if ($compressionLevel < 0 || $compressionLevel > 9) {
            throw new InvalidArgumentException("Kompressionslevel muss zwischen 0 und 9 liegen, war: $compressionLevel.");
        }
        $this->config = $config;
        $this->indexBuilder = $indexBuilder; // Store index builder
        $this->compressionLevel = $compressionLevel;

        $dataFile = $this->config->getDataFile();
        $dataDir = dirname($dataFile);

        // Ensure directory exists
        if (!is_dir($dataDir)) {
            // Try to create it recursively
            if (!@mkdir($dataDir, FlatFileDBConstants::DEFAULT_DIR_PERMISSIONS, true) && !is_dir($dataDir)) {
                // Check is_dir again in case of race condition where mkdir failed but it exists now
                $error = error_get_last();
                $errorMsg = $error ? " ({$error['message']})" : "";
                throw new RuntimeException("Daten-Verzeichnis '$dataDir' konnte nicht erstellt werden{$errorMsg}.");
            }
        }
        // Ensure directory is writable (check happens after trying to create it)
        if (!is_writable($dataDir)) {
            throw new RuntimeException("Daten-Verzeichnis '$dataDir' ist nicht beschreibbar.");
        }

        // Ensure data file exists
        if (!file_exists($dataFile)) {
            // Try to create it atomically (file_put_contents with LOCK_EX)
            // Check file_exists again in case of race conditions where file_put_contents fails but the file was created concurrently.
            if (@file_put_contents($dataFile, '', LOCK_EX) === false && !file_exists($dataFile)) {
                $error = error_get_last();
                $errorMsg = $error ? " ({$error['message']})" : "";
                throw new RuntimeException("Datendatei '$dataFile' konnte nicht erstellt werden{$errorMsg}.");
            }
        }

        // Ensure data file is writable (check happens after ensuring/attempting creation)
        // This covers cases: file existed but wasn't writable, or creation succeeded but file isn't writable (permissions).
        clearstatcache(true, $dataFile); // Ensure is_writable uses fresh stats after potential creation
        if (!is_writable($dataFile)) {
            throw new RuntimeException("Datendatei '$dataFile' ist nicht beschreibbar.");
        }
    }

    /**
     * Get shared read handle, reopening if file changed.
     * @return resource
     * @throws RuntimeException
     */
    private function getReadHandle()
    {
        $dataFile = $this->config->getDataFile();
        clearstatcache(true, $dataFile);

        if (!file_exists($dataFile)) {
            // If the file vanished after initial checks/creation
            throw new RuntimeException("Datendatei existiert nicht mehr: $dataFile");
        }
        $currentMTime = @filemtime($dataFile);
        if ($currentMTime === false) {
            $error = error_get_last();
            $errorMsg = $error ? " (Error: {$error['message']})" : "";
            throw new RuntimeException("Konnte Änderungszeit der Datendatei nicht lesen: $dataFile{$errorMsg}");
        }

        // Check if current handle is valid and file hasn't changed
        if ($this->readHandle !== null && is_resource($this->readHandle) && $this->readHandleMTime === $currentMTime) {
            return $this->readHandle;
        }

        // Close existing handle if open
        $this->closeReadHandle();

        // Open for binary read
        $handle = @fopen($dataFile, 'rb'); // Use @ to handle potential fopen errors manually
        if (!$handle) {
            $error = error_get_last();
            $errorMsg = $error ? " (Error: {$error['message']})" : "";
            throw new RuntimeException("Konnte Datendatei nicht zum Lesen ('rb') öffnen: $dataFile{$errorMsg}");
        }

        $this->readHandle = $handle;
        $this->readHandleMTime = $currentMTime;
        return $handle;
    }

    /**
     * Get exclusive write handle (append mode), reopening if file changed.
     * @return resource
     * @throws RuntimeException
     */
    private function getWriteHandle()
    {
        $dataFile = $this->config->getDataFile();
        clearstatcache(true, $dataFile);

        // Data file should exist due to constructor checks, but double-check
        if (!file_exists($dataFile)) {
            throw new RuntimeException("Datendatei existiert nicht mehr (beim Versuch zu schreiben): $dataFile");
        }
        $currentMTime = @filemtime($dataFile);
        if ($currentMTime === false) {
            $error = error_get_last();
            $errorMsg = $error ? " (Error: {$error['message']})" : "";
            throw new RuntimeException("Konnte Änderungszeit der Datendatei nicht lesen: $dataFile{$errorMsg}");
        }

        // Check if current handle is valid and file hasn't changed
        if ($this->writeHandle !== null && is_resource($this->writeHandle) && $this->writeHandleMTime === $currentMTime) {
            return $this->writeHandle;
        }

        // Close existing handle if open
        $this->closeWriteHandle();

        // Open for binary append
        $handle = @fopen($dataFile, 'ab'); // Use @ to handle potential fopen errors manually
        if (!$handle) {
            $error = error_get_last();
            $errorMsg = $error ? " (Error: {$error['message']})" : "";
            throw new RuntimeException("Fehler beim Öffnen der Datei '$dataFile' für Schreibzugriffe ('ab'){$errorMsg}.");
        }

        $this->writeHandle = $handle;
        $this->writeHandleMTime = $currentMTime;
        return $handle;
    }

    /**
     * Schließt den Lese-Handle.
     */
    public function closeReadHandle(): void {
        if ($this->readHandle !== null && is_resource($this->readHandle)) {
            fclose($this->readHandle);
        }
        $this->readHandle = null;
        $this->readHandleMTime = null;
    }

    /**
     * Schließt den Schreib-Handle.
     */
    public function closeWriteHandle(): void {
        if ($this->writeHandle !== null && is_resource($this->writeHandle)) {
            fflush($this->writeHandle);
            fclose($this->writeHandle);
        }
        $this->writeHandle = null;
        $this->writeHandleMTime = null;
    }

    // Ensure handles are closed when the object is destroyed
    public function __destruct()
    {
        $this->closeReadHandle();
        $this->closeWriteHandle();
    }

    /**
     * Hängt einen Datensatz an das Dateiende an und gibt ein Array mit Offset und Länge des geschriebenen Blocks zurück.
     * Komprimiert den Datensatz einzeln, wenn Kompression aktiviert ist.
     *
     * @param array $record Der zu speichernde Datensatz.
     * @return array{offset: int, length: int} Position und Größe des geschriebenen Datenblocks.
     * @throws RuntimeException Bei Schreib-, Sperr- oder Komprimierungsfehlern.
     * @throws JsonException Bei Fehlern während der JSON-Kodierung.
     */
    public function appendRecord(array $record): array
    {
        // 1. Encode record to JSON Line format
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $line = $json . "\n"; // Ensure newline for JSON Lines format

        // 2. Compress if needed
        $dataToWrite = $line;
        if ($this->compressionLevel > 0) {
            $compressed = gzencode($line, $this->compressionLevel);
            if ($compressed === false) {
                $error = error_get_last();
                $errorMsg = $error ? " (Error: {$error['message']})" : "";
                // Include part of the data for context? Maybe not due to size/privacy.
                throw new RuntimeException('Fehler beim Komprimieren des Datensatzes.' . $errorMsg);
            }
            $dataToWrite = $compressed;
        }

        // 3. Get write handle and lock
        $handle = $this->getWriteHandle();
        if (!flock($handle, LOCK_EX)) {
            // Unable to acquire lock, throw error
            throw new RuntimeException("Konnte keine exklusive Schreibsperre (LOCK_EX) für die Datendatei erhalten: {$this->config->getDataFile()}");
        }

        try {
            // 4. Get current offset (position before write)
            // fseek to end just in case, though 'a' mode should handle it
            if (fseek($handle, 0, SEEK_END) === -1) {
                throw new RuntimeException("Konnte nicht zum Ende der Datei springen: {$this->config->getDataFile()}");
            }
            $offset = ftell($handle);
            if ($offset === false) {
                // Should not happen after successful fseek
                throw new RuntimeException("Konnte aktuellen Datei-Offset vor dem Schreiben nicht bestimmen.");
            }

            // 5. Write data
            $bytesToWrite = strlen($dataToWrite);
            $bytesWritten = fwrite($handle, $dataToWrite);

            // 6. Verify write operation
            if ($bytesWritten === false) {
                $error = error_get_last();
                $errorMsg = $error ? " (Error: {$error['message']})" : "";
                throw new RuntimeException("Fehler beim Schreiben des Datensatzes in {$this->config->getDataFile()}{$errorMsg}.");
            }
            if ($bytesWritten < $bytesToWrite) {
                // Partial write (e.g., disk full)
                // Attempt to truncate the partial write? Difficult and risky. Better to error out.
                // Note: The file might be corrupted at this point. Compaction would be needed.
                throw new RuntimeException("Unvollständiger Schreibvorgang in {$this->config->getDataFile()}. Nur {$bytesWritten} von {$bytesToWrite} Bytes geschrieben (möglicherweise Platte voll?).");
            }

            // 7. Flush buffers to ensure data is physically written (important!)
            if (!fflush($handle)) {
                // Warning, as data might still be written eventually, but indicates potential issues.
                error_log("Warnung: fflush() fehlgeschlagen nach dem Schreiben in {$this->config->getDataFile()}");
            }


            // Calculate length based on bytes written
            $length = $bytesWritten; // More reliable than ftell() difference after write sometimes

        } finally {
            // 8. Release lock
            flock($handle, LOCK_UN);
        }

        // 9. Return offset and length
        return ['offset' => $offset, 'length' => $length];
    }

    /**
     * Liest einen Datensatz anhand des Start-Offsets und der Länge des Blocks.
     * Dekomprimiert den Block, wenn Kompression für die Tabelle aktiviert ist.
     *
     * @param int $offset Startposition des Datenblocks in Bytes.
     * @param int $length Länge des Datenblocks in Bytes.
     * @return array Der dekodierte Datensatz.
     * @throws RuntimeException Bei Lese-, Sperr-, Such- oder Dekomprimierungsfehlern.
     * @throws InvalidArgumentException Wenn Offset oder Länge ungültig sind.
     * @throws JsonException Wenn die gelesenen Daten kein gültiges JSON sind.
     */
    public function readRecordAtOffset(int $offset, int $length): array
    {
        // Validate inputs
        if ($offset < 0) {
            throw new InvalidArgumentException("Offset darf nicht negativ sein, war: $offset.");
        }
        if ($length <= 0) {
            // Allow 0 length? Probably indicates an error or deleted record previously.
            // For now, require positive length as index should store valid blocks.
            throw new InvalidArgumentException("Länge muss positiv sein, war: $length.");
        }

        $handle = $this->getReadHandle();
        $dataFile = $this->config->getDataFile();

        // Acquire shared lock for reading
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException("Konnte keine Lesesperre (LOCK_SH) für die Datendatei erhalten: {$dataFile}");
        }

        try {
            // Seek to the specified offset
            if (fseek($handle, $offset) === -1) {
                // Check if offset is beyond file size
                clearstatcache(true, $dataFile);
                $fileSize = @filesize($dataFile) ?: 0;
                throw new RuntimeException("Fehler beim Suchen (fseek) in der Datendatei {$dataFile} zu Offset {$offset} (Dateigröße: {$fileSize}).");
            }

            // Read the specified number of bytes
            $data = fread($handle, $length);

            // Verify read operation
            if ($data === false) {
                $error = error_get_last();
                $errorMsg = $error ? " (Error: {$error['message']})" : "";
                throw new RuntimeException("Fehler beim Lesen (fread) von {$length} Bytes ab Offset {$offset} in {$dataFile}{$errorMsg}.");
            }
            $bytesRead = strlen($data);
            if ($bytesRead !== $length) {
                // Could only read partial data (e.g., offset + length exceeds file bounds)
                // This usually indicates an index inconsistency or file corruption.
                clearstatcache(true, $dataFile);
                $fileSize = @filesize($dataFile) ?: 0;
                throw new RuntimeException("Unvollständiger Lesevorgang in {$dataFile}: Konnte nur {$bytesRead} von {$length} Bytes ab Offset {$offset} lesen (Dateigröße: {$fileSize}). Index ist möglicherweise inkonsistent.");
            }

            // Decompress if necessary
            $decompressedData = $data;
            if ($this->compressionLevel > 0) {
                // Use @gzdecode and check result immediately
                $decompressed = @gzdecode($data);
                if ($decompressed === false) {
                    $error = error_get_last();
                    $errorMsg = $error ? " (Error: {$error['message']})" : "";
                    // Include hex representation of first few bytes for debugging?
                    $hexData = bin2hex(substr($data, 0, 16));
                    throw new RuntimeException("Fehler beim Dekomprimieren (gzdecode) des Datensatzes ab Offset {$offset} (Länge {$length}) in {$dataFile}{$errorMsg}. Hex-Daten (max 16 Bytes): {$hexData}");
                }
                $decompressedData = $decompressed;
            }

            // Trim potential whitespace/newline and decode JSON
            // The appended newline in appendRecord should be handled correctly by trim.
            $jsonLine = trim($decompressedData);
            if ($jsonLine === '') {
                // Empty line after decompression? Indicates potential issue.
                throw new RuntimeException("Leerer Datensatz nach Dekomprimierung/Trimmen ab Offset {$offset} in {$dataFile}.");
            }

            try {
                $record = json_decode($jsonLine, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                // Include line content (truncated) in error for debugging
                $truncatedLine = substr($jsonLine, 0, 100);
                throw new JsonException("Fehler beim JSON-Dekodieren des Datensatzes ab Offset {$offset} in {$dataFile}: " . $e->getMessage() . " - Inhalt (gekürzt): " . $truncatedLine, $e->getCode(), $e);
            }


            // Validate decoded structure (optional but recommended)
            if (!is_array($record)) {
                // Should be caught by JSON_THROW_ON_ERROR for invalid JSON,
                // but check in case JSON maps to a scalar/null.
                throw new RuntimeException("Dekodierte Daten ab Offset {$offset} in {$dataFile} sind kein Array.");
            }

            return $record;

        } finally {
            // Release lock
            flock($handle, LOCK_UN);
        }
    }

    /**
     * **KORRIGIERT:** Liest Datensätze basierend auf dem Primärindex.
     * Erforderlich, da bei Komprimierung nicht zeilenweise gelesen werden kann.
     *
     * @yield int $recordId => array $record
     * @throws RuntimeException Bei Fehlern beim Lesen oder Indexzugriff.
     */
    public function readRecordsGeneratorIndexed(): Generator
    {
        $dataFile = $this->config->getDataFile();

        // Always iterate through the index, regardless of compression
        try {
            $indexEntries = $this->indexBuilder->getCurrentIndex(); // Get snapshot of index
        } catch (Throwable $e) {
            throw new RuntimeException("Fehler beim Laden des Index für Generator in {$dataFile}: " . $e->getMessage(), 0, $e);
        }

        foreach ($indexEntries as $recordId => $blockInfo) {
            if (!isset($blockInfo['offset'], $blockInfo['length'])) {
                error_log("Ungültiger Indexeintrag für ID {$recordId} in Generator übersprungen (Datei: {$dataFile}).");
                continue;
            }

            try {
                // Use readRecordAtOffset which handles decompression correctly
                $record = $this->readRecordAtOffset($blockInfo['offset'], $blockInfo['length']);

                // Basic check if record ID matches (optional, but good consistency check)
                if (!isset($record['id']) || $record['id'] != $recordId) {
                    error_log("Inkonsistenz: Gelesener Datensatz bei Offset {$blockInfo['offset']} hat ID "
                        . ($record['id'] ?? 'fehlt') . ", erwartet wurde {$recordId} (Datei: {$dataFile}). Überspringe.");
                    continue;
                }
                // Skip explicitly deleted records? Usually desired for generators scanning "active" data.
                if (isset($record['_deleted']) && $record['_deleted'] === true) {
                    continue;
                }

                yield $recordId => $record;

            } catch (Throwable $e) {
                // Log error reading specific record and continue if possible
                error_log("Fehler beim Lesen/Verarbeiten von Datensatz ID {$recordId} (Offset {$blockInfo['offset']}) in Generator für {$dataFile}: " . $e->getMessage());
                // Depending on severity, could re-throw: throw new RuntimeException(...)
                continue; // Skip this record
            }
        }
    }

    /**
     * **DEPRECATED/UNSAFE with Compression:** Reads records line by line (only works without compression).
     * Use readRecordsGeneratorIndexed instead for reliable iteration.
     *
     * @yield int $byteOffset => array $record (wenn unkomprimiert)
     * @throws RuntimeException
     * @deprecated Use readRecordsGeneratorIndexed() for reliable iteration, especially with compression.
     */
    public function readRecordsGeneratorLineByLineUncompressed(): Generator
    {
        trigger_error(
            'readRecordsGeneratorLineByLineUncompressed() is deprecated and unsafe with compression. Use readRecordsGeneratorIndexed().',
            E_USER_DEPRECATED
        );

        if ($this->compressionLevel > 0) {
            throw new RuntimeException("Line-by-line scanning (readRecordsGeneratorLineByLineUncompressed) is not supported when compression is enabled. Use readRecordsGeneratorIndexed().");
        }

        $dataFile = $this->config->getDataFile();
        $handle = @fopen($dataFile, 'rb');
        if (!$handle) {
            $error = error_get_last();
            $errorMsg = $error ? " ({$error['message']})" : "";
            throw new RuntimeException("Konnte Datendatei nicht zum Lesen öffnen: $dataFile{$errorMsg}");
        }

        if (!flock($handle, LOCK_SH)) {
            @fclose($handle);
            throw new RuntimeException("Konnte keine Lesesperre für die Datendatei erhalten: $dataFile");
        }

        $currentByteOffset = 0;
        $buffer = '';
        $lineNumber = 0; // For error reporting

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, FlatFileDBConstants::READ_CHUNK_SIZE);
                if ($chunk === false) {
                    $error = error_get_last();
                    $errorMsg = $error ? " ({$error['message']})" : "";
                    throw new RuntimeException("Fehler beim Lesen der Daten (fread) aus Datei $dataFile{$errorMsg}.");
                }
                if ($chunk === '') {
                    // Empty chunk might happen, continue if not EOF
                    if (feof($handle))
                        break;
                    else
                        continue;
                }

                $buffer .= $chunk;

                // Process lines found in buffer
                while (($newlinePos = strpos($buffer, "\n")) !== false) {
                    $lineNumber++;
                    $lineLength = $newlinePos + 1; // Include newline in length
                    $line = substr($buffer, 0, $lineLength);
                    $buffer = substr($buffer, $lineLength);

                    $trimmedLine = trim($line); // Trim whitespace/newline for JSON decoding
                    if ($trimmedLine === '') {
                        // Skip empty lines, but advance offset
                        $currentByteOffset += $lineLength;
                        continue;
                    }

                    try {
                        $record = json_decode($trimmedLine, true, 512, JSON_THROW_ON_ERROR);
                        if (!is_array($record)) {
                            throw new JsonException("Dekodierte Daten sind kein Array.");
                        }
                        // Yield the starting byte offset and the record
                        yield $currentByteOffset => $record;

                    } catch (JsonException $e) {
                        error_log("Fehler beim JSON-Dekodieren von Zeile {$lineNumber} (Offset ca. {$currentByteOffset}) in $dataFile: " . $e->getMessage() . " - Inhalt (gekürzt): " . substr($trimmedLine, 0, 100));
                        // Skip corrupted line, but still advance the offset
                    }

                    // Advance the byte offset for the next line
                    $currentByteOffset += $lineLength;
                } // End while line found in buffer
            } // End while not eof

            // Process any remaining data in the buffer (if file doesn't end with newline)
            if (!empty($buffer)) {
                $lineNumber++;
                $trimmedLine = trim($buffer);
                if ($trimmedLine !== '') {
                    try {
                        $record = json_decode($trimmedLine, true, 512, JSON_THROW_ON_ERROR);
                        if (!is_array($record)) {
                            throw new JsonException("Dekodierte Daten sind kein Array.");
                        }
                        yield $currentByteOffset => $record;
                    } catch (JsonException $e) {
                        error_log("Fehler beim JSON-Dekodieren der letzten Zeile {$lineNumber} (Offset ca. {$currentByteOffset}) in $dataFile: " . $e->getMessage() . " - Inhalt (gekürzt): " . substr($trimmedLine, 0, 100));
                    }
                }
                // No need to advance offset further, it's EOF
            }

        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }


    /**
     * Liest alle *gültigen* Datensätze über den Index.
     *
     * @return array<int, array> Key ist die Record ID.
     * @throws RuntimeException Bei Fehlern.
     */
    public function readAllRecords(): array
    {
        $records = [];
        try {
            // Use the corrected generator that works with/without compression
            foreach ($this->readRecordsGeneratorIndexed() as $recordId => $record) {
                // Generator already filters deleted/inconsistent records
                $records[$recordId] = $record;
            }
        } catch (Throwable $e) {
            // Catch potential exceptions from the generator itself
            throw new RuntimeException("Fehler beim Lesen aller Datensätze (via Index) aus {$this->config->getDataFile()}: " . $e->getMessage(), 0, $e);
        }
        return $records;
    }

    /**
     * **KORRIGIERT:** Kompaktiert die Datendatei.
     * Liest gültige Datensätze über den Index, schreibt sie in eine neue Datei
     * und ersetzt die alte Datei. Aktualisiert den Primärindex entsprechend.
     * Funktioniert jetzt auch mit Komprimierung.
     *
     * @return array<int, array{offset: int, length: int}> Der neue Primärindex für die kompaktierten Daten.
     * @throws RuntimeException Bei schwerwiegenden Fehlern während des Prozesses.
     * @throws JsonException Bei JSON-Fehlern.
     */
    public function compactData(): array
    {
        $newIndex = [];
        $dataFile = $this->config->getDataFile();
        $tempFile = $dataFile . '.tmp_' . bin2hex(random_bytes(4)); // Shorter random string
        $backupFile = $dataFile . '.bak_' . date('YmdHis');

        // Ensure temp file does not exist initially (clean up from previous failed attempts)
        if (file_exists($tempFile)) {
            @unlink($tempFile);
        }

        $latestRecordData = [];

        try {
            // 1. Read LATEST versions of records using the INDEXED generator
            // We need to handle potential multiple versions by keeping the last one seen.
            // This part is slightly more complex than just reading all, we need the *last* entry per ID.
            // Alternative: Read all via generator, then process in memory (simpler for now).
            $allRecordsFromIndex = [];
            foreach ($this->readRecordsGeneratorIndexed() as $recordId => $record) {
                // This generator should already give the latest valid record based on index
                $allRecordsFromIndex[$recordId] = $record;
            }

            // --- Sanity check: Ensure loaded records seem valid ---
            // This check is mostly done by readRecordAtOffset and the generator already.


            // 2. Open temporary file for writing compacted data
            // Use 'xb' initially to ensure atomicity if supported, fallback to 'wb'
            $writeHandle = @fopen($tempFile, 'xb');
            if (!$writeHandle) {
                // If 'xb' fails (file exists or not supported), try 'wb' but ensure file is empty
                if (file_exists($tempFile)) {
                    @unlink($tempFile);
                } // Remove if it exists
                $writeHandle = @fopen($tempFile, 'wb');
            }

            if (!$writeHandle) {
                $error = error_get_last();
                $errorMsg = $error ? " ({$error['message']})" : "";
                throw new RuntimeException("Fehler beim Öffnen der temporären Datei '$tempFile' zum Schreiben{$errorMsg}.");
            }

            // Apply exclusive lock to the temporary file
            if (!flock($writeHandle, LOCK_EX)) {
                @fclose($writeHandle);
                @unlink($tempFile);
                throw new RuntimeException("Konnte keine Schreibsperre für die temporäre Datei '$tempFile' erhalten.");
            }

            try {
                // 3. Write non-deleted records to the temporary file and build new index
                foreach ($allRecordsFromIndex as $recordId => $record) {
                    // We already skipped deleted records in the generator
                    // Double check just in case
                    if (isset($record['_deleted']) && $record['_deleted'] === true) {
                        error_log("Warnung: Kompaktierung fand '_deleted' Datensatz ID $recordId, obwohl Generator filtern sollte.");
                        continue;
                    }
                    // Re-encode
                    $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $line = $json . "\n";
                    $dataToWrite = $line;

                    // Re-compress if needed
                    if ($this->compressionLevel > 0) {
                        $compressed = gzencode($line, $this->compressionLevel);
                        if ($compressed === false) {
                            throw new RuntimeException("Fehler beim Komprimieren des Datensatzes ID '$recordId' während Kompaktierung.");
                        }
                        $dataToWrite = $compressed;
                    }

                    // Get offset within the NEW temp file
                    $offsetInNewFile = ftell($writeHandle);
                    if ($offsetInNewFile === false) {
                        throw new RuntimeException("Konnte Offset in temporärer Datei '$tempFile' nicht bestimmen.");
                    }

                    // Write data
                    $bytesToWrite = strlen($dataToWrite);
                    $bytesWritten = fwrite($writeHandle, $dataToWrite);

                    // Verify write
                    if ($bytesWritten === false || $bytesWritten < $bytesToWrite) {
                        $error = error_get_last();
                        $errorMsg = $error ? " ({$error['message']})" : "";
                        throw new RuntimeException("Fehler beim Schreiben in temporäre Datei '$tempFile' für ID '$recordId'. Geschrieben: $bytesWritten / $bytesToWrite {$errorMsg}.");
                    }

                    // Store new index entry for this record
                    $newIndex[$recordId] = ['offset' => $offsetInNewFile, 'length' => $bytesWritten];
                } // End foreach record

                // Ensure all data is written to disk before proceeding
                if (!fflush($writeHandle)) {
                    error_log("Warnung: fflush() fehlgeschlagen für temporäre Kompaktierungsdatei '$tempFile'.");
                }

            } finally {
                // Release lock and close handle
                flock($writeHandle, LOCK_UN);
                fclose($writeHandle);
            } // End try/finally for temp file handle


            // --- Atomically Replace Old File with New File ---

            // 4. Backup original data file (optional but recommended)
            $backupDone = false;
            if (file_exists($dataFile)) {
                if (!@rename($dataFile, $backupFile)) {
                    // Rename failed (e.g. cross-device) - try copy as backup? More risky.
                    // For now, we abort if rename fails, leaving the original file.
                    @unlink($tempFile); // Clean up temp file
                    $error = error_get_last();
                    $errorMsg = $error ? " ({$error['message']})" : "";
                    throw new RuntimeException("Konnte alte Datendatei '$dataFile' nicht nach '$backupFile' umbenennen{$errorMsg}. Kompaktierung abgebrochen.");
                }
                $backupDone = true;
            } else {
                // Original file doesn't exist? Shouldn't happen if there was data.
                // Continue with rename of temp file. No backup needed/possible.
            }


            // 5. Rename temporary file to the final data file name
            if (!@rename($tempFile, $dataFile)) {
                // Rename failed! Critical situation. Attempt to restore backup.
                $renameError = error_get_last();
                $renameErrorMsg = $renameError ? " ({$renameError['message']})" : "";

                if ($backupDone && file_exists($backupFile)) {
                    // Attempt to rename backup back to original
                    if (!@rename($backupFile, $dataFile)) {
                        $restoreError = error_get_last();
                        $restoreErrorMsg = $restoreError ? " ({$restoreError['message']})" : "";
                        // CRITICAL FAILURE: Could not rename temp file AND could not restore backup
                        // Data file might be missing or incomplete! Manual intervention likely needed.
                        throw new RuntimeException("KRITISCH: Kompaktierung fehlgeschlagen (Umbenennen von '$tempFile' => '$dataFile' fehlgeschlagen{$renameErrorMsg}) UND Wiederherstellung von Backup '$backupFile' fehlgeschlagen{$restoreErrorMsg}! DATENBANK INKONSISTENT!");
                    } else {
                        // Backup restored successfully. Compaction failed, but state is back to before.
                        @unlink($tempFile); // Clean up temp file
                        throw new RuntimeException("Kompaktierung fehlgeschlagen (Umbenennen von '$tempFile' => '$dataFile' fehlgeschlagen{$renameErrorMsg}). Backup '$backupFile' wurde wiederhergestellt.");
                    }
                } else {
                    // Rename failed and NO backup was made or restore failed previously
                    // Temp file still exists, original file might be missing or the backup file.
                    throw new RuntimeException("KRITISCH: Kompaktierung fehlgeschlagen (Umbenennen von '$tempFile' => '$dataFile' fehlgeschlagen{$renameErrorMsg}) und Backup konnte nicht wiederhergestellt werden (oder wurde nicht erstellt). DATENBANK INKONSISTENT!");
                }
            }

            // 6. Rename successful, temp file is now the data file. Clean up backup.
            if ($backupDone && file_exists($backupFile)) {
                @unlink($backupFile); // Remove the backup file
            }

        } catch (Throwable $e) {
            // General error handling: clean up temp file if it exists
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            // Clean up backup file if rename failed early? No, keep backup if original was renamed away.

            // Re-throw specific exceptions or a general runtime exception
            if ($e instanceof JsonException) {
                throw new RuntimeException("Fehler bei JSON Operation während Datenkompaktierung für {$dataFile}: " . $e->getMessage(), 0, $e);
            }
            // Don't wrap RuntimeExceptions, rethrow them directly
            if ($e instanceof RuntimeException || $e instanceof InvalidArgumentException) {
                throw $e;
            }
            // Wrap other Throwables
            throw new RuntimeException("Fehler bei der Datenkompaktierung für {$dataFile}: " . $e->getMessage(), (int) $e->getCode(), $e);
        }

        // 7. Return the newly generated index based on the compacted file
        return $newIndex;
    }

    /**
     * Creates a backup copy of the current data file.
     *
     * @param string $backupDir The directory to place the backup in. Must exist and be writable.
     * @param string $timestamp A unique timestamp string for the backup filename.
     * @return string The full path to the created backup file, or empty string if source does not exist.
     * @throws RuntimeException If the backup cannot be created (e.g., copy fails).
     */
    public function backupData(string $backupDir, string $timestamp): string
    {
        $dataFile = $this->config->getDataFile();

        clearstatcache(true, $dataFile); // Ensure fresh file status
        if (!file_exists($dataFile) || !is_file($dataFile)) {
            error_log("Datendatei '$dataFile' existiert nicht oder ist kein File, Backup übersprungen.");
            return ''; // Source file doesn't exist or isn't a file
        }
        if (!is_readable($dataFile)) {
            throw new RuntimeException("Datendatei '$dataFile' ist nicht lesbar, Backup nicht möglich.");
        }

        $baseName = basename($dataFile);
        // Sanitize timestamp just in case
        $safeTimestamp = preg_replace('/[^A-Za-z0-9_\-]/', '_', $timestamp);
        $backupFile = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $baseName . '.bak.' . $safeTimestamp;

        // Use copy()
        if (!@copy($dataFile, $backupFile)) {
            $error = error_get_last();
            $errorMsg = $error ? " (Error: {$error['message']})" : "";
            throw new RuntimeException("Daten-Backup von '$dataFile' nach '$backupFile' konnte nicht erstellt werden{$errorMsg}.");
        }

        return $backupFile;
    }

    /**
     * Returns the compression level used by this manager.
     * @return int
     */
    public function getCompressionLevel(): int
    {
        return $this->compressionLevel;
    }

    /**
     * Returns the associated config.
     * @return FlatFileConfig
     */
    public function getConfig(): FlatFileConfig
    {
        return $this->config;
    }
    
    /**
     * Schließt beide Handles.
     */
    public function closeHandles(): void {
        $this->closeReadHandle();
        $this->closeWriteHandle();
    }
}