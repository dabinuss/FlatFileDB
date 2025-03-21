<?php
declare(strict_types=1);

namespace FlatFileDB;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Hauptklasse zur Verwaltung mehrerer Tabellen.
 */
class FlatFileDatabase
{
    private string $baseDir;
    /** @var array<string, FlatFileTableEngine> */
    private array $tables = [];
    private bool $autoCommitIndex;
    private string $logFile;
    
    /**
     * @param string $baseDir Basisverzeichnis für die Datenbankdateien (Standard: FlatFileDBConstants::DEFAULT_BASE_DIR)
     * @param bool $autoCommitIndex Ob der Index automatisch gespeichert werden soll
     */
    public function __construct(string $baseDir = FlatFileDBConstants::DEFAULT_BASE_DIR, bool $autoCommitIndex = false)
    {
        $this->baseDir = rtrim($baseDir, '/');
        $this->autoCommitIndex = $autoCommitIndex;
        $this->logFile = "{$this->baseDir}/database.log";
        
        if (!is_dir($this->baseDir) && !mkdir($this->baseDir, 0755, true)) {
            throw new RuntimeException("Datenbank-Verzeichnis '{$this->baseDir}' konnte nicht erstellt werden.");
        }
    }
    
    /**
     * Registriert eine Tabelle und erzeugt die zugehörige Engine.
     * 
     * @param string $tableName Name der Tabelle
     * @throws InvalidArgumentException wenn der Tabellenname ungültig ist
     */
    public function registerTable(string $tableName)
    {
        if (!FlatFileValidator::isValidId($tableName)) {
            throw new InvalidArgumentException("Tabellenname '$tableName' ist ungültig.");
        }
        
        $dataFile  = "{$this->baseDir}/{$tableName}_data.jsonl";
        $indexFile = "{$this->baseDir}/{$tableName}_index.json";
        $logFile   = "{$this->baseDir}/{$tableName}_log.jsonl";
        
        $config = new FlatFileConfig($dataFile, $indexFile, $logFile, $this->autoCommitIndex);
        $this->tables[$tableName] = new FlatFileTableEngine($config);
        return $this->tables[$tableName];
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
            throw new RuntimeException("Tabelle '$tableName' wurde nicht registriert.");
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
     * @param array $tableNames Liste der Tabellennamen
     */
    public function registerTables(array $tableNames): void
    {
        foreach ($tableNames as $table) {
            $this->registerTable($table);
        }
    }
    
    /**
     * Kommittiert alle Index-Dateien.
     */
    public function commitAllIndexes(): void
    {
        foreach ($this->tables as $engine) {
            $engine->commitIndex();
        }
    }
    
    /**
     * Kompaktiert alle Tabellen.
     * 
     * @return array Status der Kompaktierung für jede Tabelle
     */
    public function compactAllTables(): array
    {
        $results = [];
        
        foreach ($this->tables as $tableName => $engine) {
            try {
                $engine->compactTable();
                $results[$tableName] = true;
            } catch (Throwable $e) {
                $results[$tableName] = false;
            }
        }
        
        return $results;
    }
    
    /**
     * Leert alle Caches.
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
     * @return array Liste der Tabellennamen
     */
    public function getTableNames(): array
    {
        return array_keys($this->tables);
    }

    /**
     * Erstellt ein Backup aller Tabellen.
     *
     * @param string $backupDir Verzeichnis für die Sicherungen
     * @return array<string, array> Status der Backups für jede Tabelle
     */
    public function createBackup(string $backupDir): array
    {
        $results = [];

        foreach ($this->tables as $tableName => $engine) {
            try {
                $backupFiles = $engine->backup($backupDir);
                $results[$tableName] = $backupFiles;
            } catch (Throwable $e) {
                $results[$tableName] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }

    public function clearDatabase(): void
    {
        foreach ($this->tables as $tableName => $engine) {
            $engine->clearTable();
        }
    }
}