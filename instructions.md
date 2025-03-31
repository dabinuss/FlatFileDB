
# FlatFileDB - Kompakte Anleitung

Diese Anleitung beschreibt die Verwendung der FlatFileDB-Bibliothek in PHP-Projekten und deckt alle Kernfunktionen ab.

**Übersicht**

1.  [Initialisierung](#1-initialisierung)
2\.  [Der Database Handler (Empfohlen für CRUD & Abfragen)](#2-der-database-handler-empfohlen-für-crud--abfragen)
    *   [Tabelle auswählen & Kette starten](#tabelle-auswählen--kette-starten)
    *   [Bedingungen hinzufügen (`where`)](#bedingungen-hinzufügen-where)
    *   [Daten für Insert/Update setzen (`data`)](#daten-für-insertupdate-setzen-data)
    *   [Datensätze einfügen (`insert`)](#datensätze-einfügen-insert)
    *   [Datensätze aktualisieren (`update`)](#datensätze-aktualisieren-update)
    *   [Datensätze löschen (`delete`)](#datensätze-löschen-delete)
    *   [Datensätze finden (`find`)](#datensätze-finden-find)
    *   [Felder auswählen (`select`)](#felder-auswählen-select)
    *   [Sortieren (`orderBy`)](#sortieren-orderby)
    *   [Limitieren & Paginieren (`limit`, `offset`)](#limitieren--paginieren-limit-offset)
    *   [Hilfsmethoden (`first`, `exists`, `count`)](#hilfsmethoden-first-exists-count)
3\.  [Tabellenverwaltung (Datenbank-Ebene)](#3-tabellenverwaltung-datenbank-ebene)
4\.  [Erweiterte Tabellenoperationen (Engine-Ebene)](#4-erweiterte-tabellenoperationen-engine-ebene)
    *   [Indizes verwalten](#indizes-verwalten)
    *   [Schema-Validierung](#schema-validierung)
    *   [Cache-Kontrolle](#cache-kontrolle)
    *   [Wartung (Kompaktieren, Leeren)](#wartung-kompaktieren-leeren)
    *   [Backup (Einzelne Tabelle)](#backup-einzelne-tabelle)
    *   [Transaktionslog](#transaktionslog)
5\.  [Globale Wartung & Backup (Datenbank-Ebene)](#5-globale-wartung--backup-datenbank-ebene)
6\.  [Statistik & Performance](#6-statistik--performance)
7\.  [Interna & Konfiguration](#7-interna--konfiguration)
8\.  [Wichtige Hinweise](#8-wichtige-hinweise)

---

## 1. Initialisierung

```php
<?php
require 'vendor/autoload.php'; // Falls Composer genutzt wird

use FlatFileDB\FlatFileDatabase;
use FlatFileDB\FlatFileDatabaseHandler;
use FlatFileDB\FlatFileDBStatistics;
use FlatFileDB\FlatFileDBConstants; // Optional, für Konstanten

// Datenbank-Instanz erstellen (Standard-Datenverzeichnis: 'data/')
$db = new FlatFileDatabase();

// Oder mit eigenem Verzeichnis:
// $db = new FlatFileDatabase('/pfad/zu/deinen/daten');

// Handler für Fluent-Interface erstellen (empfohlen!)
$handler = new FlatFileDatabaseHandler($db);
```


2\. Der Database Handler (Empfohlen für CRUD & Abfragen)
--------------------------------------------------------

Der FlatFileDatabaseHandler bietet eine verkettbare (Fluent) Schnittstelle für die gängigsten Operationen.

### Tabelle auswählen & Kette starten

Jede Abfrage beginnt mit der Auswahl der Tabelle. Dies setzt den Zustand des Handlers zurück.

```
$handler->table('users'); // Wählt die 'users' Tabelle
```


### Bedingungen hinzufügen (where)

Fügt Filterkriterien hinzu (werden mit AND verknüpft).

```
$handler->table('users')
        ->where('active', '=', true)
        ->where('login_count', '>', 10);

// Unterstützte Operatoren:
// '=', '!=', '>', '<', '>=', '<=', '===' (strikter Vergleich), '!==' (strikter Vergleich)
// 'LIKE', 'NOT LIKE' (mit % und _ als Wildcards)
// 'IN', 'NOT IN' (value muss ein Array sein)
// 'IS NULL', 'IS NOT NULL' (value wird ignoriert)

$handler->table('products')
        ->where('category', 'IN', ['electronics', 'books'])
        ->where('description', 'LIKE', '%new%')
        ->where('deleted_at', 'IS NULL');
```


### Daten für Insert/Update setzen (data)

Definiert die Nutzdaten für Einfüge- oder Aktualisierungsoperationen.

```
// Für Einzel-Insert/Update
$handler->data(['username' => 'peter', 'email' => 'p@ex.com']);

// Für Bulk-Insert (Liste von Arrays)
$handler->data([
    ['username' => 'alice', 'email' => 'a@ex.com'],
    ['username' => 'bob', 'email' => 'b@ex.com']
]);
```


### Datensätze einfügen (insert)

Fügt einen oder mehrere Datensätze hinzu (basierend auf data()).

```
// Einzel-Insert
$newUserId = $handler->table('users')
                    ->data(['username' => 'carol', 'active' => true])
                    ->insert(); // Gibt die neue int ID zurück (z.B. 4)

// Bulk-Insert
$results = $handler->table('users')
                   ->data([ ['username' => 'dave'], ['username' => 'eve'] ])
                   ->insert();
// Gibt Array zurück: [5, 6] oder [5, ['error' => '...']]
```


Setzt automatisch 

### Datensätze aktualisieren (update)

Aktualisiert Datensätze, die den where-Bedingungen entsprechen, mit den Daten aus data().

```
$success = $handler->table('users')
                   ->where('active', '=', false)
                   ->data(['active' => true, 'notes' => 'Reactivated'])
                   ->update(); // Gibt true zurück, wenn >= 1 Update erfolgreich/unnötig war

$successSpecific = $handler->table('users')
                           ->where('id', '=', 5)
                           ->data(['username' => 'david'])
                           ->update(); // Gibt true oder false zurück
```


Setzt automatisch 

### Datensätze löschen (delete)

Markiert Datensätze, die den where-Bedingungen entsprechen, als gelöscht (_deleted=true).

```
$success = $handler->table('users')
                   ->where('username', '=', 'eve')
                   ->delete(); // Gibt true zurück, wenn >= 1 Löschung erfolgreich war
```


Setzt 

### Datensätze finden (find)

Ruft Datensätze ab, die den where-Bedingungen entsprechen. Berücksichtigt select, orderBy, limit, offset.

```
$activeUsers = $handler->table('users')
                       ->where('active', '=', true)
                       ->find(); // Gibt eine Liste von Arrays zurück: [[id=>1,...], [id=>4,...]]
```


### Felder auswählen (select)

Limitiert die zurückgegebenen Felder (standardmäßig werden alle Felder plus id zurückgegeben).

```
$userNames = $handler->table('users')
                     ->select(['username', 'email']) // Fordert nur username und email an
                     ->where('active', '=', true)
                     ->find(); // Ergebnis: [[id=>1, username=>'peter', email=>...], ...]
```


### Sortieren (orderBy)

Sortiert die Ergebnisse. **Wichtig:** Erfolgt in PHP nach dem Abruf.

```
$usersSorted = $handler->table('users')
                       ->orderBy('username', 'DESC') // Absteigend nach username
                       ->find();
```


### Limitieren & Paginieren (limit, offset)

Steuert die Anzahl und den Startpunkt der Ergebnisse.

```
// Seite 3 von Benutzern (10 pro Seite), sortiert nach Erstellung
$page3Users = $handler->table('users')
                      ->orderBy('created_at', 'DESC')
                      ->limit(10)
                      ->offset(20) // (3 - 1) * 10
                      ->find();
```


### Hilfsmethoden (first, exists, count)

Vereinfachen häufige Abfragen.

```
// Ersten aktiven User finden
$firstActive = $handler->table('users')->where('active', '=', true)->first(); // Gibt Array oder null zurück

// Prüfen, ob ein User mit der E-Mail existiert
$emailExists = $handler->table('users')->where('email', '=', 'test@example.com')->exists(); // Gibt true/false zurück

// Anzahl inaktiver User zählen (Achtung: kann langsam sein!)
$inactiveCount = $handler->table('users')->where('active', '=', false)->count(); // Gibt int zurück
```


3\. Tabellenverwaltung (Datenbank-Ebene)
----------------------------------------

Diese Operationen werden direkt auf der $db-Instanz ausgeführt.

```
// Tabelle registrieren (erstellt Dateien, falls nicht vorhanden)
$usersTableEngine = $db->registerTable('users');
$productsTableEngine = $db->registerTable('products');

// Mehrere auf einmal registrieren
$db->registerTables(['orders', 'categories']);

// Prüfen, ob Tabelle registriert ist
if ($db->hasTable('users')) { /* ... */ }

// Engine für registrierte Tabelle holen (für erweiterte Operationen)
$users = $db->table('users'); // Gibt FlatFileTableEngine zurück

// Alle registrierten Tabellennamen
$names = $db->getTableNames(); // ['users', 'products', 'orders', 'categories']
```


4\. Erweiterte Tabellenoperationen (Engine-Ebene)
-------------------------------------------------

Für Operationen jenseits von CRUD muss die Tabellen-Engine verwendet werden ($db->table('tableName')).

### Indizes verwalten

Sekundäre Indizes beschleunigen = Abfragen in findRecords (wird vom Handler automatisch genutzt).

```
$users = $db->table('users');

// Sekundären Index für das Feld 'email' erstellen (scannt vorhandene Daten)
$users->createIndex('email');

// Index wieder löschen
$users->dropIndex('email');

// Indizes werden bei insert/update/delete automatisch aktuell gehalten.
// Primärindex (auf 'id') wird immer automatisch verwaltet.
```


### Schema-Validierung

Optional, zur Sicherstellung der Datenintegrität vor dem Speichern.

```
$users = $db->table('users');
$users->setSchema(
    ['username', 'email'], // Pflichtfelder
    [                      // Erwartete Datentypen (PHP-Typen + 'numeric', 'scalar')
        'username' => 'string',
        'email' => '?string', // Nullable String
        'active' => 'bool',
        'login_count' => 'int',
        'balance' => 'float',
        'tags' => 'array',
        'last_login' => '?int' // Nullable Integer
    ]
);
// Wirft InvalidArgumentException bei insert/update, wenn Schema verletzt wird.
```


### Cache-Kontrolle

Die Engine nutzt einen einfachen In-Memory-LRU-Cache für selectRecord.

```
$users = $db->table('users');

// Cache-Größe ändern (Standard: 100 Einträge)
$users->setCacheSize(500);

// Cache für diese Tabelle leeren
$users->clearCache();
```


### Wartung (Kompaktieren, Leeren)

```
$users = $db->table('users');

// Tabelle kompaktieren: Entfernt alte/gelöschte Versionen, baut Indizes neu auf.
// WICHTIG: Regelmäßig ausführen (z.B. Cronjob)! Leert auch den Cache.
$users->compactTable();

// Tabelle leeren: LÖSCHT ALLE DATEN, INDIZES, LOGS dieser Tabelle!
// $users->clearTable(); // SEHR VORSICHTIG VERWENDEN!
```


### Backup (Einzelne Tabelle)

Kopiert alle relevanten Dateien einer Tabelle in ein Backup-Verzeichnis.

```
$users = $db->table('users');
$backupFiles = $users->backup('/pfad/zum/backup/ordner');
/* Gibt Array zurück, z.B.:
[
    'data' => '/pfad/.../users_data.jsonl.gz.bak.20231027120000_abcdef',
    'index' => '/pfad/.../users_index.json.bak.20231027120000_abcdef',
    'secondary_indexes' => [
        'email' => '/pfad/.../users_index_email.json.bak.20231027120000_abcdef'
    ],
    'log' => '/pfad/.../users_log.jsonl.bak.20231027120000_abcdef'
]
*/
```


### Transaktionslog

Jede Schreiboperation wird protokolliert (_log.jsonl).

```
$users = $db->table('users');

// Zugriff auf das Log-Objekt (FlatFileTransactionLog)
$log = $users->transactionLog; // Oder: new FlatFileTransactionLog($users->getConfig());

// Letzte 50 Log-Einträge lesen
$entries = $log->readLog(50);

// Log rotieren: Aktuelles Log wird zu Backup, neues Log wird erstellt.
$backupLogPath = $log->rotateLog('/pfad/zum/log/archiv'); // Gibt Pfad zur Backup-Datei zurück
```


5\. Globale Wartung & Backup (Datenbank-Ebene)
----------------------------------------------

Operationen, die alle registrierten Tabellen betreffen.

```
// Alle Indizes aller Tabellen speichern (falls Änderungen anstehen)
$db->commitAllIndexes();

// Alle Tabellen kompaktieren
$compactionResults = $db->compactAllTables();
// Gibt Array zurück: ['users' => true, 'products' => 'Compaction failed: ...']

// Backup aller Tabellen erstellen
$allBackupResults = $db->createBackup('/pfad/zum/backup/ordner');
// ['users' => ['data'=>...], 'products' => ['data'=>...], ...]

// Alle Caches aller Tabellen leeren
$db->clearAllCaches();

// Gesamte Datenbank leeren (ruft clearTable für alle Tabellen auf!)
// $db->clearDatabase(); // EXTREME VORSICHT!
```


6\. Statistik & Performance
---------------------------

Sammelt und liefert Metriken.

```
// Statistik-Objekt erstellen
$stats = new FlatFileDBStatistics($db);

// Statistiken für eine Tabelle
$userStats = $stats->getTableStatistics('users');
/* Gibt zurück (Beispiel):
   [
       'record_count' => 150,      // Anzahl aktiver Datensätze im Index
       'data_file_size' => 102400, // Größe Daten (.jsonl.gz) in Bytes
       'index_file_size' => 5120,  // Größe Primärindex (.json)
       'log_file_size' => 20480,   // Größe Log (.jsonl)
       'secondary_index_files' => [ // Größen Sekundärindizes
           'email' => 3072
       ]
   ]
*/

// Statistiken für alle Tabellen
$allStats = $stats->getOverallStatistics(); // ['users' => [...], 'products' => [...]]

// --- Performance ---
// Engine/Handler zeichnen viele Operationen automatisch auf.

// Manuell messen:
$measurement = FlatFileDBStatistics::measurePerformance(function () use ($handler) {
    return $handler->table('users')->where('active', '=', true)->find();
});
// $measurement['duration'] enthält Zeit in Sekunden
// $measurement['result'] enthält das Ergebnis der Funktion

// Gesammelte Performance-Metriken abrufen
$metrics = FlatFileDBStatistics::getPerformanceMetrics();
// ['INSERT' => [0.001, ...], 'FIND' => [...], 'CACHE_HIT' => [...], ...]

// Aggregierte Metriken (Min, Max, Avg, Count)
$aggMetrics = FlatFileDBStatistics::getAggregatedPerformanceMetrics();

// Metriken zurücksetzen
FlatFileDBStatistics::resetPerformanceMetrics();
```


7\. Interna & Konfiguration
---------------------------

-   **Datenformat:** JSON Lines (.jsonl), GZIP-komprimiert (.gz), jede Zeile ein Datensatz-JSON. Append-Only.

    -   **Indexformat:** JSON (.json). Primär: { "id": {"offset": int, "length": int}, ... }. Sekundär: { "fieldValue": [id1, id2, ...], ... }.

    -   **Logformat:** JSON Lines (.jsonl), unkomprimiert.

    -   **Konstanten:** FlatFileDBConstants definiert Standardpfade, Dateiendungen etc. (z.B. ::DEFAULT_BASE_DIR).

    -   **Locking:** Verwendet flock() (Advisory File Locking) für konkurrierende Zugriffe. Geeignet für moderate Last. Kein Ersatz für ACID-Transaktionen bei hoher Nebenläufigkeit.

    -   **Fehlerbehandlung:** Wirft primär InvalidArgumentException (ungültige Eingaben) und RuntimeException (Datei-I/O, Laufzeitfehler). JsonException bei JSON-Problemen.

8\. Wichtige Hinweise
---------------------

-   **Performance:** Abfragen ohne passenden Sekundärindex (=) oder für andere Operatoren (>, LIKE etc.) führen zu einem Scan der Datensätze (via Generator, speichereffizient, aber potenziell langsam). count() kann viele Daten laden.

    -   **Kompaktierung:** **Notwendig**, um gelöschte Daten physisch zu entfernen und Performance zu erhalten. Planen Sie regelmäßige Kompaktierungen (z.B. nächtlicher Cronjob).

    -   **Backups:** Erstellen Sie **regelmäßig** Backups, besonders vor der Kompaktierung oder clearTable/clearDatabase.

    -   **Skalierbarkeit:** Gut geeignet für kleine bis mittlere Projekte mit moderatem Schreibaufkommen und einfachen Abfragen. Für hohe Last, komplexe Joins oder ACID-Garantien sind relationale oder NoSQL-Datenbanken besser geeignet.

    -   **Atomarität:** Einzelne Dateioperationen sind durch Locking relativ sicher. Komplexe Abläufe (z.B. update, delete, compact) bestehen aus mehreren Schritten und sind nicht vollständig atomar. Bei Fehlern wird ein Rollback versucht, aber Systemabstürze zur falschen Zeit können zu Inkonsistenzen führen (Backup ist wichtig!).