<?php
declare(strict_types=1);

namespace FlatFileDB;

use Generator;
use RuntimeException;
use JsonException;
use Throwable;

/**
 * Liest und schreibt Datensätze in einer JSON-Lines-Datei (Append-Only) mit Komprimierung.
 */
class FlatFileFileManager
{
    private FlatFileConfig $config;
    private $compressionLevel; // HINZUGEFÜGT: Kompressionslevel

    /**
     * @param FlatFileConfig $config Konfiguration der Tabelle
     * @param int $compressionLevel Kompressionslevel (0-9, 0 = keine, 9 = max)
     */
    public function __construct(FlatFileConfig $config, int $compressionLevel = 6) // HINZUGEFÜGT: compressionLevel
    {
        $this->config = $config;
        $this->compressionLevel = $compressionLevel; // HINZUGEFÜGT
        $dataFile = $this->config->getDataFile();
        $dataDir = dirname($dataFile);

        // Verzeichnis erstellen falls erforderlich
        if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true)) {
            throw new RuntimeException("Daten-Verzeichnis '$dataDir' konnte nicht erstellt werden.");
        }

        if (!file_exists($dataFile)) {
            touch($dataFile);
        }
    }

    /**
     * Hängt einen Datensatz an das Datei-Ende an und gibt dessen Byte-Offset zurück.
     *
     * @param array $record Der zu speichernde Datensatz
     * @return int Byte-Offset des Datensatzes (unkomprimiert)
     * @throws RuntimeException wenn der Datensatz nicht geschrieben werden kann
     */
    public function appendRecord(array $record): int
    {
        $dataFile = $this->config->getDataFile();

        // Check if the file exists. If not, create it.
        if (!file_exists($dataFile)) {
            if (!touch($dataFile)) {
                throw new RuntimeException("Data file '$dataFile' does not exist and could not be created.");
            }
        }

        // Open file in read/write append mode ('a+b') for stable pointer positioning
        $handle = fopen($dataFile, 'a+b');
        if (!$handle) {
            throw new RuntimeException("Fehler beim Öffnen der Datei '$dataFile'.");
        }

        $offset = null;

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Konnte keine exklusive Sperre für die Datei erhalten.');
            }

            // Sicherstellen, dass der Zeiger am Ende der Datei ist
            fseek($handle, 0, SEEK_END);
            $offset = ftell($handle); // Offset *vor* der Komprimierung

            $json = json_encode($record, JSON_THROW_ON_ERROR);
            $compressed = gzencode($json . "\n", $this->compressionLevel); // GEÄNDERT: Komprimierung

            if ($compressed === false) {
                throw new RuntimeException('Fehler beim Komprimieren des Datensatzes.');
            }

            if (fwrite($handle, $compressed) === false) { // GEÄNDERT: Schreibe komprimierte Daten
                throw new RuntimeException('Fehler beim Schreiben des Datensatzes.');
            }

            fflush($handle); // Optional: Daten sofort in die Datei schreiben

            flock($handle, LOCK_UN);
        } catch (Throwable $e) {
            throw new RuntimeException("Fehler beim Anhängen eines Datensatzes: " . $e->getMessage(), 0, $e);
        } finally {
            fclose($handle);
        }

        return $offset; // Rückgabe des *unkomprimierten* Offsets
    }



    /**
     * Liest eine Zeile ab einem bestimmten Byte-Offset.
     *
     * @param int $offset Byte-Offset in der Datei (unkomprimiert)
     * @return array|null Der gelesene Datensatz oder null bei Fehler
     */
    public function readRecordAtOffset(int $offset): ?array
    {
        $handle = fopen($this->config->getDataFile(), 'rb');
        if (!$handle) {
            throw new RuntimeException("Datendatei konnte nicht zum Lesen geöffnet werden");
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException("Konnte keine Lesesperre für die Datei erhalten.");
            }

            if (fseek($handle, $offset) !== 0) {
                throw new RuntimeException("Ungültiger Offset in der Datendatei: $offset");
            }

            // Lese komprimierte Daten, bis ein vollständiger Datensatz gefunden wurde.
            $compressedData = '';
            $startOffset = $offset;

            while (!feof($handle))
            {
                $chunk = gzread($handle, 8192); // GEÄNDERT: Verwende gzread
                if($chunk === false){
                    throw new RuntimeException("Fehler beim Lesen der komprimierten Daten ab Offset: $offset");
                }
                $compressedData .= $chunk;

                // Versuche, die Daten zu dekomprimieren
                $decompressed = @gzdecode($compressedData); // GEÄNDERT: Dekomprimierung, @ unterdrückt Warnungen
                if ($decompressed !== false)
                {
                    // Erfolgreich dekomprimiert, prüfe ob es JSON ist.
                    try {
                        $decoded = json_decode($decompressed, true, 512, JSON_THROW_ON_ERROR);
                        if ($decoded !== null) {
                            flock($handle, LOCK_UN); // Unlock *before* returning
                            return $decoded;
                        }
                    } catch (JsonException $e) {
                        // Kein gültiges JSON, lese weiter (könnte Teil eines größeren Datensatzes sein)
                        continue;
                    }
                }
            }


            // Wenn wir hier ankommen, wurde kein vollständiger Datensatz gefunden
            throw new RuntimeException("Kein gültiger Datensatz gefunden ab Offset: $offset");

        } finally {
            fclose($handle);
        }
    }



    /**
     * Liest alle Datensätze aus der Datei.
     *
     * @return array<int, array> Liste aller Datensätze mit ihren Offsets (unkomprimiert)
     */
    public function readAllRecords(): array
    {
        $result = [];
        $dataFile = $this->config->getDataFile();
        $handle = fopen($dataFile, 'rb');

        if (!$handle) {
            throw new RuntimeException("Could not open data file for reading: $dataFile");
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException("Konnte keine Lesesperre für die Datei erhalten.");
            }

            $compressedBuffer = ''; // GEÄNDERT: Buffer für komprimierte Daten
            $offset = 0;

            while (!feof($handle)) {
                $chunk = gzread($handle, 8192);  // GEÄNDERT: gzread für komprimierte Daten
                if ($chunk === false) {
                    break; // Fehler beim Lesen, Schleife verlassen
                }
                $compressedBuffer .= $chunk;


                // Versuche, vollständige Datensätze aus dem Buffer zu extrahieren
                while (true) { // Innere Schleife zum Extrahieren mehrerer Datensätze
                    $decompressed = @gzdecode($compressedBuffer); // GEÄNDERT: Dekomprimierung
                    if ($decompressed === false) {
                        break; // Nicht genug Daten zum Dekomprimieren, äußere Schleife fortsetzen
                    }

                    try {
                        // Versuche, alle JSON-Objekte zu parsen
                        $lines = explode("\n", rtrim($decompressed, "\n"));
                        $lastCompleteLine = '';
                        $completeRecords = [];


                        foreach($lines as $line) {
                            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                            if ($decoded !== null) {
                                $completeRecords[] = $decoded; // Füge den Datensatz hinzu
                                $lastCompleteLine = $line;
                            }
                        }

                        if(empty($completeRecords)){
                            break; // Keine vollständigen Datensätze gefunden.
                        }

                        // Berechne den neuen Offset NACH dem letzten vollständigen Datensatz
                        $bytesConsumed = strlen(gzencode($lastCompleteLine . "\n", $this->compressionLevel)); // Länge des *komprimierten* Datensatzes
                        $compressedBuffer = substr($compressedBuffer, $bytesConsumed); // Entferne den verarbeiteten Teil
                        foreach($completeRecords as $record){
                            $result[$offset] = $record; // Füge den Datensatz mit dem *unkomprimierten* Offset hinzu
                            $offset += strlen(json_encode($record, JSON_THROW_ON_ERROR) . "\n"); // Inkrementiere den Offset um die Länge des *unkomprimierten* Datensatzes.
                        }

                    } catch (JsonException $e) {
                        // Kein vollständiges JSON, Schleife verlassen
                        break;
                    }
                }
            }


            flock($handle, LOCK_UN);
        } catch (Throwable $e) {
            throw new RuntimeException("Error during readAllRecords in file $dataFile: " . $e->getMessage(), 0, $e);
        } finally {
            fclose($handle);
        }

        return $result;
    }


    /**
     * Kompaktiert die Datei, indem alle Datensätze eingelesen und pro ID
     * nur der letzte Eintrag übernommen wird.  ... (Rest der Methode ist unten)
     */
    public function compactData(array &$newIndex): array
    {
        $newIndex = [];
        $dataFile = $this->config->getDataFile();
        $tempFile = $dataFile . '.tmp';
        $backupFile = $dataFile . '.bak.' . date('YmdHisu');

        $records = [];
        $readHandle = fopen($dataFile, 'rb');
        if (!$readHandle) {
            throw new RuntimeException('Fehler beim Öffnen der Daten-Datei.');
        }

        try {
            if (!flock($readHandle, LOCK_SH)) {
                throw new RuntimeException("Konnte keine Lesesperre für die Datei erhalten.");
            }

            $compressedBuffer = ''; // GEÄNDERT: Buffer für komprimierte Daten

            while (!feof($readHandle)) {
                $chunk = gzread($readHandle, 8192); // GEÄNDERT: gzread
                if ($chunk === false) {
                    break;
                }
                $compressedBuffer .= $chunk;

                // Versuche, vollständige Datensätze aus dem Buffer zu extrahieren (wie in readAllRecords)
                while(true){
                    $decompressed = @gzdecode($compressedBuffer); //GEÄNDERT
                    if($decompressed === false){
                        break;
                    }

                    try {
                        $lines = explode("\n", rtrim($decompressed, "\n"));
                        $lastCompleteLine = '';
                        $completeRecords = [];

                        foreach($lines as $line){
                            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                            if (is_array($decoded) && isset($decoded['id'])) {
                                // Überschreibe vorherige Einträge – so gewinnt der letzte Eintrag
                                $records[$decoded['id']] = $decoded;
                                $completeRecords[] = $decoded;
                                $lastCompleteLine = $line;
                            }
                        }

                        if(empty($completeRecords)){
                            break;
                        }

                        $bytesConsumed = strlen(gzencode($lastCompleteLine . "\n", $this->compressionLevel));
                        $compressedBuffer = substr($compressedBuffer, $bytesConsumed);

                    } catch (JsonException $e) {
                        // Kein vollständiges JSON, Schleife verlassen
                        break;
                    }

                }
            }

            flock($readHandle, LOCK_UN);
        } finally {
            fclose($readHandle);
        }



        $writeHandle = fopen($tempFile, 'wb');
        if (!$writeHandle) {
            throw new RuntimeException('Fehler beim Öffnen der temporären Datei.');
        }

        try {
            if (!flock($writeHandle, LOCK_EX)) {
                throw new RuntimeException("Konnte keine Schreibsperre für die temporäre Datei erhalten.");
            }

            foreach ($records as $id => $record) {
                // Überspringe den Datensatz, wenn er als gelöscht markiert ist
                if (!empty($record['_deleted'])) {
                    continue;
                }

                $offsetInNewFile = ftell($writeHandle); // Offset *vor* dem Schreiben
                $encoded = json_encode($record, JSON_THROW_ON_ERROR);
                $compressed = gzencode($encoded . "\n", $this->compressionLevel); // GEÄNDERT: Komprimierung
                if (fwrite($writeHandle, $compressed) === false) { // GEÄNDERT: Schreibe komprimierte Daten
                    throw new RuntimeException('Fehler beim Schreiben während der Kompaktierung.');
                }

                $newIndex[$id] = $offsetInNewFile; // Speichere den *unkomprimierten* Offset
            }

            flock($writeHandle, LOCK_UN);
        } finally {
            fclose($writeHandle);
        }

        // 3. Erstelle ein Backup der alten Datei *VOR* dem Löschen/Umbenennen
        if (!copy($dataFile, $backupFile)) {
            throw new RuntimeException('Failed to create backup during compaction.');
        }

        // 4. Ersetze die alte Datei durch die neue.  *Zuerst* löschen, *dann* umbenennen.
        if (!unlink($dataFile)) {
            throw new RuntimeException('Alte Daten-Datei konnte nicht gelöscht werden.');
        }

        if (!rename($tempFile, $dataFile)) {
            // Fehler beim Umbenennen!  Versuche, das Backup wiederherzustellen.
            if (file_exists($backupFile)) { // Check if backup exists *before* rename
                if (!rename($backupFile, $dataFile)) { // Versuche, das Backup wiederherzustellen
                    // KRITISCHER FEHLER:  Sowohl das Umbenennen als auch die Wiederherstellung sind fehlgeschlagen!
                    throw new RuntimeException('CRITICAL: Compaction failed, and backup restoration failed!  Data may be lost.');
                }
            } else {
                // Backup file doesn't exist!
                throw new RuntimeException('CRITICAL: Compaction failed, and backup file does not exist! Data may be lost.');
            }
            throw new RuntimeException('Temporäre Datei konnte nicht umbenannt werden. Wiederherstellung versucht.');
        }

        // Aufräumen: Lösche die Backup-Datei nach erfolgreicher Kompaktierung.
        @unlink($backupFile); // Verwende @, um Warnungen zu unterdrücken, wenn die Datei nicht existiert.

        return $newIndex;
    }


    /**
     * Erstellt ein Backup der Datendatei.
     *
     * @param string $backupDir Verzeichnis für das Backup
     * @return string Pfad zur Backup-Datei
     */
    public function backupData(string $backupDir): string
    {
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
            throw new RuntimeException("Backup-Verzeichnis konnte nicht erstellt werden.");
        }

        $dataFile = $this->config->getDataFile();
        $timestamp = date('YmdHis');
        $backupFile = $backupDir . '/' . basename($dataFile) . '.' . $timestamp;

        if (!copy($dataFile, $backupFile)) {
            throw new RuntimeException("Datei-Backup konnte nicht erstellt werden.");
        }

        return $backupFile;
    }


    // Generator-based approach (better for large files)
    public function readRecordsGenerator(): Generator
    {
        $dataFile = $this->config->getDataFile(); // Get filename for error message
        $handle = fopen($dataFile, 'rb');

        if (!$handle) {
            throw new RuntimeException("Could not open data file: $dataFile");
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException("Konnte keine Lesesperre für die Datei erhalten.");
            }

            $compressedBuffer = ''; // GEÄNDERT: Buffer für komprimierte Daten
            $offset = 0;

            while (!feof($handle)) {
                $chunk = gzread($handle, 8192); // GEÄNDERT: gzread
                if ($chunk === false) {
                    break;
                }
                $compressedBuffer .= $chunk;

                // Extrahiere Datensätze (wie in readAllRecords)
                while(true){
                    $decompressed = @gzdecode($compressedBuffer);  //GEÄNDERT
                    if($decompressed === false){
                        break;
                    }

                    try{
                        $lines = explode("\n", rtrim($decompressed, "\n"));
                        $lastCompleteLine = '';
                        $completeRecords = [];

                        foreach($lines as $line){
                            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                            if($decoded !== null){
                                $completeRecords[] = $decoded;
                                $lastCompleteLine = $line;
                            }
                        }

                        if(empty($completeRecords)){
                            break;
                        }


                        $bytesConsumed = strlen(gzencode($lastCompleteLine . "\n", $this->compressionLevel));
                        $compressedBuffer = substr($compressedBuffer, $bytesConsumed);
                        foreach($completeRecords as $record){
                            yield $offset => $record; // Yield *unkomprimierten* offset
                            $offset += strlen(json_encode($record, JSON_THROW_ON_ERROR) . "\n");
                        }

                    } catch (JsonException $e) {
                        break;
                    }
                }
            }


            flock($handle, LOCK_UN);
        } catch (Throwable $e) {
            throw new RuntimeException("Error during readRecordsGenerator in file $dataFile: " . $e->getMessage(), 0, $e);
        } finally {
            fclose($handle);
        }
    }
}