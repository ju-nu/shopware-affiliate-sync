<?php

namespace JUNU\RealADCELL\Service;

use Psr\Log\LoggerInterface;

class CsvService
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Download and parse a semicolon-separated CSV,
     * then enforce that certain "must-have" columns always exist in the parsed rows.
     *
     * @param string $csvUrl      The URL of the CSV
     * @param string $csvMapping  e.g. "ext_Foo=Foo|ext_Bar=Bar"
     * @return array              An array of associative rows
     */
    public function fetchAndParseCsv(string $csvUrl, string $csvMapping): array
    {
        if (empty($csvUrl)) {
            $this->logger->error("CSV URL is empty. Skipping.");
            return [];
        }

        $this->logger->info("Fetching CSV from: {$csvUrl}");

        $content = @file_get_contents($csvUrl);
        if ($content === false) {
            $this->logger->error("Failed to download CSV from {$csvUrl}");
            return [];
        }

        // Break into lines
        $lines = str_getcsv($content, "\n");
        if (count($lines) < 2) {
            $this->logger->error("CSV from {$csvUrl} seems to have no data.");
            return [];
        }

        // Parse headers
        $headers = str_getcsv(array_shift($lines), ';', '"');
        $headers = array_map('trim', $headers);

        // Build an index: headerName => position
        // so we can quickly do data[headerName] = colValue
        $headerIndex = [];
        foreach ($headers as $idx => $hName) {
            $headerIndex[$hName] = $idx; // e.g. $headerIndex["Produkt-Deeplink"] = 0
        }

        // Parse column mappings
        // e.g. "ext_Foo=Foo|ext_Bar=Bar" => we copy columns from ext_Foo -> Foo if Foo is empty
        $mappingPairs = explode('|', $csvMapping);
        $columnMappings = [];
        foreach ($mappingPairs as $pair) {
            $pair = trim($pair);
            if (!$pair) continue;
            if (strpos($pair, '=') !== false) {
                [$from, $to] = explode('=', $pair);
                $columnMappings[trim($from)] = trim($to);
            }
        }

        // Define the "must-have" columns
        // If they are missing in the final row, we'll set them to ""
        $mustHaveCols = [
            "Produkt-Deeplink",
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
            // Split the row
            $cols = str_getcsv($line, ';', '"');

            // Build an associative array for the row
            $data = [];

            // Now ensure "must-have" columns are defined (if they don't exist, set to "")
            foreach ($mustHaveCols as $colName) {
                if (!array_key_exists($colName, $data)) {
                    $data[$colName] = "";
                }
            }

            foreach ($headers as $hdrIndex => $hdrName) {
                // be defensive
                $data[$hdrName] = isset($cols[$hdrIndex]) ? $cols[$hdrIndex] : '';
            }

            // Apply the "mapping" fallback. If the target column is empty, copy from the source
            foreach ($columnMappings as $fromCol => $toCol) {
                if (isset($data[$fromCol]) && isset($data[$toCol])) {
                    if (empty($data[$toCol]) && !empty($data[$fromCol])) {
                        $data[$toCol] = $data[$fromCol];
                    }
                }
            }

            $rows[] = $data;
        }

        $this->logger->info("Parsed " . count($rows) . " rows from CSV: {$csvUrl}");
        return $rows;
    }
}
