<header class="mb-4">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">FlatFileDB Control Center</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <!-- Datenbank-Switcher -->
            <div class="navbar-text text-light me-3">
                <form id="dbSwitcherForm" class="d-flex align-items-center">
                    <label for="dbSelector" class="me-2">Datenbank:</label>
                    <select id="dbSelector" class="form-select form-select-sm" onchange="app.switchDatabase(this.value)">
                        <?php
                        // KORREKT: Neuen Variablennamen verwenden und Funktion aufrufen
                        $availableDatabases = getAllDatabases(DATA_DIR); // Ruft die Hilfsfunktion korrekt auf
                        $currentDbName = $GLOBALS['currentDb'] ?? null; // Aktuellen DB-Namen holen

                        // Hinweis: Die Logik zum automatischen Auswählen der ersten DB, falls keine Session
                        // gesetzt ist, befindet sich jetzt zentral in init.php. Hier lesen wir nur den Wert.

                        if (empty($availableDatabases) && is_null($currentDbName)) {
                            // Optional: Platzhalter, wenn gar keine DBs existieren und keine ausgewählt ist
                            echo '<option value="">Keine Datenbanken</option>';
                        } else {
                            // Liste die verfügbaren Datenbanken auf
                            foreach ($availableDatabases as $databaseInfo):
                                ?>
                                <option value="<?php echo htmlspecialchars($databaseInfo['name']); ?>"
                                    <?php echo isset($currentDbName) && $databaseInfo['name'] === $currentDbName ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($databaseInfo['name']); ?>
                                </option>
                                <?php
                            endforeach;

                            // Füge die aktuell ausgewählte DB hinzu, falls sie aus irgendeinem Grund
                            // nicht in getAllDatabases() auftaucht (z.B. Verzeichnis existiert, aber Meta fehlt)
                            // Dies sollte aber normalerweise nicht passieren.
                            if (!is_null($currentDbName) && !in_array($currentDbName, array_column($availableDatabases, 'name'))) {
                                // Stelle sicher, dass das Verzeichnis auch existiert, bevor es hinzugefügt wird
                                if (is_dir(DATA_DIR . '/' . $currentDbName)) {
                                    echo '<option value="' . htmlspecialchars($currentDbName) . '" selected>' . htmlspecialchars($currentDbName) . ' (Aktuell)</option>';
                                }
                            }

                        }
                        ?>
                        <option value="__new__">+ Neue Datenbank...</option>
                    </select>
                </form>
            </div>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab == 'tables' ? 'active' : ''; ?>" href="index.php?tab=tables">Tabellen</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo $activeTab == 'maintenance' ? 'active' : ''; ?>" href="#" id="maintenanceDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Wartung
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="maintenanceDropdown">
                            <li><a class="dropdown-item" href="index.php?tab=maintenance&action=compact">Kompaktierung</a></li>
                            <li><a class="dropdown-item" href="index.php?tab=maintenance&action=backup">Backup</a></li>
                            <li><a class="dropdown-item" href="index.php?tab=maintenance&action=logs">Logs</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="index.php?tab=maintenance&action=databases">Datenbankverwaltung</a></li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab == 'statistics' ? 'active' : ''; ?>" href="index.php?tab=statistics">Statistik</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
</header>

<!-- Status-Meldungen -->
<div id="statusMessages" class="container-fluid mb-3"></div>

<!-- Modal für neue Datenbank -->
<div class="modal fade" id="newDatabaseModal" tabindex="-1" aria-labelledby="newDatabaseModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="newDatabaseModalLabel">Neue Datenbank erstellen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <form id="createNewDatabaseForm">
                    <div class="mb-3">
                        <label for="newDbName" class="form-label">Datenbankname</label>
                        <input type="text" class="form-control" id="newDbName" required
                               pattern="[a-zA-Z0-9_]+" title="Nur Buchstaben, Zahlen und Unterstriche erlaubt">
                        <div class="form-text">Nur Buchstaben, Zahlen und Unterstriche sind erlaubt.</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                <button type="button" class="btn btn-primary" id="createNewDbButton">Erstellen</button>
            </div>
        </div>
    </div>
</div>