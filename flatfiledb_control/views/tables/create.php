<div class="card">
    <div class="card-header bg-primary text-white">
        <h4 class="mb-0">Neue Tabelle erstellen</h4>
    </div>
    <div class="card-body">
        <form id="createTableForm">
            <div class="mb-3">
                <label for="tableName" class="form-label">Tabellenname</label>
                <input type="text" class="form-control" id="tableName" name="tableName" required pattern="[a-zA-Z0-9_]+"
                    title="Nur Buchstaben, Zahlen und Unterstriche erlaubt">
                <div class="form-text">Nur Buchstaben, Zahlen und Unterstriche erlaubt.</div>
            </div>

            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="" id="defineSchema">
                    <label class="form-check-label" for="defineSchema">
                        Schema definieren
                    </label>
                </div>
            </div>

            <div id="schemaSection" style="display: none;">
                <div class="card mb-3">
                    <div class="card-header bg-secondary text-white">
                        Schema-Definition
                    </div>
                    <div class="card-body">
                        <p class="form-text">
                            Definieren Sie die Feldstruktur Ihrer Tabelle. Pflichtfelder müssen in jedem Datensatz
                            vorhanden sein.
                            Typen helfen bei der Validierung der Daten.
                        </p>

                        <div class="mb-3">
                            <button type="button" class="btn btn-info btn-sm" id="addFieldBtn">
                                Feld hinzufügen
                            </button>
                        </div>

                        <div id="fieldsContainer">
                            <!-- Felder werden dynamisch hinzugefügt -->
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between">
                <a href="index.php?tab=tables" class="btn btn-secondary">Abbrechen</a>
                <button type="submit" class="btn btn-primary">Tabelle erstellen</button>
            </div>
        </form>
    </div>
</div>

<!-- Feldvorlage (wird geklont) -->
<template id="fieldTemplate">
    <div class="card mb-2 field-row">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Feldname</label>
                    <input type="text" class="form-control field-name" required pattern="[a-zA-Z0-9_]+">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Typ</label>
                    <select class="form-select field-type">
                        <option value="string">String</option>
                        <option value="?string">Nullable String</option>
                        <option value="int">Integer</option>
                        <option value="?int">Nullable Integer</option>
                        <option value="float">Float</option>
                        <option value="?float">Nullable Float</option>
                        <option value="bool">Boolean</option>
                        <option value="?bool">Nullable Boolean</option>
                        <option value="array">Array</option>
                        <option value="?array">Nullable Array</option>
                        <option value="numeric">Numeric</option>
                        <option value="?numeric">Nullable Numeric</option>
                        <option value="scalar">Scalar</option>
                        <option value="?scalar">Nullable Scalar</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Optionen</label>
                    <div class="form-check mt-2">
                        <input class="form-check-input field-required" type="checkbox">
                        <label class="form-check-label">Pflichtfeld</label>
                    </div>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="button" class="btn btn-danger remove-field-btn">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>