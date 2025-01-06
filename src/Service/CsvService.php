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
     * Lädt eine semikolon-getrennte CSV-Datei (ggf. aus einer GZIP), entpackt sie, 
     * parst sie und garantiert bestimmte Spalten.
     *
     * @param string $csvUrl      URL zur CSV- (oder GZIP-)Datei
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

        // 1) Datei herunterladen (ob .gz oder .csv ist egal)
        $content = @\file_get_contents($csvUrl);
        if ($content === false) {
            $this->logger->error("CSV-Download fehlgeschlagen von {$csvUrl}");
            return [];
        }

        // 2) Prüfen, ob es sich um eine GZIP-Datei handelt (z. B. am Dateinamen ".gz")
        $extension = \pathinfo($csvUrl, \PATHINFO_EXTENSION); 
        if ($extension === 'gz') {
            $this->logger->info("GZIP-Datei erkannt. Entpacke zunächst ...");
            
            // Temporäre Dateien erzeugen
            $tmpGz  = \tempnam(\sys_get_temp_dir(), 'csv_') . '.gz';
            $tmpCsv = \tempnam(\sys_get_temp_dir(), 'csv_') . '.csv';

            // GZ-Inhalt speichern
            \file_put_contents($tmpGz, $content);

            // Entpacken per Shell-Kommando (gzip -cd ...)
            $cmd = \sprintf('gzip -cd %s > %s', \escapeshellarg($tmpGz), \escapeshellarg($tmpCsv));
            \exec($cmd, $output, $returnVar);

            if ($returnVar !== 0) {
                $this->logger->error("Fehler beim Entpacken der GZIP-Datei: Exit-Code $returnVar");
                // Temporäre Dateien löschen und Abbruch
                @\unlink($tmpGz);
                @\unlink($tmpCsv);
                return [];
            }

            // Entpacktes CSV auslesen
            $content = @\file_get_contents($tmpCsv);
            if ($content === false) {
                $this->logger->error("Fehler beim Lesen der entpackten CSV-Datei.");
                @\unlink($tmpGz);
                @\unlink($tmpCsv);
                return [];
            }

            // Temporäre Dateien wieder löschen
            @\unlink($tmpGz);
            @\unlink($tmpCsv);
        }

        // 3) Jetzt den CSV-Content ganz normal verarbeiten
        // In Zeilen aufspalten
        $lines = \str_getcsv($content, "\n");
        if (\count($lines) < 2) {
            $this->logger->error("Die CSV enthält zu wenige Zeilen oder ist leer.");
            return [];
        }

        // 3.1) Header parsen
        $headers = \str_getcsv(\array_shift($lines), ';', '"');
        $headers = \array_map('trim', $headers);

        // Header-Index bauen (optional, hier nur Info)
        $headerIndex = [];
        foreach ($headers as $idx => $hName) {
            $headerIndex[$hName] = $idx;
        }

        // 3.2) Spalten-Mapping parsen (z. B. "ext_Foo=Foo|ext_Bar=Bar" => ext_Foo -> Foo)
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

        // 3.3) Muss-Spalten definieren
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

        // 4) Zeilen durchgehen und assoziative Arrays aufbauen
        $rows = [];
        foreach ($lines as $line) {
            $cols = \str_getcsv($line, ';', '"');

            $data = [];
            // Muss-Spalten mit leeren Strings initialisieren
            foreach ($mustHaveCols as $colName) {
                $data[$colName] = "";
            }

            // Header zuordnen, defensiv
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
}
