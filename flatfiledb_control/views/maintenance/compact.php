<?php
$tables = getAllTables($db);
?>

<div class="card">
    <div class="card-header bg-primary text-white">
        <h4 class="mb-0">Tabellen kompaktieren</h4>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <h5>Über die Kompaktierung</h5>
            <p>
                Die Kompaktierung entfernt physisch gelöschte Datensätze aus der Datenbank und optimiert die Speichernutzung.
                Es empfiehlt sich, Tabellen regelmäßig zu kompaktieren, besonders nach vielen Lösch- oder Aktualisierungsvorgängen.
            </p>
            <p class="mb-0">
                <strong>Wichtig:</strong> Stellen Sie sicher, dass Sie vor der Kompaktierung ein Backup erstellen, 
                da dieser Vorgang nicht rückgängig gemacht werden kann.
            </p>
        </div>
        
        <div class="mb-4">
            <h5>Einzelne Tabelle kompaktieren</h5>
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
                                <button type="button" class="btn btn-warning btn-sm" 
                                       onclick="maintenance.compactTable('<?php echo htmlspecialchars($table['name']); ?>')">
                                    Kompaktieren
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="mb-4">
            <h5>Alle Tabellen kompaktieren</h5>
            <p>
                Kompaktiert alle Tabellen in der Datenbank in einem Durchgang.
                Dieser Vorgang kann bei vielen oder großen Tabellen einige Zeit in Anspruch nehmen.
            </p>
            <button type="button" class="btn btn-warning" onclick="maintenance.compactAllTables()">
                Alle Tabellen kompaktieren
            </button>
        </div>
    </div>
</div>