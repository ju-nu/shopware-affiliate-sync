<?php
// src/Commands/SyncCommand.php

namespace JUNU\RealADCELL\Commands;

use JUNU\RealADCELL\LoggerFactory;
use JUNU\RealADCELL\Service\CsvService;
use JUNU\RealADCELL\Service\OpenAiService;
use JUNU\RealADCELL\Service\ShopwareService;
use JUNU\RealADCELL\Utils\CsvRowMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * SyncCommand
 * -----------
 * - Reads multiple CSVs (from .env) and processes them row-by-row
 * - For each product:
 *   -> If no EAN + AAN => skip
 *   -> Manufacturer from CSV or fallback from ENV
 *   -> For NEW products only: Category & Delivery Time via ChatGPT, Rewrite description
 *   -> Price logic: 
 *      * If no 'Streichpreis' => price = 'Bruttopreis'
 *      * If 'Streichpreis' => price = 'Streichpreis', listPrice = 'Bruttopreis'
 *   -> Cover in a single call: 
 *      $payload['cover'] = [ 'mediaId' => $mediaIds[0] ];
 *      $payload['media'] = array_map(fn($id) => ['mediaId' => $id], $mediaIds);
 *
 * Note: For EXISTING products, we skip unnecessary ChatGPT calls to avoid overhead.
 */
class SyncCommand extends Command
{
    protected static $defaultName = 'sync';

    protected function configure()
    {
        $this->setDescription(
            'Synchronize CSV -> Shopware, skipping unnecessary OpenAI calls for existing products.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $logger = LoggerFactory::createLogger('sync');

        // 1) Gather CSV definitions from ENV
        $csvDefs = $this->getCsvDefinitions($_ENV);
        if (empty($csvDefs)) {
            $logger->error("No CSV definitions found (CSV_URL_x). Aborting.");
            return Command::FAILURE;
        }

        // 2) Initialize services
        $shopwareService = new ShopwareService($logger);
        $openAiService   = new OpenAiService($logger);
        $csvService      = new CsvService($logger);

        try {
            // Auth => token, fetch defaultTax, salesChannel, etc.
            $shopwareService->authenticate();

            // For categories => "Parent > Child" => catId
            $categoryMap   = $shopwareService->getAllCategories();
            // For delivery times => "1-3 Tage" => someId
            $deliveryTimes = $shopwareService->getAllDeliveryTimes();

            // Process each CSV
            foreach ($csvDefs as $idx => $def) {
                $csvUrl  = $def['url']     ?? '';
                $csvId   = $def['id']      ?? "CSV{$idx}";
                $mapping = $def['mapping'] ?? '';

                $logger->info("=== Processing CSV '$csvId' ===");

                // Parse the CSV => returns array of rows with guaranteed columns
                $rows = $csvService->fetchAndParseCsv($csvUrl, $mapping);
                if (empty($rows)) {
                    $logger->warning("No rows found for CSV '$csvId'. Skipping this CSV.");
                    continue;
                }

                $createdCount = 0;
                $updatedCount = 0;
                $skippedCount = 0;
                $total        = count($rows);

                // Iterate all rows
                for ($i = 0; $i < $total; $i++) {
                    $rowIndex = $i + 1;
                    $logger->info("Row $rowIndex / $total ...");

                    // Map row => consistent structure
                    $mapped = CsvRowMapper::mapRow($rows[$i], $csvId);

                    // If EAN + AAN are missing => skip
                    if (empty($mapped['ean']) && empty($mapped['aan'])) {
                        $logger->warning("Row #$rowIndex: Missing EAN and AAN => skipping row.");
                        $skippedCount++;
                        continue;
                    }

                    // Manufacturer => from CSV or fallback (ENV)
                    $manufacturerId = $shopwareService->findOrCreateManufacturerForCsv(
                        (string)$idx,
                        $mapped['manufacturer']
                    );

                    // Bruttopreis ist immer actualPrice
                    // Streichpreis, falls vorhanden, wird zum listPrice

                    $brutto       = floatval(str_replace(',', '.', $mapped['priceBrutto']));
                    $streichpreis = floatval(str_replace(',', '.', $mapped['listPrice']));

                    // Standardwerte
                    $actualPrice = $brutto;
                    $listPrice   = $streichpreis > 0 ? $streichpreis : 0.0;

                    // Check if product exists (by productNumber)
                    $existing = $shopwareService->findProductByNumber($mapped['productNumber']);

                    if (!$existing) {
                        // --- CREATE NEW PRODUCT (ChatGPT calls allowed) ---

                        // Upload images => returns array of media IDs
                        $mediaIds = $shopwareService->uploadImages([$mapped['imageUrl']]);

                        // Category => only for new products
                        $catId = null;
                        if (!empty($mapped['categoryHint'])) {
                            $bestCatPath = $openAiService->bestCategory(
                                $mapped['title'],
                                $mapped['description'],
                                $mapped['categoryHint'],
                                array_keys($categoryMap)  // e.g. "Electronics > iPads"
                            );
                            if ($bestCatPath && isset($categoryMap[$bestCatPath])) {
                                $catId = $categoryMap[$bestCatPath];
                            }
                        }

                        // Delivery time => only for new products
                        $deliveryTimeId = null;
                        if (!empty($mapped['deliveryTimeCsv'])) {
                            $bestDt = $openAiService->bestDeliveryTime(
                                $mapped['deliveryTimeCsv'],
                                array_keys($deliveryTimes)
                            );
                            if ($bestDt && isset($deliveryTimes[$bestDt])) {
                                $deliveryTimeId = $deliveryTimes[$bestDt];
                            }
                        }

                        // Rewrite description => only for new
                        $rewrittenDesc = $openAiService->rewriteDescription(
                            $mapped['title'],
                            $mapped['description']
                        );

                        $payload = [
                            'name'          => $mapped['title'],
                            'productNumber' => $mapped['productNumber'],
                            'stock'         => 9999,
                            'description'   => $rewrittenDesc,
                            'ean'           => $mapped['ean'],
                            'manufacturerId'=> $manufacturerId,
                            'price' => [[
                                'currencyId' => 'b7d2554b0ce847cd82f3ac9bd1c0dfca',
                                'gross'      => $actualPrice,
                                'net'        => ($actualPrice / 1.19),
                                'linked'     => false,
                            ]],
                            'active' => true,
                        ];

                        // categories => if catId found
                        if ($catId) {
                            $payload['categories'] = [[ 'id' => $catId ]];
                        }

                        if (!empty($mapped['aan'])) {
                            $payload['manufacturerNumber'] = $mapped['aan'];
                        }
                        
                        if ($listPrice > 0) {
                            $payload['price'][0]['listPrice'] = [
                                'gross'  => $listPrice,
                                'net'    => ($listPrice / 1.19),
                                'linked' => false,
                            ];
                        }
                        else {
                            $payload['price'][0]['listPrice'] = null;
                        }

                        if ($deliveryTimeId) {
                            $payload['deliveryTimeId'] = $deliveryTimeId;
                        }

                        // custom fields from env
                        $cfDeeplink = $_ENV['SHOPWARE_CUSTOMFIELD_DEEPLINK'] ?? 'real_productlink';
                        $cfShipping = $_ENV['SHOPWARE_CUSTOMFIELD_SHIPPING_GENERAL'] ?? 'shipping_general';
                        $payload['customFields'] = [
                            $cfDeeplink => ($mapped['deeplink'] ?? ''),
                            $cfShipping => ($mapped['shippingGeneral'] ?? ''),
                        ];

                        // Cover + media in one call
                        if (!empty($mediaIds)) {
                            $payload['cover'] = ['mediaId' => $mediaIds[0]]; // first => cover
                            $payload['media'] = array_map(
                                fn($mId) => ['mediaId' => $mId],
                                $mediaIds
                            );
                        }

                        // CREATE
                        if ($shopwareService->createProduct($payload)) {
                            $logger->info("Created product [{$mapped['title']}]");
                            $createdCount++;
                        } else {
                            $logger->warning("Failed creating product [{$mapped['title']}]");
                            $skippedCount++;
                        }

                    } else {
                        // --- UPDATE EXISTING PRODUCT (NO ChatGPT calls) ---

                        $existingId = $existing['id'];
                        $updatePayload = [
                            'id' => $existingId,
                            'price' => [[
                                'currencyId' => 'b7d2554b0ce847cd82f3ac9bd1c0dfca',
                                'gross'      => $actualPrice,
                                'net'        => ($actualPrice / 1.19),
                                'linked'     => false,
                            ]],
                        ];
                        if ($listPrice > 0) {
                            $updatePayload['price'][0]['listPrice'] = [
                                'gross'  => $listPrice,
                                'net'    => ($listPrice / 1.19),
                                'linked' => false,
                            ];
                        }
                        else {
                            $updatePayload['price'][0]['listPrice'] = null;
                        }
                        // custom fields
                        $cfDeeplink = $_ENV['SHOPWARE_CUSTOMFIELD_DEEPLINK'] ?? 'real_productlink';
                        $cfShipping = $_ENV['SHOPWARE_CUSTOMFIELD_SHIPPING_GENERAL'] ?? 'shipping_general';
                        $updatePayload['customFields'] = [
                            $cfDeeplink => ($mapped['deeplink'] ?? ''),
                            $cfShipping => ($mapped['shippingGeneral'] ?? ''),
                        ];

                        // No rewriting description, no ChatGPT category/delivery

                        if ($shopwareService->updateProduct($existingId, $updatePayload)) {
                            $logger->info("Updated product [{$mapped['title']}]");
                            $updatedCount++;
                        } else {
                            $logger->warning("Failed updating product [{$mapped['title']}]");
                            $skippedCount++;
                        }
                    }
                } // end for rows

                $logger->info(
                    "CSV '$csvId' done. " .
                    "Created=$createdCount, Updated=$updatedCount, Skipped=$skippedCount"
                );
            } // end foreach CSV

        } catch (\Throwable $e) {
            $logger->error("Sync error: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $logger->info("All CSVs processed successfully.");
        return Command::SUCCESS;
    }

    /**
     * Gathers CSV definitions from ENV: CSV_URL_1, CSV_ID_1, CSV_MAPPING_1, etc.
     */
    private function getCsvDefinitions(array $envVars): array
    {
        $defs = [];
        foreach ($envVars as $key => $val) {
            if (preg_match('/^CSV_URL_(\d+)/', $key, $m)) {
                $index = $m[1];
                $defs[$index]['url']     = $val;
                $defs[$index]['id']      = $envVars["CSV_ID_{$index}"]      ?? "CSV{$index}";
                $defs[$index]['mapping'] = $envVars["CSV_MAPPING_{$index}"] ?? '';
            }
        }
        ksort($defs);
        return $defs;
    }
}
