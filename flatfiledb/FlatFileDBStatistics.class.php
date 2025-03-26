<?php
declare(strict_types=1);

namespace FlatFileDB;

use RuntimeException; // Correct namespace (global)
use Throwable;

/**
 * Sammelt und liefert Statistik- und Performancedaten für die Datenbank.
 */
class FlatFileDBStatistics
{
    private FlatFileDatabase $database;

    /**
     * @var array<string, list<float>> Speichert Performance-Metriken pro Aktion. Format: ['AKTION' => [duration1, duration2, ...]]
     */
    private static array $performanceMetrics = [];


    public function __construct(FlatFileDatabase $database)
    {
        $this->database = $database;
    }

    /**
     * Ermittelt Statistikdaten für eine einzelne Tabelle.
     *
     * @param string $tableName Name der Tabelle
     * @return array{
     *     record_count: int,
     *     data_file_size: int,
     *     index_file_size: int,
     *     log_file_size: int,
     *     secondary_index_files: array<string, int|string>
     * } Statistikdaten (Anzahl Datensätze, Dateigrößen in Bytes)
     * @throws RuntimeException wenn die Tabelle nicht registriert ist oder Dateigrößen nicht gelesen werden können.
     */
    public function getTableStatistics(string $tableName): array
    {
        $tableEngine = $this->database->table($tableName); // Throws if not registered
        $config = $tableEngine->getConfig();
        $indexBuilder = $tableEngine->getIndexBuilder(); // Get IndexBuilder instance

        // Anzahl der Datensätze über den Index ermitteln
        $recordCount = $tableEngine->getRecordCount(); // Uses indexBuilder->count()

        // Dateigrößen abrufen (verwende clearstatcache für aktuelle Werte)
        clearstatcache(true); // Clear all cache for safety

        // Verwende Hilfsfunktion, um Dateigröße zu holen und Fehler zu behandeln
        $dataFileSize = $this->getFileSizeSafe($config->getDataFile());
        $indexFileSize = $this->getFileSizeSafe($config->getIndexFile());
        $logFileSize = $this->getFileSizeSafe($config->getLogFile());

        // Get secondary index file sizes
        $secondaryIndexFiles = [];
        foreach ($indexBuilder->getManagedIndexedFields() as $fieldName) {
            try {
                $filePath = $indexBuilder->getSecondaryIndexFilePath($fieldName);
                $secondaryIndexFiles[$fieldName] = $this->getFileSizeSafe($filePath);
            } catch (Throwable $e) {
                $secondaryIndexFiles[$fieldName] = 'Error: ' . $e->getMessage();
                error_log("Error getting size for secondary index '$fieldName' of table '$tableName': " . $e->getMessage());
            }
        }


        return [
            'record_count' => $recordCount,
            'data_file_size' => $dataFileSize,
            'index_file_size' => $indexFileSize,
            'log_file_size' => $logFileSize,
            'secondary_index_files' => $secondaryIndexFiles,
        ];
    }

    /**
     * Helper to get file size, returns 0 if file doesn't exist, throws on error.
     * @param string $filePath
     * @return int
     * @throws RuntimeException
     */
    private function getFileSizeSafe(string $filePath): int
    {
        if (!file_exists($filePath)) {
            return 0; // File doesn't exist, size is 0
        }
        if (!is_readable($filePath)) {
            // Optional: Throw an error if file exists but isn't readable?
            // error_log("Warning: File exists but is not readable: {$filePath}");
            // return 0; // Treat as 0 if unreadable? Or throw? Let's throw.
            throw new RuntimeException("Datei existiert, ist aber nicht lesbar: {$filePath}");
        }
        // Use error suppression and check result rigorously
        $size = @filesize($filePath);
        if ($size === false) {
            // filesize() failed, potentially due to permissions or file becoming inaccessible during check
            $error = error_get_last();
            $errorMsg = $error ? " (Error: {$error['message']})" : "";
            // It might be a directory, check that
            if (is_dir($filePath)) {
                throw new RuntimeException("Pfad ist ein Verzeichnis, keine Datei: {$filePath}");
            }
            throw new RuntimeException("Konnte Dateigröße nicht ermitteln für: {$filePath}{$errorMsg}");
        }
        return $size;
    }


    /**
     * Ermittelt Statistikdaten für alle registrierten Tabellen.
     *
     * @return array<string, array|array{'error': string}> Array mit Statistikdaten pro Tabelle.
     *                              Schlüssel ist der Tabellenname. Kann 'error' enthalten.
     */
    public function getOverallStatistics(): array
    {
        $stats = [];
        foreach ($this->database->getTableNames() as $tableName) {
            try {
                $stats[$tableName] = $this->getTableStatistics($tableName);
            } catch (Throwable $e) { // Catch any Throwable
                $errMsg = $e->getMessage();
                $stats[$tableName] = ['error' => $errMsg];
                error_log("Fehler beim Abrufen der Statistiken für Tabelle '$tableName': " . $errMsg);
            }
        }
        return $stats;
    }

    /**
     * Führt eine übergebene Operation aus und misst dabei die Ausführungszeit.
     *
     * @template T
     * @param callable(): T $operation Eine Funktion, deren Ausführung gemessen werden soll.
     * @return array{result: T, duration: float} Array mit dem Ergebnis der Operation und der benötigten Zeit (in Sekunden).
     * @throws Throwable Re-throws any exception from the operation.
     */
    public static function measurePerformance(callable $operation): array
    {
        $start = microtime(true);
        try {
            $result = $operation();
        } catch (Throwable $e) {
            // Log duration even on failure? Maybe not, as it's not a successful operation time.
            throw $e; // Re-throw the exception
        }
        $end = microtime(true);
        $duration = $end - $start;
        return [
            'result' => $result,
            'duration' => $duration,
        ];
    }

    /**
     * Speichert die Dauer einer Aktion.
     *
     * @param string $action Der Aktionsname (z.B. 'INSERT', 'UPDATE', 'FIND', etc.)
     * @param float $duration Dauer in Sekunden
     */
    public static function recordPerformance(string $action, float $duration): void
    {
        // Optional: Validate action name?
        $action = strtoupper(trim($action)); // Normalize action name
        if ($action === '')
            return; // Ignore empty actions

        if (!isset(self::$performanceMetrics[$action])) {
            self::$performanceMetrics[$action] = [];
        }
        // Ensure duration is non-negative
        self::$performanceMetrics[$action][] = max(0.0, $duration);
    }

    /**
     * Gibt die gesammelten Performance-Metriken zurück.
     *
     * @return array<string, list<float>> Array mit den Metriken pro Aktion
     */
    public static function getPerformanceMetrics(): array
    {
        return self::$performanceMetrics;
    }

    /**
     * Berechnet aggregierte Performance-Statistiken (Min, Max, Avg, Count, Total).
     *
     * @return array<string, array{count: int, total_duration: float, min: float|null, max: float|null, avg: float|null}>
     *         Aggregierte Statistiken pro Aktion. Min/Max/Avg can be null if count is 0.
     */
    public static function getAggregatedPerformanceMetrics(): array
    {
        $aggregated = [];
        foreach (self::$performanceMetrics as $action => $durations) {
            $count = count($durations);
            if ($count === 0) {
                $aggregated[$action] = [
                    'count' => 0,
                    'total_duration' => 0.0,
                    'min' => null,
                    'max' => null,
                    'avg' => null,
                ];
                continue;
            }
            $total = array_sum($durations);
            $aggregated[$action] = [
                'count' => $count,
                'total_duration' => $total,
                'min' => min($durations),
                'max' => max($durations),
                'avg' => $total / $count,
            ];
        }
        ksort($aggregated); // Sort by action name
        return $aggregated;
    }

    /**
     * Setzt die Performance-Metriken zurück.
     */
    public static function resetPerformanceMetrics(): void
    {
        self::$performanceMetrics = [];
    }
}