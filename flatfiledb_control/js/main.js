/**
 * Hauptfunktionalität des FlatFileDB Control Centers
 */
const app = {
    /**
     * Initialisierung der Anwendung
     */
    init: function() {
        // Status-Container abrufen
        this.statusContainer = document.getElementById('statusMessages');
        
        // Event-Listener registrieren
        this.registerEventListeners();

        // Event-Listener für "Neue Datenbank" Modal
        const createNewDbButton = document.getElementById('createNewDbButton');
        if (createNewDbButton) {
            createNewDbButton.addEventListener('click', () => this.createNewDatabase());
        }

        // Enter-Taste im Formular abfangen
        const newDbNameInput = document.getElementById('newDbName');
        if (newDbNameInput) {
            newDbNameInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    createNewDbButton.click();
                }
            });
        }
    },
    
    /**
     * Registriert globale Event-Listener
     */
    registerEventListeners: function() {
        // Alle Formulare abfangen und über AJAX einreichen
        document.addEventListener('submit', function(e) {
            const form = e.target;
            
            // Nur für Formulare, die keinen target="_blank" haben
            if (form.getAttribute('target') !== '_blank') {
                const formId = form.id;
                
                // Form-spezifische Handler zuweisen
                if (formId === 'createTableForm') {
                    e.preventDefault();
                    tableManager.createTable(form);
                } else if (formId === 'schemaForm') {
                    e.preventDefault();
                    tableManager.saveSchema(form);
                } else if (formId === 'editRecordForm') {
                    e.preventDefault();
                    dataManager.updateRecord(form);
                } else if (formId === 'insertRecordForm') {
                    e.preventDefault();
                    dataManager.insertRecord(form);
                } else if (formId === 'selectTableForm') {
                    e.preventDefault();
                    const select = document.getElementById('logTableSelect');
                    if (select && select.value) {
                        window.location.href = 'index.php?tab=maintenance&action=logs&selected_table=' + encodeURIComponent(select.value);
                    }
                }
            }
        });
        
        // Globale Click-Handler für wiederkehrende Elemente
        document.addEventListener('click', function(e) {
            // In-Place Bearbeitung für Tabellendaten
            if (e.target.closest('.editable') && e.target.closest('#dataTable')) {
                const cell = e.target.closest('.editable');
                const field = cell.dataset.field;
                
                // ID nicht bearbeitbar
                if (field === 'id') return;
                
                const row = cell.parentNode;
                const id = row.dataset.id;
                const value = cell.textContent.trim();
                
                app.openEditModal(id, field, value);
            }
            
            // Löschen eines Datensatzes
            if (e.target.closest('.delete-record')) {
                const button = e.target.closest('.delete-record');
                const id = button.dataset.id;
                const table = app.getCurrentTable();
                
                if (confirm('Möchten Sie diesen Datensatz wirklich löschen?')) {
                    dataManager.deleteRecord(table, id);
                }
            }
        });
    },
    
    /**
     * Öffnet das Bearbeitungsmodal für eine Zelle
     */
    openEditModal: function(id, field, value) {
        const editModal = new bootstrap.Modal(document.getElementById('editModal'));
        
        document.getElementById('editRecordId').value = id;
        document.getElementById('editFieldName').value = field;
        
        // Feldtyp bestimmen und passenden Editor anzeigen
        const container = document.getElementById('editFieldValueContainer');
        let editorHtml = '';
        
        if (value === 'true' || value === 'false') {
            // Boolean
            editorHtml = `
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="editFieldValue" ${value === 'true' ? 'checked' : ''}>
                    <label class="form-check-label" for="editFieldValue">True/False</label>
                </div>
            `;
        } else if (value.startsWith('{') || value.startsWith('[')) {
            // JSON
            try {
                const jsonObj = JSON.parse(value);
                const prettyJson = JSON.stringify(jsonObj, null, 2);
                editorHtml = `<textarea class="form-control" id="editFieldValue" rows="5">${prettyJson}</textarea>
                              <div class="form-text">JSON-Format</div>`;
            } catch (e) {
                editorHtml = `<textarea class="form-control" id="editFieldValue" rows="5">${value}</textarea>`;
            }
        } else if (value === '<em>null</em>') {
            // Null
            editorHtml = `<input type="text" class="form-control" id="editFieldValue" placeholder="Null-Wert (leer lassen für null)">
                          <div class="form-text">Leer lassen für null</div>`;
        } else {
            // String oder Zahl
            editorHtml = `<input type="text" class="form-control" id="editFieldValue" value="${value.replace(/"/g, '&quot;')}">`;
        }
        
        container.innerHTML = editorHtml;
        
        // Speichern-Button einrichten
        const saveBtn = document.getElementById('saveFieldBtn');
        const tableName = app.getCurrentTable();
        
        const saveHandler = function() {
            // Wert extrahieren
            let newValue;
            const input = document.getElementById('editFieldValue');
            
            if (input.type === 'checkbox') {
                newValue = input.checked;
            } else if (input.tagName === 'TEXTAREA') {
                try {
                    // Versuchen, als JSON zu parsen
                    newValue = JSON.parse(input.value);
                } catch (e) {
                    newValue = input.value;
                }
            } else {
                newValue = input.value;
                
                // Leeren String zu null konvertieren, wenn ursprünglich null
                if (value === '<em>null</em>' && newValue === '') {
                    newValue = null;
                }
                
                // Zahlen konvertieren
                if (!isNaN(newValue) && newValue !== '') {
                    newValue = Number(newValue);
                }
            }
            
            // Daten aktualisieren
            const data = {};
            data[field] = newValue;
            
            dataManager.updateField(tableName, id, data, function() {
                editModal.hide();
                // Event-Handler entfernen, um Memory-Leaks zu verhindern
                saveBtn.removeEventListener('click', saveHandler);
            });
        };
        
        // Alten Handler entfernen und neuen hinzufügen
        saveBtn.removeEventListener('click', saveBtn.saveHandler);
        saveBtn.addEventListener('click', saveHandler);
        saveBtn.saveHandler = saveHandler;
        
        editModal.show();
    },
    
    /**
     * Hilfsfunktion zum Anzeigen von Statusmeldungen
     */
    showStatus: function(message, type = 'info') {
        const statusDiv = document.createElement('div');
        statusDiv.className = `status-message status-${type}`;
        statusDiv.innerHTML = message;
        
        this.statusContainer.appendChild(statusDiv);
        
        // Nach einiger Zeit ausblenden
        setTimeout(function() {
            statusDiv.style.opacity = '0';
            setTimeout(function() {
                if (statusDiv.parentNode) {
                    statusDiv.parentNode.removeChild(statusDiv);
                }
            }, 500);
        }, 5000);
    },
    
    /**
     * Gibt den Namen der aktuell ausgewählten Tabelle zurück
     */
    getCurrentTable: function() {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('table');
    },
    
    /**
     * AJAX-Hilfsfunktion für POST-Anfragen
     */
    ajax: function(url, data, callback) {
        // Fetch API verwenden
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                this.showStatus(data.error, 'error');
                if (callback) callback(false, data);
            } else {
                if (data.message) {
                    this.showStatus(data.message, 'success');
                }
                if (callback) callback(true, data);
            }
        })
        .catch(error => {
            this.showStatus('Fehler bei der Anfrage: ' + error.message, 'error');
            if (callback) callback(false, { error: error.message });
        });
    },
    
    /**
     * Lädt die Seite neu, falls erforderlich
     */
    reloadPage: function(newUrl = null) {
        if (newUrl) {
            window.location.href = newUrl;
        } else {
            window.location.reload();
        }
    },

    /**
     * Wechselt die aktive Datenbank
     */
    switchDatabase: function(dbName) {
        if (dbName === '__new__') {
            // Modal zum Erstellen einer neuen Datenbank anzeigen
            const modal = new bootstrap.Modal(document.getElementById('newDatabaseModal'));
            modal.show();
            return;
        }
        
        // AJAX-Anfrage zum Wechseln der Datenbank
        this.ajax('api/switch_db.php', {
            action: 'switch',
            database: dbName
        }, function(success) {
            if (success) {
                // Seite neu laden, um Änderungen zu übernehmen
                window.location.reload();
            }
        });
    },

    /**
     * Erstellt eine neue Datenbank und wechselt zu ihr
     */
    createNewDatabase: function() {
        const dbName = document.getElementById('newDbName').value.trim();
        
        if (!dbName) {
            this.showStatus('Bitte geben Sie einen Datenbanknamen ein.', 'error');
            return;
        }
        
        this.ajax('api/database.php', {
            action: 'create',
            name: dbName
        }, (success, response) => {
            if (success) {
                // Modal schließen
                const modal = bootstrap.Modal.getInstance(document.getElementById('newDatabaseModal'));
                modal.hide();
                
                // Zur neuen Datenbank wechseln
                this.switchDatabase(dbName);
            }
        });
    }
};

// Anwendung nach DOM-Laden initialisieren
document.addEventListener('DOMContentLoaded', function() {
    app.init();
});