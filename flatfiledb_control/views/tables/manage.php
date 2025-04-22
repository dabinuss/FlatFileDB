<?php
if (!isset($db) || !$db instanceof FlatFileDB\FlatFileDatabase) {
    echo '<div class="alert alert-danger">Fehler: DB-Objekt nicht verfügbar.</div>';
    exit; // Oder andere Fehlerbehandlung
}
// Handler sollte aus init.php/index.php verfügbar sein
if (!isset($handler) || !$handler instanceof FlatFileDB\FlatFileDatabaseHandler) {
    echo '<div class="alert alert-danger">Fehler: Handler-Objekt nicht verfügbar.</div>';
    exit; // Oder andere Fehlerbehandlung
}
$schema = getTableSchema($handler, $activeTable); // <-- Korrigierter Aufruf für Schema
// Indizes weiterhin über die Engine holen
try {
   $tableEngine = $db->table($activeTable); // Engine holen
   $indexes = getTableIndexNames($tableEngine); // <-- Engine übergeben
} catch (Exception $e) {
   error_log("Fehler beim Holen der Engine für Index-Abruf ($activeTable): " . $e->getMessage());
   $indexes = []; // Fallback
   echo '<div class="alert alert-warning">Fehler beim Abrufen der Indexinformationen: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// Alle Felder aus Datensätzen extrahieren (Handler verwenden)
$fields = [];
try {
   $records = $handler->table($activeTable)->limit(10)->find(); // Handler verwenden
   foreach ($records as $record) {
       foreach ($record as $field => $value) {
           if ($field !== 'id' && !in_array($field, $fields)) {
               $fields[] = $field;
           }
       }
   }
   sort($fields);
} catch (Exception $e) {
    error_log("Fehler beim Holen von Sample-Records für Feldliste ($activeTable): " . $e->getMessage());
    echo '<div class="alert alert-warning">Fehler beim Ermitteln der Felder aus Datensätzen: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>

<div class="card">
    <div class="card-header bg-primary text-white">
        <h4 class="mb-0">Tabelle verwalten: <?php echo htmlspecialchars($activeTable); ?></h4>
    </div>
    <div class="card-body">
        <ul class="nav nav-tabs" id="manageTableTabs">
            <li class="nav-item">
                <a class="nav-link active" id="schema-tab" data-bs-toggle="tab" href="#schema">Schema</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="indexes-tab" data-bs-toggle="tab" href="#indexes">Indizes</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="maintenance-tab" data-bs-toggle="tab" href="#maintenance">Wartung</a>
            </li>
        </ul>
        
        <div class="tab-content p-3 border border-top-0 rounded-bottom">
            <!-- Schema-Tab -->
            <div class="tab-pane fade show active" id="schema">
                <form id="schemaForm">
                    <input type="hidden" name="tableName" value="<?php echo htmlspecialchars($activeTable); ?>">
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5>Schema definieren</h5>
                            
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="enableSchema" 
                                       <?php echo !empty($schema) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="enableSchema">Schema aktivieren</label>
                            </div>
                        </div>
                        
                        <div class="form-text">
                            Ein Schema hilft, die Datenintegrität zu wahren, indem es Felder und Typen definiert.
                        </div>
                    </div>
                    
                    <div id="schemaFields" <?php echo empty($schema) ? 'style="display:none;"' : ''; ?>>
                        <div class="mb-3">
                            <button type="button" class="btn btn-info btn-sm" id="addSchemaFieldBtn">
                                Feld hinzufügen
                            </button>
                        </div>
                        
                        <div id="schemaFieldsContainer">
                            <?php if (!empty($schema) && isset($schema['types'])): ?>
                                <?php foreach ($schema['types'] as $field => $type): ?>
                                    <div class="card mb-2 schema-field-row">
                                        <div class="card-body">
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label class="form-label">Feldname</label>
                                                    <input type="text" class="form-control schema-field-name" 
                                                           value="<?php echo htmlspecialchars($field); ?>" required>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">Typ</label>
                                                    <select class="form-select schema-field-type">
                                                        <option value="string" <?php echo $type === 'string' ? 'selected' : ''; ?>>String</option>
                                                        <option value="?string" <?php echo $type === '?string' ? 'selected' : ''; ?>>Nullable String</option>
                                                        <option value="int" <?php echo $type === 'int' ? 'selected' : ''; ?>>Integer</option>
                                                        <option value="?int" <?php echo $type === '?int' ? 'selected' : ''; ?>>Nullable Integer</option>
                                                        <option value="float" <?php echo $type === 'float' ? 'selected' : ''; ?>>Float</option>
                                                        <option value="?float" <?php echo $type === '?float' ? 'selected' : ''; ?>>Nullable Float</option>
                                                        <option value="bool" <?php echo $type === 'bool' ? 'selected' : ''; ?>>Boolean</option>
                                                        <option value="?bool" <?php echo $type === '?bool' ? 'selected' : ''; ?>>Nullable Boolean</option>
                                                        <option value="array" <?php echo $type === 'array' ? 'selected' : ''; ?>>Array</option>
                                                        <option value="?array" <?php echo $type === '?array' ? 'selected' : ''; ?>>Nullable Array</option>
                                                        <option value="numeric" <?php echo $type === 'numeric' ? 'selected' : ''; ?>>Numeric</option>
                                                        <option value="?numeric" <?php echo $type === '?numeric' ? 'selected' : ''; ?>>Nullable Numeric</option>
                                                        <option value="scalar" <?php echo $type === 'scalar' ? 'selected' : ''; ?>>Scalar</option>
                                                        <option value="?scalar" <?php echo $type === '?scalar' ? 'selected' : ''; ?>>Nullable Scalar</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label">Optionen</label>
                                                    <div class="form-check mt-2">
                                                        <input class="form-check-input schema-field-required" type="checkbox"
                                                               <?php echo isset($schema['required']) && in_array($field, $schema['required']) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label">Pflichtfeld</label>
                                                    </div>
                                                </div>
                                                <div class="col-md-1 d-flex align-items-end">
                                                    <button type="button" class="btn btn-danger remove-schema-field-btn">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary" id="saveSchemaBtn">Schema speichern</button>
                    </div>
                </form>
            </div>
            
            <!-- Indizes-Tab -->
            <div class="tab-pane fade" id="indexes">
                <div class="mb-3">
                    <h5>Indizes verwalten</h5>
                    <p class="form-text">
                        Indizes beschleunigen Abfragen auf häufig verwendeten Feldern. Sie werden automatisch 
                        aktualisiert, wenn sich Datensätze ändern.
                    </p>
                </div>
                
                <div class="table-responsive mb-3">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Feldname</th>
                                <th>Indiziert</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fields as $field): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($field); ?></td>
                                <td>
                                    <?php if (in_array($field, $indexes)): ?>
                                    <span class="badge bg-success">Ja</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Nein</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (in_array($field, $indexes)): ?>
                                    <button type="button" class="btn btn-danger btn-sm"
                                            onclick="tableManager.dropIndex('<?php echo htmlspecialchars($activeTable); ?>', '<?php echo htmlspecialchars($field); ?>')">
                                        Index entfernen
                                    </button>
                                    <?php else: ?>
                                    <button type="button" class="btn btn-success btn-sm"
                                            onclick="tableManager.createIndex('<?php echo htmlspecialchars($activeTable); ?>', '<?php echo htmlspecialchars($field); ?>')">
                                        Index erstellen
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Wartungs-Tab -->
            <div class="tab-pane fade" id="maintenance">
                <div class="mb-4">
                    <h5>Tabelle kompaktieren</h5>
                    <p>
                        Kompaktieren entfernt gelöschte Datensätze physisch und optimiert die Speichernutzung.
                        Dies sollte regelmäßig durchgeführt werden, besonders nach vielen Änderungen.
                    </p>
                    <button type="button" class="btn btn-warning" 
                            onclick="maintenance.compactTable('<?php echo htmlspecialchars($activeTable); ?>')">
                        Tabelle kompaktieren
                    </button>
                </div>
                
                <div class="mb-4">
                    <h5>Cache leeren</h5>
                    <p>
                        Leert den Speicher-Cache dieser Tabelle. Nützlich, wenn Sie Inkonsistenzen vermuten.
                    </p>
                    <button type="button" class="btn btn-info" 
                            onclick="maintenance.clearCache('<?php echo htmlspecialchars($activeTable); ?>')">
                        Cache leeren
                    </button>
                </div>
                
                <div class="mb-4">
                    <h5>Tabelle sichern</h5>
                    <p>
                        Erstellt ein Backup dieser Tabelle im konfigurierten Backup-Verzeichnis.
                    </p>
                    <button type="button" class="btn btn-primary" 
                            onclick="maintenance.backupTable('<?php echo htmlspecialchars($activeTable); ?>')">
                        Backup erstellen
                    </button>
                </div>
                
                <div class="mb-4">
                    <h5>Gefährliche Aktionen</h5>
                    <div class="card border-danger">
                        <div class="card-body">
                            <h6 class="card-title text-danger">Tabelle leeren</h6>
                            <p class="card-text">
                                Löscht <strong>ALLE DATEN</strong> in dieser Tabelle. Diese Aktion kann nicht
                                rückgängig gemacht werden!
                            </p>
                            <button type="button" class="btn btn-danger" 
                                    onclick="if(confirm('WARNUNG: Möchten Sie wirklich ALLE DATEN in dieser Tabelle unwiderruflich löschen?')) tableManager.clearTable('<?php echo htmlspecialchars($activeTable); ?>')">
                                Tabelle leeren
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Feldvorlage für Schema (wird geklont) -->
<template id="schemaFieldTemplate">
    <div class="card mb-2 schema-field-row">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Feldname</label>
                    <input type="text" class="form-control schema-field-name" required pattern="[a-zA-Z0-9_]+">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Typ</label>
                    <select class="form-select schema-field-type">
                        <option value="string">String</option>
                        <option value="?string">Nullable String</option>
                        <option value="int">Integer</option>
                        <option value="?int">Nullable Integer</option>
                        <option value="float">Float</option>
                        <option value="?float">Nullable Float</option>
                        <option value="bool">Boolean</option>
                        <option value="?bool">Nullable Boolean</option>
                        <option value="array">Array</option>
                        <option value="?array">Nullable Array</option>
                        <option value="numeric">Numeric</option>
                        <option value="?numeric">Nullable Numeric</option>
                        <option value="scalar">Scalar</option>
                        <option value="?scalar">Nullable Scalar</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Optionen</label>
                    <div class="form-check mt-2">
                        <input class="form-check-input schema-field-required" type="checkbox">
                        <label class="form-check-label">Pflichtfeld</label>
                    </div>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="button" class="btn btn-danger remove-schema-field-btn">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>