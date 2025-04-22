<?php
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    echo '<div class="alert alert-danger">Ungültige Datensatz-ID</div>';
    exit;
}

// Datensatz abrufen
$record = $handler->table($activeTable)->where('id', '=', $id)->first();

if (!$record) {
    echo '<div class="alert alert-danger">Datensatz nicht gefunden</div>';
    exit;
}

// Schema für Feldvalidierung
$table = $db->table($activeTable);
$schema = getTableSchemaFromTable($table);
?>

<div class="card">
    <div class="card-header bg-primary text-white">
        <h4 class="mb-0">Datensatz bearbeiten: <?php echo htmlspecialchars($activeTable); ?> (ID: <?php echo $id; ?>)</h4>
    </div>
    <div class="card-body">
        <form id="editRecordForm">
            <input type="hidden" name="recordId" value="<?php echo $id; ?>">
            <input type="hidden" name="tableName" value="<?php echo htmlspecialchars($activeTable); ?>">
            
            <div class="mb-3">
                <label class="form-label">ID</label>
                <input type="text" class="form-control" value="<?php echo $id; ?>" disabled>
                <div class="form-text">Die ID kann nicht geändert werden.</div>
            </div>
            
            <?php foreach ($record as $field => $value): ?>
                <?php if ($field !== 'id'): ?>
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
                    
                    if ($fieldType === 'bool' || $fieldType === '?bool') {
                        // Boolean als Checkbox
                        echo '<div class="form-check">';
                        echo '<input class="form-check-input" type="checkbox" value="1" id="field_' . htmlspecialchars($field) . '" name="fields[' . htmlspecialchars($field) . ']"';
                        echo $value ? ' checked' : '';
                        echo '>';
                        echo '<label class="form-check-label" for="field_' . htmlspecialchars($field) . '">True/False</label>';
                        echo '</div>';
                    } else if ($fieldType === 'array' || $fieldType === '?array' || is_array($value)) {
                        // Array als Textarea mit JSON
                        echo '<textarea class="form-control" id="field_' . htmlspecialchars($field) . '" name="fields[' . htmlspecialchars($field) . ']" rows="5">';
                        echo htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT));
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
                <?php endif; ?>
            <?php endforeach; ?>
            
            <div class="d-flex justify-content-between">
                <a href="index.php?tab=tables&table=<?php echo htmlspecialchars($activeTable); ?>" class="btn btn-secondary">Zurück</a>
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>