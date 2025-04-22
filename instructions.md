Ja, hier ist die angepasste Kompaktanleitung, die die neuen Methoden im `FlatFileDatabaseHandler` (`findById`, `increment`, `decrement`, `createIndex`, `dropIndex`, `setSchema`, `getSchema`) berücksichtigt und die Beschreibung von `count()` aktualisiert:

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
    *   [Werte erhöhen/verringern (`increment`, `decrement`)](#werte-erhöhenverringern-increment-decrement) <!-- NEU -->
    *   [Datensätze löschen (`delete`)](#datensätze-löschen-delete)
    *   [Datensätze finden (`find`)](#datensätze-finden-find)
    *   [Einzelnen Datensatz nach ID finden (`findById`)](#einzelnen-datensatz-nach-id-finden-findbyid) <!-- NEU -->
    *   [Felder auswählen (`select`)](#felder-auswählen-select)
    *   [Sortieren (`orderBy`)](#sortieren-orderby)
    *   [Limitieren & Paginieren (`limit`, `offset`)](#limitieren--paginieren-limit-offset)
    *   [Hilfsmethoden (`first`, `exists`, `count`)](#hilfsmethoden-first-exists-count) <!-- `count` Beschreibung aktualisiert -->
    *   [Index-Verwaltung (`createIndex`, `dropIndex`)](#index-verwaltung-createindex-dropindex) <!-- NEU -->
    *   [Schema-Verwaltung (`setSchema`, `getSchema`)](#schema-verwaltung-setschema-getschema) <!-- NEU -->
    *   [Tabelle löschen (`dropTable`)](#tabelle-löschen-droptable)
3\.  [Tabellenverwaltung (Datenbank-Ebene)](#3-tabellenverwaltung-datenbank-ebene)
4\.  [Erweiterte Tabellenoperationen (Engine-Ebene)](#4-erweiterte-tabellenoperationen-engine-ebene)
    *   [Indizes verwalten (Engine)](#indizes-verwalten-engine) <!-- Umbenannt -->
    *   [Schema-Validierung (Engine)](#schema-validierung-engine) <!-- Umbenannt -->
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

// Tabelle registrieren (notwendig vor erster Verwendung)
$db->registerTable('users');
$db->registerTable('products');
```

---

## 2. Der Database Handler (Empfohlen für CRUD & Abfragen)

Der `FlatFileDatabaseHandler` bietet eine verkettbare (Fluent) Schnittstelle für die gängigsten Operationen.

### Tabelle auswählen & Kette starten

Jede Abfrage beginnt mit der Auswahl der Tabelle. Dies setzt den Zustand des Handlers zurück.

```php
$handler->table('users'); // Wählt die 'users' Tabelle
```

### Bedingungen hinzufügen (`where`)

Fügt Filterkriterien hinzu (werden mit `AND` verknüpft).

```php
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

### Daten für Insert/Update setzen (`data`)

Definiert die Nutzdaten für Einfüge- oder Aktualisierungsoperationen.

```php
// Für Einzel-Insert/Update
$handler->data(['username' => 'peter', 'email' => 'p@ex.com']);

// Für Bulk-Insert (Liste von Arrays)
$handler->data([
    ['username' => 'alice', 'email' => 'a@ex.com'],
    ['username' => 'bob', 'email' => 'b@ex.com']
]);
```

### Datensätze einfügen (`insert`)

Fügt einen oder mehrere Datensätze hinzu (basierend auf `data()`). Setzt automatisch `id`, `created_at`, `_deleted`.

```php
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

### Datensätze aktualisieren (`update`)

Aktualisiert Datensätze, die den `where`-Bedingungen entsprechen, mit den Daten aus `data()`. Setzt automatisch `updated_at`.

```php
$success = $handler->table('users')
                   ->where('active', '=', false)
                   ->data(['active' => true, 'notes' => 'Reactivated'])
                   ->update(); // Gibt true zurück, wenn >= 1 Update erfolgreich/unnötig war

$successSpecific = $handler->table('users')
                           ->where('id', '=', 5)
                           ->data(['username' => 'david'])
                           ->update(); // Gibt true oder false zurück
```

### Werte erhöhen/verringern (`increment`, `decrement`)

<!-- NEUE SEKTION -->
Erhöht oder verringert den Wert eines numerischen Feldes für alle passenden Datensätze.

```php
// Erhöhe login_count für User mit ID 5 um 1
$success = $handler->table('users')
                   ->where('id', '=', 5)
                   ->increment('login_count'); // Gibt true/false zurück

// Verringere Kontostand für alle inaktiven User um 10.5
$success = $handler->table('users')
                   ->where('active', '=', false)
                   ->decrement('balance', 10.5); // Gibt true/false zurück
```

### Datensätze löschen (`delete`)

Markiert Datensätze, die den `where`-Bedingungen entsprechen, als gelöscht (`_deleted=true`). Setzt `deleted_at`.

```php
$success = $handler->table('users')
                   ->where('username', '=', 'eve')
                   ->delete(); // Gibt true zurück, wenn >= 1 Löschung erfolgreich war
```

### Datensätze finden (`find`)

Ruft Datensätze ab, die den `where`-Bedingungen entsprechen. Berücksichtigt `select`, `orderBy`, `limit`, `offset`.

```php
$activeUsers = $handler->table('users')
                       ->where('active', '=', true)
                       ->find(); // Gibt eine Liste von Arrays zurück: [[id=>1,...], [id=>4,...]]
```

### Einzelnen Datensatz nach ID finden (`findById`)

<!-- NEUE SEKTION -->
Ruft einen einzelnen Datensatz direkt über seine ID ab. Ignoriert andere Filter/Einstellungen.

```php
$user5 = $handler->table('users')->findById(5); // Gibt Array oder null zurück
```

### Felder auswählen (`select`)

Limitiert die zurückgegebenen Felder (standardmäßig werden alle Felder plus `id` zurückgegeben).

```php
$userNames = $handler->table('users')
                     ->select(['username', 'email']) // Fordert nur username und email an
                     ->where('active', '=', true)
                     ->find(); // Ergebnis: [[id=>1, username=>'peter', email=>...], ...]
```

### Sortieren (`orderBy`)

Sortiert die Ergebnisse. **Wichtig:** Erfolgt in PHP *nach* dem Abruf.

```php
$usersSorted = $handler->table('users')
                       ->orderBy('username', 'DESC') // Absteigend nach username
                       ->find();
```

### Limitieren & Paginieren (`limit`, `offset`)

Steuert die Anzahl und den Startpunkt der Ergebnisse.

```php
// Seite 3 von Benutzern (10 pro Seite), sortiert nach Erstellung
$page3Users = $handler->table('users')
                      ->orderBy('created_at', 'DESC')
                      ->limit(10)
                      ->offset(20) // (3 - 1) * 10
                      ->find();
```

### Hilfsmethoden (`first`, `exists`, `count`)

Vereinfachen häufige Abfragen.

```php
// Ersten aktiven User finden
$firstActive = $handler->table('users')->where('active', '=', true)->first(); // Gibt Array oder null zurück

// Prüfen, ob ein User mit der E-Mail existiert
$emailExists = $handler->table('users')->where('email', '=', 'test@example.com')->exists(); // Gibt true/false zurück

// Anzahl inaktiver User zählen (nutzt optimierte Methode in der Engine)
$inactiveCount = $handler->table('users')->where('active', '=', false)->count(); // Gibt int zurück
```

### Index-Verwaltung (`createIndex`, `dropIndex`)

<!-- NEUE SEKTION -->
Erstellt oder löscht sekundäre Indizes über den Handler.

```php
// Index für 'email' erstellen (kann dauern!)
$handler->table('users')->createIndex('email');

// Index für 'email' wieder löschen
$handler->table('users')->dropIndex('email');
```

### Schema-Verwaltung (`setSchema`, `getSchema`)

<!-- NEUE SEKTION -->
Definiert oder liest das Schema für Datenvalidierung über den Handler.

```php
// Schema definieren
$handler->table('users')->setSchema(
    ['username', 'email'], // Pflichtfelder
    ['username' => 'string', 'email' => '?string', 'active' => 'bool'] // Typen
);

// Aktuelles Schema abrufen
$schema = $handler->table('users')->getSchema();
```

### Tabelle löschen (`dropTable`)

Löscht eine Tabelle vollständig aus der Datenbank, einschließlich aller Dateien und Verweise.

```php
// Eine Tabelle vollständig löschen
// ACHTUNG: Datenverlust!
try {
    // WICHTIG: Der Tabellenname muss bei table() UND dropTable() identisch sein!
    $success = $handler->table('temporary_data')
                       ->dropTable('temporary_data');
    if ($success) {
        echo "Tabelle 'temporary_data' wurde vollständig gelöscht!";
    }
} catch (RuntimeException $e) {
    echo "Fehler beim Löschen der Tabelle: " . $e->getMessage();
}

// Nach dem Löschen ist die Tabelle nicht mehr in der Datenbank registriert
// und muss bei Bedarf neu erstellt werden mit: $db->registerTable('temporary_data');
```

---

## 3. Tabellenverwaltung (Datenbank-Ebene)

Diese Operationen werden direkt auf der `$db`-Instanz ausgeführt.

```php
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

// Tabelle aus der Registrierung entfernen (löscht keine Dateien!)
// Nützlich, um Ressourcen freizugeben, wenn eine Tabelle nicht mehr benötigt wird.
// $db->unregisterTable('orders');
```

---

## 4. Erweiterte Tabellenoperationen (Engine-Ebene)

Für Operationen jenseits von CRUD und den Handler-Shortcuts muss die Tabellen-Engine verwendet werden (`$db->table('tableName')`).

### Indizes verwalten (Engine)

<!-- Abschnitt umbenannt -->
Sekundäre Indizes beschleunigen `=` Abfragen in `findRecords`.

```php
$users = $db->table('users');

// Sekundären Index für das Feld 'email' erstellen (scannt vorhandene Daten)
// (Identisch zu $handler->table('users')->createIndex('email'))
$users->createIndex('email');

// Index wieder löschen
// (Identisch zu $handler->table('users')->dropIndex('email'))
$users->dropIndex('email');

// Indizes werden bei insert/update/delete automatisch aktuell gehalten.
// Primärindex (auf 'id') wird immer automatisch verwaltet.
```

### Schema-Validierung (Engine)

<!-- Abschnitt umbenannt -->
Optional, zur Sicherstellung der Datenintegrität vor dem Speichern.

```php
$users = $db->table('users');

// Schema definieren
// (Identisch zu $handler->table('users')->setSchema(...))
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

// Schema abrufen
// (Identisch zu $handler->table('users')->getSchema())
// $schema = $users->getSchema(); // Benötigt Ergänzung der Methode in der Engine
```

### Cache-Kontrolle

Die Engine nutzt einen einfachen In-Memory-LRU-Cache für `selectRecord`.

```php
$users = $db->table('users');

// Cache-Größe ändern (Standard: 100 Einträge)
$users->setCacheSize(500);

// Cache für diese Tabelle leeren
$users->clearCache();
```

### Wartung (Kompaktieren, Leeren)

```php
$users = $db->table('users');

// Tabelle kompaktieren: Entfernt alte/gelöschte Versionen, baut Indizes neu auf.
// WICHTIG: Regelmäßig ausführen (z.B. Cronjob)! Leert auch den Cache.
$users->compactTable();

// Tabelle leeren: LÖSCHT ALLE DATEN, INDIZES, LOGS dieser Tabelle!
// $users->clearTable(); // SEHR VORSICHTIG VERWENDEN!
```

### Backup (Einzelne Tabelle)

Kopiert alle relevanten Dateien einer Tabelle in ein Backup-Verzeichnis.

```php
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

Jede Schreiboperation wird protokolliert (`_log.jsonl`).

```php
$users = $db->table('users');

// Zugriff auf das Log-Objekt (FlatFileTransactionLog)
// $log = $users->transactionLog; // Annahme: Es gibt eine getter-Methode
// Sicherer Weg über Config:
$log = new \FlatFileDB\FlatFileTransactionLog($users->getConfig());

// Letzte 50 Log-Einträge lesen
$entries = $log->readLog(50);

// Log rotieren: Aktuelles Log wird zu Backup, neues Log wird erstellt.
$backupLogPath = $log->rotateLog('/pfad/zum/log/archiv'); // Gibt Pfad zur Backup-Datei zurück
```

---

## 5. Globale Wartung & Backup (Datenbank-Ebene)

Operationen, die alle registrierten Tabellen betreffen.

```php
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

---

## 6. Statistik & Performance

Sammelt und liefert Metriken.

```php
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

---

## 7. Interna & Konfiguration

*   **Datenformat:** JSON Lines (`.jsonl`), GZIP-komprimiert (`.gz`), jede Zeile ein Datensatz-JSON. Append-Only.
*   **Indexformat:** JSON (`.json`). Primär: `{ "id": {"offset": int, "length": int}, ... }`. Sekundär: `{ "fieldValue": [id1, id2, ...], ... }`.
*   **Logformat:** JSON Lines (`.jsonl`), unkomprimiert.
*   **Konstanten:** `FlatFileDBConstants` definiert Standardpfade, Dateiendungen etc. (z.B. `::DEFAULT_BASE_DIR`).
*   **Locking:** Verwendet `flock()` (Advisory File Locking) für konkurrierende Zugriffe. Geeignet für moderate Last. Kein Ersatz für ACID-Transaktionen bei hoher Nebenläufigkeit.
*   **Fehlerbehandlung:** Wirft primär `InvalidArgumentException` (ungültige Eingaben) und `RuntimeException` (Datei-I/O, Laufzeitfehler). `JsonException` bei JSON-Problemen.

---

## 8. Wichtige Hinweise

*   **Performance:** Abfragen ohne passenden Sekundärindex (`=`) oder für andere Operatoren (>, `LIKE` etc.) führen zu einem Scan der Datensätze (via Generator, speichereffizient, aber potenziell langsam). Die `count()`-Methode ist optimiert, kann aber bei komplexen Filtern immer noch Daten laden müssen.
*   **Kompaktierung:** **Notwendig**, um gelöschte Daten physisch zu entfernen und Performance zu erhalten. Planen Sie regelmäßige Kompaktierungen (z.B. nächtlicher Cronjob).
*   **Backups:** Erstellen Sie **regelmäßig** Backups, besonders **vor** der Kompaktierung oder `clearTable`/`dropTable`/`clearDatabase`.
*   **Skalierbarkeit:** Gut geeignet für kleine bis mittlere Projekte mit moderatem Schreibaufkommen und einfachen Abfragen. Für hohe Last, komplexe Joins oder ACID-Garantien sind relationale oder NoSQL-Datenbanken besser geeignet.
*   **Atomarität:** Einzelne Dateioperationen sind durch Locking relativ sicher. Komplexe Abläufe (z.B. `update`, `delete`, `compact`) bestehen aus mehreren Schritten und sind nicht vollständig atomar. Bei Fehlern wird ein Rollback versucht, aber Systemabstürze zur falschen Zeit können zu Inkonsistenzen führen (Backup ist wichtig!).