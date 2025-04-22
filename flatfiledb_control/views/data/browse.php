<?php
// Paginierungsparameter
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$pageSize = PAGE_SIZE;

// Sortierparameter
$orderBy = isset($_GET['orderBy']) ? $_GET['orderBy'] : 'id';
$order = isset($_GET['order']) ? $_GET['order'] : 'ASC';

// Daten abrufen
$data = getTableData($handler, $activeTable, $page, $pageSize, $orderBy, $order);
$records = $data['records'];
$totalPages = $data['totalPages'];
$totalRecords = $data['total'];

// Spalten ermitteln
$columns = [];
if (!empty($records)) {
    foreach ($records[0] as $colName => $value) {
        $columns[] = $colName;
    }
}
?>

<div class="card">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h4 class="mb-0">Datensätze: <?php echo htmlspecialchars($activeTable); ?></h4>
        <a href="index.php?tab=tables&table=<?php echo htmlspecialchars($activeTable); ?>&action=insert" class="btn btn-light btn-sm">
            <i class="bi bi-plus-circle"></i> Neuer Datensatz
        </a>
    </div>
    <div class="card-body">
        <?php if (empty($records)): ?>
        <div class="alert alert-info">
            Diese Tabelle enthält keine Datensätze.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover" id="dataTable">
                <thead>
                    <tr>
                        <?php foreach ($columns as $colName): ?>
                        <th>
                            <a href="index.php?tab=tables&table=<?php echo htmlspecialchars($activeTable); ?>&orderBy=<?php echo htmlspecialchars($colName); ?>&order=<?php echo $orderBy == $colName && $order == 'ASC' ? 'DESC' : 'ASC'; ?>">
                                <?php echo htmlspecialchars($colName); ?>
                                <?php if ($orderBy == $colName): ?>
                                <i class="bi bi-caret-<?php echo $order == 'ASC' ? 'up' : 'down'; ?>-fill"></i>
                                <?php endif; ?>
                            </a>
                        </th>
                        <?php endforeach; ?>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                    <tr data-id="<?php echo $record['id']; ?>">
                        <?php foreach ($columns as $colName): ?>
                        <td class="editable" data-field="<?php echo htmlspecialchars($colName); ?>">
                            <?php 
                            if ($colName === 'id') {
                                echo htmlspecialchars($record[$colName]);
                            } else {
                                if (is_array($record[$colName]) || is_object($record[$colName])) {
                                    echo '<pre>' . htmlspecialchars(json_encode($record[$colName], JSON_PRETTY_PRINT)) . '</pre>';
                                } else if (is_bool($record[$colName])) {
                                    echo $record[$colName] ? 'true' : 'false';
                                } else if ($record[$colName] === null) {
                                    echo '<em>null</em>';
                                } else {
                                    echo htmlspecialchars((string)$record[$colName]);
                                }
                            }
                            ?>
                        </td>
                        <?php endforeach; ?>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="index.php?tab=tables&table=<?php echo htmlspecialchars($activeTable); ?>&action=edit&id=<?php echo $record['id']; ?>" class="btn btn-info">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <button type="button" class="btn btn-danger delete-record" data-id="<?php echo $record['id']; ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Paginierung -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Datensatz-Navigation">
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $page > 1 ? 'index.php?tab=tables&table=' . htmlspecialchars($activeTable) . '&page=' . ($page - 1) . '&orderBy=' . htmlspecialchars($orderBy) . '&order=' . htmlspecialchars($order) : '#'; ?>">
                        Zurück
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $startPage + 4);
                if ($endPage - $startPage < 4 && $startPage > 1) {
                    $startPage = max(1, $endPage - 4);
                }
                
                for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                    <a class="page-link" href="index.php?tab=tables&table=<?php echo htmlspecialchars($activeTable); ?>&page=<?php echo $i; ?>&orderBy=<?php echo htmlspecialchars($orderBy); ?>&order=<?php echo htmlspecialchars($order); ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>
                
                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $page < $totalPages ? 'index.php?tab=tables&table=' . htmlspecialchars($activeTable) . '&page=' . ($page + 1) . '&orderBy=' . htmlspecialchars($orderBy) . '&order=' . htmlspecialchars($order) : '#'; ?>">
                        Weiter
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
        
        <div class="text-center text-muted mt-2">
            Zeige <?php echo count($records); ?> von <?php echo $totalRecords; ?> Datensätzen
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modales Fenster für Inline-Bearbeitung -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel">Feld bearbeiten</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <form id="editFieldForm">
                    <input type="hidden" id="editRecordId">
                    <input type="hidden" id="editFieldName">
                    
                    <div class="mb-3">
                        <label for="editFieldValue" class="form-label">Wert</label>
                        <div id="editFieldValueContainer">
                            <!-- Wird dynamisch ersetzt -->
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                <button type="button" class="btn btn-primary" id="saveFieldBtn">Speichern</button>
            </div>
        </div>
    </div>
</div>