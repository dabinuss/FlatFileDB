<div class="card mb-4">
    <div class="card-header bg-secondary text-white">
        <h5 class="mb-0">Tabellen</h5>
    </div>
    <div class="list-group list-group-flush">
        <?php foreach ($tableNames as $tableName): ?>
            <a href="index.php?tab=tables&table=<?php echo htmlspecialchars($tableName); ?>"
                class="list-group-item list-group-item-action <?php echo $activeTable == $tableName ? 'active' : ''; ?>">
                <?php echo htmlspecialchars($tableName); ?>
            </a>
        <?php endforeach; ?>

        <a href="index.php?tab=tables&action=create" class="list-group-item list-group-item-action text-primary">
            <i class="bi bi-plus-circle"></i> Neue Tabelle
        </a>
    </div>
</div>

<?php if (!empty($activeTable) && $tableExists && $activeTab == 'tables'): ?>
    <div class="card mb-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="mb-0">Tabellenaktionen</h5>
        </div>
        <div class="list-group list-group-flush">
            <a href="index.php?tab=tables&table=<?php echo htmlspecialchars($activeTable); ?>"
                class="list-group-item list-group-item-action <?php echo empty($action) ? 'active' : ''; ?>">
                Datensätze anzeigen
            </a>
            <a href="index.php?tab=tables&table=<?php echo htmlspecialchars($activeTable); ?>&action=insert"
                class="list-group-item list-group-item-action <?php echo $action == 'insert' ? 'active' : ''; ?>">
                Datensatz hinzufügen
            </a>
            <a href="index.php?tab=tables&table=<?php echo htmlspecialchars($activeTable); ?>&action=manage"
                class="list-group-item list-group-item-action <?php echo $action == 'manage' ? 'active' : ''; ?>">
                Tabelle verwalten
            </a>
            <a href="#" class="list-group-item list-group-item-action text-danger"
                onclick="if(confirm('Möchten Sie wirklich alle Datensätze aus der Tabelle entfernen?')) tableManager.clearTable('<?php echo htmlspecialchars($activeTable); ?>'); return false;">
                Tabelle leeren
            </a>
        </div>
    </div>
<?php endif; ?>