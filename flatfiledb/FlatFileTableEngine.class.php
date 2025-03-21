<?php
declare(strict_types=1);

namespace FlatFileDB;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Engine für eine einzelne Tabelle: Insert, Update, Delete, Select, Kompaktierung.
 */
class FlatFileTableEngine
{
    private FlatFileConfig $config;
    private FlatFileFileManager $fileManager;
    private FlatFileIndexBuilder $indexBuilder;
    private FlatFileTransactionLog $transactionLog;
    
    /** @var array<string, array> Einfacher Datensatz-Cache */
    private array $dataCache = [];
    
    /** @var int Maximale Anzahl an Datensätzen im Cache */
    private int $maxCacheSize = 100;
    
    /** @var array<string, array> Schema-Definition für diese Tabelle */
    private array $schema = [];
    
    /**
     * @param FlatFileConfig $config Konfiguration der Tabelle
     */
    public function __construct(FlatFileConfig $config)
    {
        $this->config = $config;
        $this->fileManager = new FlatFileFileManager($config);
        $this->indexBuilder = new FlatFileIndexBuilder($config);
        $this->transactionLog = new FlatFileTransactionLog($config);
    }
    
    /**
     * Gibt die Konfiguration zurück.
     * 
     * @return FlatFileConfig Konfiguration
     */
    public function getConfig(): FlatFileConfig
    {
        return $this->config;
    }
    
    /**
     * Setzt ein Schema für die Tabelle (Validierung).
     * 
     * @param array $requiredFields Liste der Pflichtfelder
     * @param array $fieldTypes Assoziatives Array mit Feldname => Erwarteter Typ
     */
    public function setSchema(array $requiredFields = [], array $fieldTypes = []): void
    {
        $this->schema = [
            'requiredFields' => $requiredFields,
            'fieldTypes'     => $fieldTypes
        ];
    }
    
    /**
     * Fügt einen neuen Datensatz ein.
     * 
     * @param string $recordId ID des Datensatzes
     * @param array $data Datensatzfelder
     * @return bool True bei Erfolg, false wenn ID bereits existiert
     * @throws InvalidArgumentException bei ungültiger ID oder Daten
     * @throws RuntimeException bei Schreibfehlern
     */
    public function insertRecord(string $recordId, array $data): bool
    {
        if (!FlatFileValidator::isValidId($recordId)) {
            throw new InvalidArgumentException("Ungültige ID: $recordId");
        }
        
        // Schema-Validierung wenn definiert
        if (!empty($this->schema)) {
            FlatFileValidator::validateData(
                $data, 
                $this->schema['requiredFields'] ?? [], 
                $this->schema['fieldTypes'] ?? []
            );
        }
        
        // Prüfen ob Datensatz bereits existiert
        if ($this->indexBuilder->hasKey($recordId)) {
            return false;
        }
        
        try {
            $data['id'] = (string)$recordId;
            $data['created_at'] = time();
            $data['_deleted'] = false;
            $offset = $this->fileManager->appendRecord($data);
            $this->indexBuilder->setIndex($recordId, $offset);
            $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_INSERT, $recordId, $data);
            
            // Im Cache speichern
            $this->addToCache($recordId, $data);
            
            return true;
        } catch (Throwable $e) {
            throw new RuntimeException("Fehler beim Einfügen des Datensatzes $recordId: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Aktualisiert einen bestehenden Datensatz.
     * 
     * @param string $recordId ID des Datensatzes
     * @param array $newData Neue Datensatzfelder
     * @return bool True bei Erfolg, false wenn Datensatz nicht existiert
     * @throws RuntimeException bei Schreibfehlern
     */
    public function updateRecord(string $recordId, array $newData): bool
    {
        $recordId = (string)$recordId;
        $oldOffset = $this->indexBuilder->getIndexOffset($recordId);
        if ($oldOffset === null) {
            return false;
        }
    
        // Schema-Validierung wenn definiert
        if (!empty($this->schema)) {
            FlatFileValidator::validateData(
                $newData,
                $this->schema['requiredFields'] ?? [],
                $this->schema['fieldTypes'] ?? []
            );
        }
    
        try {
            $oldData = $this->fileManager->readRecordAtOffset($oldOffset);
            if (!$oldData || !is_array($oldData) || !isset($oldData['id'])) {
                return false;
            }
    
            // 1. Append the *new* record first.
            $newData['id'] = $recordId;
            $newData['created_at'] = $oldData['created_at'] ?? time();
            $newData['updated_at'] = time();
            $newOffset = $this->fileManager->appendRecord($newData);
    
            // 2. Update the index.
            $this->indexBuilder->setIndex($recordId, $newOffset);
    
            // 3. *Now* mark the old record as deleted.
            $oldData['_deleted'] = true;
            $this->fileManager->appendRecord($oldData); // Append the deletion marker
    
            $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_UPDATE, $recordId, $newData);
    
            // Cache aktualisieren
            $this->addToCache($recordId, $newData);
    
            return true;
        } catch (Throwable $e) {
            // Rollback:  If anything failed, try to restore the old state.  This is
            // simplified; a full transaction log would be better.
            if (isset($newOffset)) {
                $this->indexBuilder->setIndex($recordId, $oldOffset); // Restore old index
            }
            throw new RuntimeException("Fehler beim Aktualisieren des Datensatzes $recordId: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Löscht einen Datensatz.
     * 
     * @param string $recordId ID des Datensatzes
     * @return bool True bei Erfolg, false wenn Datensatz nicht existiert
     * @throws RuntimeException bei Schreibfehlern
     */
    public function deleteRecord(string $recordId): bool
    {
        $recordId = (string)$recordId;
        $oldOffset = $this->indexBuilder->getIndexOffset($recordId);
        if ($oldOffset === null) {
            error_log("DEBUG: ID '$recordId' nicht im Index gefunden.");
            return false;
        }

        try {
            $oldData = $this->fileManager->readRecordAtOffset($oldOffset);
            if (!$oldData || ($oldData['_deleted'] === true)) {
                return false;
            }

            $oldData['_deleted'] = true;
            $oldData['deleted_at'] = time();
            // Append the deletion marker *first*.
            $this->fileManager->appendRecord($oldData);

            // *Now* remove the index.
            $this->indexBuilder->removeIndex($recordId);
            $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_DELETE, $recordId);

            // Aus Cache entfernen
            unset($this->dataCache[$recordId]);

            return true;
        } catch (Throwable $e) {
            error_log("Fehler beim Löschen des Datensatzes '$recordId': " . $e->getMessage());
            // Rollback:  If we failed to mark the record as deleted,
            // we should put the index entry *back*.
            $this->indexBuilder->setIndex($recordId, $oldOffset);
            throw new RuntimeException("Fehler beim Löschen des Datensatzes $recordId: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Liest einen Datensatz.
     * 
     * @param string $recordId ID des Datensatzes
     * @return array|null Datensatz oder null wenn nicht gefunden
     */
    public function selectRecord(string|int $recordId): ?array
    {
        $recordId = (string)$recordId;

        // Zuerst im Cache suchen
        if (isset($this->dataCache[$recordId])) {
            return $this->dataCache[$recordId];
        }
        
        $offset = $this->indexBuilder->getIndexOffset($recordId);
        if ($offset === null) {
            return null;
        }
        
        try {
            $data = $this->fileManager->readRecordAtOffset($offset);
            
            if (isset($data) && empty($data['_deleted'])) {
                // Im Cache speichern
                $this->addToCache($recordId, $data);
                return $data;
            }
            
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }
    
    /**
     * Liest alle aktiven Datensätze (nicht gelöscht) unter Verwendung des Index.
     * 
     * @return array Liste aller Datensätze
     */
    public function selectAllRecords(): array
    {
        $results = [];
        $allKeys = $this->indexBuilder->getAllKeys();
        
        foreach ($allKeys as $recordId) {
            $recordId = (string)$recordId;
            $record = $this->selectRecord($recordId);
            if ($record !== null) {
                $results[] = $record;
            }
        }
        
        return $results;
    }
    
    /**
     * Sucht nach Datensätzen, die bestimmte Kriterien erfüllen.
     * 
     * @param callable $filterFn Filterfunktion, die für jeden Datensatz true/false zurückgibt
     * @param int $limit Maximale Anzahl der zurückgegebenen Datensätze (0 = alle)
     * @param int $offset Überspringt die ersten n passenden Datensätze
     * @return array Liste der passenden Datensätze
     */
    public function findRecords(callable $filterFn, int $limit = 0, int $offset = 0): array
    {
        $results = [];
        $count = 0;
        $skipped = 0;
        
        foreach ($this->fileManager->readRecordsGenerator() as $record)
        {
            if ($record !== null && $filterFn($record)) {
                if ($offset > 0 && $skipped < $offset) {
                    $skipped++;
                    continue;
                }
    
                $results[] = $record;
                $count++;
    
                if ($limit > 0 && $count >= $limit) {
                    break;
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Kompaktiert die Tabelle und baut den Index neu auf.
     */
    public function compactTable(): void
    {
        try {
            // Vor der Kompaktierung die Indizes speichern
            $this->commitIndex();
            
            // Backup erstellen
            $backupDir = dirname($this->config->getDataFile()) . '/backups';
            $this->fileManager->backupData($backupDir);
            
            $newIndex = [];
            $this->fileManager->compactData($newIndex);
            
            // Index-Datei aktualisieren
            $result = file_put_contents(
                $this->config->getIndexFile(),
                json_encode($newIndex, JSON_THROW_ON_ERROR)
            );
            
            if ($result === false) {
                throw new RuntimeException("Index-Datei konnte nicht geschrieben werden.");
            }
            
            // Neuinitialisierung des IndexBuilders
            // $this->indexBuilder = new FlatFileIndexBuilder($this->config);
            $this->indexBuilder->updateIndex($newIndex);
            
            // Cache leeren
            $this->dataCache = [];
        } catch (Throwable $e) {
            throw new RuntimeException("Fehler bei der Tabellenkompaktierung: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Fügt einen Datensatz zum Cache hinzu.
     * 
     * @param string $recordId ID des Datensatzes
     * @param array $data Datensatz
     */
    private function addToCache(string $recordId, array $data): void
    {
        if (count($this->dataCache) >= $this->maxCacheSize) {
            reset($this->dataCache);
            $firstKey = key($this->dataCache);
            unset($this->dataCache[$firstKey]);
        }
        
        $this->dataCache[$recordId] = $data;
    }
    
    /**
     * Speichert den Index in die Datei.
     */
    public function commitIndex(): void
    {
        $this->indexBuilder->commitIndex();
    }
    
    /**
     * Leert den Cache.
     */
    public function clearCache(): void
    {
        $this->dataCache = [];
    }
    
    /**
     * Setzt die maximale Cache-Größe.
     * 
     * @param int $size Maximale Anzahl der Datensätze im Cache
     */
    public function setCacheSize(int $size): void
    {
        if ($size < 1) {
            throw new InvalidArgumentException("Cache size must be at least 1");
        }
        $this->maxCacheSize = max(1, $size);
        while (count($this->dataCache) > $this->maxCacheSize) {
            reset($this->dataCache);
            $firstKey = key($this->dataCache);
            unset($this->dataCache[$firstKey]);
        }
    }
    
    /**
     * Erstellt eine Sicherung der Tabellenkomponenten.
     * 
     * @param string $backupDir Verzeichnis für die Sicherung
     * @return array Pfade zu den gesicherten Dateien
     */
    public function backup(string $backupDir): array
    {
        $backupFiles = [];
        
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
            throw new RuntimeException("Backup-Verzeichnis konnte nicht erstellt werden.");
        }
        
        // Zuerst alle Indizes speichern
        $this->commitIndex();
        
        // Datendatei sichern
        $dataFile = $this->config->getDataFile();
        $timestamp = date('YmdHis') . '_' . uniqid();
        $backupData = $backupDir . '/' . basename($dataFile) . '.' . $timestamp;
        
        if (copy($dataFile, $backupData)) {
            $backupFiles['data'] = $backupData;
        } else {
            throw new RuntimeException("Datendatei konnte nicht gesichert werden.");
        }
        
        // Indexdatei sichern
        $indexFile = $this->config->getIndexFile();
        $backupIndex = $backupDir . '/' . basename($indexFile) . '.' . $timestamp;
        
        if (copy($indexFile, $backupIndex)) {
            $backupFiles['index'] = $backupIndex;
        } else {
            throw new RuntimeException("Indexdatei konnte nicht gesichert werden."); // Add exception
        }
        
        // Log-Datei sichern
        $logFile = $this->config->getLogFile();
        $backupLog = $backupDir . '/' . basename($logFile) . '.' . $timestamp;
        
        if (copy($logFile, $backupLog)) {
            $backupFiles['log'] = $backupLog;
        } else {
            throw new RuntimeException("Log-Datei konnte nicht gesichert werden."); // Add exception
        }
        
        return $backupFiles;
    }

    public function clearTable(): void
    {
        // Leere die Datendatei
        if (file_put_contents($this->config->getDataFile(), '') === false) {
            throw new RuntimeException("Daten-Datei konnte nicht geleert werden.");
        }
        
        // Leere die Index-Datei
        if (file_put_contents($this->config->getIndexFile(), '') === false) {
            throw new RuntimeException("Index-Datei konnte nicht geleert werden.");
        }
        
        // Leere die Log-Datei
        if (file_put_contents($this->config->getLogFile(), '') === false) {
            throw new RuntimeException("Log-Datei konnte nicht geleert werden.");
        }
        
        // Setze den internen Index und Cache zurück
        $this->indexBuilder->updateIndex([]);
        $this->clearCache();
    }
}