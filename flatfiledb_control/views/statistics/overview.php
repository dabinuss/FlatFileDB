<?php
$allStats = $stats->getOverallStatistics();
$totalSize = 0;
$totalRecords = 0;

foreach ($allStats as $tableName => $tableStat) {
    $totalSize += $tableStat['data_file_size'] + $tableStat['index_file_size'] + $tableStat['log_file_size'];
    
    if (isset($tableStat['secondary_index_files'])) {
        foreach ($tableStat['secondary_index_files'] as $indexSize) {
            $totalSize += $indexSize;
        }
    }
    
    $totalRecords += $tableStat['record_count'];
}
?>

<div class="card">
    <div class="card-header bg-primary text-white">
        <h4 class="mb-0">Datenbankstatistik</h4>
    </div>
    <div class="card-body">
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title">Tabellen</h5>
                        <p class="display-4"><?php echo count($allStats); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title">Datensätze</h5>
                        <p class="display-4"><?php echo number_format($totalRecords); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title">Größe</h5>
                        <p class="display-4"><?php echo formatBytes($totalSize); ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <h5>Tabellen-Details</h5>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Tabelle</th>
                        <th>Datensätze</th>
                        <th>Daten</th>
                        <th>Index</th>
                        <th>Log</th>
                        <th>Sek. Indizes</th>
                        <th>Gesamt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allStats as $tableName => $tableStat): ?>
                    <?php
                        $secondaryIndexSize = 0;
                        if (isset($tableStat['secondary_index_files'])) {
                            foreach ($tableStat['secondary_index_files'] as $indexSize) {
                                $secondaryIndexSize += $indexSize;
                            }
                        }
                        $totalTableSize = $tableStat['data_file_size'] + $tableStat['index_file_size'] + 
                                         $tableStat['log_file_size'] + $secondaryIndexSize;
                    ?>
                    <tr>
                        <td>
                            <a href="index.php?tab=tables&table=<?php echo htmlspecialchars($tableName); ?>">
                                <?php echo htmlspecialchars($tableName); ?>
                            </a>
                        </td>
                        <td><?php echo number_format($tableStat['record_count']); ?></td>
                        <td><?php echo formatBytes($tableStat['data_file_size']); ?></td>
                        <td><?php echo formatBytes($tableStat['index_file_size']); ?></td>
                        <td><?php echo formatBytes($tableStat['log_file_size']); ?></td>
                        <td><?php echo formatBytes($secondaryIndexSize); ?></td>
                        <td><?php echo formatBytes($totalTableSize); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>