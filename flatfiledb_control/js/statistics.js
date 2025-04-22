/**
 * Statistikfunktionen für das FlatFileDB Control Center
 */
const statistics = {
    init: function() {
        this.initPerformanceMetrics();
    },
    
    /**
     * Initialisiert die Performance-Metriken-Anzeige
     */
    initPerformanceMetrics: function() {
        const refreshBtn = document.getElementById('refreshMetricsBtn');
        const resetBtn = document.getElementById('resetMetricsBtn');
        const chartContainer = document.getElementById('performanceChartContainer');
        
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                this.loadPerformanceMetrics();
            });
        }
        
        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                if (confirm('Möchten Sie wirklich alle Performance-Metriken zurücksetzen?')) {
                    this.resetPerformanceMetrics();
                }
            });
        }
        
        if (chartContainer) {
            this.loadPerformanceMetrics();
        }
    },
    
    /**
     * Lädt Performance-Metriken vom Server
     */
    loadPerformanceMetrics: function() {
        app.ajax('api/statistics.php', {
            action: 'performance'
        }, (success, response) => {
            if (success && response.aggregated) {
                this.renderPerformanceChart(response.aggregated);
            }
        });
    },
    
    /**
     * Setzt die Performance-Metriken zurück
     */
    resetPerformanceMetrics: function() {
        app.ajax('api/statistics.php', {
            action: 'reset_metrics'
        }, (success) => {
            if (success) {
                app.showStatus('Performance-Metriken wurden zurückgesetzt', 'success');
                this.loadPerformanceMetrics();
            }
        });
    },
    
    /**
     * Zeigt ein einfaches Balkendiagramm für die Performance-Metriken an
     */
    renderPerformanceChart: function(metrics) {
        const chartContainer = document.getElementById('performanceChartContainer');
        if (!chartContainer) return;
        
        // Einfaches HTML-basiertes Balkendiagramm erstellen
        let html = '<h5 class="mt-4 mb-3">Durchschnittliche Ausführungszeit (Sekunden)</h5>';
        
        // Finde den max. Wert für die Skalierung
        let maxAvg = 0;
        for (const op in metrics) {
            if (metrics[op].avg > maxAvg) {
                maxAvg = metrics[op].avg;
            }
        }
        
        // Sortiere Operationen nach Durchschnittswert absteigend
        const sortedOps = Object.keys(metrics).sort((a, b) => {
            return metrics[b].avg - metrics[a].avg;
        });
        
        html += '<div class="chart-container" style="margin-top: 10px;">';
        
        for (const op of sortedOps) {
            const metric = metrics[op];
            const percentage = (metric.avg / maxAvg) * 100;
            const barColor = this.getBarColor(op);
            
            html += `
            <div class="chart-row mb-2">
                <div class="chart-label" style="width: 150px; display: inline-block;">${op}</div>
                <div class="chart-bar" style="display: inline-block; width: calc(100% - 250px);">
                    <div style="background-color: ${barColor}; height: 20px; width: ${percentage}%; min-width: 2px;"></div>
                </div>
                <div class="chart-value" style="width: 100px; display: inline-block; text-align: right; padding-left: 10px;">
                    ${metric.avg.toFixed(6)}s
                </div>
            </div>
            `;
        }
        
        html += '</div>';
        chartContainer.innerHTML = html;
    },
    
    /**
     * Liefert eine Farbe für das Balkendiagramm basierend auf der Operation
     */
    getBarColor: function(operation) {
        const colors = {
            'INSERT': '#28a745',
            'UPDATE': '#fd7e14',
            'DELETE': '#dc3545',
            'FIND': '#17a2b8',
            'SELECT': '#6610f2',
            'CACHE_HIT': '#20c997',
            'CACHE_MISS': '#ffc107'
        };
        
        if (operation in colors) {
            return colors[operation];
        }
        
        // Default-Farbe
        return '#007bff';
    }
};

// Nach DOM-Laden initialisieren
document.addEventListener('DOMContentLoaded', function() {
    statistics.init();
});