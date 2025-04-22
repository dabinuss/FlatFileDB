<?php
require_once 'init.php';

ini_set('display_errors', '1'); // Fehler im Browser anzeigen (Unsicher für Produktion!)
ini_set('display_startup_errors', '1'); // Auch Startfehler anzeigen
ini_set('error_log', __DIR__ . '/error_log.txt');
ini_set('log_errors', '1');
error_reporting(E_ALL); // Alle Fehler melden

$noDatabaseSelected = !isset($GLOBALS['db']) || $GLOBALS['db'] === null;

// Aktiven Tab erkennen
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : ($noDatabaseSelected ? 'maintenance' : 'tables'); // Standard auf Maintenance, wenn keine DB
$validTabs = ['tables', 'maintenance', 'statistics'];
$activeTab = in_array($activeTab, $validTabs) ? $activeTab : ($noDatabaseSelected ? 'maintenance' : 'tables');

// Aktive Tabelle erkennen
$activeTable = '';
$tableNames = [];
$tableExists = false;
if (!$noDatabaseSelected) { // Nur wenn DB Objekt existiert
    $activeTable = isset($_GET['table']) ? $_GET['table'] : '';
    try {
        $tableNames = $db->getTableNames(); // $db sollte hier existieren
        $tableExists = in_array($activeTable, $tableNames);
    } catch (Exception $e) {
        error_log("Fehler beim Holen der Tabellennamen in index.php: " . $e->getMessage());
        // Behandlung, falls getTableNames fehlschlägt (sollte durch init.php abgefangen sein)
        $noDatabaseSelected = true; // Setze Flag, um DB-Ansichten zu verhindern
        $activeTab = 'maintenance';
    }

}

// Tab-spezifische Aktion
$action = isset($_GET['action']) ? $_GET['action'] : '';
// Wenn keine DB ausgewählt ist, leite ggf. auf die DB-Verwaltung um
if ($noDatabaseSelected && $activeTab !== 'maintenance') {
    $activeTab = 'maintenance';
    $action = 'databases'; // Zeige DB-Verwaltung als Standard
}

?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FlatFileDB Control Center</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/lib/bootstrap.bundle.min.js"></script>
</head>

<body>
    <div class="container-fluid">
        <?php include 'includes/header.php'; ?>

        <?php if ($noDatabaseSelected): ?>
            <div class="container mt-3"> <!-- Container für bessere Darstellung -->
                <div class="alert alert-warning">
                    <h4 class="alert-heading">Keine Datenbank ausgewählt oder verfügbar!</h4>
                    <p>
                        Bitte erstellen Sie eine neue Datenbank oder wählen Sie eine vorhandene aus.
                    </p>
                    <hr>
                    <p class="mb-0">
                        Gehen Sie zur <a href="index.php?tab=maintenance&action=databases"
                            class="alert-link">Datenbankverwaltung</a>.
                    </p>
                </div>
            </div>
            <?php // Verhindere das Laden der Sidebar/des Hauptinhalts, wenn keine DB ausgewählt ist? Oder lasse Wartung zu. ?>
        <?php elseif (!is_null($GLOBALS['currentDataDir']) && !is_dir($GLOBALS['currentDataDir'])): ?>
            <div class="container mt-3">
                <div class="alert alert-danger">
                    <h4 class="alert-heading">Datenbankverzeichnis nicht gefunden!</h4>
                    <p>
                        Das Verzeichnis für die ausgewählte Datenbank
                        '<?php echo htmlspecialchars($GLOBALS['currentDb'] ?? 'Unbekannt'); ?>'
                        (<code><?php echo htmlspecialchars($GLOBALS['currentDataDir']); ?></code>) existiert nicht.
                        Möglicherweise wurde sie gelöscht.
                    </p>
                    <p class="mb-0">
                        Bitte wählen Sie eine andere Datenbank aus oder gehen Sie zur <a
                            href="index.php?tab=maintenance&action=databases" class="alert-link">Datenbankverwaltung</a>.
                    </p>
                </div>
            </div>
        <?php else: // Nur wenn DB ausgewählt und Verzeichnis existiert ?>

            <div class="row">
                <div class="col-md-3 col-lg-2 sidebar">
                    <?php include 'includes/tables_sidebar.php'; ?>
                </div>

                <div class="col-md-9 col-lg-10 main-content">
                    <?php
                    // Hauptinhalt basierend auf Tab laden
                    switch ($activeTab) {
                        case 'tables':
                            if (!empty($activeTable) && $tableExists) {
                                // Tabellenverwaltung
                                switch ($action) {
                                    case 'edit':
                                        include 'views/data/edit.php';
                                        break;
                                    case 'insert':
                                        include 'views/data/insert.php';
                                        break;
                                    case 'manage':
                                        include 'views/tables/manage.php';
                                        break;
                                    default:
                                        include 'views/data/browse.php';
                                        break;
                                }
                            } elseif ($action == 'create') {
                                include 'views/tables/create.php';
                            } else {
                                error_log(">>> DEBUG index.php vor include list.php: \$db type = " . (isset($db) ? (is_object($db) ? get_class($db) : gettype($db)) : 'Not Set'));
                                include 'views/tables/list.php';
                            }
                            break;

                        case 'maintenance':
                            switch ($action) {
                                case 'compact':
                                    include 'views/maintenance/compact.php';
                                    break;
                                case 'backup':
                                    include 'views/maintenance/backup.php';
                                    break;
                                case 'logs':
                                    include 'views/maintenance/logs.php';
                                    break;
                                case 'databases':  // NEUE OPTION
                                    include 'views/maintenance/databases.php';
                                    break;
                                default:
                                    include 'views/maintenance/compact.php';
                                    break;
                            }
                            break;

                        case 'statistics':
                            switch ($action) {
                                case 'performance':
                                    include 'views/statistics/performance.php';
                                    break;
                                default:
                                    include 'views/statistics/overview.php';
                                    break;
                            }
                            break;
                    }
                    ?>
                </div>
            </div>
        <?php endif; // Ende der Prüfung auf $noDatabaseSelected und existierendes Verzeichnis ?>

        <?php include 'includes/footer.php'; ?>
    </div>

    <script src="lib/bootstrap.bundle.min.js"></script>
    <script src="js/main.js"></script>

    <?php if ($activeTab == 'tables'): ?>
        <script src="js/table_manager.js"></script>
        <script src="js/data_manager.js"></script>
    <?php elseif ($activeTab == 'maintenance'): ?>
        <script src="js/maintenance.js"></script>
    <?php elseif ($activeTab == 'statistics'): ?>
        <script src="js/statistics.js"></script>
    <?php endif; ?>
</body>

</html>