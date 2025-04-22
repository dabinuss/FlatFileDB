/**
 * Wartungsfunktionen für das FlatFileDB Control Center
 */
const maintenance = {
    init: function() {
        this.initLogViewer();
    },
    
    /**
     * Initialisiert die Log-Anzeige
     */
    initLogViewer: function() {
        const logContainer = document.getElementById('logContainer');
        if (!logContainer) return;
        
        const tableName = logContainer.dataset.table;
        if (!tableName) return;
        
        // Logs laden
        this.loadLogs(tableName);
        
        // Event-Listener für Aktualisieren-Button
        const refreshBtn = document.getElementById('refreshLogsBtn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                this.loadLogs(tableName);
            });
        }
        
        // Event-Listener für Log-Rotation
        const rotateBtn = document.getElementById('rotateLogBtn');
        if (rotateBtn) {
            rotateBtn.addEventListener('click', () => {
                this.rotateLog(tableName);
            });
        }
    },
    
    /**
     * Lädt Logs einer Tabelle
     */
    loadLogs: function(tableName) {
        app.ajax('api/maintenance.php', {
            action: 'log',
            table: tableName
        }, (success, response) => {
            if (success && response.entries) {
                this.displayLogs(response.entries);
            }
        });
    },
    
    /**
     * Zeigt Logs in der Tabelle an
     */
    displayLogs: function(entries) {
        const logTableBody = document.getElementById('logTableBody');
        if (!logTableBody) return;
        
        let html = '';
        
        if (entries.length === 0) {
            html = '<tr><td colspan="4" class="text-center">Keine Log-Einträge gefunden</td></tr>';
        } else {
            entries.forEach(entry => {
                const timestamp = new Date(entry.timestamp * 1000).toLocaleString();
                let operation = entry.operation || '';
                let recordId = entry.record_id || '';
                
                // JSON-Daten für Details
                const detailsJson = JSON.stringify(entry, null, 2);
                
                html += `
                <tr>
                    <td>${timestamp}</td>
                    <td>${operation}</td>
                    <td>${recordId}</td>
                    <td>
                        <button type="button" class="btn btn-sm btn-info view-log-details" 
                                data-details='${detailsJson.replace(/'/g, "&#39;")}'>
                            Details
                        </button>
                    </td>
                </tr>
                `;
            });
        }
        
        logTableBody.innerHTML = html;
        
        // Event-Listener für Detail-Buttons hinzufügen
        const detailButtons = logTableBody.querySelectorAll('.view-log-details');
        detailButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const details = JSON.parse(btn.dataset.details);
                this.showLogDetails(details);
            });
        });
    },
    
    /**
     * Zeigt Log-Details im Modal an
     */
    showLogDetails: function(details) {
        const modal = new bootstrap.Modal(document.getElementById('logDetailsModal'));
        const contentElem = document.getElementById('logDetailsContent');
        
        if (contentElem) {
            contentElem.textContent = JSON.stringify(details, null, 2);
        }
        
        modal.show();
    },
    
    /**
     * Rotiert das Log einer Tabelle
     */
    rotateLog: function(tableName) {
        if (confirm('Möchten Sie das Log wirklich rotieren? Das aktuelle Log wird archiviert und ein neues angelegt.')) {
            app.ajax('api/maintenance.php', {
                action: 'rotate_log',
                table: tableName
            }, (success, response) => {
                if (success) {
                    this.loadLogs(tableName);
                }
            });
        }
    },
    
    /**
     * Kompaktiert eine Tabelle
     */
    compactTable: function(tableName) {
        if (confirm('Möchten Sie die Tabelle "' + tableName + '" kompaktieren? Dies kann bei großen Tabellen einige Zeit dauern.')) {
            app.showStatus('Kompaktierung wird ausgeführt...', 'info');
            
            app.ajax('api/maintenance.php', {
                action: 'compact',
                table: tableName
            }, (success) => {
                if (success) {
                    app.showStatus('Tabelle "' + tableName + '" erfolgreich kompaktiert', 'success');
                }
            });
        }
    },
    
    /**
     * Kompaktiert alle Tabellen
     */
    compactAllTables: function() {
        if (confirm('Möchten Sie alle Tabellen kompaktieren? Dies kann je nach Datenbankgröße länger dauern.')) {
            app.showStatus('Kompaktierung aller Tabellen wird ausgeführt...', 'info');
            
            app.ajax('api/maintenance.php', {
                action: 'compact_all'
            }, (success, response) => {
                if (success) {
                    app.showStatus('Alle Tabellen wurden kompaktiert', 'success');
                    console.log('Kompaktierungsergebnisse:', response.results);
                }
            });
        }
    },
    
    /**
     * Erstellt ein Backup einer Tabelle
     */
    backupTable: function(tableName) {
        app.showStatus('Backup wird erstellt...', 'info');
        
        app.ajax('api/maintenance.php', {
            action: 'backup',
            table: tableName
        }, (success, response) => {
            if (success) {
                app.showStatus('Backup für Tabelle "' + tableName + '" erfolgreich erstellt', 'success');
                console.log('Backup-Dateien:', response.files);
            }
        });
    },
    
    /**
     * Erstellt ein Backup aller Tabellen
     */
    backupAllTables: function() {
        app.showStatus('Backup aller Tabellen wird erstellt...', 'info');
        
        app.ajax('api/maintenance.php', {
            action: 'backup_all'
        }, (success, response) => {
            if (success) {
                app.showStatus('Backup aller Tabellen erfolgreich erstellt', 'success');
                console.log('Backup-Dateien:', response.files);
            }
        });
    },
    
    /**
     * Leert den Cache einer Tabelle
     */
    clearCache: function(tableName) {
        app.ajax('api/maintenance.php', {
            action: 'clear_cache',
            table: tableName
        }, (success) => {
            if (success) {
                app.showStatus('Cache für Tabelle "' + tableName + '" erfolgreich geleert', 'success');
            }
        });
    },
    
    /**
     * Leert alle Caches
     */
    clearAllCaches: function() {
        app.ajax('api/maintenance.php', {
            action: 'clear_cache'
        }, (success) => {
            if (success) {
                app.showStatus('Alle Caches erfolgreich geleert', 'success');
            }
        });
    },

    /**
     * Erstellt eine neue Datenbank
     */
    createDatabase: function(dbName) {
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
    },

    /**
     * Löscht eine Datenbank
     */
    deleteDatabase: function(dbName) {
        if (confirm('WARNUNG: Möchten Sie die Datenbank "' + dbName + '" wirklich komplett löschen? Diese Aktion kann nicht rückgängig gemacht werden!')) {
            app.ajax('api/database.php', {
                action: 'delete',
                name: dbName
            }, function(success) {
                if (success) {
                    app.reloadPage();
                }
            });
        }
    },

    /**
     * Erstellt ein Backup einer Datenbank
     */
    backupDatabase: function(dbName) {
        app.showStatus('Backup wird erstellt...', 'info');
        
        app.ajax('api/database.php', {
            action: 'backup',
            name: dbName
        }, function(success) {
            if (success) {
                app.showStatus('Backup für Datenbank "' + dbName + '" erfolgreich erstellt', 'success');
            }
        });
    }
};

// Nach DOM-Laden initialisieren
document.addEventListener('DOMContentLoaded', function() {
    maintenance.init();
});