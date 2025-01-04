<?php

namespace JUNU\RealADCELL\Service;

use Psr\Log\LoggerInterface;

/**
 * Class CsvService
 * Fetches and parses CSV files (semicolon-separated, quoted fields).
 */
class CsvService
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Download and parse a semicolon-separated CSV.
     *
     * @param string $csvUrl      The URL of the CSV.
     * @param string $csvMapping  e.g. "ext_Foo=Foo|ext_Bar=Bar"
     * @return array              An array of associative rows.
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

        // Parse column mappings
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

        $rows = [];
        foreach ($lines as $line) {
            // Parse row
            $cols = str_getcsv($line, ';', '"');
            if (count($cols) !== count($headers)) {
                $this->logger->warning("CSV row column mismatch. Skipping: $line");
                continue;
            }
            $data = array_combine($headers, $cols);

            // Apply extra column mappings if the 'to' column is empty
            foreach ($columnMappings as $extColumn => $mainColumn) {
                if (isset($data[$extColumn]) && isset($data[$mainColumn])) {
                    if (empty($data[$mainColumn])) {
                        $data[$mainColumn] = $data[$extColumn];
                    }
                }
            }

            $rows[] = $data;
        }

        $this->logger->info("Parsed " . count($rows) . " rows from CSV: {$csvUrl}");
        return $rows;
    }
}
