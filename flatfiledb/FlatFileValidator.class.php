<?php
declare(strict_types=1);

namespace FlatFileDB;

use InvalidArgumentException;

/**
 * Zentrale Hilfsklasse für Validierungen.
 */
class FlatFileValidator
{
    /**
     * Überprüft, ob eine ID gültig ist (nur positive ganze Zahlen).
     *
     * @param string|int $recordId Die zu prüfende ID
     * @return bool True wenn gültig, sonst false
     */
    public static function isValidId(string|int $recordId): bool
    {
        // Erlaubt nur positive ganze Zahlen als String oder Integer
        // filter_var ist robuster als preg_match für Integer-Validierung
        $intVal = filter_var($recordId, FILTER_VALIDATE_INT);
        return $intVal !== false && $intVal > 0;
    }

    /**
     * Validiert Felder eines Datensatzes anhand eines Schemas
     *
     * @param array $data Die zu validierenden Daten
     * @param array $requiredFields Liste der Pflichtfelder
     * @param array $fieldTypes Assoziatives Array mit Feldname => Erwarteter Typ
     * @throws InvalidArgumentException wenn Validierung fehlschlägt
     */
    public static function validateData(array $data, array $requiredFields = [], array $fieldTypes = []): void
    {
        // Pflichtfelder prüfen
        foreach ($requiredFields as $field) {
            // Verwende array_key_exists, um Felder zu erkennen, die explizit auf null gesetzt sind
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Fehlendes Pflichtfeld: $field");
            }
            // Optional: Auch auf leere Werte prüfen, falls gewünscht (berücksichtige 0 und false)
            // if (empty($data[$field]) && $data[$field] !== 0 && $data[$field] !== false) {
            //     throw new InvalidArgumentException("Pflichtfeld '$field' darf nicht leer sein.");
            // }
        }

        // Datentypen prüfen
        foreach ($fieldTypes as $field => $type) {
            if (array_key_exists($field, $data)) { // Prüfen, ob das Feld überhaupt existiert
                $actualValue = $data[$field];

                // Erlaube null, wenn der Typ es zulässt (z.B. "?string")
                $allowsNull = str_starts_with($type, '?');
                if ($allowsNull) {
                    $type = substr($type, 1); // Entferne das '?' für den Typ-Check
                    if ($actualValue === null) {
                        continue; // Null ist erlaubt, weiter zum nächsten Feld
                    }
                }

                // Behandle 'numeric' und 'scalar' separat
                if (strtolower($type) === 'numeric') {
                    if (!is_numeric($actualValue)) {
                        throw new InvalidArgumentException(sprintf(
                            "Feld '%s' muss numerisch sein (ist '%s').",
                            $field,
                            get_debug_type($actualValue)
                        ));
                    }
                    continue; // Gültig, weiter
                }
                if (strtolower($type) === 'scalar') {
                    if (!is_scalar($actualValue)) {
                        throw new InvalidArgumentException(sprintf(
                            "Feld '%s' muss skalar sein (ist '%s').",
                            $field,
                            get_debug_type($actualValue)
                        ));
                    }
                    continue; // Gültig, weiter
                }


                $validType = match (strtolower($type)) {
                    'string' => is_string($actualValue),
                    'int', 'integer' => is_int($actualValue),
                    'float', 'double' => is_float($actualValue),
                    'bool', 'boolean' => is_bool($actualValue),
                    'array' => is_array($actualValue),
                    'object' => is_object($actualValue),
                    'null' => is_null($actualValue), // Expliziter Check für 'null' Typ
                    // Füge ggf. weitere spezifische Typen hinzu
                    default => throw new InvalidArgumentException("Unbekannter Typ '$type' für Feld '$field' im Schema.")
                };

                if (!$validType) {
                    // Verwende get_debug_type für präzisere Typinformationen (PHP 8+)
                    $actualType = get_debug_type($actualValue);
                    throw new InvalidArgumentException("Feld '$field' hat nicht den erwarteten Typ '$type' (ist '$actualType').");
                }
            }
        }
    }
}