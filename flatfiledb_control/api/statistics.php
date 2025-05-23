<?php
require_once '../init.php';
requireAjax();

$action = isset($_POST['action']) ? $_POST['action'] : '';

switch ($action) {
    case 'table':
        // Statistiken für eine Tabelle
        $tableName = isset($_POST['table']) ? trim($_POST['table']) : '';

        if (empty($tableName)) {
            outputJSON(['error' => 'Tabellenname ist erforderlich']);
            exit;
        }

        try {
            $tableStats = $stats->getTableStatistics($tableName);
            outputJSON(['success' => true, 'statistics' => $tableStats]);
            exit;
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
            exit;
            
        }
        // break;

    case 'overall':
        // Gesamtstatistiken
        try {
            $allStats = $stats->getOverallStatistics();
            outputJSON(['success' => true, 'statistics' => $allStats]);
            exit;
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
            exit;
        }
        // break;

    case 'performance':
        // Performance-Metriken
        try {
            $metrics = FlatFileDB\FlatFileDBStatistics::getPerformanceMetrics();
            $aggregated = FlatFileDB\FlatFileDBStatistics::getAggregatedPerformanceMetrics();
            outputJSON([
                'success' => true,
                'metrics' => $metrics,
                'aggregated' => $aggregated
            ]);
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
            exit;
        }
        // break;

    case 'reset_metrics':
        // Performance-Metriken zurücksetzen
        try {
            FlatFileDB\FlatFileDBStatistics::resetPerformanceMetrics();
            outputJSON(['success' => true, 'message' => 'Metriken erfolgreich zurückgesetzt']);
            exit;
        } catch (Exception $e) {
            outputJSON(['error' => $e->getMessage()]);
            exit;
        }
        // break;

    default:
        outputJSON(['error' => 'Ungültige Aktion']);
        exit;
        // break;
}