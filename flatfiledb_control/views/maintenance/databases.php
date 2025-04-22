<?php
$databases = getAllDatabases();
?>

<div class="card">
    <div class="card-header bg-primary text-white">
        <h4 class="mb-0">Datenbankverwaltung</h4>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <h5>Über Datenbankverwaltung</h5>
            <p>
                Hier können Sie neue Datenbanken erstellen oder bestehende Datenbanken verwalten.
                Eine Datenbank in FlatFileDB ist ein Verzeichnis, das Tabellendateien, Indizes und Logs enthält.
            </p>
            <p class="mb-0">
                <strong>Datenverzeichnis:</strong> <?php echo htmlspecialchars(DATA_DIR); ?>
            </p>
        </div>
        
        <div class="mb-4">
            <h5>Neue Datenbank erstellen</h5>
            <form id="createDatabaseForm" class="row g-3">
                <div class="col-md-8">
                    <input type="text" class="form-control" id="newDatabaseName" placeholder="Datenbankname" required
                           pattern="[a-zA-Z0-9_]+" title="Nur Buchstaben, Zahlen und Unterstriche erlaubt">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-success">Datenbank erstellen</button>
                </div>
            </form>
        </div>
        
        <h5>Verfügbare Datenbanken</h5>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Erstellt am</th>
                        <th>Tabellen</th>
                        <th>Größe</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($databases)): ?>
                    <tr>
                        <td colspan="5" class="text-center">Keine Datenbanken vorhanden</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($databases as $db): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($db['name']); ?></td>
                            <td><?php echo htmlspecialchars($db['created']); ?></td>
                            <td><?php echo number_format($db['tables']); ?></td>
                            <td><?php echo htmlspecialchars($db['size']); ?></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-info backup-db-btn"
                                            data-db-name="<?php echo htmlspecialchars($db['name']); ?>">
                                        Backup
                                    </button>
                                    <button type="button" class="btn btn-danger delete-db-btn"
                                            data-db-name="<?php echo htmlspecialchars($db['name']); ?>">
                                        Löschen
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Event-Handler für Formular zum Erstellen einer Datenbank
    const createDatabaseForm = document.getElementById('createDatabaseForm');
    if (createDatabaseForm) {
        createDatabaseForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const dbName = document.getElementById('newDatabaseName').value.trim();
            if (!dbName) {
                app.showStatus('Bitte geben Sie einen Datenbanknamen ein.', 'error');
                return;
            }
            
            app.ajax('api/database.php', {
                action: 'create',
                name: dbName
            }, function(success) {
                if (success) {
                    app.reloadPage();
                }
            });
        });
    }
    
    // Event-Handler für Backup-Buttons
    const backupButtons = document.querySelectorAll('.backup-db-btn');
    backupButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            const dbName = this.dataset.dbName;
            
            app.ajax('api/database.php', {
                action: 'backup',
                name: dbName
            }, function(success) {
                if (success) {
                    app.showStatus('Backup erfolgreich erstellt.', 'success');
                }
            });
        });
    });
    
    // Event-Handler für Lösch-Buttons
    const deleteButtons = document.querySelectorAll('.delete-db-btn');
    deleteButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            const dbName = this.dataset.dbName;
            
            if (confirm('Möchten Sie die Datenbank "' + dbName + '" wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden!')) {
                app.ajax('api/database.php', {
                    action: 'delete',
                    name: dbName
                }, function(success) {
                    if (success) {
                        app.reloadPage();
                    }
                });
            }
        });
    });
});
</script>