<?php

namespace JUNU\RealADCELL\Commands;

use JUNU\RealADCELL\LoggerFactory;
use JUNU\RealADCELL\Service\CsvService;
use JUNU\RealADCELL\Service\OpenAiService;
use JUNU\RealADCELL\Service\ShopwareService;
use JUNU\RealADCELL\Service\UuidService;
use JUNU\RealADCELL\Utils\CsvRowMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class SyncCommand
 * The main console command that orchestrates CSV -> OpenAI -> Shopware sync.
 */
class SyncCommand extends Command
{
    protected static $defaultName = 'sync';

    protected function configure()
    {
        $this
            ->setDescription('Synchronizes products from multiple CSV(s) to Shopware 6.6 with help of OpenAI');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $logger = LoggerFactory::createLogger('sync');

        // 1) Gather CSV definitions from ENV
        $envVars = $_ENV;
        $csvDefs = $this->getCsvDefinitions($envVars);

        if (empty($csvDefs)) {
            $logger->error("No CSV definitions found in ENV (CSV_URL_x).");
            return Command::FAILURE;
        }

        // 2) Instantiate services
        $shopwareService = new ShopwareService($logger);
        $openAiService   = new OpenAiService($logger);
        $csvService      = new CsvService($logger);

        try {
            // Authenticate with Shopware
            $shopwareService->authenticate();

            // Fetch categories & delivery times
            $categoryMap     = $shopwareService->getAllCategories();      // name => id
            $deliveryTimeMap = $shopwareService->getAllDeliveryTimes();   // name => id

            // Process each CSV
            foreach ($csvDefs as $def) {
                $csvUrl  = $def['url'];
                $csvId   = $def['id'];
                $mapping = $def['mapping'] ?? '';

                $logger->info("===== Processing CSV ID '{$csvId}' =====");

                // Download & parse
                $rows = $csvService->fetchAndParseCsv($csvUrl, $mapping);
                if (empty($rows)) {
                    $logger->warning("No rows parsed for CSV '{$csvId}'. Skipping.");
                    continue;
                }

                $createdCount = 0;
                $updatedCount = 0;
                $skippedCount = 0;

                $total = count($rows);
                $index = 0;

                foreach ($rows as $row) {
                    $index++;
                    $logger->info("Processing row {$index} / {$total} for CSV '{$csvId}'");

                    // Map row
                    $mapped = CsvRowMapper::mapRow($row, $csvId);

                    $ean = $mapped['ean'];
                    $aan = $mapped['aan'];
                    $productNumber = $mapped['productNumber'];

                    // If no EAN or AAN => skip
                    if (empty($ean) && empty($aan)) {
                        $logger->warning("Row #{$index}: Missing EAN/AAN. Skipping.");
                        $skippedCount++;
                        continue;
                    }

                    // 1) Find existing product
                    $existing = null;
                    if (!empty($ean)) {
                        $existing = $shopwareService->findProductByEan($ean);
                    }
                    if (!$existing && !empty($productNumber)) {
                        $existing = $shopwareService->findProductByNumber($productNumber);
                    }

                    // 2) Rewrite description
                    $rewritten = $openAiService->rewriteDescription(
                        $mapped['title'],
                        $mapped['description']
                    );

                    // Create new or update existing
                    if (!$existing) {
                        // Create new product
                        //  - Manufacturer
                        $manufacturerId = $shopwareService->findOrCreateManufacturer($mapped['manufacturer']);

                        //  - Category
                        $bestCategoryName = null;
                        if (!empty($mapped['categoryHint']) && !empty($mapped['title'])) {
                            $bestCategoryName = $openAiService->bestCategory(
                                $mapped['title'],
                                $mapped['description'],
                                $mapped['categoryHint'],
                                array_keys($categoryMap)
                            );
                        }
                        $categoryId = ($bestCategoryName && isset($categoryMap[$bestCategoryName]))
                            ? $categoryMap[$bestCategoryName]
                            : null;

                        //  - Delivery Time
                        $deliveryTimeId = null;
                        if (!empty($mapped['deliveryTimeCsv'])) {
                            $bestDtName = $openAiService->bestDeliveryTime(
                                $mapped['deliveryTimeCsv'],
                                array_keys($deliveryTimeMap)
                            );
                            $deliveryTimeId = ($bestDtName && isset($deliveryTimeMap[$bestDtName]))
                                ? $deliveryTimeMap[$bestDtName]
                                : null;
                        }

                        //  - Price
                        $priceBruttoFloat = floatval(str_replace(',', '.', $mapped['priceBrutto']));
                        $listPriceFloat   = floatval(str_replace(',', '.', $mapped['listPrice']));

                        //  - Image
                        $imageIds = [];
                        if (!empty($mapped['imageUrl'])) {
                            $imageIds = $shopwareService->uploadImages([$mapped['imageUrl']]);
                        }

                        $newId = UuidService::generate(); // => 32 hex chars
                        $payload = [
                            'id'            => $newId,
                            'name'          => $mapped['title'],
                            'productNumber' => $productNumber,
                            'stock'         => 9999,
                            'description'   => $rewritten,
                            'ean'           => $ean,
                            'manufacturerId'=> $manufacturerId,
                            'price' => [[
                                'currencyId' => 'b7d2554b0ce847cd82f3ac9bd1c0dfca', // default EUR in Shopware
                                'gross'      => $priceBruttoFloat,
                                'net'        => ($priceBruttoFloat / 1.19), 
                                'linked'     => false
                            ]],
                            'active' => true,
                            'customFields' => [
                                'real_productlink' => $mapped['deeplink'],
                                'shipping_general' => $mapped['shippingGeneral'],
                            ],
                        ];

                        if ($categoryId) {
                            $payload['categories'] = [[ 'id' => $categoryId ]];
                        }

                        if ($listPriceFloat > 0) {
                            $payload['price'][0]['listPrice'] = [
                                'gross'  => $listPriceFloat,
                                'net'    => $listPriceFloat / 1.19,
                                'linked' => false,
                            ];
                        }

                        if ($deliveryTimeId) {
                            $payload['deliveryTimeId'] = $deliveryTimeId;
                        }

                        if (!empty($imageIds)) {
                            $mediaAssoc = [];
                            foreach ($imageIds as $pos => $mid) {
                                $mediaAssoc[] = [
                                    'mediaId'   => $mid,
                                    'position'  => $pos,
                                    'highlight' => ($pos === 0),
                                ];
                            }
                            $payload['media'] = $mediaAssoc;
                        }

                        $created = $shopwareService->createProduct($payload);
                        if ($created) {
                            $logger->info("Created product [{$mapped['title']}]");
                            $createdCount++;
                        } else {
                            $logger->warning("Failed creating product [{$mapped['title']}]");
                            $skippedCount++;
                        }

                    } else {
                        // Update existing
                        $existingId = $existing['id'];

                        $priceBruttoFloat = floatval(str_replace(',', '.', $mapped['priceBrutto']));
                        $listPriceFloat   = floatval(str_replace(',', '.', $mapped['listPrice']));

                        $updatePayload = [
                            'id' => $existingId,
                            'price' => [[
                                'currencyId' => 'b7d2554b0ce847cd82f3ac9bd1c0dfca',
                                'gross'      => $priceBruttoFloat,
                                'net'        => ($priceBruttoFloat / 1.19),
                                'linked'     => false,
                            ]],
                            'customFields' => [
                                'real_productlink' => $mapped['deeplink'],
                                'shipping_general' => $mapped['shippingGeneral'],
                            ],
                        ];
                        if ($listPriceFloat > 0) {
                            $updatePayload['price'][0]['listPrice'] = [
                                'gross'  => $listPriceFloat,
                                'net'    => $listPriceFloat / 1.19,
                                'linked' => false,
                            ];
                        }

                        // Delivery time
                        if (!empty($mapped['deliveryTimeCsv'])) {
                            $bestDtName = $openAiService->bestDeliveryTime(
                                $mapped['deliveryTimeCsv'],
                                array_keys($deliveryTimeMap)
                            );
                            if ($bestDtName && isset($deliveryTimeMap[$bestDtName])) {
                                $updatePayload['deliveryTimeId'] = $deliveryTimeMap[$bestDtName];
                            }
                        }

                        $updated = $shopwareService->updateProduct($existingId, $updatePayload);
                        if ($updated) {
                            $logger->info("Updated product [{$mapped['title']}]");
                            $updatedCount++;
                        } else {
                            $logger->warning("Failed updating product [{$mapped['title']}]");
                            $skippedCount++;
                        }
                    }
                }

                $logger->info("CSV '{$csvId}' done. Created={$createdCount}, Updated={$updatedCount}, Skipped={$skippedCount}");
            }

        } catch (\Throwable $e) {
            $logger->error("Sync error: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $logger->info("All CSVs processed successfully.");
        return Command::SUCCESS;
    }

    private function getCsvDefinitions(array $envVars): array
    {
        // e.g. CSV_URL_1, CSV_ID_1, CSV_MAPPING_1
        $defs = [];
        foreach ($envVars as $key => $val) {
            if (preg_match('/^CSV_URL_(\d+)/', $key, $m)) {
                $index = $m[1];
                $defs[$index]['url'] = $val;
                $defs[$index]['id']  = $envVars["CSV_ID_{$index}"] ?? "CSV{$index}";
                $defs[$index]['mapping'] = $envVars["CSV_MAPPING_{$index}"] ?? '';
            }
        }
        ksort($defs);
        return $defs;
    }
}
