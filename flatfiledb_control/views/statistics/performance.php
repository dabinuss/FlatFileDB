<?php
$aggregatedMetrics = FlatFileDB\FlatFileDBStatistics::getAggregatedPerformanceMetrics();
?>

<div class="card">
    <div class="card-header bg-primary text-white">
        <h4 class="mb-0">Performance-Metriken</h4>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <h5>Über Performance-Metriken</h5>
            <p>
                Diese Metriken zeigen die Dauer verschiedener Datenbankoperationen an.
                Sie können helfen, Engpässe zu identifizieren und die Datenbankleistung zu optimieren.
            </p>
            <p class="mb-0">
                <strong>Hinweis:</strong> Metriken werden seit dem letzten Neustart der Anwendung oder dem letzten Zurücksetzen gesammelt.
            </p>
        </div>
        
        <div class="mb-3">
            <button type="button" class="btn btn-primary" id="refreshMetricsBtn">
                <i class="bi bi-arrow-clockwise"></i> Aktualisieren
            </button>
            <button type="button" class="btn btn-warning" id="resetMetricsBtn">
                Metriken zurücksetzen
            </button>
        </div>
        
        <?php if (empty($aggregatedMetrics)): ?>
        <div class="alert alert-secondary">
            Keine Metriken verfügbar. Führen Sie einige Datenbankoperationen aus, um Metriken zu sammeln.
        </div>
        <?php else: ?>
        <div class="table-responsive mb-4">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Operation</th>
                        <th>Anzahl</th>
                        <th>Min (s)</th>
                        <th>Max (s)</th>
                        <th>Durchschnitt (s)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($aggregatedMetrics as $operation => $metrics): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($operation); ?></td>
                        <td><?php echo number_format($metrics['count']); ?></td>
                        <td><?php echo number_format($metrics['min'], 6); ?></td>
                        <td><?php echo number_format($metrics['max'], 6); ?></td>
                        <td><?php echo number_format($metrics['avg'], 6); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div id="performanceChartContainer">
            <!-- Hier wird das Diagramm eingefügt -->
        </div>
        <?php endif; ?>
    </div>
</div>