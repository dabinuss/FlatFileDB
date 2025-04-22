<?php
$selectedTable = !empty($activeTable) ? $activeTable : (isset($_GET['selected_table']) ? $_GET['selected_table'] : '');
$tableNames = $db->getTableNames();
?>

<div class="card">
    <div class="card-header bg-primary text-white">
        <h4 class="mb-0">Transaktionslogs</h4>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <h5>Über Transaktionslogs</h5>
            <p>
                Transaktionslogs zeichnen alle Änderungen an Tabellen auf, einschließlich Einfüge-, 
                Aktualisierungs- und Löschoperationen. Sie helfen bei der Fehlerbehebung und bieten 
                einen Audit-Trail für Datenbankaktivitäten.
            </p>
        </div>
        
        <div class="mb-4">
            <h5>Tabelle auswählen</h5>
            <form id="selectTableForm" class="mb-3">
                <div class="row g-3">
                    <div class="col-md-8">
                        <select class="form-select" id="logTableSelect" name="selected_table">
                            <option value="">-- Tabelle wählen --</option>
                            <?php foreach ($tableNames as $tableName): ?>
                            <option value="<?php echo htmlspecialchars($tableName); ?>" 
                                    <?php echo $selectedTable === $tableName ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tableName); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary">Logs anzeigen</button>
                    </div>
                </div>
            </form>
        </div>
        
        <?php if (!empty($selectedTable)): ?>
        <div id="logContainer" data-table="<?php echo htmlspecialchars($selectedTable); ?>">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5>Logs für Tabelle: <?php echo htmlspecialchars($selectedTable); ?></h5>
                <div class="btn-group">
                    <button type="button" class="btn btn-info btn-sm" id="refreshLogsBtn">
                        <i class="bi bi-arrow-clockwise"></i> Aktualisieren
                    </button>
                    <button type="button" class="btn btn-warning btn-sm" id="rotateLogBtn">
                        Log rotieren
                    </button>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-striped table-sm" id="logTable">
                    <thead>
                        <tr>
                            <th>Zeitstempel</th>
                            <th>Operation</th>
                            <th>Datensatz-ID</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody id="logTableBody">
                        <tr>
                            <td colspan="4" class="text-center">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Wird geladen...</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-secondary">
            Bitte wählen Sie eine Tabelle aus, um deren Transaktionslogs anzuzeigen.
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modales Fenster für Log-Details -->
<div class="modal fade" id="logDetailsModal" tabindex="-1" aria-labelledby="logDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="logDetailsModalLabel">Log-Eintrag Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <pre id="logDetailsContent" class="bg-light p-3" style="max-height: 400px; overflow-y: auto;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>