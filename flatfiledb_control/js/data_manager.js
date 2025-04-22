/**
 * Verwaltung von Datensätzen im FlatFileDB Control Center
 */
const dataManager = {
    init: function() {
        this.initInsertForm();
        this.initEditInline();
    },
    
    /**
     * Initialisiert das Formular zum Einfügen neuer Datensätze
     */
    initInsertForm: function() {
        const addCustomFieldBtn = document.getElementById('addCustomFieldBtn');
        const customFieldsContainer = document.getElementById('customFieldsContainer');
        const customFieldTemplate = document.getElementById('customFieldTemplate');
        
        if (!addCustomFieldBtn || !customFieldsContainer || !customFieldTemplate) return; // Elemente nicht gefunden
        
        // Benutzerdefiniertes Feld hinzufügen
        addCustomFieldBtn.addEventListener('click', function() {
            const newField = document.importNode(customFieldTemplate.content, true);
            customFieldsContainer.appendChild(newField);
            
            // Event für Lösch-Button
            const removeBtn = customFieldsContainer.lastElementChild.querySelector('.remove-custom-field');
            removeBtn.addEventListener('click', function() {
                this.closest('.custom-field').remove();
            });
        });
    },
    
    /**
     * Initialisiert die Inline-Bearbeitung von Zellen
     */
    initEditInline: function() {
        // Wird in app.openEditModal() behandelt
    },
    
    /**
     * Fügt einen neuen Datensatz in die Tabelle ein
     */
    insertRecord: function(form) {
        const tableName = form.querySelector('input[name="tableName"]').value;
        const data = {};
        
        // Reguläre Felder
        const fieldInputs = form.querySelectorAll('[name^="fields["]');
        fieldInputs.forEach(function(input) {
            const fieldName = input.name.match(/fields\[(.*?)\]/)[1];
            
            if (input.type === 'checkbox') {
                data[fieldName] = input.checked;
            } else {
                // Versuch, JSON zu parsen
                if (input.tagName === 'TEXTAREA') {
                    try {
                        data[fieldName] = JSON.parse(input.value);
                    } catch (e) {
                        data[fieldName] = input.value;
                    }
                } else {
                    let value = input.value;
                    
                    // Konvertierung zu Zahlen, wenn möglich
                    if (value !== '' && !isNaN(value)) {
                        value = Number(value);
                    }
                    
                    // Leere Textfelder zu null
                    if (value === '') {
                        value = null;
                    }
                    
                    data[fieldName] = value;
                }
            }
        });
        
        // Benutzerdefinierte Felder (falls vorhanden)
        const customFields = form.querySelectorAll('.custom-field');
        customFields.forEach(function(field) {
            const nameInput = field.querySelector('.custom-field-name');
            const valueInput = field.querySelector('.custom-field-value');
            
            if (nameInput && nameInput.value.trim() && valueInput) {
                const fieldName = nameInput.value.trim();
                let value = valueInput.value;
                
                // Konvertierung zu Zahlen, wenn möglich
                if (value !== '' && !isNaN(value)) {
                    value = Number(value);
                }
                
                // Leere Textfelder zu null
                if (value === '') {
                    value = null;
                }
                
                data[fieldName] = value;
            }
        });
        
        // API-Anfrage
        app.ajax('api/data.php', {
            action: 'insert',
            table: tableName,
            data: JSON.stringify(data)
        }, function(success, response) {
            if (success) {
                app.reloadPage('index.php?tab=tables&table=' + encodeURIComponent(tableName));
            }
        });
    },
    
    /**
     * Aktualisiert einen Datensatz
     */
    updateRecord: function(form) {
        const tableName = form.querySelector('input[name="tableName"]').value;
        const recordId = form.querySelector('input[name="recordId"]').value;
        const data = {};
        
        // Felder sammeln
        const fieldInputs = form.querySelectorAll('[name^="fields["]');
        fieldInputs.forEach(function(input) {
            const fieldName = input.name.match(/fields\[(.*?)\]/)[1];
            
            if (input.type === 'checkbox') {
                data[fieldName] = input.checked;
            } else {
                // Versuch, JSON zu parsen
                if (input.tagName === 'TEXTAREA') {
                    try {
                        data[fieldName] = JSON.parse(input.value);
                    } catch (e) {
                        data[fieldName] = input.value;
                    }
                } else {
                    let value = input.value;
                    
                    // Konvertierung zu Zahlen, wenn möglich
                    if (value !== '' && !isNaN(value)) {
                        value = Number(value);
                    }
                    
                    // Leere Textfelder zu null
                    if (value === '') {
                        value = null;
                    }
                    
                    data[fieldName] = value;
                }
            }
        });
        
        // API-Anfrage
        app.ajax('api/data.php', {
            action: 'update',
            table: tableName,
            id: recordId,
            data: JSON.stringify(data)
        }, function(success) {
            if (success) {
                app.reloadPage('index.php?tab=tables&table=' + encodeURIComponent(tableName));
            }
        });
    },
    
    /**
     * Aktualisiert ein einzelnes Feld eines Datensatzes
     */
    updateField: function(tableName, recordId, data, callback) {
        // API-Anfrage
        app.ajax('api/data.php', {
            action: 'update',
            table: tableName,
            id: recordId,
            data: JSON.stringify(data)
        }, function(success) {
            if (callback) callback(success);
            if (success) {
                app.reloadPage('index.php?tab=tables&table=' + encodeURIComponent(tableName));
            }
        });
    },
    
    /**
     * Löscht einen Datensatz
     */
    deleteRecord: function(tableName, recordId) {
        // API-Anfrage
        app.ajax('api/data.php', {
            action: 'delete',
            table: tableName,
            id: recordId
        }, function(success) {
            if (success) {
                app.reloadPage('index.php?tab=tables&table=' + encodeURIComponent(tableName));
            }
        });
    }
};

// Nach DOM-Laden initialisieren
document.addEventListener('DOMContentLoaded', function() {
    dataManager.init();
});