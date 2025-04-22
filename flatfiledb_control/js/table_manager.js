/**
 * Verwaltung von Tabellen im FlatFileDB Control Center
 */
const tableManager = {
    init: function() {
        this.initCreateTableForm();
        this.initSchemaForm();
    },
    
    /**
     * Initialisiert das Formular zum Erstellen neuer Tabellen
     */
    initCreateTableForm: function() {
        const defineSchemaCheckbox = document.getElementById('defineSchema');
        const schemaSection = document.getElementById('schemaSection');
        const addFieldBtn = document.getElementById('addFieldBtn');
        const fieldsContainer = document.getElementById('fieldsContainer');
        const fieldTemplate = document.getElementById('fieldTemplate');
        
        if (!defineSchemaCheckbox || !schemaSection) return; // Elemente nicht gefunden
        
        // Schema-Bereich ein-/ausblenden
        defineSchemaCheckbox.addEventListener('change', function() {
            schemaSection.style.display = this.checked ? 'block' : 'none';
        });
        
        if (addFieldBtn && fieldsContainer && fieldTemplate) {
            // Feld hinzufügen
            addFieldBtn.addEventListener('click', function() {
                const newField = document.importNode(fieldTemplate.content, true);
                fieldsContainer.appendChild(newField);
                
                // Event für Lösch-Button
                const removeBtn = fieldsContainer.lastElementChild.querySelector('.remove-field-btn');
                removeBtn.addEventListener('click', function() {
                    this.closest('.field-row').remove();
                });
            });
            
            // Erstes Feld automatisch hinzufügen, wenn noch keins vorhanden ist
            if (fieldsContainer.children.length === 0 && defineSchemaCheckbox.checked) {
                addFieldBtn.click();
            }
        }
    },
    
    /**
     * Initialisiert das Formular zur Schema-Verwaltung
     */
    initSchemaForm: function() {
        const enableSchema = document.getElementById('enableSchema');
        const schemaFields = document.getElementById('schemaFields');
        const addSchemaFieldBtn = document.getElementById('addSchemaFieldBtn');
        const schemaFieldsContainer = document.getElementById('schemaFieldsContainer');
        const schemaFieldTemplate = document.getElementById('schemaFieldTemplate');
        
        if (!enableSchema || !schemaFields) return; // Elemente nicht gefunden
        
        // Schema-Bereich ein-/ausblenden
        enableSchema.addEventListener('change', function() {
            schemaFields.style.display = this.checked ? 'block' : 'none';
        });
        
        if (addSchemaFieldBtn && schemaFieldsContainer && schemaFieldTemplate) {
            // Feld hinzufügen
            addSchemaFieldBtn.addEventListener('click', function() {
                const newField = document.importNode(schemaFieldTemplate.content, true);
                schemaFieldsContainer.appendChild(newField);
                
                // Event für Lösch-Button
                const removeBtn = schemaFieldsContainer.lastElementChild.querySelector('.remove-schema-field-btn');
                removeBtn.addEventListener('click', function() {
                    this.closest('.schema-field-row').remove();
                });
            });
            
            // Events für bestehende Lösch-Buttons registrieren
            const existingButtons = schemaFieldsContainer.querySelectorAll('.remove-schema-field-btn');
            existingButtons.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    this.closest('.schema-field-row').remove();
                });
            });
        }
    },
    
    /**
     * Erstellt eine neue Tabelle
     */
    createTable: function(form) {
        const tableName = form.querySelector('#tableName').value.trim();
        const defineSchema = form.querySelector('#defineSchema').checked;

        if (!tableName) {
            app.showStatus('Bitte geben Sie einen Tabellennamen ein.', 'error');
            return;
        }
         // Validierung des Tabellennamens (optional, da auch Backend prüft)
         if (!/^[a-zA-Z0-9_]+$/.test(tableName)) {
              app.showStatus('Ungültiger Tabellenname. Nur Buchstaben, Zahlen und Unterstriche erlaubt.', 'error');
              return;
         }

        // Daten sammeln
        const data = {
            action: 'create',
            table: tableName,
            schema: defineSchema ? 'true' : 'false' // Flag senden
        };

        if (defineSchema) {
            // Korrigierter Selektor, Annahme: Template ID="fieldTemplate" hat .field-row als Haupt-Div
            const fieldRows = form.querySelectorAll('#fieldsContainer .field-row'); // <-- KORRIGIERTER SELEKTOR
            const columns = [];

            fieldRows.forEach(function(field) {
                const nameInput = field.querySelector('.field-name');
                const typeSelect = field.querySelector('.field-type');
                const requiredCheckbox = field.querySelector('.field-required'); // <-- Pflichtfeld holen

                // Prüfe, ob alle Elemente gefunden wurden und der Name nicht leer ist
                if (nameInput && nameInput.value.trim() && typeSelect && requiredCheckbox) {
                    columns.push({
                        name: nameInput.value.trim(),
                        type: typeSelect.value,
                        required: requiredCheckbox.checked // <-- Boolean-Wert senden
                    });
                } else {
                     console.warn("Überspringe unvollständiges Schema-Feld:", field);
                     app.showStatus("Warnung: Ein oder mehrere Schema-Felder sind unvollständig und wurden ignoriert.", 'warning');
                }
            });

            // Sende nur, wenn Spalten definiert wurden
            if (columns.length > 0) {
                 data.columns = JSON.stringify(columns);  // <-- Sendet Array von Objekten als JSON
            } else if (defineSchema) {
                // Wenn Schema angehakt, aber keine Felder, trotzdem Flag senden, aber keine Columns
                // Oder Fehlermeldung? Hier senden wir einfach das Flag ohne Columns.
                 app.showStatus("Schema aktiviert, aber keine Felder definiert. Tabelle wird ohne Schema erstellt.", 'warning');
                 data.schema = 'false'; // Deaktiviere Schema, wenn keine Felder da sind
            }
        }

        app.showStatus('Erstelle Tabelle...', 'info');

        // API-Anfrage
        app.ajax('api/table.php', data, function(success, response) {
            // Callback-Logik bleibt gleich
            if (success && response.success) { // <-- Prüfe explizit response.success
                app.showStatus('Tabelle "' + tableName + '" erfolgreich erstellt', 'success');
                setTimeout(function() {
                    // Zur neuen Tabelle oder zur Tabellenliste navigieren
                    if (response.tableName) { // Optional: Backend kann Namen zurückgeben
                         app.reloadPage('index.php?tab=tables&table=' + encodeURIComponent(response.tableName));
                    } else {
                         app.reloadPage('index.php?tab=tables'); // Fallback zur Liste
                    }
                }, 1000);
            } else {
                // Detailliertere Fehlermeldung aus dem Backend anzeigen
                const errorMsg = response?.error || 'Unbekannter Fehler beim Erstellen der Tabelle';
                app.showStatus(errorMsg, 'error');
            }
        });
    },
    
    /**
     * Speichert das Schema einer Tabelle
     */
    saveSchema: function(form) {
        const tableName = form.querySelector('input[name="tableName"]').value;
        const enableSchema = form.querySelector('#enableSchema').checked;

        // Daten sammeln
        const data = {
            action: 'schema',
            table: tableName
        };

        if (enableSchema) {
            const fieldRows = form.querySelectorAll('.schema-field-row'); // Selektor scheint hier korrekt
            const requiredFields = [];
            const schema = {}; // Objekt für Feldname => Typ

            fieldRows.forEach(function(row) {
                const nameInput = row.querySelector('.schema-field-name');
                const typeSelect = row.querySelector('.schema-field-type');
                const requiredCheckbox = row.querySelector('.schema-field-required');

                if (nameInput && nameInput.value.trim() && typeSelect && requiredCheckbox) {
                    const fieldName = nameInput.value.trim();
                     // Einfache Validierung des Feldnamens (optional)
                     if (!/^[a-zA-Z0-9_]+$/.test(fieldName)) {
                         app.showStatus(`Ungültiger Feldname: "${fieldName}". Übersprungen.`, 'warning');
                         return; // Nächstes Feld
                     }

                    schema[fieldName] = typeSelect.value; // schema = {'feld1': 'string', 'feld2': 'int'}

                    if (requiredCheckbox.checked) {
                        requiredFields.push(fieldName); // requiredFields = ['feld1', 'feld3']
                    }
                } else {
                    console.warn("Überspringe unvollständiges Schema-Feld beim Speichern:", row);
                    app.showStatus("Warnung: Ein oder mehrere Schema-Felder sind unvollständig und wurden ignoriert.", 'warning');
                }
            });

            // Sende JSON-Strings für Arrays/Objekte
            data.required_fields = JSON.stringify(requiredFields);
            data.schema = JSON.stringify(schema); // Sendet {'feld':'typ',...} als JSON

        } else {
            // Schema deaktivieren: Leere Arrays/Objekte senden
            data.required_fields = JSON.stringify([]);
            data.schema = JSON.stringify({});
        }

        // API-Anfrage
        app.ajax('api/table.php', data, function(success, response) { // <-- Response hinzugefügt
            if (success && response.success) { // <-- Explizite Erfolgsprüfung
                app.showStatus('Schema erfolgreich gespeichert.', 'success');
                // Seite neu laden, um Änderungen zu sehen (oder spezifische Teile aktualisieren)
                app.reloadPage();
            } else {
                 const errorMsg = response?.error || 'Unbekannter Fehler beim Speichern des Schemas';
                 app.showStatus(errorMsg, 'error');
            }
        });
    },
    
    /**
     * Löscht eine Tabelle
     */
    deleteTable: function(tableName) {
        const data = {
            action: 'delete',
            table: tableName
        };
        
        app.ajax('api/table.php', data, function(success) {
            if (success) {
                app.reloadPage('index.php?tab=tables');
            }
        });
    },
    
    /**
     * Leert eine Tabelle
     */
    clearTable: function(tableName) {
        const data = {
            action: 'clear',
            table: tableName
        };
        
        app.ajax('api/table.php', data, function(success) {
            if (success) {
                app.reloadPage('index.php?tab=tables&table=' + encodeURIComponent(tableName));
            }
        });
    },
    
    /**
     * Erstellt einen Index für ein Feld
     */
    createIndex: function(tableName, field) {
        const data = {
            action: 'create',
            table: tableName,
            field: field
        };
        
        app.ajax('api/index.php', data, function(success) {
            if (success) {
                app.reloadPage();
            }
        });
    },
    
    /**
     * Löscht einen Index für ein Feld
     */
    dropIndex: function(tableName, field) {
        const data = {
            action: 'delete',
            table: tableName,
            field: field
        };
        
        app.ajax('api/index.php', data, function(success) {
            if (success) {
                app.reloadPage();
            }
        });
    }
};

// Nach DOM-Laden initialisieren
document.addEventListener('DOMContentLoaded', function() {
    tableManager.init();
});