<?php
declare(strict_types=1);

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Autoload oder require_once für alle benötigten Klassen
$requiredFiles = [
    'FlatFileConfig.class.php',
    'FlatFileDatabase.class.php',
    'FlatFileDBConstants.class.php',
    'FlatFileFileManager.class.php',
    'FlatFileIndexBuilder.class.php',
    'FlatFileTableEngine.class.php',
    'FlatFileTransactionLog.class.php',
    'FlatFileValidator.class.php',
    'FlatFileDBStatistics.class.php',
];

foreach ($requiredFiles as $file) {
    $filePath = __DIR__ . '/../flatfiledb/' . $file;
    if (!file_exists($filePath)) {
        die("Fehler: Datei $file konnte nicht gefunden werden.");
    }
    require_once $filePath;
}

// --- Funktionen zum Laden und Speichern der Performance-Historie ---

function loadPerformanceHistory(): array {
    // Statt die gesamte Historie zu laden, gib nur die letzten 10 Ausführungen zurück
    $file = __DIR__ . '/performance_history.csv';
    $history = [];
    
    if (file_exists($file)) {
        $handle = fopen($file, 'r');
        if ($handle) {
            // Lies die letzten 10 Zeilen
            $lines = [];
            while (($line = fgets($handle)) !== false) {
                $lines[] = $line;
                if (count($lines) > 10) {
                    array_shift($lines);
                }
            }
            fclose($handle);
            
            foreach ($lines as $line) {
                $parts = str_getcsv($line);
                if (count($parts) >= 3) {
                    $timestamp = $parts[0];
                    $action = $parts[1];
                    $duration = (float)$parts[2];
                    
                    if (!isset($history[$timestamp])) {
                        $history[$timestamp] = ['timestamp' => $timestamp];
                    }
                    
                    if (!isset($history[$timestamp][$action])) {
                        $history[$timestamp][$action] = [];
                    }
                    
                    $history[$timestamp][$action][] = $duration;
                }
            }
        }
    }
    
    return array_values($history);
}

function savePerformanceHistory(array $newRecord): bool {
    $file = __DIR__ . '/performance_history.csv';
    $timestamp = date('Y-m-d H:i:s');
    
    $handle = fopen($file, 'a');
    if (!$handle) {
        return false;
    }
    
    $success = true;
    foreach ($newRecord as $action => $durations) {
        if ($action === 'timestamp') continue;
        
        foreach ($durations as $duration) {
            $line = [$timestamp, $action, $duration];
            if (fputcsv($handle, $line) === false) {
                $success = false;
            }
        }
    }
    
    fclose($handle);
    return $success;
}

// --- Datenbankpfad sicherstellen ---
$dataPath = __DIR__ . '/data';
if (!is_dir($dataPath)) {
    if (!mkdir($dataPath, 0755, true)) {
        die("Fehler: Datenverzeichnis konnte nicht erstellt werden.");
    }
}

// --- Datenbankinstanz erstellen ---
try {
    $db = new \FlatFileDB\FlatFileDatabase($dataPath);
} catch (\Exception $e) {
    die("Fehler beim Initialisieren der Datenbank: " . $e->getMessage());
}

$resultMessage = '';
$dummyResults = [];
$performanceLog = [];

// Option: Einzelmesswerte anzeigen?
$showDetails = isset($_POST['showDetails']) && $_POST['showDetails'] === '1';

// Parameter aus Formular mit Validierung
$action = isset($_POST['action']) ? htmlspecialchars(trim($_POST['action']), ENT_QUOTES, 'UTF-8') : null;
$count = filter_input(INPUT_POST, 'count', FILTER_VALIDATE_INT, ['options' => ['default' => 100, 'min_range' => 1]]);
$numTables = filter_input(INPUT_POST, 'numTables', FILTER_VALIDATE_INT, ['options' => ['default' => 5, 'min_range' => 1]]);
$selectedTable = isset($_POST['table']) ? htmlspecialchars(trim($_POST['table']), ENT_QUOTES, 'UTF-8') : 'performance';

// Hilfsfunktion: Stelle sicher, dass die gewünschte Tabelle existiert und registriere sie ggf.
function getTableByName(\FlatFileDB\FlatFileDatabase $db, string $tableName): \FlatFileDB\FlatFileTableEngine {
    if (!$db->hasTable($tableName)) {
        $engine = $db->registerTable($tableName);
        // Schema setzen – passe es bei Bedarf an
        $engine->setSchema(['name', 'value'], ['name' => 'string', 'value' => 'int']);
        return $engine;
    }
    return $db->table($tableName);
}

// --- Zusätzliche Funktion: Parallele Zugriffe simulieren ---
function parallelTest(int $count): float {
    $urls = [];
    // Verwende Hostname aus aktueller Anfrage für korrekten Test-URL
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    $testUrl = "$protocol://$host$scriptPath/" . basename($_SERVER['SCRIPT_NAME']) . "?test=1";
    
    for ($i = 0; $i < $count; $i++) {
        $urls[] = $testUrl;
    }
    
    $mh = curl_multi_init();
    if ($mh === false) {
        throw new \RuntimeException("Curl Multi konnte nicht initialisiert werden");
    }
    
    $curlArr = [];
    foreach ($urls as $i => $url) {
        $curlArr[$i] = curl_init($url);
        if ($curlArr[$i] === false) {
            continue;
        }
        curl_setopt($curlArr[$i], CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlArr[$i], CURLOPT_TIMEOUT, 30);
        curl_multi_add_handle($mh, $curlArr[$i]);
    }
    
    $start = microtime(true);
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($status > 0) {
            break; // Bei Fehler abbrechen
        }
        if (curl_multi_select($mh, 0.1) === -1) {
            usleep(100);
        }
    } while ($running > 0);
    
    $end = microtime(true);
    foreach ($curlArr as $ch) {
        if ($ch) {
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
    }
    curl_multi_close($mh);
    return $end - $start;
}

// --- Zusätzliche Funktion: I/O-Messung ---
function ioTest(int $iterations): float {
    $file = __DIR__ . '/io_test.tmp';
    $data = str_repeat("0123456789", 1000); // ca. 10 KB pro Iteration
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        if (file_put_contents($file, $data) === false) {
            throw new \RuntimeException("Konnte Testdatei nicht schreiben");
        }
        $dummy = file_get_contents($file);
        if ($dummy === false) {
            throw new \RuntimeException("Konnte Testdatei nicht lesen");
        }
    }
    $end = microtime(true);
    if (file_exists($file)) {
        unlink($file);
    }
    return $end - $start;
}

// Behandle Test-Anfragen sofort
if (isset($_GET['test']) && $_GET['test'] === '1') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'timestamp' => time()]);
    exit;
}

// --- Aktion ausführen, falls ein Formular abgeschickt wurde ---
if ($action) {
    try {
        \FlatFileDB\FlatFileDBStatistics::resetPerformanceMetrics();
        
        // Falls die Aktion nicht "parallel_test", "io_test", "clear_database", "create_tables" oder "bulk_search" ist, arbeite mit einer Ziel-Tabelle
        if (!in_array($action, ['parallel_test', 'io_test', 'clear_database', 'create_tables'])) {
            $tableEngine = getTableByName($db, $selectedTable);
        }
        
        switch ($action) {
            case 'bulk_insert':
                $measurement = \FlatFileDB\FlatFileDBStatistics::measurePerformance(function() use ($tableEngine, $count) {
                    $dummyRecords = [];
                    for ($i = 0; $i < $count; $i++) {
                        $dummyRecords[] = [
                            'name'  => 'Dummy ' . mt_rand(1, 1000000),
                            'value' => mt_rand(1, 1000)
                        ];
                    }
                    return $tableEngine->bulkInsertRecords($dummyRecords);
                });
                \FlatFileDB\FlatFileDBStatistics::recordPerformance('BULK_INSERT', $measurement['duration']);
                $resultMessage = "Bulk Insert: " . count($measurement['result']) . " Datensätze in Tabelle '$selectedTable' eingefügt.";
                break;
                
            case 'bulk_update':
                $records = $tableEngine->selectAllRecords();
                $updates = [];
                $i = 0;
                foreach ($records as $record) {
                    if ($i >= $count) break;
                    $updates[] = [
                        'recordId' => $record['id'],
                        'newData'  => [
                            'name'  => $record['name'],
                            'value' => mt_rand(1, 1000)
                        ]
                    ];
                    $i++;
                }
                if (empty($updates)) {
                    $resultMessage = "Keine Datensätze zum Aktualisieren gefunden.";
                } else {
                    $measurement = \FlatFileDB\FlatFileDBStatistics::measurePerformance(function() use ($tableEngine, $updates) {
                        return $tableEngine->bulkUpdateRecords($updates);
                    });
                    \FlatFileDB\FlatFileDBStatistics::recordPerformance('BULK_UPDATE', $measurement['duration']);
                    $resultMessage = "Bulk Update: " . count($updates) . " Datensätze in Tabelle '$selectedTable' aktualisiert.";
                }
                break;
                
            case 'bulk_delete':
                $records = $tableEngine->selectAllRecords();
                $deleteIds = [];
                $i = 0;
                foreach ($records as $record) {
                    if ($i >= $count) break;
                    $deleteIds[] = $record['id'];
                    $i++;
                }
                if (empty($deleteIds)) {
                    $resultMessage = "Keine Datensätze zum Löschen gefunden.";
                } else {
                    $measurement = \FlatFileDB\FlatFileDBStatistics::measurePerformance(function() use ($tableEngine, $deleteIds) {
                        return $tableEngine->bulkDeleteRecords($deleteIds);
                    });
                    \FlatFileDB\FlatFileDBStatistics::recordPerformance('BULK_DELETE', $measurement['duration']);
                    $resultMessage = "Bulk Delete: " . count($deleteIds) . " Datensätze in Tabelle '$selectedTable' gelöscht.";
                }
                break;
                
            case 'compact_table':
                $start = microtime(true);
                $tableEngine->compactTable();
                $duration = microtime(true) - $start;
                \FlatFileDB\FlatFileDBStatistics::recordPerformance('COMPACT_TABLE', $duration);
                $resultMessage = "Tabelle '$selectedTable' in " . round($duration, 6) . " Sekunden kompaktiert.";
                break;
                
            case 'clear_database':
                $start = microtime(true);
                $db->clearDatabase();
                $duration = microtime(true) - $start;
                \FlatFileDB\FlatFileDBStatistics::recordPerformance('CLEAR_DATABASE', $duration);
                $resultMessage = "Gesamte Datenbank in " . round($duration, 6) . " Sekunden geleert.";
                break;
                
            case 'create_tables':
                $tableNames = [];
                $start = microtime(true);
                for ($i = 1; $i <= $numTables; $i++) {
                    $tableName = "performance_{$i}";
                    $tableNames[] = $tableName;
                    try {
                        $engine = getTableByName($db, $tableName);
                        $dummyRecords = [];
                        for ($j = 0; $j < $count; $j++) {
                            $dummyRecords[] = [
                                'name'  => 'Dummy ' . mt_rand(1, 1000000),
                                'value' => mt_rand(1, 1000)
                            ];
                        }
                        $engine->bulkInsertRecords($dummyRecords);
                    } catch (\Throwable $e) {
                        $resultMessage .= "Fehler bei Tabelle {$tableName}: " . $e->getMessage() . "<br>";
                    }
                }
                $duration = microtime(true) - $start;
                \FlatFileDB\FlatFileDBStatistics::recordPerformance('CREATE_TABLES', $duration);
                $resultMessage .= count($tableNames) . " Tabellen erstellt und jeweils " . $count . " Datensätze in " . round($duration, 6) . " Sekunden eingefügt.";
                break;
                
            case 'parallel_test':
                try {
                    $measurementDuration = parallelTest($count);
                    \FlatFileDB\FlatFileDBStatistics::recordPerformance('PARALLEL_TEST', $measurementDuration);
                    $resultMessage = "Parallel Test: $count Anfragen in " . round($measurementDuration, 6) . " Sekunden abgearbeitet.";
                } catch (\Throwable $e) {
                    $resultMessage = "Fehler beim parallelen Test: " . $e->getMessage();
                }
                break;
                
            case 'io_test':
                try {
                    $measurementDuration = ioTest($count);
                    \FlatFileDB\FlatFileDBStatistics::recordPerformance('IO_TEST', $measurementDuration);
                    $resultMessage = "I/O Test: $count Iterationen in " . round($measurementDuration, 6) . " Sekunden durchgeführt.";
                } catch (\Throwable $e) {
                    $resultMessage = "Fehler beim I/O-Test: " . $e->getMessage();
                }
                break;
                
            case 'bulk_search':
                $tableEngine = getTableByName($db, $selectedTable); // Hier wird die Tabelle explizit geladen
                // Definiere eine Suchbedingung, die alle Datensätze findet (z.B. value > 0)
                $conditions = [
                    ['field' => 'value', 'operator' => '>', 'value' => 0]
                ];
                // Führe die Suche $count-mal durch, um die Performance zu messen
                $measurement = \FlatFileDB\FlatFileDBStatistics::measurePerformance(function() use ($tableEngine, $conditions, $count) {
                    $results = [];
                    for ($i = 0; $i < $count; $i++) {
                        $results[] = $tableEngine->findRecords($conditions);
                    }
                    return $results;
                });
                \FlatFileDB\FlatFileDBStatistics::recordPerformance('BULK_SEARCH', $measurement['duration']);
                $resultMessage = "Bulk Search: $count Suchen in Tabelle '$selectedTable' durchgeführt. Durchschnittliche Zeit: " . round($measurement['duration'] / $count, 6) . " Sekunden pro Suche.";
                break;
                
            default:
                $resultMessage = "Unbekannte Aktion: " . htmlspecialchars($action);
                break;
        }
        
        // Aktuelle Performance-Metriken abrufen und in der Historie speichern
        $currentMetrics = \FlatFileDB\FlatFileDBStatistics::getPerformanceMetrics();
        if (!empty($currentMetrics)) {
            savePerformanceHistory($currentMetrics);
        }
        
        // Gesamten Verlauf laden
        $performanceLog = loadPerformanceHistory();
        
    } catch (\Throwable $e) {
        $resultMessage = "Fehler bei der Ausführung: " . $e->getMessage();
    }
}

// Ermittle alle bereits registrierten Tabellen für die Dropdown-Auswahl
$availableTables = $db->getTableNames();
// Falls die Standardtabelle "performance" noch nicht existiert, füge sie als Option hinzu
if (!in_array('performance', $availableTables, true)) {
    $availableTables[] = 'performance';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FlatFileDB Benchmark</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            line-height: 1.5;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        form { 
            margin-bottom: 20px; 
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
        }
        .form-group {
            margin-bottom: 10px;
        }
        label { 
            display: inline-block; 
            width: 300px; 
            font-weight: bold;
        }
        input, select, button {
            padding: 5px 10px;
            border-radius: 3px;
            border: 1px solid #ccc;
        }
        button {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 10px 15px;
            cursor: pointer;
            font-size: 14px;
        }
        button:hover {
            background-color: #45a049;
        }
        table { 
            border-collapse: collapse; 
            margin-top: 20px; 
            width: 100%;
        }
        table, th, td { 
            border: 1px solid #ccc; 
            padding: 8px; 
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .result {
            background-color: #e9ffe9;
            border-left: 4px solid #4CAF50;
            padding: 10px;
            margin: 15px 0;
        }
        .error {
            background-color: #ffe9e9;
            border-left: 4px solid #F44336;
            padding: 10px;
            margin: 15px 0;
        }
        @media (max-width: 768px) {
            label {
                width: 100%;
                margin-bottom: 5px;
            }
            input, select {
                width: 100%;
                box-sizing: border-box;
            }
        }
    </style>
</head>
<body>
    <h1>FlatFileDB Benchmark</h1>
    <form method="post">
        <div class="form-group">
            <label for="table">Tabelle auswählen:</label>
            <select name="table" id="table">
                <?php foreach ($availableTables as $table): ?>
                    <option value="<?php echo htmlspecialchars($table); ?>" <?php if ($selectedTable === $table) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($table); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label for="action">Aktion auswählen:</label>
            <select name="action" id="action">
                <option value="bulk_insert">Bulk Insert (Dummy-Daten in ausgewählte Tabelle)</option>
                <option value="bulk_update">Bulk Update (in ausgewählter Tabelle)</option>
                <option value="bulk_delete">Bulk Delete (in ausgewählter Tabelle)</option>
                <option value="compact_table">Tabelle kompaktieren (ausgewählte Tabelle)</option>
                <option value="clear_database">Datenbank leeren</option>
                <option value="create_tables">Mehrere Tabellen erstellen und befüllen</option>
                <option value="parallel_test">Parallel Test (simulierte parallele Zugriffe)</option>
                <option value="io_test">I/O Test (Datei-Lese/Schreib-Operationen)</option>
                <option value="bulk_search">Bulk Search (Mehrfachsuche)</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="count">Anzahl (für Insert/Update/Delete / Test-Anfragen, Iterationen oder Suchen):</label>
            <input type="number" name="count" id="count" value="<?php echo $count; ?>" min="1">
        </div>
        
        <div class="form-group">
            <label for="numTables">Anzahl Tabellen (nur bei "Mehrere Tabellen erstellen"):</label>
            <input type="number" name="numTables" id="numTables" value="<?php echo $numTables; ?>" min="1">
        </div>
        
        <div class="form-group">
            <label for="showDetails">Einzelwerte anzeigen?</label>
            <input type="checkbox" name="showDetails" id="showDetails" value="1" <?php if($showDetails) echo 'checked'; ?>>
        </div>
        
        <button type="submit">Aktion ausführen</button>
    </form>

    <?php if ($resultMessage): ?>
        <div class="<?php echo strpos($resultMessage, 'Fehler') !== false ? 'error' : 'result'; ?>">
            <h2>Ergebnis:</h2>
            <p><?php echo $resultMessage; ?></p>
            <?php if (!empty($dummyResults)): ?>
                <pre><?php print_r($dummyResults); ?></pre>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php
    // Aktuelle Performance: Durchschnittswerte anzeigen (Einzelwerte optional)
    $currentMetrics = \FlatFileDB\FlatFileDBStatistics::getPerformanceMetrics();
    if (!empty($currentMetrics)):
    ?>
        <h2>Aktuelle Performance (Durchschnittswerte)</h2>
        <ul>
            <?php foreach ($currentMetrics as $actionName => $durations): 
                $avg = array_sum($durations) / count($durations);
            ?>
                <li>
                    <strong><?php echo htmlspecialchars($actionName); ?>:</strong>
                    Durchschnitt: <?php echo round($avg, 6); ?> Sekunden
                    <?php if ($showDetails): ?>
                        (Einzelwerte: <?php echo implode(', ', array_map(function($d) { return round($d, 6); }, $durations)); ?>)
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if (!empty($performanceLog)): ?>
        <h2>Historie der Performance-Messungen</h2>
        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Aktion</th>
                    <th>Durchschnitt (s)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Jeder Eintrag enthält einen Zeitstempel und für jede Aktion eine Liste von Messwerten
                foreach ($performanceLog as $entry):
                    $timestamp = $entry['timestamp'] ?? 'n/a';
                    foreach ($entry as $key => $data):
                        if ($key === 'timestamp') continue;
                        if (!is_array($data) || empty($data)) continue;
                        $avg = array_sum($data) / count($data);
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($timestamp); ?></td>
                        <td><?php echo htmlspecialchars($key); ?></td>
                        <td><?php echo round($avg, 6); ?></td>
                    </tr>
                <?php 
                    endforeach;
                endforeach; 
                ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>Aktueller Datenbankstatus</h2>
    <?php
    $tableNames = $db->getTableNames();
    if (empty($tableNames)):
    ?>
        <p>Keine Tabellen vorhanden.</p>
    <?php else: ?>
        <ul>
        <?php
        foreach ($tableNames as $tableName) {
            try {
                $stats = (new \FlatFileDB\FlatFileDBStatistics($db))->getTableStatistics($tableName);
                echo "<li><strong>" . htmlspecialchars($tableName) . ":</strong> " .
                     "Datensätze: " . $stats['record_count'] . ", " .
                     "Daten-Dateigröße: " . $stats['data_file_size'] . " Bytes, " .
                     "Index-Dateigröße: " . $stats['index_file_size'] . " Bytes, " .
                     "Log-Dateigröße: " . $stats['log_file_size'] . " Bytes" .
                     "</li>";
            } catch (\Throwable $e) {
                echo "<li><strong>" . htmlspecialchars($tableName) . ":</strong> Fehler beim Abruf der Statistik: " . htmlspecialchars($e->getMessage()) . "</li>";
            }
        }
        ?>
        </ul>
    <?php endif; ?>
</body>
</html>
