<?php
/**
 * Autor:    Sebastian Gräbner (sebastian@ju.nu)
 * Firma:    JUNU Marketing Group LTD
 * Datum:    2025-01-05
 * Zweck:    Service-Klasse zum Einlesen und Parsen von CSV-Dateien.
 */

namespace JUNU\ShopwareAffiliateSync\Service;

use Psr\Log\LoggerInterface;

final class CsvService
{
    /**
     * LoggerInterface zum Protokollieren von Informationen und Fehlern.
     */
    private LoggerInterface $logger;

    /**
     * Konstruktor
     *
     * @param LoggerInterface $logger Logger zur Ausgabe von Logmeldungen
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Lädt eine semikolon-getrennte CSV-Datei, parst sie und garantiert bestimmte Spalten.
     *
     * @param string $csvUrl      URL zur CSV-Datei
     * @param string $csvMapping  z.B. "ext_Foo=Foo|ext_Bar=Bar"
     *
     * @return array Ein Array assoziativer Arrays (Zeilen).
     */
    public function fetchAndParseCsv(string $csvUrl, string $csvMapping): array
    {
        if (empty($csvUrl)) {
            $this->logger->error("CSV-URL ist leer. Überspringe.");
            return [];
        }

        $this->logger->info("Lade CSV von: {$csvUrl}");

        $content = @\file_get_contents($csvUrl);
        if ($content === false) {
            $this->logger->error("CSV-Download fehlgeschlagen von {$csvUrl}");
            return [];
        }

        // In Zeilen aufspalten
        $lines = \str_getcsv($content, "\n");
        if (\count($lines) < 2) {
            $this->logger->error("Die CSV unter {$csvUrl} enthält zu wenige Zeilen.");
            return [];
        }

        // Header parsen
        $headers = \str_getcsv(\array_shift($lines), ';', '"');
        $headers = \array_map('trim', $headers);

        // Header-Index bauen
        $headerIndex = [];
        foreach ($headers as $idx => $hName) {
            $headerIndex[$hName] = $idx;
        }

        // Spalten-Mapping parsen
        // Bsp: "ext_Foo=Foo|ext_Bar=Bar" => ext_Foo -> Foo
        $mappingPairs   = \explode('|', $csvMapping);
        $columnMappings = [];
        foreach ($mappingPairs as $pair) {
            $pair = \trim($pair);
            if (!$pair) {
                continue;
            }
            if (\str_contains($pair, '=')) {
                [$from, $to] = \array_map('trim', \explode('=', $pair));
                $columnMappings[$from] = $to;
            }
        }

        // Muss-Spalten (falls nicht vorhanden => leer)
        $mustHaveCols = [
            "Deeplink",
            "Produkt-Titel",
            "Produktbeschreibung",
            "Bruttopreis",
            "Streichpreis",
            "Währung",
            "europäische Artikelnummer EAN",
            "Anbieter Artikelnummer AAN",
            "Produktbild-URL",
            "Produktkategorie",
            "Versandkosten Allgemein",
            "Lieferzeit",
            "Inhalt",
            "Grundpreis",
            "Grundpreiseinheit"
        ];

        $rows = [];
        foreach ($lines as $line) {
            $cols = \str_getcsv($line, ';', '"');
            $data = [];

            // Muss-Spalten absichern
            foreach ($mustHaveCols as $colName) {
                $data[$colName] = "";
            }

            // Header zuordnen
            foreach ($headers as $hdrIndex => $hdrName) {
                // defensiv prüfen
                $data[$hdrName] = $cols[$hdrIndex] ?? '';
            }

            // Mapping anwenden
            foreach ($columnMappings as $fromCol => $toCol) {
                if (!empty($data[$fromCol]) && \array_key_exists($toCol, $data)) {
                    $data[$toCol] = $data[$fromCol];
                }
            }

            $rows[] = $data;
        }

        $this->logger->info("Gelesene Zeilen aus CSV: " . \count($rows));
        return $rows;
    }
}
