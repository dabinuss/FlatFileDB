<?php
declare(strict_types=1);

namespace FlatFileDB;

use InvalidArgumentException;
use RuntimeException;
use JsonException;
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

    /** @var array<string, array> LRU Cache für Datensätze */
    private array $dataCache = [];
    private int $maxCacheSize = 100;
    /** @var array{requiredFields?: list<string>, fieldTypes?: array<string, string>} Schema Definition */
    private array $schema = [];
    /** @var array<string, float> Zugriffszeiten für LRU Cache */
    private array $cacheTimestamps = [];

    /**
     * @param FlatFileConfig $config
     * @param int $compressionLevel
     * @param int $cacheSize
     * @throws RuntimeException
     */
    public function __construct(
        FlatFileConfig $config,
        int $compressionLevel = FlatFileDBConstants::DEFAULT_COMPRESSION_LEVEL,
        int $cacheSize = 100
    ) {
        try {
            $this->config = $config;
            $this->setCacheSize($cacheSize);
            $this->indexBuilder = new FlatFileIndexBuilder($config);
            $this->fileManager = new FlatFileFileManager($config, $this->indexBuilder, $compressionLevel);
            $this->transactionLog = new FlatFileTransactionLog($config);
            $this->schema = [];
        } catch (Throwable $e) {
            $tableNameGuess = basename(dirname($config->getDataFile()));
            throw new RuntimeException(
                "Fehler bei der Initialisierung der TableEngine für Tabelle '$tableNameGuess' (Daten: {$config->getDataFile()}): " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    public function getConfig(): FlatFileConfig
    {
        return $this->config;
    }
    /** @internal */
    public function getIndexBuilder(): FlatFileIndexBuilder
    {
        return $this->indexBuilder;
    }

    /**
     * @param list<string> $requiredFields
     * @param array<string, string> $fieldTypes
     * @throws InvalidArgumentException
     */
    public function setSchema(array $requiredFields = [], array $fieldTypes = []): void
    {
        if (!array_is_list($requiredFields))
            throw new InvalidArgumentException("requiredFields muss eine Liste (list) von Strings sein.");
        foreach ($requiredFields as $field) {
            if (!is_string($field) || trim($field) === '')
                throw new InvalidArgumentException("requiredFields muss eine Liste von nicht-leeren Strings sein.");
        }
        $uniqueRequired = array_unique($requiredFields);
        if (count($uniqueRequired) !== count($requiredFields))
            throw new InvalidArgumentException("requiredFields enthält doppelte Feldnamen.");

        foreach ($fieldTypes as $key => $value) {
            if (!is_string($key) || trim($key) === '' || !is_string($value) || trim($value) === '')
                throw new InvalidArgumentException("fieldTypes muss ein Array<string, string> mit nicht-leeren Schlüsseln und Werten sein.");
            $normalizedType = strtolower(ltrim($value, '?'));
            $knownTypes = ['string', 'int', 'integer', 'float', 'double', 'bool', 'boolean', 'array', 'list', 'object', 'numeric', 'scalar', 'null'];
            if (!in_array($normalizedType, $knownTypes, true))
                trigger_error("Unbekannter Typ '$value' im Schema für Feld '$key' definiert.", E_USER_WARNING);
        }
        $this->schema = ['requiredFields' => $uniqueRequired, 'fieldTypes' => $fieldTypes];
    }

    /**
     * @param string $fieldName
     * @throws InvalidArgumentException | RuntimeException
     */
    public function createIndex(string $fieldName): void
    {
        if (trim($fieldName) === '')
            throw new InvalidArgumentException("Feldname für Index darf nicht leer sein.");
        if (in_array($fieldName, ['id', '_deleted', 'created_at', 'updated_at', 'deleted_at'], true))
            throw new InvalidArgumentException("Interne Felder können nicht indiziert werden.");

        $measurement = FlatFileDBStatistics::measurePerformance(function () use ($fieldName) {
            $isNewIndex = !in_array($fieldName, $this->indexBuilder->getManagedIndexedFields(), true);
            try {
                $this->indexBuilder->createIndex($fieldName);
                $tempSecondaryIndexData = [];
                foreach ($this->fileManager->readRecordsGeneratorIndexed() as $recordId => $record) {
                    if (array_key_exists($fieldName, $record)) {
                        $value = $record[$fieldName];
                        if (!is_scalar($value) && $value !== null)
                            continue;
                        if ($value === null)
                            $stringValue = "";
                        elseif (is_bool($value))
                            $stringValue = $value ? "1" : "0";
                        else
                            $stringValue = (string) $value;
                        if (!isset($tempSecondaryIndexData[$stringValue]))
                            $tempSecondaryIndexData[$stringValue] = [];
                        $tempSecondaryIndexData[$stringValue][$recordId] = $recordId;
                    }
                }
                $finalSecondaryIndex = [];
                foreach ($tempSecondaryIndexData as $stringValue => $idMap) {
                    $ids = array_values($idMap);
                    $finalSecondaryIndex[$stringValue] = $ids;
                }
                $this->indexBuilder->updateSecondaryIndex($fieldName, $finalSecondaryIndex);
                $this->indexBuilder->commitSecondaryIndex($fieldName);
                return true;
            } catch (Throwable $e) {
                if ($isNewIndex) {
                    try {
                        $this->indexBuilder->dropIndex($fieldName);
                    } catch (Throwable $ignore) {
                        error_log("Fehler beim Rollback (dropIndex) für '$fieldName': " . $ignore->getMessage());
                    }
                } else {
                    error_log("Fehler beim Neuaufbau des Index '$fieldName'. Index könnte inkonsistent sein.");
                }
                if ($e instanceof RuntimeException || $e instanceof InvalidArgumentException)
                    throw $e;
                throw new RuntimeException("Fehler beim Erstellen/Aufbauen des Index für '$fieldName': " . $e->getMessage(), (int) $e->getCode(), $e);
            }
        });
        FlatFileDBStatistics::recordPerformance('CREATE_INDEX_' . strtoupper($fieldName), $measurement['duration']);
    }

    /** @throws InvalidArgumentException | RuntimeException */
    public function dropIndex(string $fieldName): void
    {
        try {
            $this->indexBuilder->dropIndex($fieldName);
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log("Fehler beim Löschen des Index für Feld '$fieldName': " . $e->getMessage());
            throw new RuntimeException("Fehler beim Löschen des Index für Feld '$fieldName': " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * @param array $data
     * @return int
     * @throws InvalidArgumentException | RuntimeException | JsonException
     */
    public function insertRecord(array $data): int
    {
        $measurement = FlatFileDBStatistics::measurePerformance(function () use ($data) {
            $recordId = $this->indexBuilder->getNextId();
            $record = $data;
            unset($record['id'], $record['_deleted'], $record['created_at'], $record['updated_at'], $record['deleted_at']);
            $record['id'] = $recordId;
            $record['created_at'] = time();
            $record['updated_at'] = null;
            $record['_deleted'] = false;
            $record['deleted_at'] = null;

            if (!empty($this->schema)) {
                FlatFileValidator::validateData($record, $this->schema['requiredFields'] ?? [], $this->schema['fieldTypes'] ?? []);
            }

            $blockInfo = null;
            $committed = false;
            try {
                $blockInfo = $this->fileManager->appendRecord($record);
                $this->indexBuilder->setIndex($recordId, $blockInfo);
                $this->updateSecondaryIndexesOnInsert($recordId, $record);
                $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_INSERT, $recordId, $record);
                $this->indexBuilder->commitIndex();
                $this->indexBuilder->commitAllSecondaryIndexes();
                $committed = true;
                $this->addToCache((string) $recordId, $record);
                return $recordId;
            } catch (Throwable $e) {
                error_log("Fehler während Insert für ID $recordId: " . $e->getMessage() . " - Versuchter Rollback...");
                if (!$committed) {
                    try {
                        if ($blockInfo !== null && $this->indexBuilder->getIndexEntry($recordId) === $blockInfo) {
                            $this->indexBuilder->removeIndex($recordId);
                            $this->indexBuilder->commitIndex();
                        }
                        $this->indexBuilder->removeAllSecondaryIndexesForRecord($recordId);
                        $this->indexBuilder->commitAllSecondaryIndexes();
                        unset($this->dataCache[(string) $recordId], $this->cacheTimestamps[(string) $recordId]);
                    } catch (Throwable $rollbackError) {
                        error_log("KRITISCH: Fehler beim Rollback nach fehlgeschlagenem Insert ID $recordId: " . $rollbackError->getMessage());
                    }
                } else {
                    error_log("Fehler nach Commit bei Insert ID $recordId: " . $e->getMessage());
                }
                if ($e instanceof RuntimeException || $e instanceof InvalidArgumentException || $e instanceof JsonException)
                    throw $e;
                throw new RuntimeException("Fehler beim Einfügen von Datensatz ID $recordId: " . $e->getMessage(), (int) $e->getCode(), $e);
            }
        });
        FlatFileDBStatistics::recordPerformance('INSERT', $measurement['duration']);
        return $measurement['result'];
    }

    private function updateSecondaryIndexesOnInsert(int $recordId, array $data): void
    {
        foreach ($this->indexBuilder->getManagedIndexedFields() as $fieldName) {
            if (array_key_exists($fieldName, $data)) {
                try {
                    $this->indexBuilder->setSecondaryIndex($fieldName, $data[$fieldName], $recordId);
                } catch (Throwable $e) {
                    error_log("Fehler beim Aktualisieren des Sek.-Index '$fieldName' für Insert ID $recordId: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * @param int $recordId
     * @param array $newData
     * @return bool True if changed, False if not found or no change.
     * @throws InvalidArgumentException | RuntimeException | JsonException
     */
    public function updateRecord(int $recordId, array $newData): bool
    {
        if ($recordId <= 0)
            throw new InvalidArgumentException("Ungültige Record-ID: $recordId");

        $measurement = FlatFileDBStatistics::measurePerformance(function () use ($recordId, $newData) {
            $oldIndexEntry = $this->indexBuilder->getIndexEntry($recordId);
            if ($oldIndexEntry === null)
                return false;
            try {
                $oldData = $this->fileManager->readRecordAtOffset($oldIndexEntry['offset'], $oldIndexEntry['length']);
            } catch (Throwable $e) {
                error_log("Update Error Reading ID $recordId: " . $e->getMessage());
                try {
                    $this->indexBuilder->removeIndex($recordId);
                    $this->indexBuilder->commitIndex();
                } catch (Throwable $ignore) {
                }
                unset($this->dataCache[(string) $recordId], $this->cacheTimestamps[(string) $recordId]);
                return false;
            }
            if (empty($oldData['id']) || $oldData['id'] != $recordId || !empty($oldData['_deleted'])) {
                error_log("Update Inconsistency ID $recordId (Offset {$oldIndexEntry['offset']}). Fixing index.");
                try {
                    $this->indexBuilder->removeIndex($recordId);
                    $this->indexBuilder->commitIndex();
                } catch (Throwable $ignore) {
                }
                unset($this->dataCache[(string) $recordId], $this->cacheTimestamps[(string) $recordId]);
                return false;
            }

            $updatedRecord = $newData;
            unset($updatedRecord['id'], $updatedRecord['_deleted'], $updatedRecord['created_at'], $updatedRecord['updated_at'], $updatedRecord['deleted_at']);
            $updatedRecord = array_merge($oldData, $updatedRecord);
            $updatedRecord['id'] = $recordId;
            $updatedRecord['created_at'] = $oldData['created_at'] ?? time();
            $updatedRecord['updated_at'] = time();
            $updatedRecord['_deleted'] = false;
            $updatedRecord['deleted_at'] = null;

            if (!empty($this->schema)) {
                FlatFileValidator::validateData($updatedRecord, $this->schema['requiredFields'] ?? [], $this->schema['fieldTypes'] ?? []);
            }
            $metaFields = ['id', 'created_at', 'updated_at', '_deleted', 'deleted_at'];
            $compareOld = array_diff_key($oldData, array_flip($metaFields));
            $compareNew = array_diff_key($updatedRecord, array_flip($metaFields));
            if ($compareOld == $compareNew)
                return false; // No effective change

            $newBlockInfo = null;
            $committed = false;
            try {
                $newBlockInfo = $this->fileManager->appendRecord($updatedRecord);
                $this->indexBuilder->setIndex($recordId, $newBlockInfo);
                $this->updateSecondaryIndexesOnUpdate($recordId, $oldData, $updatedRecord);
                $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_UPDATE, $recordId, $updatedRecord);
                $this->indexBuilder->commitIndex();
                $this->indexBuilder->commitAllSecondaryIndexes();
                $committed = true;
                $this->addToCache((string) $recordId, $updatedRecord);
                return true;
            } catch (Throwable $e) {
                error_log("Fehler während Update ID $recordId: " . $e->getMessage() . " - Versuchter Rollback...");
                if (!$committed) {
                    try {
                        if ($newBlockInfo !== null && $this->indexBuilder->getIndexEntry($recordId) === $newBlockInfo) {
                            $this->indexBuilder->setIndex($recordId, $oldIndexEntry);
                            $this->indexBuilder->commitIndex();
                        }
                        $this->updateSecondaryIndexesOnUpdate($recordId, $updatedRecord, $oldData); // Reverse diff
                        $this->indexBuilder->commitAllSecondaryIndexes();
                        unset($this->dataCache[(string) $recordId], $this->cacheTimestamps[(string) $recordId]);
                    } catch (Throwable $rollbackError) {
                        error_log("KRITISCH: Fehler beim Rollback nach fehlgeschlagenem Update ID $recordId: " . $rollbackError->getMessage());
                    }
                } else {
                    error_log("Fehler nach Commit bei Update ID $recordId: " . $e->getMessage());
                }
                if ($e instanceof RuntimeException || $e instanceof InvalidArgumentException || $e instanceof JsonException)
                    throw $e;
                throw new RuntimeException("Fehler beim Aktualisieren von Datensatz ID $recordId: " . $e->getMessage(), (int) $e->getCode(), $e);
            }
        });
        FlatFileDBStatistics::recordPerformance('UPDATE', $measurement['duration']);
        return $measurement['result'];
    }

    private function updateSecondaryIndexesOnUpdate(int $recordId, array $oldData, array $newData): void
    {
        foreach ($this->indexBuilder->getManagedIndexedFields() as $fieldName) {
            $oldValueExists = array_key_exists($fieldName, $oldData);
            $newValueExists = array_key_exists($fieldName, $newData);
            $oldValue = $oldValueExists ? $oldData[$fieldName] : null;
            $newValue = $newValueExists ? $newData[$fieldName] : null;
            $valueChanged = false;
            if ($oldValueExists !== $newValueExists)
                $valueChanged = true;
            elseif ($oldValueExists && $oldValue !== $newValue)
                $valueChanged = true;

            if ($valueChanged) {
                if ($oldValueExists && (is_scalar($oldValue) || $oldValue === null)) {
                    try {
                        $this->indexBuilder->removeSecondaryIndex($fieldName, $oldValue, $recordId);
                    } catch (Throwable $e) {
                        error_log("Update: Fehler remove Sek.-Index '$fieldName', ID $recordId: " . $e->getMessage());
                    }
                }
                if ($newValueExists && (is_scalar($newValue) || $newValue === null)) {
                    try {
                        $this->indexBuilder->setSecondaryIndex($fieldName, $newValue, $recordId);
                    } catch (Throwable $e) {
                        error_log("Update: Fehler set Sek.-Index '$fieldName', ID $recordId: " . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * @param int $recordId
     * @return bool True if deleted, False if not found.
     * @throws InvalidArgumentException | RuntimeException | JsonException
     */
    public function deleteRecord(int $recordId): bool
    {
        if ($recordId <= 0)
            throw new InvalidArgumentException("Ungültige Record-ID: $recordId");

        $measurement = FlatFileDBStatistics::measurePerformance(function () use ($recordId) {
            $indexEntry = $this->indexBuilder->getIndexEntry($recordId);
            if ($indexEntry === null) {
                unset($this->dataCache[(string) $recordId], $this->cacheTimestamps[(string) $recordId]);
                return false;
            }
            $committed = false;
            $oldData = null;
            try {
                try {
                    $oldData = $this->fileManager->readRecordAtOffset($indexEntry['offset'], $indexEntry['length']);
                    if (empty($oldData['id']) || $oldData['id'] != $recordId || !empty($oldData['_deleted'])) {
                        error_log("Warnung: Delete ID $recordId (Offset {$indexEntry['offset']}) war inkonsistent/gelöscht.");
                        $oldData = null;
                    }
                } catch (Throwable $e) {
                    error_log("Delete: Fehler Lesen ID $recordId vor Löschen: " . $e->getMessage());
                    $oldData = null;
                }
                if ($oldData !== null) {
                    $tombstoneRecord = $oldData;
                    $tombstoneRecord['_deleted'] = true;
                    $tombstoneRecord['deleted_at'] = time();
                    $this->fileManager->appendRecord($tombstoneRecord);
                }
                $this->indexBuilder->removeIndex($recordId);
                $this->indexBuilder->removeAllSecondaryIndexesForRecord($recordId);
                $logData = $oldData ?? ['_deleted' => true, 'deleted_at' => time()];
                $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_DELETE, $recordId, $logData);
                $this->indexBuilder->commitIndex();
                $this->indexBuilder->commitAllSecondaryIndexes();
                $committed = true;
                unset($this->dataCache[(string) $recordId], $this->cacheTimestamps[(string) $recordId]);
                return true;
            } catch (Throwable $e) {
                error_log("Fehler während Delete ID $recordId: " . $e->getMessage() . " - Versuchter Rollback...");
                if (!$committed) {
                    try {
                        if ($indexEntry !== null && $this->indexBuilder->getIndexEntry($recordId) === null) {
                            $this->indexBuilder->setIndex($recordId, $indexEntry);
                            $this->indexBuilder->commitIndex();
                        }
                        error_log("Warnung: Sekundäre Indizes für ID $recordId könnten nach fehlgeschlagenem Delete inkonsistent sein.");
                        unset($this->dataCache[(string) $recordId], $this->cacheTimestamps[(string) $recordId]);
                    } catch (Throwable $rollbackError) {
                        error_log("KRITISCH: Fehler beim Rollback nach fehlgeschlagenem Delete ID $recordId: " . $rollbackError->getMessage());
                    }
                } else {
                    error_log("Fehler nach Commit bei Delete ID $recordId: " . $e->getMessage());
                }
                if ($e instanceof RuntimeException || $e instanceof InvalidArgumentException || $e instanceof JsonException)
                    throw $e;
                throw new RuntimeException("Fehler beim Löschen von Datensatz ID $recordId: " . $e->getMessage(), (int) $e->getCode(), $e);
            }
        });
        FlatFileDBStatistics::recordPerformance('DELETE', $measurement['duration']);
        return $measurement['result'];
    }

    /**
     * @param int $recordId
     * @return array|null
     * @throws InvalidArgumentException
     */
    public function selectRecord(int $recordId): ?array
    {
        if ($recordId <= 0)
            throw new InvalidArgumentException("Ungültige Record-ID: $recordId");
        $stringId = (string) $recordId;

        if (isset($this->dataCache[$stringId])) {
            $this->cacheTimestamps[$stringId] = microtime(true);
            if (!empty($this->dataCache[$stringId]['_deleted'])) {
                unset($this->dataCache[$stringId], $this->cacheTimestamps[$stringId]);
                return null;
            }
            FlatFileDBStatistics::recordPerformance('CACHE_HIT', 0.0);
            return $this->dataCache[$stringId];
        }
        FlatFileDBStatistics::recordPerformance('CACHE_MISS', 0.0);

        $indexEntry = $this->indexBuilder->getIndexEntry($recordId);
        if ($indexEntry === null)
            return null;

        try {
            $measurement = FlatFileDBStatistics::measurePerformance(fn() => $this->fileManager->readRecordAtOffset($indexEntry['offset'], $indexEntry['length']));
            $data = $measurement['result'];
            FlatFileDBStatistics::recordPerformance('READ_OFFSET', $measurement['duration']);

            if (empty($data['id']) || $data['id'] != $recordId || !empty($data['_deleted'])) {
                error_log("Inkonsistenz bei selectRecord ID $recordId (Offset {$indexEntry['offset']}). Korrigiere Index.");
                try {
                    $this->indexBuilder->removeIndex($recordId);
                    $this->indexBuilder->commitIndex();
                } catch (Throwable $e) {
                    error_log("Fehler Index-Korrektur ID $recordId: " . $e->getMessage());
                }
                return null;
            }
            $this->addToCache($stringId, $data);
            return $data;
        } catch (Throwable $e) {
            error_log("Fehler Lesen ID $recordId für Select: " . $e->getMessage());
            if ($e instanceof JsonException || str_contains($e->getMessage(), 'gzdecode') || str_contains($e->getMessage(), 'Dekomprimieren')) {
                error_log("Vermutlich korrupter Datenblock ID $recordId. Index-Eintrag wird entfernt.");
                try {
                    $this->indexBuilder->removeIndex($recordId);
                    $this->indexBuilder->commitIndex();
                } catch (Throwable $ignore) {
                }
            }
            return null;
        }
    }

    /** @return array<int, array> */
    public function selectAllRecords(): array
    {
        $results = [];
        foreach ($this->indexBuilder->getAllKeys() as $recordId) {
            try {
                $record = $this->selectRecord($recordId);
                if ($record !== null)
                    $results[$recordId] = $record;
            } catch (InvalidArgumentException $e) {
                error_log("Fehler (ungültige ID $recordId?) in selectAllRecords: " . $e->getMessage());
            } catch (Throwable $e) {
                error_log("Unerwarteter Fehler Lesen ID $recordId in selectAllRecords: " . $e->getMessage());
            }
        }
        return $results;
    }

    /**
     * KORRIGIERT: Sucht Datensätze.
     * @param array $whereConditions [['field'=>..., 'operator'=>..., 'value'=>...], ...]
     * @param int $limit
     * @param int $offset
     * @param int|null $id Optional: Suche nach ID (ignoriert $whereConditions).
     * @return list<array>
     * @throws InvalidArgumentException | RuntimeException
     */
    public function findRecords(array $whereConditions, int $limit = 0, int $offset = 0, ?int $id = null): array
    {
        if ($limit < 0)
            throw new InvalidArgumentException("Limit darf nicht negativ sein.");
        if ($offset < 0)
            throw new InvalidArgumentException("Offset darf nicht negativ sein.");
        if ($id !== null) {
            if ($id <= 0)
                return [];
            $record = $this->selectRecord($id);
            return ($record === null) ? [] : [$record];
        }
        foreach ($whereConditions as $index => $condition) {
            if (!is_array($condition) || !isset($condition['field']) || !is_string($condition['field']) || trim($condition['field']) === '' || !isset($condition['operator']) || !is_string($condition['operator']) || trim($condition['operator']) === '' || !array_key_exists('value', $condition)) {
                throw new InvalidArgumentException("Ungültige Bedingungsstruktur bei Index $index.");
            }
        }

        $usedIndex = false; // Flag for statistics
        $measurement = FlatFileDBStatistics::measurePerformance(function () use ($whereConditions, $limit, $offset, &$usedIndex) {
            $candidateIds = null;
            $bestIndexCondition = null;
            $smallestCandidateSetSize = PHP_INT_MAX;
            $managedFields = $this->indexBuilder->getManagedIndexedFields();

            foreach ($whereConditions as $condition) {
                $field = $condition['field'];
                $operator = trim(strtoupper($condition['operator']));
                $value = $condition['value'];
                if ($operator === '=' && in_array($field, $managedFields, true)) {
                    if (!is_scalar($value) && $value !== null)
                        continue;
                    try {
                        $idsFromIndex = $this->indexBuilder->getRecordIdsByFieldValue($field, $value);
                        $count = count($idsFromIndex);
                        if ($count < $smallestCandidateSetSize) {
                            $smallestCandidateSetSize = $count;
                            $candidateIds = $idsFromIndex;
                            $bestIndexCondition = $condition;
                            $usedIndex = true;
                        }
                        if ($count === 0)
                            return []; // Early exit
                    } catch (Throwable $e) {
                        error_log("Fehler Index-Suche '$field' Wert '" . (is_scalar($value) ? $value : get_debug_type($value)) . "': " . $e->getMessage() . " - Fallback auf Scan.");
                        $candidateIds = null;
                        $usedIndex = false;
                        break;
                    }
                }
            }
            $results = [];
            $count = 0;
            $skipped = 0;
            $sourceIds = $candidateIds ?? $this->indexBuilder->getAllKeys();
            if (empty($sourceIds))
                return [];

            foreach ($sourceIds as $recordId) {
                if (!is_int($recordId) || $recordId <= 0)
                    continue;
                $record = $this->selectRecord($recordId);
                if ($record === null)
                    continue;
                $conditionsToApply = ($usedIndex && $bestIndexCondition !== null) ? array_filter($whereConditions, fn($c) => $c !== $bestIndexCondition) : $whereConditions;
                if ($this->recordMatchesConditions($record, $conditionsToApply)) {
                    if ($offset > 0 && $skipped < $offset) {
                        $skipped++;
                        continue;
                    }
                    $results[] = $record;
                    $count++;
                    if ($limit > 0 && $count >= $limit)
                        break;
                }
            }
            return $results;
        });
        FlatFileDBStatistics::recordPerformance('FIND', $measurement['duration']);
        FlatFileDBStatistics::recordPerformance($usedIndex ? 'FIND_INDEX_USED' : 'FIND_SCAN', $measurement['duration']);
        return $measurement['result'];
    }

    /** @throws InvalidArgumentException */
    private function recordMatchesConditions(array $record, array $whereConditions): bool
    {
        if (empty($whereConditions))
            return true;
        foreach ($whereConditions as $condition) {
            $field = $condition['field'];
            $operator = trim(strtoupper($condition['operator']));
            $condValue = $condition['value'];
            $fieldExists = array_key_exists($field, $record);
            $recordValue = $fieldExists ? $record[$field] : null;
            $match = false;
            switch ($operator) {
                case '=':
                case '==':
                    $match = $condValue === null ? (!$fieldExists || $recordValue === null) : ($fieldExists && ($recordValue == $condValue));
                    break;
                case '!=':
                case '<>':
                    $match = $condValue === null ? ($fieldExists && $recordValue !== null) : (!$fieldExists || ($recordValue != $condValue));
                    break;
                case '>':
                    $match = $fieldExists && $recordValue > $condValue;
                    break;
                case '<':
                    $match = $fieldExists && $recordValue < $condValue;
                    break;
                case '>=':
                    $match = $fieldExists && $recordValue >= $condValue;
                    break;
                case '<=':
                    $match = $fieldExists && $recordValue <= $condValue;
                    break;
                case '===':
                    $match = $fieldExists && ($recordValue === $condValue);
                    break;
                case '!==':
                    $match = !$fieldExists || ($recordValue !== $condValue);
                    break;
                case 'IN':
                    if (!is_array($condValue))
                        throw new InvalidArgumentException("Wert für IN muss Array sein für '$field'.");
                    $match = $fieldExists && in_array($recordValue, $condValue, false);
                    break;
                case 'NOT IN':
                    if (!is_array($condValue))
                        throw new InvalidArgumentException("Wert für NOT IN muss Array sein für '$field'.");
                    $match = !$fieldExists || !in_array($recordValue, $condValue, false);
                    break;
                case 'LIKE':
                    if (!is_string($condValue))
                        throw new InvalidArgumentException("Wert für LIKE muss String sein für '$field'.");
                    if (!$fieldExists || !is_scalar($recordValue))
                        $match = false;
                    else {
                        $pattern = preg_quote($condValue, '/');
                        $pattern = str_replace(['%', '_'], ['.*', '.'], $pattern);
                        $match = (bool) preg_match('/^' . $pattern . '$/i', (string) $recordValue);
                    }
                    break;
                case 'NOT LIKE':
                    if (!is_string($condValue))
                        throw new InvalidArgumentException("Wert für NOT LIKE muss String sein für '$field'.");
                    if (!$fieldExists || !is_scalar($recordValue))
                        $match = true;
                    else {
                        $pattern = preg_quote($condValue, '/');
                        $pattern = str_replace(['%', '_'], ['.*', '.'], $pattern);
                        $match = !(bool) preg_match('/^' . $pattern . '$/i', (string) $recordValue);
                    }
                    break;
                case 'IS NULL':
                    $match = !$fieldExists || $recordValue === null;
                    break;
                case 'IS NOT NULL':
                    $match = $fieldExists && $recordValue !== null;
                    break;
                default:
                    throw new InvalidArgumentException("Nicht unterstützter Operator: '$operator' für Feld '$field'.");
            }
            if (!$match)
                return false;
        }
        return true;
    }

    /**
     * @param list<array> $records
     * @return array Results per index: `[index => recordId | ['error' => string]]`
     * @throws InvalidArgumentException
     */
    public function bulkInsertRecords(array $records): array
    {
        if (!array_is_list($records))
            throw new InvalidArgumentException("Eingabe muss Liste von Datensätzen sein.");
        if (empty($records))
            return [];

        $measurement = FlatFileDBStatistics::measurePerformance(function () use ($records) {
            $results = [];
            $processedRecords = [];
            $bulkErrors = [];
            foreach ($records as $index => $data) {
                if (!is_array($data)) {
                    $bulkErrors[$index] = ['error' => 'Datensatz ist kein Array.'];
                    continue;
                }
                try {
                    $recordId = $this->indexBuilder->getNextId();
                    $record = $data;
                    unset($record['id'], $record['_deleted'], $record['created_at'], $record['updated_at'], $record['deleted_at']);
                    $record['id'] = $recordId;
                    $record['created_at'] = time();
                    $record['updated_at'] = null;
                    $record['_deleted'] = false;
                    $record['deleted_at'] = null;
                    if (!empty($this->schema))
                        FlatFileValidator::validateData($record, $this->schema['requiredFields'] ?? [], $this->schema['fieldTypes'] ?? []);
                    $blockInfo = $this->fileManager->appendRecord($record);
                    $processedRecords[$index] = ['id' => $recordId, 'block' => $blockInfo, 'record' => $record];
                    $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_INSERT, $recordId, $record);
                    $results[$index] = $recordId;
                } catch (Throwable $e) {
                    $bulkErrors[$index] = ['error' => $e->getMessage()];
                }
            }
            $commitFailed = false;
            if (!empty($processedRecords)) {
                try {
                    foreach ($processedRecords as $proc) {
                        $this->indexBuilder->setIndex($proc['id'], $proc['block']);
                        $this->updateSecondaryIndexesOnInsert($proc['id'], $proc['record']);
                    }
                    $this->indexBuilder->commitIndex();
                    $this->indexBuilder->commitAllSecondaryIndexes();
                } catch (Throwable $e) {
                    $commitFailed = true;
                    $commitErrorMsg = "Index-Commit fehlgeschlagen nach Bulk-Insert: " . $e->getMessage();
                    error_log("KRITISCH: " . $commitErrorMsg);
                    foreach ($processedRecords as $originalIndex => $proc)
                        $bulkErrors[$originalIndex] = ['error' => $commitErrorMsg];
                }
            }
            if (!$commitFailed && !empty($processedRecords)) {
                foreach ($processedRecords as $proc)
                    $this->addToCache((string) $proc['id'], $proc['record']);
            }
            foreach ($bulkErrors as $idx => $errorInfo)
                $results[$idx] = $errorInfo;
            return $results;
        });
        FlatFileDBStatistics::recordPerformance('BULK_INSERT', $measurement['duration']);
        return $measurement['result'];
    }

    /**
     * @param list<array{recordId: int, newData: array}> $updates
     * @return array Results per index: `[index => bool|string|['error' => string]]`
     * @throws InvalidArgumentException
     */
    public function bulkUpdateRecords(array $updates): array
    {
        if (!array_is_list($updates))
            throw new InvalidArgumentException("Eingabe muss Liste von Update-Arrays sein.");
        if (empty($updates))
            return [];

        $results = [];
        $processedRecords = [];
        $bulkErrors = [];
        foreach ($updates as $index => $update) {
            if (!is_array($update) || !isset($update['recordId']) || !is_int($update['recordId']) || $update['recordId'] <= 0 || !isset($update['newData']) || !is_array($update['newData'])) {
                $bulkErrors[$index] = ['error' => "Ungültige Update-Struktur bei Index $index."];
                continue;
            }
            $recordId = $update['recordId'];
            $newData = $update['newData'];
            try {
                $oldIndexEntry = $this->indexBuilder->getIndexEntry($recordId);
                if ($oldIndexEntry === null) {
                    $results[$index] = false;
                    continue;
                }
                try {
                    $oldData = $this->fileManager->readRecordAtOffset($oldIndexEntry['offset'], $oldIndexEntry['length']);
                } catch (Throwable $e) {
                    error_log("BulkUpdate: Lesen ID $recordId Error: " . $e->getMessage());
                    try {
                        $this->indexBuilder->removeIndex($recordId);
                        $this->indexBuilder->commitIndex();
                    } catch (Throwable $ignore) {
                    }
                    unset($this->dataCache[(string) $recordId], $this->cacheTimestamps[(string) $recordId]);
                    $results[$index] = false;
                    continue;
                }
                if (empty($oldData['id']) || $oldData['id'] != $recordId || !empty($oldData['_deleted'])) {
                    error_log("BulkUpdate: Inkonsistenz ID $recordId.");
                    try {
                        $this->indexBuilder->removeIndex($recordId);
                        $this->indexBuilder->commitIndex();
                    } catch (Throwable $ignore) {
                    }
                    unset($this->dataCache[(string) $recordId], $this->cacheTimestamps[(string) $recordId]);
                    $results[$index] = false;
                    continue;
                }

                $updatedRecord = $newData;
                unset($updatedRecord['id'], $updatedRecord['_deleted'], $updatedRecord['created_at'], $updatedRecord['updated_at'], $updatedRecord['deleted_at']);
                $updatedRecord = array_merge($oldData, $updatedRecord);
                $updatedRecord['id'] = $recordId;
                $updatedRecord['created_at'] = $oldData['created_at'] ?? time();
                $updatedRecord['updated_at'] = time();
                $updatedRecord['_deleted'] = false;
                $updatedRecord['deleted_at'] = null;
                if (!empty($this->schema))
                    FlatFileValidator::validateData($updatedRecord, $this->schema['requiredFields'] ?? [], $this->schema['fieldTypes'] ?? []);
                $metaFields = ['id', 'created_at', 'updated_at', '_deleted', 'deleted_at'];
                $compareOld = array_diff_key($oldData, array_flip($metaFields));
                $compareNew = array_diff_key($updatedRecord, array_flip($metaFields));
                if ($compareOld == $compareNew) {
                    $results[$index] = 'no_change';
                    continue;
                }

                $newBlockInfo = $this->fileManager->appendRecord($updatedRecord);
                $processedRecords[$index] = ['recordId' => $recordId, 'oldIndex' => $oldIndexEntry, 'newBlock' => $newBlockInfo, 'oldData' => $oldData, 'newData' => $updatedRecord];
                $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_UPDATE, $recordId, $updatedRecord);
                $results[$index] = true;
            } catch (Throwable $e) {
                $bulkErrors[$index] = ['error' => "Update ID $recordId fehlgeschlagen: " . $e->getMessage()];
            }
        }
        $commitFailed = false;
        if (!empty($processedRecords)) {
            try {
                foreach ($processedRecords as $rec) {
                    $this->indexBuilder->setIndex($rec['recordId'], $rec['newBlock']);
                    $this->updateSecondaryIndexesOnUpdate($rec['recordId'], $rec['oldData'], $rec['newData']);
                }
                $this->indexBuilder->commitIndex();
                $this->indexBuilder->commitAllSecondaryIndexes();
            } catch (Throwable $e) {
                $commitFailed = true;
                $commitErrorMsg = "Index-Commit fehlgeschlagen nach Bulk-Update: " . $e->getMessage();
                error_log("KRITISCH: " . $commitErrorMsg);
                foreach ($processedRecords as $idx => $rec)
                    $bulkErrors[$idx] = ['error' => $commitErrorMsg];
            }
        }
        if (!$commitFailed && !empty($processedRecords)) {
            foreach ($processedRecords as $rec)
                $this->addToCache((string) $rec['recordId'], $rec['newData']);
        }
        foreach ($bulkErrors as $idx => $errorInfo)
            $results[$idx] = $errorInfo;
        return $results;
    }

    /**
     * @param list<int> $recordIds
     * @return array Results per index: `[index => bool|['error' => string]]`
     * @throws InvalidArgumentException
     */
    public function bulkDeleteRecords(array $recordIds): array
    {
        if (!array_is_list($recordIds))
            throw new InvalidArgumentException("Eingabe muss Liste von IDs sein.");
        if (empty($recordIds))
            return [];

        $results = [];
        $processedRecords = [];
        $bulkErrors = [];
        foreach ($recordIds as $index => $recordId) {
            if (!is_int($recordId) || $recordId <= 0) {
                $bulkErrors[$index] = ['error' => 'Ungültige Record-ID: ' . $recordId];
                continue;
            }
            try {
                $indexEntry = $this->indexBuilder->getIndexEntry($recordId);
                if ($indexEntry === null) {
                    $results[$index] = false;
                    unset($this->dataCache[(string) $recordId], $this->cacheTimestamps[(string) $recordId]);
                    continue;
                }
                $oldData = null;
                try {
                    $oldData = $this->fileManager->readRecordAtOffset($indexEntry['offset'], $indexEntry['length']);
                    if (empty($oldData['id']) || $oldData['id'] != $recordId || !empty($oldData['_deleted'])) {
                        error_log("[BulkDelete ID $recordId] Gelesener Datensatz inkonsistent/gelöscht.");
                        $oldData = null;
                    }
                } catch (Throwable $e) {
                    error_log("[BulkDelete ID $recordId] Fehler Lesen für Tombstone/Log: " . $e->getMessage());
                }
                if ($oldData !== null) {
                    $tombstone = $oldData;
                    $tombstone['_deleted'] = true;
                    $tombstone['deleted_at'] = time();
                    try {
                        $this->fileManager->appendRecord($tombstone);
                    } catch (Throwable $e) {
                        error_log("[BulkDelete ID $recordId] Fehler Schreiben Tombstone: " . $e->getMessage());
                    }
                }
                $processedRecords[$index] = ['recordId' => $recordId, 'oldDataForLog' => $oldData ?? ['_deleted' => true, 'deleted_at' => time()]];
                $this->transactionLog->writeLog(FlatFileDBConstants::LOG_ACTION_DELETE, $recordId, $processedRecords[$index]['oldDataForLog']);
                $results[$index] = true;
            } catch (Throwable $e) {
                $bulkErrors[$index] = ['error' => "Löschen ID $recordId fehlgeschlagen: " . $e->getMessage()];
            }
        }
        $commitFailed = false;
        if (!empty($processedRecords)) {
            try {
                foreach ($processedRecords as $rec) {
                    $this->indexBuilder->removeIndex($rec['recordId']);
                    $this->indexBuilder->removeAllSecondaryIndexesForRecord($rec['recordId']);
                }
                $this->indexBuilder->commitIndex();
                $this->indexBuilder->commitAllSecondaryIndexes();
            } catch (Throwable $e) {
                $commitFailed = true;
                $commitErrorMsg = "Index-Commit fehlgeschlagen nach Bulk-Delete: " . $e->getMessage();
                error_log("KRITISCH: " . $commitErrorMsg);
                foreach ($processedRecords as $idx => $rec)
                    $bulkErrors[$idx] = ['error' => $commitErrorMsg];
            }
        }
        if (!$commitFailed && !empty($processedRecords)) {
            foreach ($processedRecords as $rec)
                unset($this->dataCache[(string) $rec['recordId']], $this->cacheTimestamps[(string) $rec['recordId']]);
        }
        foreach ($bulkErrors as $idx => $errorInfo)
            $results[$idx] = $errorInfo;
        return $results;
    }


    /** @throws RuntimeException */
    public function compactTable(): void
    {
        $measurement = FlatFileDBStatistics::measurePerformance(function () {
            try {
                $this->commitIndex();
                $this->commitAllSecondaryIndexes();
                $newPrimaryIndex = $this->fileManager->compactData();
                $this->indexBuilder->updateIndex($newPrimaryIndex);
                $this->indexBuilder->commitIndex();

                $managedFields = $this->indexBuilder->getManagedIndexedFields();
                $secondaryIndexRebuildData = [];
                foreach ($newPrimaryIndex as $recordId => $indexEntry) {
                    try {
                        $record = $this->fileManager->readRecordAtOffset($indexEntry['offset'], $indexEntry['length']);
                        if (empty($record['id']) || $record['id'] != $recordId || !empty($record['_deleted'])) {
                            error_log("Warnung: Inkonsistenz ID $recordId nach Kompaktierung.");
                            continue;
                        }
                        foreach ($managedFields as $fieldName) {
                            if (array_key_exists($fieldName, $record)) {
                                $value = $record[$fieldName];
                                if (is_scalar($value) || $value === null) {
                                    if ($value === null)
                                        $stringValue = "";
                                    elseif (is_bool($value))
                                        $stringValue = $value ? "1" : "0";
                                    else
                                        $stringValue = (string) $value;
                                    if (!isset($secondaryIndexRebuildData[$fieldName][$stringValue]))
                                        $secondaryIndexRebuildData[$fieldName][$stringValue] = [];
                                    $secondaryIndexRebuildData[$fieldName][$stringValue][$recordId] = $recordId;
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        error_log("Fehler Lesen ID $recordId während Sek.Index-Rebuild nach Kompaktierung: " . $e->getMessage());
                    }
                }
                foreach ($managedFields as $fieldName) {
                    $fieldData = $secondaryIndexRebuildData[$fieldName] ?? [];
                    $finalFieldData = [];
                    foreach ($fieldData as $stringValue => $idMap)
                        $finalFieldData[$stringValue] = array_values($idMap);
                    try {
                        $this->indexBuilder->updateSecondaryIndex($fieldName, $finalFieldData);
                        $this->indexBuilder->commitSecondaryIndex($fieldName);
                    } catch (Throwable $e) {
                        error_log("Fehler Update/Commit Sek.Index '$fieldName' nach Kompaktierung: " . $e->getMessage());
                    }
                }
                $this->clearCache();
            } catch (Throwable $e) {
                if ($e instanceof RuntimeException)
                    throw $e;
                throw new RuntimeException("Fehler Tabellenkompaktierung {$this->config->getDataFile()}: " . $e->getMessage(), (int) $e->getCode(), $e);
            }
        });
        FlatFileDBStatistics::recordPerformance('COMPACT', $measurement['duration']);
    }

    private function addToCache(string $recordId, array $data): void
    {
        if ($this->maxCacheSize <= 0 || $recordId === '')
            return;
        $this->cacheTimestamps[$recordId] = microtime(true);
        $this->dataCache[$recordId] = $data;
        if (count($this->dataCache) > $this->maxCacheSize) {
            asort($this->cacheTimestamps);
            $oldestKey = key($this->cacheTimestamps);
            if ($oldestKey !== null)
                unset($this->dataCache[$oldestKey], $this->cacheTimestamps[$oldestKey]);
        }
    }

    /** @throws RuntimeException */
    public function commitIndex(): void
    {
        try {
            $this->indexBuilder->commitIndex();
        } catch (Throwable $e) {
            throw new RuntimeException("Fehler Speichern Primärindex: " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /** @throws RuntimeException */
    public function commitAllSecondaryIndexes(): void
    {
        try {
            $this->indexBuilder->commitAllSecondaryIndexes();
        } catch (Throwable $e) {
            error_log("Schwerwiegender Fehler Speichern Sekundärindizes: " . $e->getMessage());
            throw new RuntimeException("Schwerwiegender Fehler Speichern Sekundärindizes: " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public function clearCache(): void
    {
        $this->dataCache = [];
        $this->cacheTimestamps = [];
        FlatFileDBStatistics::recordPerformance('CACHE_CLEAR', 0.0);
    }

    /** @throws InvalidArgumentException */
    public function setCacheSize(int $size): void
    {
        if ($size < 0)
            throw new InvalidArgumentException("Cache-Größe muss >= 0 sein.");
        $this->maxCacheSize = $size;
        if ($this->maxCacheSize === 0)
            $this->clearCache();
        elseif (count($this->dataCache) > $this->maxCacheSize) {
            asort($this->cacheTimestamps);
            $toRemove = count($this->dataCache) - $this->maxCacheSize;
            $keysToRemove = array_slice(array_keys($this->cacheTimestamps), 0, $toRemove);
            foreach ($keysToRemove as $key)
                unset($this->dataCache[$key], $this->cacheTimestamps[$key]);
        }
    }

    /**
     * @param string $backupDir
     * @return array<string, string|array> Backup status report.
     * @throws RuntimeException
     */
    public function backup(string $backupDir): array
    {
        $backupFiles = [];
        $timestamp = date('YmdHis') . '_' . substr(bin2hex(random_bytes(8)), 0, 8);
        try {
            $this->commitIndex();
            $this->commitAllSecondaryIndexes();

            $copyFile = function (string $source, string $targetDir, string $ts) use ($timestamp): string {
                clearstatcache(true, $source);
                if (!file_exists($source) || !is_file($source))
                    return 'skipped (does not exist or not a file)';
                if (!is_readable($source))
                    throw new RuntimeException("Quelldatei '$source' nicht lesbar für Backup.");
                $target = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . basename($source) . '.bak.' . $timestamp; // Use consistent timestamp
                if (!@copy($source, $target)) {
                    $error = error_get_last();
                    $errorMsg = $error ? " ({$error['message']})" : "";
                    throw new RuntimeException("Backup von '$source' nach '$target' fehlgeschlagen{$errorMsg}.");
                }
                return $target;
            };
            $backupFiles['data'] = $copyFile($this->config->getDataFile(), $backupDir, $timestamp);
            $backupFiles['index'] = $copyFile($this->config->getIndexFile(), $backupDir, $timestamp);
            $backupFiles['secondary_indexes'] = [];
            foreach ($this->indexBuilder->getManagedIndexedFields() as $fieldName) {
                try {
                    $secIndexFile = $this->indexBuilder->getSecondaryIndexFilePath($fieldName);
                    $backupFiles['secondary_indexes'][$fieldName] = $copyFile($secIndexFile, $backupDir, $timestamp);
                } catch (Throwable $e) {
                    $errMsg = "Backup Error: " . $e->getMessage();
                    error_log("Backup Error Sek.Index '$fieldName': " . $e->getMessage());
                    $backupFiles['secondary_indexes'][$fieldName] = $errMsg;
                }
            }
            $backupFiles['log'] = $copyFile($this->config->getLogFile(), $backupDir, $timestamp);
        } catch (Throwable $e) {
            throw new RuntimeException("Backup Error Tabelle (Daten: {$this->config->getDataFile()}): " . $e->getMessage(), (int) $e->getCode(), $e);
        }
        return $backupFiles;
    }

    /** @throws RuntimeException */
// In Klasse: FlatFileDB\FlatFileTableEngine

    /**
     * Leert die Tabelle: Schließt Handles, löscht Daten-, Index- (primär und sekundär)
     * und Log-Dateien und setzt den internen Zustand zurück.
     *
     * MAXIMAL ROBUSTE VERSION MIT VERBESSERTEM DEBUGGING
     *
     * @throws RuntimeException Wenn beim Leeren Fehler auftreten.
     */
    public function clearTable(): void {
        $errors = []; // Sammelt Fehler
        $tableNameGuess = basename(dirname($this->config->getDataFile()));

        // --- 1. Handles schließen ---
        try {
            $this->fileManager->closeHandles();
        } catch (\Throwable $e) {
            // Logge als Warnung, fahre aber fort
            error_log("Warnung [ClearTable: $tableNameGuess]: Fehler beim Schließen der FileManager-Handles: " . $e->getMessage());
        }
        try {
             $this->indexBuilder->closeIdLockHandle();
        } catch (\Throwable $e) {
            // Logge als Warnung, fahre aber fort
            error_log("Warnung [ClearTable: $tableNameGuess]: Fehler beim Schließen des IndexBuilder ID-Locks: " . $e->getMessage());
        }

        // --- 2. Dateipfade sammeln ---
        $filesToDelete = [];
        try {
            $filesToDelete = [
                'data' => $this->config->getDataFile(),
                'index' => $this->config->getIndexFile(),
                'log' => $this->config->getLogFile(),
                'id_lock' => $this->indexBuilder->getIdLockFilePath(),
            ];

            $managedFields = $this->indexBuilder->getManagedIndexedFields();
            foreach ($managedFields as $fieldName) {
                try {
                    $secIndexPath = $this->indexBuilder->getSecondaryIndexFilePath($fieldName);
                     if(!empty($secIndexPath)) {
                         $filesToDelete['sec_index_' . $fieldName] = $secIndexPath;
                     }
                } catch (\Throwable $e) {
                    $errors['collect_sec_idx_path_' . $fieldName] = "Pfad für Sek.-Index '$fieldName' nicht ermittelbar: " . $e->getMessage();
                    error_log("Fehler [ClearTable: $tableNameGuess]: " . $errors['collect_sec_idx_path_' . $fieldName]);
                }
            }
        } catch (\Throwable $e) {
             $errors['collect_paths'] = 'Fehler beim Sammeln der Dateipfade: ' . $e->getMessage();
             error_log("Fehler [ClearTable: $tableNameGuess]: " . $errors['collect_paths']);
             // Weitermachen mit den bisher gesammelten Pfaden
        }

        // --- 3. Dateien löschen ---
        foreach ($filesToDelete as $key => $filePath) {
            if (empty($filePath) || !is_string($filePath)) continue;

            clearstatcache(true, $filePath);
            if (file_exists($filePath)) {
                // UNLINK OHNE @, um Berechtigungsfehler sichtbar zu machen!
                if (!unlink($filePath)) {
                    $error = error_get_last();
                    $osError = ($error && isset($error['message'])) ? $error['message'] : 'Unbekannter Systemfehler';
                    $errors[$key . '_unlink'] = "Löschen von '$filePath' fehlgeschlagen: $osError";
                    // Logge diesen Fehler immer, da er kritisch ist
                    error_log("Fehler [ClearTable: $tableNameGuess]: " . $errors[$key . '_unlink']);
                }
            }
        }

        // --- 4. Wichtige Dateien leer neu erstellen ---
        $filesToRecreate = [
            'data' => [$this->config->getDataFile(), ''],
            'index' => [$this->config->getIndexFile(), '{}'], // Leeres JSON Objekt
            'log' => [$this->config->getLogFile(), ''],
        ];
        foreach ($filesToRecreate as $key => [$filePath, $content]) {
             if (empty($filePath) || !is_string($filePath)) continue;

             // Verwende wieder @file_put_contents, um harmlose Warnungen zu unterdrücken,
             // aber prüfe das Ergebnis rigoros.
             if (@file_put_contents($filePath, $content, LOCK_EX) === false) {
                 $error = error_get_last();
                 $osError = ($error && isset($error['message'])) ? $error['message'] : 'Unbekannter Systemfehler';
                 // Füge Fehler hinzu oder aktualisiere bestehenden
                 $errors[$key . '_recreate'] = ($errors[$key . '_recreate'] ?? '') . " | Neuerstellen von '$filePath' fehlgeschlagen: $osError";
                 error_log("Fehler [ClearTable: $tableNameGuess]: " . ($errors[$key . '_recreate'] ?? 'Unbekannter Fehler beim Neuerstellen'));
             } else {
                 // Optional: Berechtigungen setzen (mit @, da weniger kritisch)
                 $perms = 0664; // Anpassen nach Bedarf
                 @chmod($filePath, $perms);
             }
        }

        // --- 5. Internen Zustand zurücksetzen (IMMER versuchen) ---
        try {
            // Primärindex im Speicher leeren
            $this->indexBuilder->updateIndex([]);
            // Sekundärindizes im Speicher leeren
            $this->indexBuilder->resetSecondaryIndexes();
            // Cache leeren
            $this->clearCache();

            // Versuche, den leeren Index zu speichern (Commit)
            try {
                $this->indexBuilder->commitIndex();
            } catch (\Throwable $e) {
                 // Fehler beim Commit des leeren Index ist kritisch
                 $errors['index_commit'] = "Speichern des leeren Primärindex fehlgeschlagen: " . $e->getMessage();
                 error_log("Kritischer Fehler [ClearTable: $tableNameGuess]: " . $errors['index_commit']);
            }

        } catch(\Throwable $e) {
             // Fehler beim internen Reset selbst
             $errors['internal_reset'] = 'Kritischer Fehler beim internen Zustand-Reset: ' . $e->getMessage();
             error_log("Kritischer Fehler [ClearTable: $tableNameGuess]: " . $errors['internal_reset']);
        }

        // --- 6. Fehler reporten, falls welche aufgetreten sind ---
        if (!empty($errors)) {
            $errorMessages = ["Fehler beim Leeren der Tabelle '$tableNameGuess':"];
            foreach ($errors as $key => $msg) {
                $errorMessages[] = "- [$key]: $msg";
            }
            // Werfe eine einzelne Exception mit allen gesammelten Fehlern
            throw new \RuntimeException(implode("\n", $errorMessages));
        }

        // Kein explizites Erfolgslogging mehr nötig in der Produktivversion
    }
    

    public function getRecordCount(): int
    {
        try {
            return $this->indexBuilder->count();
        } catch (Throwable $e) {
            error_log("Fehler Abrufen Datensatzanzahl {$this->config->getDataFile()}: " . $e->getMessage());
            return 0;
        }
    }
}