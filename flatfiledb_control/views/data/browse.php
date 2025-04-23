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

$schemaInfo = null;
$hasSchema = false;

if (isset($handler) && $handler instanceof FlatFileDB\FlatFileDatabaseHandler && !empty($activeTable)) {
    try {
        if (function_exists('getTableSchema')) {
            $schemaInfo = getTableSchema($handler, $activeTable);
            $hasSchema = is_array($schemaInfo) && (
                (isset($schemaInfo['types']) && !empty($schemaInfo['types'])) ||
                (isset($schemaInfo['required']) && !empty($schemaInfo['required']))
            );
        } else {
             error_log("Funktion getTableSchema() nicht gefunden in browse.php");
        }
    } catch (Exception $e) {
        error_log("Fehler beim Abrufen des Schemas für Tabelle '$activeTable' in browse.php: " . $e->getMessage());
    }
}

?>

<div class="card">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h4 class="mb-0 d-inline-block me-2">Datensätze: <?php echo htmlspecialchars($activeTable); ?></h4>

            <?php // --- Vereinfachte Schema-Status Anzeige --- ?>
            <?php if ($hasSchema): ?>
                <span class="badge bg-info text-dark me-1" title="Ein Schema ist für diese Tabelle definiert.">
                    <i class="bi bi-check-circle-fill"></i> Schema aktiv
                </span>
                <a href="index.php?tab=tables&table=<?php echo htmlspecialchars($activeTable); ?>&action=manage#schema-tab" class="badge bg-light text-dark text-decoration-none" title="Schema anzeigen/bearbeiten">
                    <i class="bi bi-pencil-square"></i> Verwalten
                </a>
            <?php else: // Wenn !$hasSchema (egal ob null, leer oder Fehler beim Laden) ?>
                 <span class="badge bg-secondary me-1" title="Für diese Tabelle ist kein Schema definiert.">
                     <i class="bi bi-slash-circle"></i> Kein Schema
                 </span>
                 <a href="index.php?tab=tables&table=<?php echo htmlspecialchars($activeTable); ?>&action=manage#schema-tab" class="badge bg-light text-dark text-decoration-none" title="Schema definieren">
                      <i class="bi bi-plus-circle"></i> Definieren
                 </a>
            <?php endif; ?>
            <?php // --- Ende Vereinfachung --- ?>

        </div>
        <a href="index.php?tab=tables&table=<?php echo htmlspecialchars($activeTable); ?>&action=insert" class="btn btn-success btn-sm mt-1 mt-md-0">
            <i class="bi bi-plus-circle"></i> Neuer Datensatz
        </a>
    </div>
    <div class="card-body">
        <?php if (empty($records)): ?>
             <div class="alert alert-info">
                Diese Tabelle enthält keine Datensätze.
                <?php if (isset($handler)): ?>
                <a href="index.php?tab=tables&table=<?php echo htmlspecialchars($activeTable); ?>&action=insert" class="alert-link">Fügen Sie den ersten Datensatz hinzu</a>.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                 <table class="table table-hover table-sm" id="dataTable">
                    <thead>
                        <tr>
                            <?php foreach ($columns as $colName): ?>
                                <th>
                                    <a href="index.php?tab=tables&table=<?php echo htmlspecialchars($activeTable); ?>&orderBy=<?php echo htmlspecialchars($colName); ?>&order=<?php echo $orderBy == $colName && $order == 'ASC' ? 'DESC' : 'ASC'; ?>">
                                        <?php echo htmlspecialchars($colName); ?>

                                        <?php
                                        // --- Schema-Info direkt anzeigen ---
                                        $colType = null;
                                        $colRequired = false;

                                        // Nur auf das Schema zugreifen, wenn es ein Array ist
                                        if (is_array($schemaInfo)) {
                                            if (isset($schemaInfo['types']) && is_array($schemaInfo['types'])) {
                                                $colType = $schemaInfo['types'][$colName] ?? null;
                                            }
                                            if (isset($schemaInfo['required']) && is_array($schemaInfo['required'])) {
                                                $colRequired = in_array($colName, $schemaInfo['required']);
                                            }
                                        }

                                        // Direkte Anzeige von Typ und Pflichtfeld-Status
                                        if ($colRequired) {
                                            echo ' <span class="text-danger" title="Pflichtfeld">*</span>';
                                        }
                                        if ($colType) {
                                            echo ' <span class="text-muted ms-1" style="font-size: 0.8em; font-weight: normal;">(' . htmlspecialchars($colType) . ')</span>';
                                        }
                                        // --- Ende Direkte Anzeige ---
                                        ?>

                                        <?php // Sortier-Icon anzeigen
                                        if ($orderBy == $colName): ?>
                                            <i class="bi bi-caret-<?php echo $order == 'ASC' ? 'up' : 'down'; ?>-fill ms-1"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                            <?php endforeach; ?>
                            <th style="width: 80px;">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php // --- Tbody bleibt wie vorher (mit optimierter Anzeige) --- ?>
                        <?php foreach ($records as $record): ?>
                        <tr data-id="<?php echo htmlspecialchars($record['id']); ?>">
                            <?php foreach ($columns as $colName): ?>
                            <td class="editable align-middle" data-field="<?php echo htmlspecialchars($colName); ?>">
                                <?php
                                $displayValue = '';
                                $currentValue = $record[$colName] ?? null;

                                if ($colName === 'id') {
                                    $displayValue = htmlspecialchars($currentValue);
                                } else {
                                    if (is_array($currentValue) || is_object($currentValue)) {
                                        $jsonValue = json_encode($currentValue, JSON_PRETTY_PRINT);
                                        $preview = mb_substr($jsonValue, 0, 50);
                                        $displayValue = '<pre class="mb-0 p-1 bg-light border rounded" title="' . htmlspecialchars($jsonValue) . '" style="font-size: 0.8em; max-height: 5em; overflow:hidden;">'
                                                        . htmlspecialchars($preview) . (mb_strlen($jsonValue) > 50 ? '...' : '')
                                                        . '</pre>';
                                    } else if (is_bool($currentValue)) {
                                        $displayValue = $currentValue
                                            ? '<span class="badge bg-success"><i class="bi bi-check-lg"></i></span>'
                                            : '<span class="badge bg-danger"><i class="bi bi-x-lg"></i></span>';
                                    } else if ($currentValue === null) {
                                        $displayValue = '<em class="text-muted">null</em>';
                                    } else {
                                        $stringValue = (string)$currentValue;
                                        if (mb_strlen($stringValue) > 100) {
                                             $displayValue = '<span title="'.htmlspecialchars($stringValue).'">' . htmlspecialchars(mb_substr($stringValue, 0, 100)) . '...</span>';
                                        } else {
                                             $displayValue = htmlspecialchars($stringValue);
                                        }
                                    }
                                }
                                echo $displayValue;
                                ?>
                            </td>
                            <?php endforeach; ?>
                            <td class="align-middle">
                                <div class="btn-group btn-group-sm">
                                    <a href="index.php?tab=tables&table=<?php echo htmlspecialchars($activeTable); ?>&action=edit&id=<?php echo htmlspecialchars($record['id']); ?>" class="btn btn-outline-primary" title="Bearbeiten">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button type="button" class="btn btn-outline-danger delete-record" data-id="<?php echo htmlspecialchars($record['id']); ?>" title="Löschen">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                         <?php // --- Ende Tbody --- ?>
                    </tbody>
                </table>
            </div>
             <?php // ... Paginierung ... ?>
             <div class="text-center text-muted mt-2" style="font-size: 0.9em;">
                Zeige <?php echo count($records); ?> von <?php echo $totalRecords; ?> Datensätzen
                (Seite <?php echo $page; ?> von <?php echo $totalPages; ?>)
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