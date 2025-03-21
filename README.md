FlatFileDB Documentation
========================

FlatFileDB: A Simple File-Based Database Solution for PHP
=========================================================

Introduction
------------

FlatFileDB is a lightweight, file-based database solution for PHP applications that requires no external database servers such as MySQL or PostgreSQL. This system is perfect for small to medium-sized projects where setting up and maintaining a full-fledged database would be disproportionate in terms of effort.

### Why FlatFileDB?

-   **Easy Installation**: No complex server configuration needed -- simply include the PHP classes and get started.
-   **Portability**: The entire database consists of plain files, which can be easily moved between different environments.
-   **No Dependencies**: Works without external libraries or services.
-   **Transparency**: Records are stored in readable JSON-lines files, making debugging and manual intervention straightforward.
-   **Performance**: Indexing ensures that data access remains efficient even with larger datasets.

### Use Cases

FlatFileDB is particularly suitable for:

-   Prototypes and proof-of-concept applications
-   Small web applications with limited data volume
-   Local tools and utilities
-   Projects with restricted server resources
-   Educational settings to learn about database concepts

### Technical Fundamentals

FlatFileDB is based on the following principles:

-   Data stored in JSON-lines format (one JSON object per line)
-   Indexing of records to speed up access
-   Transaction-safe logging for traceability of changes
-   Schemas for validating records

It is fully implemented in PHP and can be used in any environment that supports PHP.

* * * * *

1\. Overview and Preparation
----------------------------

FlatFileDB is a simple file-based database that stores records in JSON-lines files. Its key features include:

-   **CRUD Operations**: Insert, update, delete, and retrieve records.
-   **Index Management**: An internal index maps record IDs to byte offsets in the file, ensuring efficient access even with large files.
-   **Transaction Logging**: Each operation is logged, which is particularly useful for error tracing or auditing.
-   **Compaction**: Redundant (deleted or outdated) records can be removed from the file, and the index can be rebuilt.

Before you start, you should have the following files in your project:

-   FlatFileDB.php (contains all classes: FlatFileDatabase, FlatFileTableEngine, etc.)
-   A file in which you write your application logic (e.g. testdb.php)

2\. Including and Initializing the Database
-------------------------------------------

First, include the database classes and create a database instance.

Example:

php

KopierenBearbeiten

`<?php
// testdb.php

// Enable error display (development only)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include FlatFileDB
require_once 'FlatFileDB.php'; // Contains all classes (Namespace: FlatFileDB)

// Use the classes with "use"
use FlatFileDB\FlatFileDatabase;
use FlatFileDB\FlatFileDBConstants;

// Create a database instance
$db = new FlatFileDatabase(FlatFileDBConstants::DEFAULT_BASE_DIR, false);

// Register tables -- for example, here we register the "users" and "products" tables
$db->registerTables(['users', 'products']);`

3\. Defining the Schema
-----------------------

For each table, you can define a schema. The schema specifies required fields and expected data types. This is especially helpful to ensure that only valid records make it into the database.

Example:

php

KopierenBearbeiten

`// For the "users" table, we define that 'name' and 'email' are required fields.
// We also specify that 'name' and 'email' are strings, and 'age' is an integer.
$db->table('users')->setSchema(
    ['name', 'email'],
    ['name' => 'string', 'email' => 'string', 'age' => 'int']
);`

4\. CRUD Operations
-------------------

### a) Inserting a Record (Insert)

Use the `insertRecord()` method to add a new record. It's important that the ID is unique.

Example:

php

KopierenBearbeiten

`// Insert a new user
$success = $db->table('users')->insertRecord('user123', [
    'name'  => 'Alice Johnson',
    'email' => 'alice@example.com',
    'age'   => 32
]);

if ($success) {
    echo "User successfully inserted.";
} else {
    echo "Error: A user with this ID already exists.";
}

// After writing, manually commit the index
// so the current index file is used on page reload
$db->commitAllIndexes();`

### b) Updating a Record (Update)

Use `updateRecord()` to update an existing record. Older versions of the record are marked as deleted, and a new entry is appended.

Example:

php

KopierenBearbeiten

`// Update an existing user
$success = $db->table('users')->updateRecord('user123', [
    'name'  => 'Alice J.',
    'email' => 'alice_j@example.com',
    'age'   => 33
]);

if ($success) {
    echo "User successfully updated.";
} else {
    echo "Error: User not found.";
}

// Commit the index
$db->commitAllIndexes();`

### c) Deleting a Record (Delete)

Use `deleteRecord()` to delete a record. The record is marked as deleted, and the index is updated accordingly.

Example:

php

KopierenBearbeiten

`// Delete a user
$success = $db->table('users')->deleteRecord('user123');

if ($success) {
    echo "User successfully deleted.";
} else {
    echo "Error: User could not be found.";
}

// Save the index
$db->commitAllIndexes();`

### d) Retrieving a Record (Select)

Use `selectRecord()` to retrieve a single record, and `selectAllRecords()` to fetch all active (non-deleted) records.

Example:

php

KopierenBearbeiten

`// Retrieve a single user
$user = $db->table('users')->selectRecord('user123');
if ($user) {
    print_r($user);
} else {
    echo "User not found.";
}

// Retrieve all active users
$allUsers = $db->table('users')->selectAllRecords();
foreach ($allUsers as $user) {
    echo "ID: {$user['id']}, Name: {$user['name']}<br>";
}`

5\. Additional Functions
------------------------

### a) Index Management

**compactTable()**:

-   This operation "cleans up" the data file by removing outdated and deleted entries and rebuilding the index.
-   Usage: Run compaction manually or periodically, as it can be relatively intensive.

Example:

php

KopierenBearbeiten

`$db->table('users')->compactTable();
echo "Table 'users' has been compacted.";`

### b) Backup and Clearing the Database

**Creating a Backup**:

-   Use `createBackup($backupDir)` to back up all tables.

Example:

php

KopierenBearbeiten

`$backupResults = $db->createBackup(FlatFileDBConstants::DEFAULT_BACKUP_DIR);
echo "Backup has been created.";`

**Clearing the Database**:

-   Use `clearDatabase()` to delete all data, indexes, and logs.

Example:

php

KopierenBearbeiten

`$db->clearDatabase();
echo "The database has been cleared.";`

6\. Integrating into Your HTML Interface
----------------------------------------

Typically, you combine the operations described above with an HTML form to allow user interactions. A sample workflow might look like this:

**Submitting a Form via POST**:

-   Each action (insert, update, delete, search, backup, compaction) is defined by a hidden field `action`, for example:

html

KopierenBearbeiten

`<form method="post">
    <input type="hidden" name="action" value="insert_user">
    <!-- Other fields for user ID, name, etc. -->
    <button type="submit">Add User</button>
</form>`

**Executing the PHP Logic**:

-   In your PHP code, read `$_POST['action']` and run the corresponding case in a switch statement (as in the examples above).

**Feedback and Updates**:

-   After the operation, commit the index (or optionally compact the table). Then display a success message. On page reload, the current data is loaded from the file (or from the persisted index).