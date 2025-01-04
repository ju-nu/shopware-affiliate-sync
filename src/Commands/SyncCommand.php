<?php

namespace JUNU\RealADCELL\Commands;

use JUNU\RealADCELL\LoggerFactory;
use JUNU\RealADCELL\Service\CsvService;
use JUNU\RealADCELL\Service\OpenAiService;
use JUNU\RealADCELL\Service\ShopwareService;
use JUNU\RealADCELL\Utils\CsvRowMapper;
use JUNU\RealADCELL\Service\UuidService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * SyncCommand
 * -----------
 * Liest CSVs, rewritet Beschreibungen via OpenAI,
 * erstellt / updatet Produkte in Shopware
 * -> nun mit product ID, taxId, etc.
 */
class SyncCommand extends Command
{
    protected static $defaultName = 'sync';

    protected function configure()
    {
        $this->setDescription('Synchronisiert Produkte aus CSV in Shopware (deutsche Beschreibungen, Bilder, etc.)');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $logger = LoggerFactory::createLogger('sync');

        // CSV-Definitionen
        $csvDefs = $this->getCsvDefinitions($_ENV);
        if (empty($csvDefs)) {
            $logger->error("Keine CSV-Definitionen gefunden (CSV_URL_x). Abbruch.");
            return Command::FAILURE;
        }

        // Services
        $shopwareService = new ShopwareService($logger);
        $openAiService   = new OpenAiService($logger);
        $csvService      = new CsvService($logger);

        try {
            $shopwareService->authenticate();

            // Hole alle Kategorien (z.B. für OpenAI-Kategorisierung)
            $categoryMap     = $shopwareService->getAllCategories();    
            $deliveryTimeMap = $shopwareService->getAllDeliveryTimes();

            foreach ($csvDefs as $def) {
                $csvUrl  = $def['url'];
                $csvId   = $def['id'];
                $mapping = $def['mapping'] ?? '';

                $logger->info("== CSV '{$csvId}' wird verarbeitet ==");
                $rows = $csvService->fetchAndParseCsv($csvUrl, $mapping);
                if (empty($rows)) {
                    $logger->warning("Keine Zeilen gefunden für CSV '{$csvId}'. Überspringe.");
                    continue;
                }

                $createdCount = 0;
                $updatedCount = 0;
                $skippedCount = 0;
                $total        = count($rows);
                $index        = 0;

                foreach ($rows as $row) {
                    $index++;
                    $logger->info("Zeile {$index}/{$total} für '{$csvId}'...");

                    $mapped = CsvRowMapper::mapRow($row, $csvId);

                    // Falls EAN + AAN beide fehlen => skip
                    if (empty($mapped['ean']) && empty($mapped['aan'])) {
                        $logger->warning("EAN und AAN fehlen => skip");
                        $skippedCount++;
                        continue;
                    }

                    // customFields: wir möchten 'real_productlink' => $mapped['deeplink'],
                    // Falls CSV-Spalte 'Produkt-Deeplink' => $mapped['deeplink']
                    // -> wir übernehmen den Wert. Falls leer => leeren String
                    $deeplink = $mapped['deeplink'] ?? '';

                    // Prüfe, ob bereits Produkt existiert
                    $existing = $shopwareService->findProductByNumber($mapped['productNumber']);

                    // Deutsche Beschreibung via OpenAI
                    $rewrittenDesc = $openAiService->rewriteDescription(
                        $mapped['title'], 
                        $mapped['description']
                    );

                    if (!$existing) {
                        // Neuerstellung
                        $manufacturerId = $shopwareService->findOrCreateManufacturer($mapped['manufacturer']);

                        // Kategory via OpenAI
                        // Falls leer => fallback?
                        $categoryId = null;
                        if (!empty($mapped['categoryHint'])) {
                            $bestCatName = $openAiService->bestCategory(
                                $mapped['title'],
                                $mapped['description'],
                                $mapped['categoryHint'],
                                array_keys($categoryMap)
                            );
                            if ($bestCatName && isset($categoryMap[$bestCatName])) {
                                $categoryId = $categoryMap[$bestCatName];
                            }
                        }
                        // Falls wir zwingend eine Kategorie brauchen, setze fallback
                        if (empty($categoryId)) {
                            // z.B. default-Kategorie:
                            // $categoryId = 'SOME-DEFAULT-CATEGORY-ID';
                            // oder ein leeres Array => createProduct(...) => 'categories' => []
                        }

                        // Delivery Time
                        $deliveryTimeId = null;
                        if (!empty($mapped['deliveryTimeCsv'])) {
                            $bestDtName = $openAiService->bestDeliveryTime(
                                $mapped['deliveryTimeCsv'],
                                array_keys($deliveryTimeMap)
                            );
                            if ($bestDtName && isset($deliveryTimeMap[$bestDtName])) {
                                $deliveryTimeId = $deliveryTimeMap[$bestDtName];
                            }
                        }

                        // Price
                        $priceBrutto = floatval(str_replace(',', '.', $mapped['priceBrutto']));
                        $listPrice   = floatval(str_replace(',', '.', $mapped['listPrice']));

                        // Bilder
                        $imageIds = $shopwareService->uploadImages([$mapped['imageUrl']]);

                        // Erzeuge finalen payload
                        $newProductPayload = [
                            'id'            => UuidService::generate(), // ^[0-9a-f]{32}$
                            'name'          => $mapped['title'],
                            'productNumber' => $mapped['productNumber'],
                            'stock'         => 9999,
                            'description'   => $rewrittenDesc,
                            'ean'           => $mapped['ean'],
                            'manufacturerId'=> $manufacturerId,
                            // taxId kommt in ShopwareService->createProduct automatisch
                            'price' => [[
                                'currencyId' => 'b7d2554b0ce847cd82f3ac9bd1c0dfca', 
                                'gross'      => $priceBrutto,
                                'net'        => ($priceBrutto / 1.19),
                                'linked'     => false,
                            ]],
                            'active' => true,
                            'customFields' => [
                                'real_productlink' => $deeplink,
                                'shipping_general' => $mapped['shippingGeneral'] ?? '',
                            ],
                        ];

                        if ($listPrice > 0) {
                            $newProductPayload['price'][0]['listPrice'] = [
                                'gross'  => $listPrice,
                                'net'    => ($listPrice / 1.19),
                                'linked' => false,
                            ];
                        }

                        if ($categoryId) {
                            $newProductPayload['categories'] = [[ 'id' => $categoryId ]];
                        } else {
                            // ensure at least an empty array
                            $newProductPayload['categories'] = [];
                        }

                        if ($deliveryTimeId) {
                            $newProductPayload['deliveryTimeId'] = $deliveryTimeId;
                        }

                        // Bilder -> cover
                        if (!empty($imageIds)) {
                            $newProductPayload['media'] = [];
                            foreach ($imageIds as $pos => $mid) {
                                $newProductPayload['media'][] = [
                                    'mediaId'   => $mid,
                                    'position'  => $pos,
                                    'cover'     => ($pos === 0),
                                    'highlight' => ($pos === 0),
                                ];
                            }
                        }

                        // Erstelle Produkt
                        if ($shopwareService->createProduct($newProductPayload)) {
                            $logger->info("Produkt [{$mapped['title']}] angelegt.");
                            $createdCount++;
                        } else {
                            $logger->warning("Fehler beim Erstellen des Produkts [{$mapped['title']}]");
                            $skippedCount++;
                        }

                    } else {
                        // Update
                        $existingId = $existing['id'];

                        $priceBrutto = floatval(str_replace(',', '.', $mapped['priceBrutto']));
                        $listPrice   = floatval(str_replace(',', '.', $mapped['listPrice']));

                        $updatePayload = [
                            'id'    => $existingId,
                            'price' => [[
                                'currencyId' => 'b7d2554b0ce847cd82f3ac9bd1c0dfca',
                                'gross'      => $priceBrutto,
                                'net'        => ($priceBrutto / 1.19),
                                'linked'     => false,
                            ]],
                            'customFields' => [
                                'real_productlink' => $deeplink,
                                'shipping_general' => $mapped['shippingGeneral'] ?? '',
                            ],
                        ];

                        if ($listPrice > 0) {
                            $updatePayload['price'][0]['listPrice'] = [
                                'gross'  => $listPrice,
                                'net'    => ($listPrice / 1.19),
                                'linked' => false,
                            ];
                        }
                        // optional: update deliveryTimeId => ...
                        
                        if ($shopwareService->updateProduct($existingId, $updatePayload)) {
                            $logger->info("Produkt [{$mapped['title']}] aktualisiert.");
                            $updatedCount++;
                        } else {
                            $logger->warning("Fehler beim Update des Produkts [{$mapped['title']}]");
                            $skippedCount++;
                        }
                    }
                } // foreach row

                $logger->info("CSV '{$csvId}' abgeschlossen. Created=$createdCount, Updated=$updatedCount, Skipped=$skippedCount");
            } // foreach csv

        } catch (\Throwable $e) {
            $logger->error("Sync-Fehler: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $logger->info("Alle CSVs erfolgreich verarbeitet.");
        return Command::SUCCESS;
    }

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
