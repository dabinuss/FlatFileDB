<?php
// debug_table_list.php
require_once 'init.php';

echo "<h1>Debug Tabellenliste</h1>";

// 1. Basisverzeichnis prüfen
echo "<h2>Basisverzeichnis</h2>";
echo "<p>Pfad: {$currentDataDir}</p>";
echo "<p>Existiert: " . (file_exists($currentDataDir) ? "Ja" : "Nein") . "</p>";
echo "<p>Ist Verzeichnis: " . (is_dir($currentDataDir) ? "Ja" : "Nein") . "</p>";

// 2. Dateien im Verzeichnis
echo "<h2>Dateien im Verzeichnis</h2>";
$files = scandir($currentDataDir);
echo "<ul>";
foreach ($files as $file) {
    if ($file === '.' || $file === '..')
        continue;
    echo "<li>{$file} (" . filesize($currentDataDir . '/' . $file) . " Bytes)</li>";
}
echo "</ul>";

// 3. Prüfe tables.json
echo "<h2>Tabellenliste (tables.json)</h2>";
$tablesFile = $currentDataDir . "/tables.json";
if (file_exists($tablesFile)) {
    $content = file_get_contents($tablesFile);
    echo "<p>Inhalt (" . filesize($tablesFile) . " Bytes):</p>";
    echo "<pre>" . htmlspecialchars($content) . "</pre>";

    $tables = json_decode($content, true);
    echo "<p>Als Array:</p>";
    echo "<pre>" . print_r($tables, true) . "</pre>";
} else {
    echo "<p>Datei existiert nicht!</p>";

    // Versuche, eine neue tables.json zu erstellen
    echo "<p>Erstelle neue tables.json...</p>";
    $result = file_put_contents($tablesFile, json_encode([]));
    echo "<p>Ergebnis: " . ($result !== false ? "Erfolgreich ({$result} Bytes)" : "Fehlgeschlagen") . "</p>";
}

// 4. Prüfe alle Tabellendateien
echo "<h2>Tabellendateien</h2>";
$tableFiles = glob($currentDataDir . "/*.json");
$tableNames = [];

foreach ($tableFiles as $file) {
    $fileName = basename($file);
    if ($fileName === 'tables.json')
        continue;

    $isMetaFile = strpos($fileName, '_meta.json') !== false;
    $tableName = $isMetaFile ? str_replace('_meta.json', '', $fileName) : str_replace('.json', '', $fileName);

    if (!in_array($tableName, $tableNames)) {
        $tableNames[] = $tableName;
    }
}

echo "<ul>";
foreach ($tableNames as $tableName) {
    echo "<li>{$tableName}</li>";

    $tableFile = $currentDataDir . "/{$tableName}.json";
    $metaFile = $currentDataDir . "/{$tableName}_meta.json";

    echo "<ul>";
    echo "<li>Datendatei: " . (file_exists($tableFile) ? "Existiert (" . filesize($tableFile) . " Bytes)" : "Fehlt") . "</li>";
    echo "<li>Metadatei: " . (file_exists($metaFile) ? "Existiert (" . filesize($metaFile) . " Bytes)" : "Fehlt") . "</li>";
    echo "</ul>";
}
echo "</ul>";

// 5. Tables.json reparieren
echo "<h2>Repairing tables.json</h2>";
$tablesInJson = [];
if (file_exists($tablesFile)) {
    $content = file_get_contents($tablesFile);
    $tablesInJson = !empty($content) ? json_decode($content, true) : [];
    if (!is_array($tablesInJson))
        $tablesInJson = [];
}

$missingTables = array_diff($tableNames, $tablesInJson);
$extraTables = array_diff($tablesInJson, $tableNames);

echo "<p>Fehlende Tabellen in tables.json: " . implode(", ", $missingTables) . "</p>";
echo "<p>Überschüssige Tabellen in tables.json: " . implode(", ", $extraTables) . "</p>";

if (!empty($missingTables) || !empty($extraTables)) {
    echo "<p>Repariere tables.json...</p>";
    $result = file_put_contents($tablesFile, json_encode($tableNames));
    echo "<p>Ergebnis: " . ($result !== false ? "Erfolgreich ({$result} Bytes)" : "Fehlgeschlagen") . "</p>";
}

// 6. Vergleich mit getAllTables
echo "<h2>Vergleich mit getAllTables()</h2>";
$tablesFromFunction = getAllTables($db);
echo "<pre>" . print_r($tablesFromFunction, true) . "</pre>";
?>

<p><a href="index.php?tab=tables" class="btn btn-primary">Zurück zur Tabellenansicht</a></p>