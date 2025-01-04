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
 * Liest mehrere CSVs aus (Def. in .env) und erzeugt/aktualisiert Produkte in Shopware.
 * - Bestimmt Kategorien über OpenAI
 * - Schreibt Beschreibungen in Deutsch (ohne Titel) über OpenAiService
 * - Hängt Medien mit cover=true an
 * - Hersteller wird angelegt, falls nicht vorhanden
 * - Legt Visibility für den konfigurierten Sales-Channel an
 */
class SyncCommand extends Command
{
    protected static $defaultName = 'sync';

    protected function configure()
    {
        $this->setDescription('Synchronisiert Produkte aus CSV-Dateien in Shopware 6.6 (inkl. Bilder, Kategorien, Hersteller, Sales-Channel).');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $logger = LoggerFactory::createLogger('sync');

        // 1) CSV-Definitionen aus ENV ermitteln
        $csvDefs = $this->getCsvDefinitions($_ENV);
        if (empty($csvDefs)) {
            $logger->error("Keine CSV-Definitionen gefunden (CSV_URL_x). Abbruch.");
            return Command::FAILURE;
        }

        // 2) Services instanzieren
        $shopwareService = new ShopwareService($logger);
        $openAiService   = new OpenAiService($logger);
        $csvService      = new CsvService($logger);

        try {
            // Shopware-Token holen
            $shopwareService->authenticate();

            // Kategorien & Lieferzeiten laden
            $categoryMap     = $shopwareService->getAllCategories();      // [name => id]
            $deliveryTimeMap = $shopwareService->getAllDeliveryTimes();   // [name => id]

            // Jedes CSV bearbeiten
            foreach ($csvDefs as $def) {
                $csvUrl  = $def['url'];
                $csvId   = $def['id'];
                $mapping = $def['mapping'] ?? '';

                $logger->info("===== Verarbeite CSV ID '{$csvId}' =====");

                $rows = $csvService->fetchAndParseCsv($csvUrl, $mapping);
                if (empty($rows)) {
                    $logger->warning("Keine Zeilen für CSV '{$csvId}'. Überspringe.");
                    continue;
                }

                $createdCount = 0;
                $updatedCount = 0;
                $skippedCount = 0;
                $total        = count($rows);
                $index        = 0;

                foreach ($rows as $row) {
                    $index++;
                    $logger->info("Zeile {$index} / {$total} für CSV '{$csvId}' wird bearbeitet...");

                    $mapped = CsvRowMapper::mapRow($row, $csvId);

                    // Wenn EAN + AAN fehlen => kein Produkt anlegbar
                    if (empty($mapped['ean']) && empty($mapped['aan'])) {
                        $logger->warning("Zeile #{$index}: EAN und AAN fehlen, überspringe.");
                        $skippedCount++;
                        continue;
                    }

                    // Produktnummer (CSV_ID + AAN oder CSV_ID + EAN) ist in mapRow
                    $existingProduct = $shopwareService->findProductByNumber($mapped['productNumber']);

                    // Beschreibung via OpenAI in Deutsch
                    $rewrittenDesc = $openAiService->rewriteDescription(
                        $mapped['title'],
                        $mapped['description']
                    );

                    if (!$existingProduct) {
                        // Neuanlage
                        $manufacturerId = $shopwareService->findOrCreateManufacturer($mapped['manufacturer']);

                        // Kategorie via OpenAI
                        $categoryId = null;
                        if (!empty($mapped['categoryHint']) && !empty($mapped['description'])) {
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

                        // Lieferzeit
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

                        // Preise
                        $priceBrutto = floatval(str_replace(',', '.', $mapped['priceBrutto']));
                        $listPrice   = floatval(str_replace(',', '.', $mapped['listPrice']));

                        // Bilder hochladen
                        $imageIds = $shopwareService->uploadImages([$mapped['imageUrl']]);

                        // Produkt-Payload
                        $newProductPayload = [
                            // ID von Shopware generieren lassen oder
                            // 'id' => UuidService::generate(),
                            'name'          => $mapped['title'],
                            'productNumber' => $mapped['productNumber'],
                            'stock'         => 9999,
                            'description'   => $rewrittenDesc,
                            'ean'           => $mapped['ean'],
                            'manufacturerId'=> $manufacturerId,
                            'price' => [[
                                'currencyId' => 'b7d2554b0ce847cd82f3ac9bd1c0dfca', // EUR
                                'gross'      => $priceBrutto,
                                'net'        => ($priceBrutto / 1.19),
                                'linked'     => false,
                            ]],
                            'active' => true,
                            'customFields' => [
                                'real_productlink' => $mapped['deeplink']        ?? '',
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
                        }

                        if ($deliveryTimeId) {
                            $newProductPayload['deliveryTimeId'] = $deliveryTimeId;
                        }

                        if (!empty($imageIds)) {
                            $newProductPayload['media'] = [];
                            foreach ($imageIds as $pos => $mid) {
                                $newProductPayload['media'][] = [
                                    'mediaId'   => $mid,
                                    'position'  => $pos,
                                    'cover'     => ($pos === 0), // erstes Bild als Cover
                                    'highlight' => ($pos === 0),
                                ];
                            }
                        }

                        // Anlegen in Shopware
                        if ($shopwareService->createProduct($newProductPayload)) {
                            $logger->info("Produkt [{$mapped['title']}] erstellt.");
                            $createdCount++;
                        } else {
                            $logger->warning("Erstellen des Produkts [{$mapped['title']}] fehlgeschlagen.");
                            $skippedCount++;
                        }

                    } else {
                        // Update
                        $existingId = $existingProduct['id'];
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
                                'real_productlink' => $mapped['deeplink']        ?? '',
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

                        // Man könnte noch Lieferzeit etc. updaten, je nach Wunsch
                        if ($shopwareService->updateProduct($existingId, $updatePayload)) {
                            $logger->info("Produkt [{$mapped['title']}] aktualisiert.");
                            $updatedCount++;
                        } else {
                            $logger->warning("Update des Produkts [{$mapped['title']}] fehlgeschlagen.");
                            $skippedCount++;
                        }
                    }
                } // foreach row

                $logger->info("CSV '{$csvId}' fertig. Created={$createdCount}, Updated={$updatedCount}, Skipped={$skippedCount}");
            }

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
