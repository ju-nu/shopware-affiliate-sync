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
 * - Reads multiple CSVs (defined in .env) and processes them
 * - Loads categories (tree), passes "Parent > Child" paths to ChatGPT, picks the deepest
 * - Creates/updates products in Shopware:
 *   -> Uses coverId (set after creation)
 *   -> listPrice if brutto < original => listPrice = original
 *   -> defaultTaxId from ShopwareService
 *   -> Manufacturer from CSV or fallback from ENV
 */
class SyncCommand extends Command
{
    protected static $defaultName = 'sync';

    protected function configure()
    {
        $this->setDescription(
            'Synchronize CSV -> Shopware with category hierarchy, ' .
            'two-step coverId assignment, manufacturer fallback, and listPrice logic.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $logger = LoggerFactory::createLogger('sync');

        // 1) CSV definitions from env
        $csvDefs = $this->getCsvDefinitions($_ENV);
        if (empty($csvDefs)) {
            $logger->error("No CSV definitions found (CSV_URL_x). Aborting.");
            return Command::FAILURE;
        }

        // 2) Services
        $shopwareService = new ShopwareService($logger);
        $openAiService   = new OpenAiService($logger);
        $csvService      = new CsvService($logger);

        try {
            // Authenticate => load token, salesChannelId, defaultTaxId, etc.
            $shopwareService->authenticate();

            // "Parent > Child" => categoryId
            $categoryMap   = $shopwareService->getAllCategories();
            $deliveryTimes = $shopwareService->getAllDeliveryTimes();

            foreach ($csvDefs as $idx => $def) {
                $csvUrl  = $def['url']     ?? '';
                $csvId   = $def['id']      ?? "CSV{$idx}";
                $mapping = $def['mapping'] ?? '';

                $logger->info("=== Processing CSV '$csvId' ===");

                // Parse CSV (enforcing must-have columns, applying mappings, etc.)
                $rows = $csvService->fetchAndParseCsv($csvUrl, $mapping);
                if (empty($rows)) {
                    $logger->warning("No rows for CSV '$csvId'. Skipping.");
                    continue;
                }

                $createdCount = 0;
                $updatedCount = 0;
                $skippedCount = 0;
                $total        = count($rows);

                for ($i=0; $i < $total; $i++) {
                    $rowIndex = $i + 1;
                    $logger->info("Row $rowIndex / $total ...");

                    // Map row to consistent structure
                    $mapped = CsvRowMapper::mapRow($rows[$i], $csvId);

                    // If EAN + AAN missing => skip
                    if (empty($mapped['ean']) && empty($mapped['aan'])) {
                        $logger->warning("Row #$rowIndex: No EAN/AAN => skip");
                        $skippedCount++;
                        continue;
                    }

                    // Manufacturer from CSV or fallback from ENV
                    $manufacturerId = $shopwareService->findOrCreateManufacturerForCsv(
                        (string)$idx,
                        $mapped['manufacturer']
                    );

                    // Category from ChatGPT
                    $catId = null;
                    if (!empty($mapped['categoryHint'])) {
                        $bestCatPath = $openAiService->bestCategory(
                            $mapped['title'],
                            $mapped['description'],
                            $mapped['categoryHint'],
                            array_keys($categoryMap)
                        );
                        if ($bestCatPath && isset($categoryMap[$bestCatPath])) {
                            $catId = $categoryMap[$bestCatPath];
                        }
                    }

                    // Delivery time
                    $deliveryTimeId = null;
                    if (!empty($mapped['deliveryTimeCsv'])) {
                        $bestDtName = $openAiService->bestDeliveryTime(
                            $mapped['deliveryTimeCsv'],
                            array_keys($deliveryTimes)
                        );
                        if ($bestDtName && isset($deliveryTimes[$bestDtName])) {
                            $deliveryTimeId = $deliveryTimes[$bestDtName];
                        }
                    }

                    // Price logic
                    $priceBrutto   = floatval(str_replace(',', '.', $mapped['priceBrutto']));
                    $originalPrice = floatval(str_replace(',', '.', $mapped['listPrice']));

                    // Upload images => returns array of mediaIds
                    $mediaIds = $shopwareService->uploadImages([$mapped['imageUrl']]);

                    // Rewritten desc in German
                    $rewrittenDesc = $openAiService->rewriteDescription(
                        $mapped['title'],
                        $mapped['description']
                    );

                    // Check if product exists
                    $existing = $shopwareService->findProductByNumber($mapped['productNumber']);

                    // If not => create
                    if (!$existing) {
                        // Step 1: create product payload
                        $payload = [
                            // 'id' => auto in createProduct if empty
                            'name'          => $mapped['title'],
                            'productNumber' => $mapped['productNumber'],
                            'stock'         => 9999,
                            'description'   => $rewrittenDesc,
                            'ean'           => $mapped['ean'],
                            'manufacturerId'=> $manufacturerId,
                            'price' => [[
                                'currencyId' => 'b7d2554b0ce847cd82f3ac9bd1c0dfca',
                                'gross'      => $priceBrutto,
                                'net'        => ($priceBrutto / 1.19),
                                'linked'     => false,
                            ]],
                            'active' => true,
                            'categories' => $catId ? [[ 'id' => $catId ]] : [],
                        ];

                        // If AAN => manufacturerNumber
                        if (!empty($mapped['aan'])) {
                            $payload['manufacturerNumber'] = $mapped['aan'];
                        }

                        // If original price is bigger => set listPrice
                        if (!empty($originalPrice) && $originalPrice > $priceBrutto) {
                            $payload['price'][0]['listPrice'] = [
                                'gross'  => $originalPrice,
                                'net'    => ($originalPrice / 1.19),
                                'linked' => false,
                            ];
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

                        // Add media references (no coverId yet)
                        if (!empty($mediaIds)) {
                            $payload['media'] = [];
                            foreach ($mediaIds as $pos => $mid) {
                                $payload['media'][] = [
                                    'mediaId'  => $mid,
                                    'position' => $pos,
                                    // no 'cover' => true
                                ];
                            }
                        }

                        // Create
                        if ($shopwareService->createProduct($payload)) {
                            $logger->info("Created product [{$mapped['title']}]");

                            // Step 2: if we have media => set coverId in a second call
                            if (!empty($mediaIds)) {
                                // Let’s pick the first
                                $coverId = $mediaIds[0];

                                // We'll find the newly assigned product ID
                                // either from $payload['id'] if we forced an id
                                // or we might re-search by productNumber
                                $productId = $payload['id'] ?? null;
                                if (empty($productId)) {
                                    // If we didn't set 'id' in the payload, we re-check Shopware
                                    $justCreated = $shopwareService->findProductByNumber($mapped['productNumber']);
                                    $productId   = $justCreated['id'] ?? null;
                                }

                                if ($productId && $coverId) {
                                    $coverPayload = [
                                        'id'      => $productId,
                                        'coverId' => $coverId,
                                    ];
                                    $okCover = $shopwareService->updateProduct($productId, $coverPayload);
                                    if ($okCover) {
                                        $logger->info("Cover ID set to $coverId for product #$productId");
                                    } else {
                                        $logger->warning("Failed to set cover ID $coverId for product #$productId");
                                    }
                                }
                            }
                            $createdCount++;
                        } else {
                            $logger->warning("Failed creating product [{$mapped['title']}]");
                            $skippedCount++;
                        }

                    } else {
                        // Update existing
                        $existingId = $existing['id'];
                        $updatePayload = [
                            'id'    => $existingId,
                            'price' => [[
                                'currencyId' => 'b7d2554b0ce847cd82f3ac9bd1c0dfca',
                                'gross'      => $priceBrutto,
                                'net'        => ($priceBrutto / 1.19),
                                'linked'     => false,
                            ]],
                        ];
                        if (!empty($originalPrice) && $originalPrice > $priceBrutto) {
                            $updatePayload['price'][0]['listPrice'] = [
                                'gross'  => $originalPrice,
                                'net'    => ($originalPrice / 1.19),
                                'linked' => false,
                            ];
                        }

                        // customFields
                        $cfDeeplink = $_ENV['SHOPWARE_CUSTOMFIELD_DEEPLINK'] ?? 'real_productlink';
                        $cfShipping = $_ENV['SHOPWARE_CUSTOMFIELD_SHIPPING_GENERAL'] ?? 'shipping_general';
                        $updatePayload['customFields'] = [
                            $cfDeeplink => ($mapped['deeplink'] ?? ''),
                            $cfShipping => ($mapped['shippingGeneral'] ?? ''),
                        ];

                        if ($shopwareService->updateProduct($existingId, $updatePayload)) {
                            $logger->info("Updated product [{$mapped['title']}]");
                            $updatedCount++;
                        } else {
                            $logger->warning("Failed updating product [{$mapped['title']}]");
                            $skippedCount++;
                        }
                    }
                } // end for each row

                $logger->info("CSV '$csvId' done. Created=$createdCount, Updated=$updatedCount, Skipped=$skippedCount");
            } // end foreach CSV

        } catch (\Throwable $e) {
            $logger->error("Sync error: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $logger->info("All CSVs processed successfully.");
        return Command::SUCCESS;
    }

    /**
     * Gather CSV definitions from ENV (CSV_URL_1, CSV_ID_1, CSV_MAPPING_1, etc.).
     */
    private function getCsvDefinitions(array $envVars): array
    {
        $defs = [];
        foreach ($envVars as $key => $val) {
            if (preg_match('/^CSV_URL_(\d+)/', $key, $m)) {
                $idx = $m[1];
                $defs[$idx]['url']     = $val;
                $defs[$idx]['id']      = $envVars["CSV_ID_{$idx}"]      ?? "CSV{$idx}";
                $defs[$idx]['mapping'] = $envVars["CSV_MAPPING_{$idx}"] ?? '';
            }
        }
        ksort($defs);
        return $defs;
    }
}
