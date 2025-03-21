<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>FlatFileDB Demo - Erweiterte Version</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #f0f0f0; }
        form { margin-bottom: 20px; padding: 10px; border: 1px solid #ddd; }
        .message { padding: 10px; border: 1px solid #aaa; background-color: #e0ffe0; margin-bottom: 20px; }
        .section { margin-bottom: 30px; }
    </style>
</head>
<body>
    <h1>FlatFileDB - Erweiterte Demo</h1>
    
    <?php if (!empty($message)): ?>
        <div class="message"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <div class="section">
        <h2>Neuen Benutzer hinzufügen</h2>
        <form method="post" action="">
            <input type="hidden" name="action" value="insert_user">
            <label for="user_id">Benutzer-ID:</label><br>
            <input type="text" id="user_id" name="user_id" required><br><br>
            
            <label for="name">Name:</label><br>
            <input type="text" id="name" name="name" required><br><br>
            
            <label for="email">Email:</label><br>
            <input type="email" id="email" name="email" required><br><br>
            
            <label for="age">Alter:</label><br>
            <input type="number" id="age" name="age" required><br><br>
            
            <button type="submit">Benutzer hinzufügen</button>
        </form>
    </div>
    
    <div class="section">
        <h2>Benutzer aktualisieren</h2>
        <form method="post" action="">
            <input type="hidden" name="action" value="update_user">
            <label for="update_user_id">Benutzer-ID:</label><br>
            <input type="text" id="update_user_id" name="update_user_id" required><br><br>
            
            <label for="update_name">Neuer Name:</label><br>
            <input type="text" id="update_name" name="update_name" required><br><br>
            
            <label for="update_email">Neue Email:</label><br>
            <input type="email" id="update_email" name="update_email" required><br><br>
            
            <label for="update_age">Neues Alter:</label><br>
            <input type="number" id="update_age" name="update_age" required><br><br>
            
            <button type="submit">Benutzer aktualisieren</button>
        </form>
    </div>
    
    <div class="section">
        <h2>Benutzer löschen</h2>
        <form method="post" action="">
            <input type="hidden" name="action" value="delete_user">
            <label for="delete_user_id">Benutzer-ID:</label><br>
            <input type="text" id="delete_user_id" name="delete_user_id" required><br><br>
            
            <button type="submit">Benutzer löschen</button>
        </form>
    </div>
    
    <div class="section">
        <h2>Benutzer suchen</h2>
        <form method="post" action="">
            <input type="hidden" name="action" value="search_user">
            <label for="search_term">Suchbegriff (im Namen):</label><br>
            <input type="text" id="search_term" name="search_term" required><br><br>
            
            <button type="submit">Suchen</button>
        </form>
        
        <?php if (!empty($searchResults)): ?>
            <h3>Suchergebnisse:</h3>
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
                            <td><?php echo htmlspecialchars($user['id']); ?></td>
                            <td><?php echo htmlspecialchars($user['name']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><?php echo htmlspecialchars($user['age']); ?></td>
                            <td><?php echo date('Y-m-d H:i:s', $user['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <div class="section">
        <h2>Alle Benutzer anzeigen</h2>
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
                            <td><?php echo htmlspecialchars($user['id']); ?></td>
                            <td><?php echo htmlspecialchars($user['name']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><?php echo htmlspecialchars($user['age']); ?></td>
                            <td><?php echo date('Y-m-d H:i:s', $user['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Es wurden noch keine Benutzer eingefügt.</p>
        <?php endif; ?>
    </div>
    
    <div class="section">
        <h2>Systemaktionen</h2>
        <form method="post" action="" style="display:inline-block;">
            <input type="hidden" name="action" value="compact_table">
            <button type="submit">Tabelle kompaktieren</button>
        </form>
        <form method="post" action="" style="display:inline-block; margin-left: 20px;">
            <input type="hidden" name="action" value="backup_db">
            <button type="submit">Backup erstellen</button>
        </form>
        <form method="post" action="" style="display:inline-block; margin-left: 20px;">
            <input type="hidden" name="action" value="clear_database">
            <button type="submit">Datenbank leeren</button>
        </form>
        <p>
            Kompaktierung entfernt gelöschte Datensätze und baut den Index neu auf. Mit Backup wird die
            aktuelle Datenbank in das standardmäßige Backup-Verzeichnis gesichert.
        </p>
    </div>
    
    <p>Diese Demo zeigt umfassend, wie du mit der FlatFile-Datenbank Tabellen registrierst, Schemas definierst und alle CRUD-Operationen sowie Systemaktionen (Kompaktierung, Backup) über ein HTML-Interface steuerst.</p>
</body>
</html>
