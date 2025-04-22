<?php
error_log(">>> DEBUG list.php START: \$db type = " . (isset($db) ? (is_object($db) ? get_class($db) : gettype($db)) : 'Not Set'));

// Stelle sicher, dass $db, $stats, und $handler im aktuellen Scope verfügbar sind (aus index.php)
if (!isset($db) || !isset($stats) || !isset($handler)) {
    echo '<div class="alert alert-danger">Fehler: Datenbankobjekte sind nicht verfügbar. Die Datenbank konnte möglicherweise nicht initialisiert werden. Bitte Server-Logs prüfen.</div>';
    // Evtl. hier abbrechen oder Error-Log schreiben
    error_log("Fehler in views/tables/list.php: Globale DB/Stats/Handler Objekte fehlen.");
    $tables = []; // Leeres Array als Fallback
} else {
    // Übergebe alle benötigten Objekte explizit
    error_log(">>> DEBUG list.php: Rufe getAllTables auf mit gültigen Objekten.");
    $tables = getAllTables($db, $stats, $handler); // <-- Aufruf bleibt gleich, aber Objekte sind geprüft
    error_log(">>> DEBUG list.php: getAllTables zurückgegeben: " . count($tables) . " Tabellen.");
}
?>

<div class="card">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h4 class="mb-0">Verfügbare Tabellen (<?php echo htmlspecialchars($GLOBALS['currentDb'] ?? 'Keine DB'); ?>)</h4>
        <!-- DB-Namen anzeigen -->
        <!-- Button zum Erstellen ist jetzt in der Sidebar -->
        <!-- <a href="index.php?tab=tables&action=create" class="btn btn-light btn-sm">
            <i class="bi bi-plus-circle"></i> Neue Tabelle
         </a> -->
    </div>
    <div class="card-body">
        <?php if (empty($tables)): ?>
            <div class="alert alert-info">
                <?php if (!isset($db)): ?>
                    Keine Datenbank ausgewählt oder initialisiert.
                <?php else: ?>
                    Keine Tabellen in dieser Datenbank vorhanden. <a href="index.php?tab=tables&action=create">Erstellen Sie
                        eine neue Tabelle</a>.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Erstellt am</th>
                            <th>Spalten</th>
                            <th>Datensätze</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tables as $table): ?>
                            <tr>
                                <td>
                                    <a href="index.php?tab=tables&table=<?php echo urlencode($table['name']); ?>">
                                        <?php echo htmlspecialchars($table['name']); ?>
                                    </a>
                                </td>
                                <!-- Verwende die korrekten Keys aus getAllTables -->
                                <td><?php echo htmlspecialchars($table['created'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($table['columns'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format((int) ($table['records'] ?? 0)); ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="index.php?tab=tables&table=<?php echo urlencode($table['name']); ?>"
                                            class="btn btn-primary" title="Öffnen">
                                            <i class="bi bi-folder2-open"></i>
                                        </a>
                                        <a href="index.php?tab=tables&table=<?php echo urlencode($table['name']); ?>&action=manage"
                                            class="btn btn-secondary" title="Verwalten">
                                            <i class="bi bi-gear"></i>
                                        </a>
                                        <button type="button" class="btn btn-danger delete-table" title="Löschen"
                                            data-table="<?php echo htmlspecialchars($table['name']); ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal für Tabellenerstellung wird nicht mehr von dieser View direkt getriggert -->

<script>
    // Tabellenlöschung (Event-Delegation für bessere Performance und Flexibilität)
    document.addEventListener('click', function (event) {
        const deleteButton = event.target.closest('.delete-table');
        if (deleteButton) {
            const tableName = deleteButton.dataset.table;
            if (confirm('Möchten Sie die Tabelle "' + tableName + '" wirklich löschen? Alle Daten gehen verloren!')) {
                if (typeof tableManager !== 'undefined') {
                    tableManager.deleteTable(tableName);
                } else {
                    console.error('tableManager ist nicht definiert.');
                    // Hier könnte ein direkter AJAX Call als Fallback stehen
                    // app.ajax('api/table.php', { action: 'delete', table: tableName }, ...);
                }
            }
        }
    });
</script>