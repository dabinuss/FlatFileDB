<?php
declare(strict_types=1);

// --- Error Reporting ---
// In der Entwicklung alle Fehler anzeigen; in Produktion bitte anders konfigurieren.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// --- Autoload / require_once ---
// Basisverzeichnis der Bibliothek relativ zum Skript
$baseDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'flatfiledb';

// Liste der erforderlichen Dateien (hier als Beispiel, Dateiendungen können variieren)
$requiredFiles = [
    'FlatFileDBConstants.class.php',       // Constants
    'FlatFileConfig.class.php',              // Configuration
    'FlatFileValidator.class.php',           // Validation class
    'FlatFileDatabase.class.php',            // Database base class
    'FlatFileFileManager.class.php',         // File manager
    'FlatFileIndexBuilder.class.php',        // Index building and management
    'FlatFileTransactionLog.class.php',      // Transaction log
    'FlatFileDBStatistics.class.php',        // Performance statistics
    'FlatFileTableEngine.class.php',         // Table engine (core class)
];

foreach ($requiredFiles as $file) {
    $filePath = $baseDir . DIRECTORY_SEPARATOR . $file;
    if (!file_exists($filePath)) {
        trigger_error("Kritischer Fehler: Datei $file unter $filePath konnte nicht gefunden oder gelesen werden.", E_USER_ERROR);
    }
    require_once $filePath;
}

// --- Performance History Functions (CSV) ---
/**
 * Lädt die Performance-Historie (bis zu $maxEntries) aus der CSV-Datei.
 *
 * @param int $maxEntries Maximum an Einträgen.
 * @return list<array{timestamp: string, action: string, duration: float}>
 */
function loadPerformanceHistory(int $maxEntries = 50): array {
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'performance_history.csv';
    $history = [];
    if (!file_exists($file)) {
        return [];
    }
    if (!is_readable($file)) {
        error_log("Performance history file exists but is not readable: $file");
        return [];
    }
    try {
        $fileObject = new SplFileObject($file, 'r');
        $fileObject->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $lines = [];
        foreach ($fileObject as $row) {
            if (is_array($row) && count($row) >= 3) {
                $duration = filter_var($row[2] ?? null, FILTER_VALIDATE_FLOAT);
                if ($duration !== false) {
                    $lines[] = [
                        'timestamp' => (string)($row[0] ?? ''),
                        'action'    => (string)($row[1] ?? ''),
                        'duration'  => $duration
                    ];
                }
            }
        }
        $fileObject = null;
        if (count($lines) > $maxEntries) {
            $history = array_slice($lines, -$maxEntries);
        } else {
            $history = $lines;
        }
    } catch (Throwable $e) {
        error_log("Error reading performance history file '$file': " . $e->getMessage());
        return [];
    }
    return $history;
}

/**
 * Speichert die aktuellen Performance-Metriken in der CSV-Historie.
 *
 * @param array<string, list<float>> $currentMetrics Raw Performance-Metriken.
 * @return bool True bei Erfolg, false bei Fehler.
 */
function savePerformanceHistory(array $currentMetrics): bool {
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'performance_history.csv';
    $timestamp = date('Y-m-d\TH:i:s.uP');
    $handle = fopen($file, 'a');
    if (!$handle) {
        error_log("Could not open performance history file for appending: $file");
        return false;
    }
    $success = true;
    if (flock($handle, LOCK_EX)) {
        try {
            foreach ($currentMetrics as $action => $durations) {
                foreach ($durations as $duration) {
                    $line = [$timestamp, (string)$action, (float)$duration];
                    if (fputcsv($handle, $line) === false) {
                        $success = false;
                        error_log("Failed to write performance data to CSV for action '$action'.");
                    }
                }
            }
        } finally {
            fflush($handle);
            flock($handle, LOCK_UN);
        }
    } else {
        error_log("Could not lock performance history file for writing: $file");
        $success = false;
    }
    fclose($handle);
    return $success;
}

/**
 * Gibt eine Bewertung anhand eines Kriteriums und des gemessenen Werts zurück.
 *
 * @param string $criterion Internes Kriterium.
 * @param float $value Gemessener Wert.
 * @return string Bewertung (Worst bis Outstanding).
 */
function getRating(string $criterion, float $value): string {
    // Neue Schwellenwerte basierend auf Vergleichsdaten von Systemen wie SQLite/MongoDB:
    $thresholds = [
        'bulk_insert' => [1000, 4000, 8000, 12000, 16000],
        'bulk_update' => [800, 3000, 6000, 9000, 12000],
        'bulk_delete' => [500, 2000, 4000, 6000, 8000],
        'io'          => [100, 150, 200, 250, 300],
        'search'      => [40, 30, 20, 10, 5],
        'parallel'    => [50, 40, 30, 20, 10],
        'stability'   => [0.98, 0.99, 0.995, 0.997, 0.999],
        'compact_table' => [3.0, 2.5, 2.0, 1.5, 1.0], // Für die Kompaktierung: ≤ 1s ist outstanding
    ];
    $levels = ['Worst', 'Poor', 'Fair', 'Good', 'Very Good', 'Outstanding'];
    if (!isset($thresholds[$criterion])) {
        error_log("Unknown criterion '$criterion' passed to getRating.");
        return "N/A";
    }
    $t = $thresholds[$criterion];
    // Für search, parallel und compact_table: kleinere Werte sind besser
    if (in_array($criterion, ['search', 'parallel', 'compact_table'])) {
        if ($value <= $t[4]) return $levels[5];
        if ($value <= $t[3]) return $levels[4];
        if ($value <= $t[2]) return $levels[3];
        if ($value <= $t[1]) return $levels[2];
        if ($value <= $t[0]) return $levels[1];
        return $levels[0];
    } else {
        if ($value >= $t[4]) return $levels[5];
        if ($value >= $t[3]) return $levels[4];
        if ($value >= $t[2]) return $levels[3];
        if ($value >= $t[1]) return $levels[2];
        if ($value >= $t[0]) return $levels[1];
        return $levels[0];
    }
}

// Angepasste Scale-Texts für die Vergleichstabelle:
$scaleTexts = [
    'bulk_insert' => [
        'Worst' => "< 1k", 'Poor' => "1k–4k", 'Fair' => "4k–8k",
        'Good' => "8k–12k", 'Very Good' => "12k–16k", 'Outstanding' => "≥ 16k",
    ],
    'bulk_update' => [
        'Worst' => "< 0.8k", 'Poor' => "0.8k–3k", 'Fair' => "3k–6k",
        'Good' => "6k–9k", 'Very Good' => "9k–12k", 'Outstanding' => "≥ 12k",
    ],
    'bulk_delete' => [
        'Worst' => "< 0.5k", 'Poor' => "0.5k–2k", 'Fair' => "2k–4k",
        'Good' => "4k–6k", 'Very Good' => "6k–8k", 'Outstanding' => "≥ 8k",
    ],
    'io' => [
        'Worst' => "< 100 MB/s", 'Poor' => "100–150 MB/s", 'Fair' => "150–200 MB/s",
        'Good' => "200–250 MB/s", 'Very Good' => "250–300 MB/s", 'Outstanding' => "≥ 300 MB/s",
    ],
    'search' => [
        'Worst' => "> 40 ms", 'Poor' => "30–40 ms", 'Fair' => "20–30 ms",
        'Good' => "10–20 ms", 'Very Good' => "5–10 ms", 'Outstanding' => "≤ 5 ms",
    ],
    'parallel' => [
        'Worst' => "> 30 ms", 'Poor' => "20–30 ms", 'Fair' => "15–20 ms",
        'Good' => "10–15 ms", 'Very Good' => "5–10 ms", 'Outstanding' => "≤ 5 ms",
    ],
    'stability' => [
        'Worst' => "< 98%", 'Poor' => "98%–<99%", 'Fair' => "99%–<99.5%",
        'Good' => "99.5%–<99.7%", 'Very Good' => "99.7%–<99.9%", 'Outstanding' => "≥ 99.9%",
    ],
    'compact_table' => [
        'Worst' => "> 3 s", 'Poor' => "2.5–3 s", 'Fair' => "2–2.5 s",
        'Good' => "1.5–2 s", 'Very Good' => "1–1.5 s", 'Outstanding' => "≤ 1 s",
    ],
];

/**
 * Rendert die HTML-Vergleichstabelle für Benchmark-Ergebnisse.
 *
 * @param array<string, array{value: float, duration?: float, count?: int}> $benchmarkResults
 * @param array<string, string> $ratings
 * @param array<string, array<string, string>> $scaleTexts
 * @return string HTML-Markup der Tabelle.
 */
function renderComparisonTable(array $benchmarkResults, array $ratings, array $scaleTexts): string {
    $criteriaMap = [
        'bulk_insert' => 'Bulk Insert (Records/s)',
        'search'      => 'Search (avg ms)',
        'bulk_update' => 'Bulk Update (Records/s)',
        'bulk_delete' => 'Bulk Delete (Records/s)',
        'io'          => 'I/O Throughput (MB/s)',
        'parallel'    => 'Parallel Load (avg ms)',
        'stability'   => 'Stability (%)',
        'compact_table' => 'Compact Table (s)',
    ];
    if (empty($benchmarkResults)) {
        return "<p>Keine Benchmark-Ergebnisse für Vergleichstabelle vorhanden.</p>";
    }
    $html = '<h3>Benchmark Bewertung</h3>';
    $html .= '<table border="1" cellpadding="5" style="border-collapse: collapse; width: 100%;">';
    $html .= '<thead><tr style="background-color: #f2f2f2;">';
    $html .= '<th>Kriterium</th><th>Ergebnis</th><th>Bewertung</th>';
    $html .= '<th>Worst</th><th>Poor</th><th>Fair</th><th>Good</th><th>Very Good</th><th>Outstanding</th>';
    $html .= '</tr></thead><tbody>';
    $levels = ['Worst', 'Poor', 'Fair', 'Good', 'Very Good', 'Outstanding'];
    foreach ($criteriaMap as $key => $label) {
        if (!isset($benchmarkResults[$key]['value']) || !isset($ratings[$key]) || !isset($scaleTexts[$key])) {
            error_log("Missing data for criterion '$key' in renderComparisonTable.");
            $html .= '<tr><td>' . htmlspecialchars($label) . '</td><td colspan="' . (3 + count($levels)) . '" style="color: red;">Daten fehlen oder Benchmark-Schritt fehlgeschlagen</td></tr>';
            continue;
        }
        $value = $benchmarkResults[$key]['value'];
        $rating = $ratings[$key];
        $scales = $scaleTexts[$key];
        $displayValue = 'N/A';
        if ($key === 'stability') {
            $displayValue = round($value * 100, 2) . '%';
        } elseif ($key === 'search' || $key === 'parallel') {
            $displayValue = round($value, 2) . ' ms';
        } elseif ($key === 'io') {
            $displayValue = round($value, 2) . ' MB/s';
        } elseif (in_array($key, ['bulk_insert', 'bulk_update', 'bulk_delete'])) {
            $displayValue = number_format(round($value, 0));
        } elseif ($key === 'compact_table') {
            $displayValue = round($value, 2) . ' s';
        } else {
            $displayValue = round($value, 3);
        }
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($label) . '</td>';
        $html .= '<td style="font-weight: bold; text-align: right;">' . htmlspecialchars($displayValue) . '</td>';
        $html .= '<td style="font-weight: bold;">' . htmlspecialchars($rating) . '</td>';
        foreach ($levels as $level) {
            $html .= '<td>' . htmlspecialchars($scales[$level] ?? 'N/A') . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    return $html;
}

// --- Database Path & Instance ---
$dataPath = __DIR__ . DIRECTORY_SEPARATOR . 'data';
if (!is_dir($dataPath)) {
    if (!@mkdir($dataPath, 0755, true)) {
         if (!is_dir($dataPath)) {
            die("Fehler: Datenverzeichnis '$dataPath' konnte nicht erstellt werden. Überprüfen Sie die Berechtigungen.");
         }
    }
}
if (!is_writable($dataPath)) {
     die("Fehler: Datenverzeichnis '$dataPath' ist nicht beschreibbar. Überprüfen Sie die Berechtigungen.");
}

try {
    $db = new \FlatFileDB\FlatFileDatabase($dataPath);
} catch (Throwable $e) {
    die("Kritischer Fehler beim Initialisieren der Datenbank: " . htmlspecialchars($e->getMessage()));
}

// --- Initialize Variables ---
$resultMessage = '';
$benchmarkResults = [];
$ratingsOutput = [];
$comparisonTable = '';
$showDetails = isset($_POST['showDetails']) && $_POST['showDetails'] === '1';

$action = isset($_POST['action']) ? trim($_POST['action']) : null;
$action = $action ? preg_replace('/[^a-z_]/', '', $action) : null;

$count = filter_input(INPUT_POST, 'count', FILTER_VALIDATE_INT, [
    'options' => ['default' => 100, 'min_range' => 1]
]);
$numTables = filter_input(INPUT_POST, 'numTables', FILTER_VALIDATE_INT, [
    'options' => ['default' => 5, 'min_range' => 1]
]);
$selectedTable = isset($_POST['table']) && trim($_POST['table']) !== '' ? trim($_POST['table']) : 'performance';

if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $selectedTable)) {
    die("Ungültiger Tabellenname angegeben: " . htmlspecialchars($selectedTable));
}

/**
 * Gets or registers a table and ensures a standard schema is set.
 *
 * @param \FlatFileDB\FlatFileDatabase $db
 * @param string $tableName
 * @return \FlatFileDB\FlatFileTableEngine
 * @throws RuntimeException
 */
function getTableByName(\FlatFileDB\FlatFileDatabase $db, string $tableName): \FlatFileDB\FlatFileTableEngine {
    try {
        $engine = $db->registerTable($tableName);
        $engine->setSchema(
            ['name', 'value'],
            ['name' => 'string', 'value' => 'string']
        );
        return $engine;
    } catch (Throwable $e) {
         throw new RuntimeException("Fehler beim Holen/Erstellen der Tabelle '$tableName': " . $e->getMessage(), 0, $e);
    }
}

/**
 * Simulates parallel HTTP requests to this script.
 *
 * @param int $count Number of parallel requests.
 * @return float Total duration in seconds.
 * @throws RuntimeException
 */
function parallelTest(int $count): float {
    $urls = [];
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $isCli = php_sapi_name() === 'cli';
    if ($isCli) {
         throw new RuntimeException("Parallel-Test kann nicht von der Kommandozeile (CLI) ausgeführt werden.");
    }
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $testUrl = "$protocol://$host" . $scriptPath . "?test=1";
    for ($i = 0; $i < $count; $i++) {
        $urls[] = $testUrl;
    }
    $mh = curl_multi_init();
    if ($mh === false) {
        throw new RuntimeException("Curl Multi konnte nicht initialisiert werden.");
    }
    $curlArr = [];
    $success = true;
    $errorMessages = [];
    foreach ($urls as $i => $url) {
        $ch = curl_init($url);
        if ($ch === false) {
             $errorMessages[] = "curl_init fehlgeschlagen für URL: $url";
             $success = false;
            continue;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        curl_multi_add_handle($mh, $ch);
        $curlArr[(int)$ch] = $ch;
    }
    if (empty($curlArr)) {
         curl_multi_close($mh);
         $errorDetails = !empty($errorMessages) ? ": " . implode('; ', $errorMessages) : "";
         throw new RuntimeException("Keine gültigen Curl-Handles für Paralleltest erstellt" . $errorDetails);
    }
    $start = microtime(true);
    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($mh, 0.5) != -1) {
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        } else {
             usleep(50000);
        }
    }
    $end = microtime(true);
    if ($mrc != CURLM_OK) {
        $errorMessages[] = "curl_multi global error: " . curl_multi_strerror($mrc);
        $success = false;
    }
    foreach ($curlArr as $ch) {
        $info = curl_getinfo($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($err !== '') {
            $errorMessages[] = "Curl error for {$info['url']}: $err";
            $success = false;
        } elseif ($httpCode !== 200) {
             $errorMessages[] = "HTTP error {$httpCode} for {$info['url']}";
             $success = false;
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    if (!$success) {
        error_log("Ein oder mehrere parallele Anfragen schlugen fehl: " . implode('; ', $errorMessages));
    }
    return $end - $start;
}

/**
 * Führt einfache Schreib-/Leseoperationen durch, um den I/O-Durchsatz abzuschätzen.
 *
 * @param int $iterations Anzahl der Lese-/Schreibzyklen.
 * @param string $dataPath Verzeichnis für die temporäre Datei.
 * @return float Gesamtdauer in Sekunden.
 * @throws RuntimeException
 */
function ioTest(int $iterations, string $dataPath): float {
    $file = $dataPath . DIRECTORY_SEPARATOR . 'io_test.tmp';
    $data = str_repeat("0123456789ABCDEF", 640); // ca. 10 KB
    $dataSize = strlen($data);
    $totalBytesWritten = 0;
    $totalBytesRead = 0;
    $start = microtime(true);
    try {
        for ($i = 0; $i < $iterations; $i++) {
            $bytesWritten = @file_put_contents($file, $data, LOCK_EX);
            if ($bytesWritten === false || $bytesWritten < $dataSize) {
                 $error = error_get_last();
                 $errorMsg = $error ? " (Systemfehler: {$error['message']})" : "";
                throw new RuntimeException("I/O Test: Konnte Testdatei '$file' nicht (vollständig) schreiben{$errorMsg}. Geschrieben: " . ($bytesWritten === false ? 'false' : $bytesWritten) . " Bytes.");
            }
            $totalBytesWritten += $bytesWritten;
            $readData = @file_get_contents($file);
            if ($readData === false) {
                 $error = error_get_last();
                 $errorMsg = $error ? " (Systemfehler: {$error['message']})" : "";
                throw new RuntimeException("I/O Test: Konnte Testdatei '$file' nicht lesen{$errorMsg}.");
            }
            $totalBytesRead += strlen($readData);
        }
    } finally {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
    $end = microtime(true);
    return $end - $start;
}

// --- Test Request Handler ---
if (isset($_GET['test']) && $_GET['test'] === '1') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode(['status' => 'ok', 'timestamp' => microtime(true)]);
    exit;
}

// --- Main Action Handling ---
if ($action === 'auto_benchmark') {
    $startTime = microtime(true);
    $benchmarkResults = [];
    $ratingsOutput = [];
    $comparisonTable = '';
    $autoBenchmarkSuccess = true;
    
    try {
        \FlatFileDB\FlatFileDBStatistics::resetPerformanceMetrics();
        $tableEngine = getTableByName($db, $selectedTable);
        $tableEngine->clearTable();
        $bulkErrors = 0;
        
        // 1. Bulk Insert Test
        $insertData = [];
        for ($i = 0; $i < $count; $i++) {
            $insertData[] = ['name' => 'BInsert ' . $i . '_' . bin2hex(random_bytes(3)), 'value' => (string)mt_rand(1, 10000)];
        }
        $measurement = \FlatFileDB\FlatFileDBStatistics::measurePerformance(fn() => $tableEngine->bulkInsertRecords($insertData));
        $duration = $measurement['duration'];
        $insertResults = $measurement['result'];
        $insertedCount = count(array_filter($insertResults, 'is_int'));
        $bulkErrors += count(array_filter($insertResults, 'is_array'));
        $bulkInsertDS = ($duration > 0 && $insertedCount > 0) ? $insertedCount / $duration : 0;
        $benchmarkResults['bulk_insert'] = ['value' => $bulkInsertDS, 'duration' => $duration, 'count' => $insertedCount];
        
        // 2. Bulk Search Test
        $searchConditions = [['field' => 'value', 'operator' => '>', 'value' => '0']];
        $searchCount = max(10, min($count, 100));
        $totalFound = 0;
        $measurement = \FlatFileDB\FlatFileDBStatistics::measurePerformance(function() use ($tableEngine, $searchConditions, $searchCount, &$totalFound) {
            for ($i = 0; $i < $searchCount; $i++) {
                $found = $tableEngine->findRecords($searchConditions, 10);
                $totalFound += count($found);
            }
        });
        $totalSearchDuration = $measurement['duration'];
        $avgSearchTime = ($searchCount > 0) ? ($totalSearchDuration / $searchCount) * 1000 : 0;
        $benchmarkResults['search'] = ['value' => $avgSearchTime, 'duration' => $totalSearchDuration, 'count' => $searchCount];
        
        // 3. Bulk Update Test
        $updateCandidates = $tableEngine->findRecords([], $count);
        $updates = [];
        foreach ($updateCandidates as $record) {
            $updates[] = ['recordId' => $record['id'], 'newData' => ['value' => (string)mt_rand(10001, 20000)]];
        }
        $actualUpdateCount = count($updates);
        $duration = 0;
        if ($actualUpdateCount > 0) {
            $measurement = \FlatFileDB\FlatFileDBStatistics::measurePerformance(fn() => $tableEngine->bulkUpdateRecords($updates));
            $duration = $measurement['duration'];
            $updateResults = $measurement['result'];
            if (is_array($updateResults)) {
                $bulkErrors += count(array_filter($updateResults, 'is_array'));
            } else {
                error_log("Bulk Update: Unerwartetes Ergebnis: " . print_r($updateResults, true));
                $autoBenchmarkSuccess = false;
            }
        }
        $bulkUpdateDS = ($duration > 0 && $actualUpdateCount > 0) ? $actualUpdateCount / $duration : 0;
        $benchmarkResults['bulk_update'] = ['value' => $bulkUpdateDS, 'duration' => $duration, 'count' => $actualUpdateCount];
        
        // 4. Bulk Delete Test
        // Nach dem Update haben alle Datensätze "value" > 10000 – daher den Filter entsprechend anpassen:
        $deleteCandidates = $tableEngine->findRecords([['field' => 'value', 'operator' => '>', 'value' => '10000']], $count);
        $deleteIds = array_column($deleteCandidates, 'id');
        $actualDeleteCount = count($deleteIds);
        $duration = 0;
        if ($actualDeleteCount > 0) {
            $measurement = \FlatFileDB\FlatFileDBStatistics::measurePerformance(fn() => $tableEngine->bulkDeleteRecords($deleteIds));
            $duration = $measurement['duration'];
            $deleteResults = $measurement['result'];
            if (is_array($deleteResults)) {
                $bulkErrors += count(array_filter($deleteResults, 'is_array'));
            } else {
                error_log("Bulk Delete: Unerwartetes Ergebnis: " . print_r($deleteResults, true));
                $autoBenchmarkSuccess = false;
            }
        }
        $bulkDeleteDS = ($duration > 0 && $actualDeleteCount > 0) ? $actualDeleteCount / $duration : 0;
        $benchmarkResults['bulk_delete'] = ['value' => $bulkDeleteDS, 'duration' => $duration, 'count' => $actualDeleteCount];
        
        // 5. I/O Test
        $ioIterations = max(50, min($count * 2, 500));
        $ioDuration = ioTest($ioIterations, $dataPath);
        $ioTotalBytes = $ioIterations * 10240;
        $ioThroughput = ($ioDuration > 0) ? ($ioTotalBytes / $ioDuration) / (1024 * 1024) : 0;
        $benchmarkResults['io'] = ['value' => $ioThroughput, 'duration' => $ioDuration, 'count' => $ioIterations];
        
        // 6. Parallel Test
        $parallelCount = max(5, min($count, 50));
        $parallelDuration = parallelTest($parallelCount);
        $avgParallel = ($parallelCount > 0) ? ($parallelDuration / $parallelCount) * 1000 : 0;
        $benchmarkResults['parallel'] = ['value' => $avgParallel, 'duration' => $parallelDuration, 'count' => $parallelCount];
        
        // 7. Stability
        $totalBulkOpsAttempted = $insertedCount + $actualUpdateCount + $actualDeleteCount;
        $stability = ($totalBulkOpsAttempted > 0) ? max(0, ($totalBulkOpsAttempted - $bulkErrors)) / $totalBulkOpsAttempted : 1.0;
        $benchmarkResults['stability'] = ['value' => $stability, 'duration' => 0, 'count' => $totalBulkOpsAttempted];
        
        // 8. Compact Table Test – Automatisch am Ende
        $measurement = \FlatFileDB\FlatFileDBStatistics::measurePerformance(fn() => $tableEngine->compactTable());
        $compactDuration = $measurement['duration'];
        $benchmarkResults['compact_table'] = ['value' => $compactDuration, 'duration' => $compactDuration, 'count' => 1];
        
        // Performance-Metriken zurücksetzen und dann die aktuellen Metriken speichern
        \FlatFileDB\FlatFileDBStatistics::resetPerformanceMetrics();
        foreach ($benchmarkResults as $key => $result) {
            if (isset($result['value'])) {
                \FlatFileDB\FlatFileDBStatistics::recordPerformance($key, $result['value']);
            }
        }
        $metricsToSave = \FlatFileDB\FlatFileDBStatistics::getPerformanceMetrics();
        if (!empty($metricsToSave)) {
            if (!savePerformanceHistory($metricsToSave)) {
                $resultMessage .= "<br><strong style='color: orange;'>Warnung: Performance-Historie konnte nicht gespeichert werden.</strong>";
            }
        }
        
        foreach ($benchmarkResults as $key => $result) {
            if (isset($result['value'])) {
                $ratingsOutput[$key] = getRating($key, $result['value']);
            } else {
                $ratingsOutput[$key] = 'N/A';
            }
        }
        
        $totalDuration = microtime(true) - $startTime;
        $resultMessage = "Automatischer Benchmark abgeschlossen in " . round($totalDuration, 3) . " Sekunden.<br>";
        $details = [];
        if (isset($benchmarkResults['bulk_insert']['count'])) $details[] = "Insert=" . $benchmarkResults['bulk_insert']['count'];
        if (isset($benchmarkResults['search']['count'])) $details[] = "Search Iterations=" . $benchmarkResults['search']['count'];
        if (isset($benchmarkResults['bulk_update']['count'])) $details[] = "Update=" . $benchmarkResults['bulk_update']['count'];
        if (isset($benchmarkResults['bulk_delete']['count'])) $details[] = "Delete=" . $benchmarkResults['bulk_delete']['count'];
        if (isset($benchmarkResults['io']['count'])) $details[] = "IO Iterations=" . $benchmarkResults['io']['count'];
        if (isset($benchmarkResults['parallel']['count'])) $details[] = "Parallel Requests=" . $benchmarkResults['parallel']['count'];
        if (isset($benchmarkResults['stability']['count'])) $details[] = "Total Bulk Ops=" . $benchmarkResults['stability']['count'];
        if (isset($benchmarkResults['compact_table']['duration'])) $details[] = "Compact Table Duration=" . round($benchmarkResults['compact_table']['duration'], 3) . " s";
        $resultMessage .= "(Angeforderte Anzahl '$count'. Tatsächliche Aktionen: " . implode(', ', $details) . ")";
        if ($bulkErrors > 0) {
            $resultMessage .= "<br><strong style='color: red;'>$bulkErrors Fehler in Bulk-Operationen aufgetreten.</strong>";
        }
        if (isset($benchmarkResults['stability']['value']) && $benchmarkResults['stability']['value'] < 0.95 && $totalBulkOpsAttempted > 0) {
            $resultMessage .= "<br><strong style='color: orange;'>Warnung: Stabilität der Bulk-Operationen war niedrig (" . round($benchmarkResults['stability']['value'] * 100, 1) . "%).</strong>";
        }
        if ($autoBenchmarkSuccess && !empty($benchmarkResults) && !empty($ratingsOutput)) {
            $comparisonTable = renderComparisonTable($benchmarkResults, $ratingsOutput, $scaleTexts);
        } else {
            $comparisonTable = "<p>Benchmark enthielt Fehler oder lieferte keine vollständigen Ergebnisse, Vergleichstabelle wird nicht angezeigt.</p>";
        }
        
    } catch (Throwable $e) {
        $autoBenchmarkSuccess = false;
        $resultMessage = "<strong style='color: red;'>Fehler beim automatischen Benchmark:</strong> " . htmlspecialchars($e->getMessage())
                       . "<br>Datei: " . htmlspecialchars($e->getFile()) . " Zeile: " . $e->getLine()
                       . "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        $comparisonTable = '';
        $benchmarkResults = [];
        $ratingsOutput = [];
    } finally {
        $action = 'auto_benchmark';
    }
}
// --- INDIVIDUAL ACTIONS ---
elseif ($action) {
    $startTime = microtime(true);
    \FlatFileDB\FlatFileDBStatistics::resetPerformanceMetrics();
    try {
        $tableEngine = null;
        if (!in_array($action, ['parallel_test', 'io_test', 'clear_database', 'create_tables'])) {
            if (empty($selectedTable)) {
                throw new InvalidArgumentException("Keine Tabelle ausgewählt für Aktion '$action'.");
            }
            $tableEngine = getTableByName($db, $selectedTable);
        }
        switch ($action) {
            case 'bulk_insert':
                if (!$tableEngine) throw new LogicException("TableEngine wurde nicht initialisiert für Aktion '$action'.");
                $measurement = \FlatFileDB\FlatFileDBStatistics::measurePerformance(function() use ($tableEngine, $count) {
                    $dummyRecords = [];
                    for ($i = 0; $i < $count; $i++) {
                        $dummyRecords[] = ['name' => 'IndivInsert ' . $i . '_' . bin2hex(random_bytes(3)), 'value' => (string)mt_rand(20001, 30000)];
                    }
                    return $tableEngine->bulkInsertRecords($dummyRecords);
                });
                $duration = $measurement['duration'];
                $insertResults = $measurement['result'];
                $successCount = 0;
                $errorCount = 0;
                if (is_array($insertResults)) {
                    $successCount = count(array_filter($insertResults, 'is_int'));
                    $errorCount = count(array_filter($insertResults, 'is_array'));
                } else {
                    error_log("Bulk Insert: Unerwartetes Ergebnis: " . print_r($insertResults, true));
                }
                $resultMessage = "Bulk Insert: $successCount / $count Datensätze in Tabelle '$selectedTable' eingefügt.";
                if ($errorCount > 0) $resultMessage .= " ($errorCount Fehler)";
                \FlatFileDB\FlatFileDBStatistics::recordPerformance('BULK_INSERT_DURATION', $duration);
                break;
            case 'bulk_update':
                if (!$tableEngine) throw new LogicException("TableEngine wurde nicht initialisiert für Aktion '$action'.");
                $updateCandidates = $tableEngine->findRecords([], $count);
                $updates = [];
                foreach ($updateCandidates as $record) {
                    $updates[] = ['recordId' => $record['id'], 'newData' => ['value' => (string)mt_rand(30001, 40000)]];
                }
                $actualUpdateAttemptCount = count($updates);
                if (empty($updates)) {
                    $resultMessage = "Keine Datensätze zum Aktualisieren gefunden in Tabelle '$selectedTable'.";
                } else {
                    $measurement = \FlatFileDB\FlatFileDBStatistics::measurePerformance(fn() => $tableEngine->bulkUpdateRecords($updates));
                    $duration = $measurement['duration'];
                    $updateResults = $measurement['result'];
                    if (is_array($updateResults)) {
                        $writtenCount = count(array_filter($updateResults, fn($r) => $r === true));
                        $noChangeCount = count(array_filter($updateResults, fn($r) => $r === 'no_change'));
                        $errorCount = count(array_filter($updateResults, 'is_array'));
                        $notFoundCount = count(array_filter($updateResults, fn($r) => $r === false));
                        $resultMessage = "Bulk Update: $writtenCount / $actualUpdateAttemptCount Datensätze geschrieben in Tabelle '$selectedTable'. "
                                        . "($noChangeCount keine Änderung, $notFoundCount nicht gefunden, $errorCount Fehler)";
                        \FlatFileDB\FlatFileDBStatistics::recordPerformance('BULK_UPDATE_DURATION', $duration);
                    } else {
                        $resultMessage = "Fehler: Unerwartetes Ergebnis von bulkUpdateRecords erhalten.";
                        error_log("Bulk Update: Unerwartetes Ergebnis: " . print_r($updateResults, true));
                    }
                }
                break;
            case 'bulk_delete':
                if (!$tableEngine) throw new LogicException("TableEngine wurde nicht initialisiert für Aktion '$action'.");
                $deleteCandidates = $tableEngine->findRecords([], $count);
                $deleteIds = array_column($deleteCandidates, 'id');
                $actualDeleteAttemptCount = count($deleteIds);
                if (empty($deleteIds)) {
                    $resultMessage = "Keine Datensätze zum Löschen gefunden in Tabelle '$selectedTable'.";
                } else {
                    $measurement = \FlatFileDB\FlatFileDBStatistics::measurePerformance(fn() => $tableEngine->bulkDeleteRecords($deleteIds));
                    $duration = $measurement['duration'];
                    $deleteResults = $measurement['result'];
                    if (is_array($deleteResults)) {
                        $successCount = count(array_filter($deleteResults, fn($r) => $r === true));
                        $errorCount = count(array_filter($deleteResults, 'is_array'));
                        $notFoundCount = count(array_filter($deleteResults, fn($r) => $r === false));
                        $resultMessage = "Bulk Delete: $successCount / $actualDeleteAttemptCount Datensätze gelöscht in Tabelle '$selectedTable'. ($notFoundCount nicht gefunden, $errorCount Fehler)";
                        \FlatFileDB\FlatFileDBStatistics::recordPerformance('BULK_DELETE_DURATION', $duration);
                    } else {
                        $resultMessage = "Fehler: Unerwartetes Ergebnis von bulkDeleteRecords erhalten.";
                        error_log("Bulk Delete: Unerwartetes Ergebnis: " . print_r($deleteResults, true));
                    }
                }
                break;
            case 'bulk_search':
                if (!$tableEngine) throw new LogicException("TableEngine wurde nicht initialisiert für Aktion '$action'.");
                $searchConditions = [['field' => 'value', 'operator' => '>', 'value' => '0']];
                $searchCount = max(1, $count);
                $totalFound = 0;
                $measurement = \FlatFileDB\FlatFileDBStatistics::measurePerformance(function() use ($tableEngine, $searchConditions, $searchCount, &$totalFound) {
                    for ($i = 0; $i < $searchCount; $i++) {
                        $found = $tableEngine->findRecords($searchConditions, 10);
                        $totalFound += count($found);
                    }
                });
                $duration = $measurement['duration'];
                $avgTime = ($searchCount > 0) ? $duration / $searchCount : 0;
                $resultMessage = "Bulk Search: $searchCount Suchen in Tabelle '$selectedTable'. Gesamtdauer: " . round($duration, 4) . "s. Avg Zeit/Suche: " . round($avgTime * 1000, 2) . "ms. Insgesamt " . $totalFound . " Datensätze gefunden (max 10 pro Suche).";
                \FlatFileDB\FlatFileDBStatistics::recordPerformance('BULK_SEARCH_AVG_MS', $avgTime * 1000);
                break;
        case 'compact_table':
            if (!$tableEngine) throw new LogicException("TableEngine wurde nicht initialisiert für Aktion '$action'.");
            $measurement = \FlatFileDB\FlatFileDBStatistics::measurePerformance(fn() => $tableEngine->compactTable());
            $duration = $measurement['duration'];
            \FlatFileDB\FlatFileDBStatistics::recordPerformance('COMPACT_TABLE_DURATION', $duration);
            $resultMessage = "Tabelle '$selectedTable' in " . round($duration, 4) . " Sekunden kompaktiert.";
            break;
            case 'clear_database':
                error_log("Benchmark Action: clear_database - STARTING"); // Logging hinzufügen
                $tablesToClear = [];
                $registeredCount = 0;
                $registrationErrors = [];

                try {
                    // --- NEU: Tabellen aus dem Dateisystem erkennen ---
                    error_log("Benchmark Action: clear_database - Discovering tables in $dataPath");
                    $dataFiles = glob($dataPath . DIRECTORY_SEPARATOR . '*_data' . \FlatFileDB\FlatFileDBConstants::DATA_FILE_EXTENSION);
                    if ($dataFiles === false) {
                        error_log("Benchmark Action: clear_database - glob() failed for data files.");
                    } else {
                        foreach ($dataFiles as $dataFile) {
                            $baseName = basename($dataFile, \FlatFileDB\FlatFileDBConstants::DATA_FILE_EXTENSION);
                            // Entferne das Suffix '_data'
                            if (str_ends_with($baseName, '_data')) {
                                $tableName = substr($baseName, 0, -strlen('_data'));
                                if (!empty($tableName) && !in_array($tableName, $tablesToClear)) {
                                    $tablesToClear[] = $tableName;
                                }
                            }
                        }
                    }
                    error_log("Benchmark Action: clear_database - Discovered tables: " . implode(', ', $tablesToClear));

                    // --- NEU: Entdeckte Tabellen registrieren ---
                    if (empty($tablesToClear)) {
                            error_log("Benchmark Action: clear_database - No tables found to register/clear.");
                            $resultMessage = "Keine Tabellen im Datenverzeichnis gefunden zum Leeren.";
                            $duration = 0; // Keine Aktion durchgeführt
                    } else {
                            error_log("Benchmark Action: clear_database - Registering discovered tables...");
                            foreach ($tablesToClear as $tableName) {
                                try {
                                    $db->registerTable($tableName);
                                    $registeredCount++;
                                    error_log("Benchmark Action: clear_database - Registered table '$tableName'.");
                                } catch (\Throwable $e) {
                                    $errMsg = "Fehler beim Registrieren der Tabelle '$tableName' vor dem Löschen: " . $e->getMessage();
                                    $registrationErrors[] = $errMsg;
                                    error_log("Benchmark Action: clear_database - ERROR: " . $errMsg);
                                }
                            }
                            error_log("Benchmark Action: clear_database - Registration complete ($registeredCount tables).");

                            // --- Bestehenden Code ausführen (Datenbank leeren) ---
                            if ($registeredCount > 0) {
                                error_log("Benchmark Action: clear_database - Calling \$db->clearDatabase()...");
                                $measurement = \FlatFileDB\FlatFileDBStatistics::measurePerformance(fn() => $db->clearDatabase());
                                $duration = $measurement['duration'];
                                \FlatFileDB\FlatFileDBStatistics::recordPerformance('CLEAR_DATABASE_DURATION', $duration);
                                $resultMessage = "Versucht, $registeredCount registrierte Tabellen in " . round($duration, 4) . " Sekunden zu leeren.";
                                error_log("Benchmark Action: clear_database - \$db->clearDatabase() finished.");
                            } else {
                                $resultMessage = "Keine Tabellen konnten erfolgreich registriert werden.";
                                $duration = 0;
                            }
                    }
                } catch (\Throwable $e) {
                    // Fehler beim Entdecken oder genereller Fehler
                    $resultMessage = "<strong style='color: red;'>Fehler während der Vorbereitung zum Datenbankleeren:</strong> " . htmlspecialchars($e->getMessage());
                    $duration = 0;
                    error_log("Benchmark Action: clear_database - CRITICAL ERROR during setup: " . $e->getMessage());
                }

                // Fehlermeldungen aus der Registrierung hinzufügen
                if (!empty($registrationErrors)) {
                    $resultMessage .= "<br><strong style='color: orange;'>Fehler bei der Tabellenregistrierung:</strong><br>" . implode("<br>", array_map('htmlspecialchars', $registrationErrors));
                }

                $selectedTable = 'performance'; // Setzt nur die UI-Auswahl zurück
                error_log("Benchmark Action: clear_database - FINISHED. Duration reported: " . ($duration ?? 'N/A'));
                break;
            case 'create_tables':
                $tableNames = [];
                $errors = [];
                $totalInserted = 0;
                $measurement = \FlatFileDB\FlatFileDBStatistics::measurePerformance(function() use ($db, $numTables, $count, &$tableNames, &$errors, &$totalInserted) {
                    for ($i = 1; $i <= $numTables; $i++) {
                        $tableName = "generated_table_{$i}";
                        $tableNames[] = $tableName;
                        try {
                            $engine = getTableByName($db, $tableName);
                            $engine->clearTable();
                            $dummyRecords = [];
                            for ($j = 0; $j < $count; $j++) {
                                $dummyRecords[] = ['name' => "Gen $i-$j", 'value' => (string)mt_rand(1, 5000)];
                            }
                            $insertResult = $engine->bulkInsertRecords($dummyRecords);
                            if (is_array($insertResult)) {
                                $totalInserted += count(array_filter($insertResult, 'is_int'));
                                $tableErrors = count(array_filter($insertResult, 'is_array'));
                                if ($tableErrors > 0) $errors[] = "Fehler ($tableErrors) beim Einfügen in Tabelle {$tableName}";
                            } else {
                                $errors[] = "Unerwartetes Ergebnis beim Einfügen in Tabelle {$tableName}";
                            }
                        } catch (Throwable $e) {
                            $errors[] = "Fehler bei Tabelle {$tableName}: " . $e->getMessage();
                        }
                    }
                });
                $duration = $measurement['duration'];
                \FlatFileDB\FlatFileDBStatistics::recordPerformance('CREATE_TABLES_DURATION', $duration);
                $resultMessage = count($tableNames) . " Tabellen erstellt/geleert und insgesamt $totalInserted Datensätze in " . round($duration, 4) . " Sekunden eingefügt.";
                if (!empty($errors)) {
                    $resultMessage .= "<br><strong style='color: red;'>Fehler aufgetreten:</strong><br>" . implode("<br>", array_map('htmlspecialchars', $errors));
                }
                break;
            case 'parallel_test':
                try {
                    $parallelCount = max(1, $count);
                    $measurementDuration = parallelTest($parallelCount);
                    $avgTime = ($parallelCount > 0) ? $measurementDuration / $parallelCount : 0;
                    $resultMessage = "Parallel Test: $parallelCount Anfragen in " . round($measurementDuration, 4) . "s abgeschlossen. Avg Zeit/Anfrage: " . round($avgTime * 1000, 2) . "ms.";
                    \FlatFileDB\FlatFileDBStatistics::recordPerformance('PARALLEL_AVG_MS', $avgTime * 1000);
                } catch (Throwable $e) {
                    $resultMessage = "<strong style='color: red;'>Fehler beim parallelen Test:</strong> " . htmlspecialchars($e->getMessage());
                }
                break;
            case 'io_test':
                try {
                    $ioIterations = max(10, min($count, 500));
                    $measurementDuration = ioTest($ioIterations, $dataPath);
                    $ioTotalBytes = $ioIterations * 10240;
                    $ioThroughput = ($measurementDuration > 0) ? ($ioTotalBytes / $measurementDuration) / (1024 * 1024) : 0;
                    $resultMessage = "I/O Test: $ioIterations Lese/Schreib-Iterationen in " . round($measurementDuration, 4) . "s. Geschätzter Durchsatz: ~" . round($ioThroughput, 2) . " MB/s.";
                    \FlatFileDB\FlatFileDBStatistics::recordPerformance('IO_MBPS', $ioThroughput);
                } catch (Throwable $e) {
                    $resultMessage = "<strong style='color: red;'>Fehler beim I/O-Test:</strong> " . htmlspecialchars($e->getMessage());
                }
                break;
            default:
                if ($action !== null) {
                    $resultMessage = "Unbekannte Aktion gewählt: " . htmlspecialchars($action);
                }
                break;
        }
        $currentMetricsRun = \FlatFileDB\FlatFileDBStatistics::getPerformanceMetrics();
        if (!empty($currentMetricsRun)) {
            if (!savePerformanceHistory($currentMetricsRun)) {
                $resultMessage .= "<br><strong style='color: orange;'>Warnung: Performance-Historie konnte nicht gespeichert werden.</strong>";
            }
        }
    } catch (Throwable $e) {
        $resultMessage = "<strong style='color: red;'>Fehler bei Aktion '$action':</strong> " . htmlspecialchars($e->getMessage())
                       . "<br>Datei: " . htmlspecialchars($e->getFile()) . " Zeile: " . $e->getLine()
                       . "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
}

$performanceLog = loadPerformanceHistory(100);
try {
    $availableTables = $db->getTableNames();
} catch (Throwable $e) {
    $availableTables = [];
    $resultMessage .= "<br><strong style='color: red;'>Fehler beim Abrufen der Tabellenliste: " . htmlspecialchars($e->getMessage()) . "</strong>";
}
$defaultTableName = 'performance';
if (!in_array($defaultTableName, $availableTables, true)) {
    $availableTables[] = $defaultTableName;
    sort($availableTables);
}
if (!in_array($selectedTable, $availableTables, true)) {
    $selectedTable = $defaultTableName;
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
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Fira Sans", "Droid Sans", "Helvetica Neue", sans-serif;
            margin: 20px auto;
            padding: 0 15px;
            line-height: 1.6;
            max-width: 1200px;
            color: #333;
        }
        form {
            margin-bottom: 25px;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            align-items: center;
        }
        .form-group { }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            font-size: 0.9em;
            color: #495057;
        }
        input[type="number"], select {
            width: 100%;
            padding: 8px 12px;
            border-radius: 4px;
            border: 1px solid #ced4da;
            box-sizing: border-box;
            background-color: #fff;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        input:focus, select:focus {
            border-color: #86b7fe;
            outline: 0;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        .checkbox-group label {
             display: inline-block;
             margin-bottom: 0;
             margin-left: 5px;
             font-weight: normal;
        }
        .checkbox-group input[type="checkbox"] {
             width: auto;
             vertical-align: middle;
        }
        button[type="submit"] {
            background-color: #0d6efd;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
            transition: background-color 0.2s ease;
            margin-top: 15px;
            grid-column: 1 / -1;
            justify-self: start;
        }
        button:hover {
            background-color: #0b5ed7;
        }
        h1, h2, h3 {
            color: #212529;
            margin-top: 1.5em;
            margin-bottom: 0.8em;
            border-bottom: 1px solid #eee;
            padding-bottom: 0.3em;
        }
        table {
            border-collapse: collapse;
            margin: 25px 0;
            width: 100%;
            font-size: 0.9em;
            box-shadow: 0 2px 3px rgba(0,0,0,0.1);
        }
        th, td {
            border: 1px solid #e9ecef;
            padding: 10px 12px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        tbody tr:nth-child(even) {
            background-color: #fdfdfe;
        }
        tbody tr:hover {
            background-color: #f1f3f5;
        }
        .result, .error {
            border-left: 5px solid;
            padding: 15px;
            margin: 20px 0;
            background-color: #fff;
            border-radius: 4px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.07);
            word-wrap: break-word;
        }
        .result {
            border-color: #198754;
            background-color: #d1e7dd;
            color: #0f5132;
        }
        .error {
            border-color: #dc3545;
            background-color: #f8d7da;
            color: #842029;
        }
        pre {
            background-color: #e9ecef;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
            border: 1px solid #ced4da;
            color: #495057;
        }
        ul {
             padding-left: 20px;
        }
        td:nth-child(n+4) {
            font-size: 0.85em;
            color: #6c757d;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <h1>FlatFileDB Benchmark</h1>
    <form method="post">
        <div class="form-grid">
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
                    <option value="">-- Bitte wählen --</option>
                    <option value="auto_benchmark" <?php if($action === 'auto_benchmark') echo 'selected'; ?>>Automatischer Benchmarkmodus</option>
                    <option value="bulk_insert" <?php if($action === 'bulk_insert') echo 'selected'; ?>>Bulk Insert (Dummy-Daten)</option>
                    <option value="bulk_update" <?php if($action === 'bulk_update') echo 'selected'; ?>>Bulk Update</option>
                    <option value="bulk_delete" <?php if($action === 'bulk_delete') echo 'selected'; ?>>Bulk Delete</option>
                    <option value="bulk_search" <?php if($action === 'bulk_search') echo 'selected'; ?>>Bulk Search</option>
                    <option value="compact_table" <?php if($action === 'compact_table') echo 'selected'; ?>>Tabelle kompaktieren</option>
                    <option value="io_test" <?php if($action === 'io_test') echo 'selected'; ?>>I/O Test</option>
                    <option value="parallel_test" <?php if($action === 'parallel_test') echo 'selected'; ?>>Parallel Test</option>
                    <option value="create_tables" <?php if($action === 'create_tables') echo 'selected'; ?>>Mehrere Tabellen erstellen</option>
                    <option value="clear_database" <?php if($action === 'clear_database') echo 'selected'; ?>>Datenbank leeren</option>
                </select>
            </div>
            <div class="form-group">
                <label for="count">Anzahl (Operationen/Iterationen):</label>
                <input type="number" name="count" id="count" value="<?php echo $count; ?>" min="1">
            </div>
            <div class="form-group">
                <label for="numTables">Anzahl Tabellen (für "Mehrere erstellen"):</label>
                <input type="number" name="numTables" id="numTables" value="<?php echo $numTables; ?>" min="1">
            </div>
            <div class="form-group checkbox-group">
                <input type="checkbox" name="showDetails" id="showDetails" value="1" <?php if($showDetails) echo 'checked'; ?>>
                <label for="showDetails">Einzelne Laufzeiten anzeigen?</label>
            </div>
        </div><!-- End form-grid -->
        <button type="submit">Aktion ausführen</button>
    </form>
    <?php if ($resultMessage): ?>
        <div class="<?php echo (strpos(strtolower($resultMessage), 'fehler') !== false || strpos($resultMessage, 'KRITISCH') !== false) ? 'error' : 'result'; ?>">
            <h2>Ergebnis der Aktion '<?php echo htmlspecialchars($action ?? 'Keine'); ?>'</h2>
            <?php echo $resultMessage; ?>
        </div>
    <?php endif; ?>
    <?php
    if (!empty($comparisonTable)) {
         echo $comparisonTable;
    }
    ?>
    <?php
    $lastRunMetricsRaw = \FlatFileDB\FlatFileDBStatistics::getPerformanceMetrics();
    if (!empty($lastRunMetricsRaw)):
    ?>
        <h2>Laufzeiten der letzten Aktion (Rohdaten)</h2>
        <ul>
            <?php foreach ($lastRunMetricsRaw as $actionName => $durations):
                if(empty($durations)) continue;
                $actionCount = count($durations);
                $totalDuration = array_sum($durations);
                $avg = $actionCount > 0 ? $totalDuration / $actionCount : 0;
                $min = $actionCount > 0 ? min($durations) : 0;
                $max = $actionCount > 0 ? max($durations) : 0;
                $unit = 's';
                if (str_contains($actionName, 'AVG_MS')) { $unit = 'ms'; }
                elseif (str_contains($actionName, 'MBPS')) { $unit = 'MB/s'; }
                elseif (str_contains($actionName, 'DURATION')) { $unit = 's'; }
                elseif (in_array($actionName, ['BULK_INSERT', 'BULK_UPDATE', 'BULK_DELETE'])) { $unit = 'Records/s'; }
            ?>
                <li>
                    <strong><?php echo htmlspecialchars($actionName); ?>:</strong>
                    <?php if ($actionCount > 1): ?>
                        Avg: <?php echo round($avg, 5); ?><?php echo $unit; ?> |
                        Min: <?php echo round($min, 5); ?><?php echo $unit; ?> |
                        Max: <?php echo round($max, 5); ?><?php echo $unit; ?> |
                        Total Duration: <?php echo round($totalDuration, 5); ?>s |
                        Count: <?php echo $actionCount; ?> Run(s)
                    <?php else: ?>
                        Duration: <?php echo round($totalDuration, 5); ?>s
                    <?php endif; ?>
                    <?php if ($showDetails && $actionCount > 1): ?>
                        <br><small>(Einzelwerte [s]: <?php echo implode(', ', array_map(function($d) { return round($d, 5); }, $durations)); ?>)</small>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if (!empty($performanceLog)): ?>
        <h2>Performance Historie (Letzte <?php echo count($performanceLog); ?> Einträge)</h2>
        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Aktion</th>
                    <th>Wert</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach (array_reverse($performanceLog) as $entry):
                    $displayValueHist = round((float)($entry['duration'] ?? 0), 6);
                    $unitHist = 's';
                    $actionHist = $entry['action'] ?? 'N/A';
                    if (str_contains($actionHist, 'AVG_MS')) { $unitHist = 'ms'; }
                    elseif (str_contains($actionHist, 'MBPS')) { $unitHist = 'MB/s'; $displayValueHist = round($displayValueHist, 2); }
                    elseif (in_array($actionHist, ['BULK_INSERT', 'BULK_UPDATE', 'BULK_DELETE'])) { $unitHist = 'Records/s'; $displayValueHist = round($displayValueHist, 0); }
                    elseif ($actionHist === 'STABILITY') { $unitHist = '%'; $displayValueHist = round($displayValueHist * 100, 2); }
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($entry['timestamp'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($actionHist); ?></td>
                        <td><?php echo $displayValueHist . ' ' . $unitHist; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <h2>Aktueller Datenbankstatus</h2>
    <?php
    try {
        $dbTableNamesStatus = $db->getTableNames();
        if (empty($dbTableNamesStatus)) {
            echo "<p>Keine Tabellen in der Datenbank registriert oder vorhanden.</p>";
        } else {
            echo "<ul>";
            $statsService = new \FlatFileDB\FlatFileDBStatistics($db);
            foreach ($dbTableNamesStatus as $tableName) {
                try {
                    $stats = $statsService->getTableStatistics($tableName);
                    echo "<li><strong>" . htmlspecialchars($tableName) . ":</strong> " .
                         number_format($stats['record_count']) . " Datensätze | " .
                         "Data: " . number_format($stats['data_file_size']) . " B | " .
                         "Index: " . number_format($stats['index_file_size']) . " B | " .
                         "Log: " . number_format($stats['log_file_size']) . " B" .
                         "</li>";
                } catch (Throwable $e) {
                    echo "<li><strong>" . htmlspecialchars($tableName) . ":</strong> <span style='color:red;'>Fehler bei Statistik: " . htmlspecialchars($e->getMessage()) . "</span></li>";
                }
            }
            echo "</ul>";
        }
    } catch (Throwable $e) {
         echo "<p class='error'>Fehler beim Abrufen der Tabellenliste für Status: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    ?>
</body>
</html>
