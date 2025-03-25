<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>FlatFileDB Kompakte Demo</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 10px; }
        h1 { margin-bottom: 10px; }
        .message, .performance { padding: 10px; margin-bottom: 10px; border: 1px solid #aaa; background-color: #e0ffe0; }
        .performance { background-color: #f9f9f9; border-style: dashed; }
        .grid-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            grid-gap: 20px;
        }
        .card {
            border: 1px solid #ddd;
            padding: 10px;
            box-shadow: 2px 2px 5px rgba(0,0,0,0.1);
        }
        .card h2 { font-size: 1.2em; margin: 0 0 10px; }
        form { display: flex; flex-direction: column; }
        label { margin-top: 8px; }
        input, button { padding: 5px; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.9em; }
        th, td { border: 1px solid #ccc; padding: 4px; text-align: left; }
        th { background-color: #f0f0f0; }
    </style>
    <script>
        // Clientseitige Messung der vollständigen Ladezeit mittels performance.now()
        const clientStart = performance.now();
        window.addEventListener('load', function() {
            const clientEnd = performance.now();
            const clientDuration = (clientEnd - clientStart) / 1000; // in Sekunden
            document.getElementById('clientPerformance').innerText =
                "Clientseitige Ladezeit: " + clientDuration.toFixed(4) + " s";
        });
    </script>
</head>
<body>
    <h1>FlatFileDB Kompakte Demo</h1>
    <h2><a href="https://faktenfront.de/test/test-env/dummyDataFiller.php">Dummy Data Filler</a></h2>
    <?php if (!empty($message)): ?>
        <div class="message"><?php echo $message; ?></div>
    <?php endif; ?>
    <?php if (!empty($operationPerformance)): ?>
        <div class="performance"><strong>Letzte DB-Operation:</strong> <?php echo $operationPerformance; ?></div>
    <?php endif; ?>
    <div class="grid-container">
        <div class="card">
            <h2>Benutzer hinzufügen</h2>
            <form method="post">
                <input type="hidden" name="action" value="insert_user">
                <label for="name">Name:</label>
                <input type="text" id="name" name="name" required>
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required>
                <label for="age">Alter:</label>
                <input type="number" id="age" name="age" required>
                <button type="submit">Hinzufügen</button>
            </form>
        </div>
        <div class="card">
            <h2>Benutzer aktualisieren</h2>
            <form method="post">
                <input type="hidden" name="action" value="update_user">
                <label for="update_user_id">ID:</label>
                <input type="number" id="update_user_id" name="update_user_id" required>
                <label for="update_name">Neuer Name:</label>
                <input type="text" id="update_name" name="update_name" required>
                <label for="update_email">Neue Email:</label>
                <input type="email" id="update_email" name="update_email" required>
                <label for="update_age">Neues Alter:</label>
                <input type="number" id="update_age" name="update_age" required>
                <button type="submit">Aktualisieren</button>
            </form>
        </div>
        <div class="card">
            <h2>Benutzer löschen</h2>
            <form method="post">
                <input type="hidden" name="action" value="delete_user">
                <label for="delete_user_id">ID:</label>
                <input type="number" id="delete_user_id" name="delete_user_id" required>
                <button type="submit">Löschen</button>
            </form>
        </div>
        <div class="card">
            <h2>Benutzer suchen</h2>
            <form method="post">
                <input type="hidden" name="action" value="search_user">
                <label for="search_id">ID:</label>
                <input type="number" id="search_id" name="search_id">
                <label for="search_term">Name:</label>
                <input type="text" id="search_term" name="search_term">
                <button type="submit">Suchen</button>
            </form>
        </div>
        <div class="card">
            <h2>Systemaktionen</h2>
            <form method="post">
                <input type="hidden" name="action" value="compact_table">
                <button type="submit">Tabelle kompaktieren</button>
            </form>
            <form method="post">
                <input type="hidden" name="action" value="backup_db">
                <button type="submit">Backup erstellen</button>
            </form>
            <form method="post">
                <input type="hidden" name="action" value="clear_database">
                <button type="submit">DB leeren</button>
            </form>
        </div>
        <div class="card" style="grid-column: 1 / -1;">
            <h2>Performance Informationen</h2>
            <p><strong>Ladezeit (Benutzer-Daten):</strong> <?php echo number_format($loadUsersDuration, 4); ?> s</p>
            <p><strong>PHP-Ausführungszeit:</strong> <?php echo number_format($totalExecutionTime, 4); ?> s</p>
            <p><strong>Gesamt (serverseitig gemessen):</strong> <?php echo $overallPerformance; ?></p>
            <p id="clientPerformance"><strong>Clientseitige Ladezeit:</strong> wird gemessen...</p>
            <hr>
            <h3>Zusätzliche Kennzahlen</h3>
            <p><strong>Daten-Dateigröße:</strong> <?php echo number_format($dataFileSize / 1024, 2); ?> KB</p>
            <p><strong>Index-Dateigröße:</strong> <?php echo number_format($indexFileSize / 1024, 2); ?> KB</p>
            <p><strong>Log-Dateigröße:</strong> <?php echo number_format($logFileSize / 1024, 2); ?> KB</p>
            <p><strong>Aktuelle Memory-Nutzung:</strong> <?php echo number_format($currentMemory / 1024 / 1024, 2); ?> MB</p>
            <p><strong>Peak Memory-Nutzung:</strong> <?php echo number_format($peakMemory / 1024 / 1024, 2); ?> MB</p>
        </div>
        <?php if (!empty($searchResults)): ?>
            <div class="card" style="grid-column: 1 / -1;">
                <h2>Suchergebnisse</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Alter</th>
                            <th>Erstellt am</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($searchResults as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$user['id']); ?></td>
                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars((string)$user['age']); ?></td>
                                <td><?php echo date('Y-m-d H:i:s', (int)$user['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <div class="card" style="grid-column: 1 / -1;">
            <h2>Alle Benutzer</h2>
            <?php if (count($users) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Alter</th>
                            <th>Erstellt am</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$user['id']); ?></td>
                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars((string)$user['age']); ?></td>
                                <td><?php echo date('Y-m-d H:i:s', (int)$user['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Keine Benutzer vorhanden.</p>
            <?php endif; ?>
        </div>
    </div>
    <p>Diese Demo zeigt, wie du mit FlatFileDB CRUD-Operationen und Systemaktionen über ein kompaktes HTML-Interface steuerst. Zusätzlich werden erweiterte Metriken (Dateigrößen, Memory-Nutzung) und sowohl server- als auch clientseitige Ladezeiten gemessen.</p>
</body>
</html>