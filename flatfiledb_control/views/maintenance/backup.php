<?php
$tables = getAllTables($db);
?>

<div class="card">
    <div class="card-header bg-primary text-white">
        <h4 class="mb-0">Datenbank-Backup</h4>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <h5>Über Backups</h5>
            <p>
                Backups sichern den aktuellen Zustand Ihrer Daten und ermöglichen die Wiederherstellung 
                im Falle von Datenverlusten oder Beschädigungen.
            </p>
            <p class="mb-0">
                <strong>Backup-Verzeichnis:</strong> <?php echo htmlspecialchars(BACKUP_DIR); ?>
            </p>
        </div>
        
        <div class="mb-4">
            <h5>Einzelne Tabelle sichern</h5>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Tabelle</th>
                            <th>Datensätze</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tables as $table): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($table['name']); ?></td>
                            <td>
                                <?php if (isset($table['error'])): ?>
                                <span class="text-danger"><?php echo htmlspecialchars($table['error']); ?></span>
                                <?php else: ?>
                                <?php echo number_format($table['record_count']); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-primary btn-sm" 
                                       onclick="maintenance.backupTable('<?php echo htmlspecialchars($table['name']); ?>')">
                                    Sichern
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="mb-4">
            <h5>Gesamte Datenbank sichern</h5>
            <p>
                Erstellt ein Backup aller Tabellen in der Datenbank.
                Dies umfasst Daten, Indizes und Transaktionslogs.
            </p>
            <button type="button" class="btn btn-primary" onclick="maintenance.backupAllTables()">
                Gesamte Datenbank sichern
            </button>
        </div>
        
        <div class="mb-4">
            <h5>Cache-Verwaltung</h5>
            <p>
                Die Flatfile-Datenbank nutzt einen In-Memory-Cache, um häufig abgerufene Daten zu speichern.
                Das Leeren des Caches kann bei Speicherproblemen oder vermuteten Inkonsistenzen helfen.
            </p>
            <button type="button" class="btn btn-info" onclick="maintenance.clearAllCaches()">
                Alle Caches leeren
            </button>
        </div>
    </div>
</div>