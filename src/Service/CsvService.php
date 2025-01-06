<?php
/**
 * Autor:    Sebastian Gräbner (sebastian@ju.nu)
 * Firma:    JUNU Marketing Group LTD
 * Datum:    2025-01-05
 * Zweck:    Service-Klasse zum Einlesen und ggf. Entpacken von CSV-Dateien aus einer GZIP.
 */

namespace JUNU\ShopwareAffiliateSync\Service;

use Psr\Log\LoggerInterface;

final class CsvService
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Lädt eine CSV (ggf. aus einer GZIP), entpackt sie bei Bedarf, 
     * parst sie und wendet ein optionales Spalten-Mapping an.
     */
    public function fetchAndParseCsv(string $csvUrl, string $csvMapping): array
    {
        if (empty($csvUrl)) {
            $this->logger->error("CSV-URL ist leer. Überspringe.");
            return [];
        }

        $this->logger->info("Lade CSV von: {$csvUrl}");

        // Datei herunterladen
        $content = @\file_get_contents($csvUrl);
        if ($content === false) {
            $this->logger->error("CSV-Download fehlgeschlagen von {$csvUrl}");
            return [];
        }

        // Prüfen, ob es GZIP "Magic Bytes" sind
        if ($this->isGzip($content)) {
            $this->logger->info("GZIP-Datei (Magic Bytes erkannt). Entpacke ...");

            // Entpacken in temporäre Datei
            $tmpGz  = \tempnam(\sys_get_temp_dir(), 'csv_') . '.gz';
            $tmpCsv = \tempnam(\sys_get_temp_dir(), 'csv_') . '.csv';

            // Komprimiertes Binary speichern
            \file_put_contents($tmpGz, $content);

            // Entpacken per Shell-Kommando: gzip -cd
            $cmd = \sprintf('gzip -cd %s > %s', \escapeshellarg($tmpGz), \escapeshellarg($tmpCsv));
            \exec($cmd, $output, $returnVar);

            if ($returnVar !== 0) {
                $this->logger->error("Fehler beim Entpacken der GZIP-Datei (Exit-Code {$returnVar})");
                @\unlink($tmpGz);
                @\unlink($tmpCsv);
                return [];
            }

            // Entpackten Inhalt laden
            $content = @\file_get_contents($tmpCsv);
            if ($content === false) {
                $this->logger->error("Fehler beim Lesen der entpackten CSV.");
                @\unlink($tmpGz);
                @\unlink($tmpCsv);
                return [];
            }

            // Temporäre Dateien entfernen
            @\unlink($tmpGz);
            @\unlink($tmpCsv);
        }

        // Ab hier: "content" ist die unkomprimierte CSV
        $lines = \str_getcsv($content, "\n");
        if (\count($lines) < 2) {
            $this->logger->error("Die CSV enthält zu wenige Zeilen oder ist leer.");
            return [];
        }

        // Header
        $headers = \str_getcsv(\array_shift($lines), ';', '"');
        $headers = \array_map('trim', $headers);

        // Spalten-Mapping parsen
        // z. B. "ext_Foo=Foo|ext_Bar=Bar" => ext_Foo -> Foo
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

        // Pflichtspalten
        $mustHaveCols = [
            "Deeplink",
            "Produkt-Titel",
            "Produktbeschreibung",
            "Preis (Brutto)",
            "Streichpreis",
            "europäische Artikelnummer EAN",
            "Anbieter Artikelnummer AAN",
            "Hersteller",
            "Produktbild-URL",
            "Vorschaubild-URL",
            "Produktkategorie",
            "Versandkosten Allgemein",
            "Lieferzeit"
        ];

        $rows = [];
        foreach ($lines as $line) {
            $cols = \str_getcsv($line, ';', '"');
            $data = [];

            // Muss-Spalten vorab definieren
            foreach ($mustHaveCols as $colName) {
                $data[$colName] = "";
            }

            // Header zuordnen
            foreach ($headers as $hdrIndex => $hdrName) {
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

    /**
     * Prüft an den ersten beiden Bytes (0x1F 0x8B), ob es GZIP sein könnte.
     */
    private function isGzip(string $binary): bool
    {
        return \strlen($binary) >= 2 
            && \ord($binary[0]) === 0x1F 
            && \ord($binary[1]) === 0x8B;
    }
}
