<?php
// Schema für Feldvalidierung
$table = $db->table($activeTable);
$schema = getTableSchemaFromTable($table);

// Wenn kein Schema definiert ist, mit leeren Standard beginnen
if (empty($schema)) {
    // Sample-Felder aus bestehenden Datensätzen extrahieren
    $fields = [];
    $records = $handler->table($activeTable)->limit(1)->find();
    if (!empty($records)) {
        foreach ($records[0] as $field => $value) {
            if ($field !== 'id') {
                $fields[$field] = $value;
            }
        }
    }
} else {
    // Felder aus Schema vorbereiten
    $fields = [];
    if (isset($schema['types'])) {
        foreach ($schema['types'] as $field => $type) {
            // Standardwert basierend auf Typ
            if (strpos($type, 'int') !== false) {
                $fields[$field] = 0;
            } else if (strpos($type, 'float') !== false) {
                $fields[$field] = 0.0;
            } else if (strpos($type, 'bool') !== false) {
                $fields[$field] = false;
            } else if (strpos($type, 'array') !== false) {
                $fields[$field] = [];
            } else {
                $fields[$field] = '';
            }
            
            // Nullable Typen können null sein
            if (strpos($type, '?') === 0) {
                $fields[$field] = null;
            }
        }
    }
}
?>

<div class="card">
    <div class="card-header bg-primary text-white">
        <h4 class="mb-0">Neuen Datensatz erstellen: <?php echo htmlspecialchars($activeTable); ?></h4>
    </div>
    <div class="card-body">
        <form id="insertRecordForm">
            <input type="hidden" name="tableName" value="<?php echo htmlspecialchars($activeTable); ?>">
            
            <?php foreach ($fields as $field => $value): ?>
            <div class="mb-3">
                <label for="field_<?php echo htmlspecialchars($field); ?>" class="form-label">
                    <?php echo htmlspecialchars($field); ?>
                    <?php if (isset($schema['required']) && in_array($field, $schema['required'])): ?>
                    <span class="text-danger">*</span>
                    <?php endif; ?>
                </label>
                
                <?php 
                $type = 'text';
                $fieldType = isset($schema['types'][$field]) ? $schema['types'][$field] : null;
                
                if ($fieldType === 'bool' || $fieldType === '?bool' || is_bool($value)) {
                    // Boolean als Checkbox
                    echo '<div class="form-check">';
                    echo '<input class="form-check-input" type="checkbox" value="1" id="field_' . htmlspecialchars($field) . '" name="fields[' . htmlspecialchars($field) . ']">';
                    echo '<label class="form-check-label" for="field_' . htmlspecialchars($field) . '">True/False</label>';
                    echo '</div>';
                } else if ($fieldType === 'array' || $fieldType === '?array' || is_array($value)) {
                    // Array als Textarea mit JSON
                    echo '<textarea class="form-control" id="field_' . htmlspecialchars($field) . '" name="fields[' . htmlspecialchars($field) . ']" rows="5">';
                    echo is_array($value) ? htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT)) : '';
                    echo '</textarea>';
                    echo '<div class="form-text">JSON-Format: ["wert1", "wert2"] oder {"key": "value"}</div>';
                } else {
                    // Textfeld für alles andere
                    if ($fieldType === 'int' || $fieldType === '?int' || $fieldType === 'numeric' || $fieldType === '?numeric') {
                        $type = 'number';
                    }
                    
                    $nullable = strpos($fieldType ?? '', '?') === 0;
                    
                    echo '<input type="' . $type . '" class="form-control" id="field_' . htmlspecialchars($field) . '" 
                          name="fields[' . htmlspecialchars($field) . ']" value="' . htmlspecialchars($value ?? '') . '"';
                    echo isset($schema['required']) && in_array($field, $schema['required']) ? ' required' : '';
                    echo '>';
                    
                    if ($nullable) {
                        echo '<div class="form-text">Kann leer sein (null).</div>';
                    }
                }
                ?>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($fields)): ?>
            <div class="alert alert-info">
                Keine Felder definiert. Sie können ein Schema in der Tabellenverwaltung festlegen, um Feldstrukturen zu definieren.
            </div>
            <div class="mb-3">
                <button type="button" class="btn btn-info" id="addCustomFieldBtn">Benutzerdefiniertes Feld hinzufügen</button>
            </div>
            <div id="customFieldsContainer">
                <!-- Hier werden benutzerdefinierte Felder dynamisch hinzugefügt -->
            </div>
            <?php endif; ?>
            
            <div class="d-flex justify-content-between">
                <a href="index.php?tab=tables&table=<?php echo htmlspecialchars($activeTable); ?>" class="btn btn-secondary">Zurück</a>
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- Vorlage für benutzerdefinierte Felder -->
<template id="customFieldTemplate">
    <div class="card mb-3 custom-field">
        <div class="card-body">
            <div class="row">
                <div class="col-md-5 mb-2">
                    <label class="form-label">Feldname</label>
                    <input type="text" class="form-control custom-field-name" required>
                </div>
                <div class="col-md-5 mb-2">
                    <label class="form-label">Feldwert</label>
                    <input type="text" class="form-control custom-field-value">
                </div>
                <div class="col-md-2 d-flex align-items-end mb-2">
                    <button type="button" class="btn btn-danger remove-custom-field">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>