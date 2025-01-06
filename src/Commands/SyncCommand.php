<?php
/**
 * Autor:    Sebastian Gräbner (sebastian@ju.nu)
 * Firma:    JUNU Marketing Group LTD
 * Datum:    2025-01-05
 * Zweck:    Definiert den Konsolenbefehl zum CSV->Shopware-Sync.
 */

namespace JUNU\ShopwareAffiliateSync\Commands;

use JUNU\ShopwareAffiliateSync\LoggerFactory;
use JUNU\ShopwareAffiliateSync\Service\CsvService;
use JUNU\ShopwareAffiliateSync\Service\OpenAiService;
use JUNU\ShopwareAffiliateSync\Service\ShopwareService;
use JUNU\ShopwareAffiliateSync\Utils\CsvRowMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * SyncCommand
 * -----------
 * - Liest mehrere CSVs (konfiguriert in .env)
 * - Verarbeitet jede Zeile:
 *   -> Falls weder EAN noch AAN vorhanden => Überspringen
 *   -> Hersteller aus CSV oder Fallback (ENV)
 *   -> Für NEUE Produkte: Kategorie, Lieferzeit per ChatGPT und Beschreibung umschreiben
 *   -> Preislogik:
 *      * Ohne 'Streichpreis' => price = 'Bruttopreis'
 *      * Mit 'Streichpreis' => price = 'Streichpreis', listPrice = 'Bruttopreis'
 *   -> Bilder in einem Aufruf anhängen:
 *      $payload['cover'] = [ 'mediaId' => $mediaIds[0] ];
 *      $payload['media'] = array_map(fn($id) => ['mediaId' => $id], $mediaIds);
 *
 * Beachte: Bei EXISTIERENDEN Produkten sparen wir uns ChatGPT-Aufrufe.
 */
class SyncCommand extends Command
{
    protected static $defaultName = 'sync';

    /**
     * Konfiguration (z.B. Beschreibung, Argumente/Optionen).
     */
    protected function configure(): void
    {
        $this->setDescription(
            'Synchronisiert CSV -> Shopware, und überspringt unnötige OpenAI-Aufrufe bei existierenden Produkten.'
        );
    }

    /**
     * Kernlogik des SyncCommands.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Logger erzeugen
        $logger = LoggerFactory::createLogger('sync');

        // 1) CSV-Definitionen aus ENV ermitteln
        $csvDefs = $this->getCsvDefinitions($_ENV);
        if (empty($csvDefs)) {
            $logger->error("Keine CSV-Definitionen gefunden (CSV_URL_x). Abbruch.");
            return Command::FAILURE;
        }

        // 2) Dienste initialisieren
        $shopwareService = new ShopwareService($logger);
        $openAiService   = new OpenAiService($logger);
        $csvService      = new CsvService($logger);

        try {
            // Authentifizierung bei Shopware
            $shopwareService->authenticate();

            // Alle Kategorien + Delivery-Times holen
            $categoryMap   = $shopwareService->getAllCategories();
            $deliveryTimes = $shopwareService->getAllDeliveryTimes();

            // Jede definierte CSV abarbeiten
            foreach ($csvDefs as $idx => $def) {
                $csvUrl  = $def['url']     ?? '';
                $csvId   = $def['id']      ?? "CSV{$idx}";
                $mapping = $def['mapping'] ?? '';

                $logger->info("=== Verarbeite CSV '$csvId' ===");

                // CSV parsen => Array aus Zeilen
                $rows = $csvService->fetchAndParseCsv($csvUrl, $mapping);
                if (empty($rows)) {
                    $logger->warning("Keine Daten in CSV '$csvId'. Überspringe.");
                    continue;
                }

                $createdCount = 0;
                $updatedCount = 0;
                $skippedCount = 0;
                $total        = \count($rows);

                // Jede Zeile durchgehen
                for ($i = 0; $i < $total; $i++) {
                    $rowIndex = $i + 1;
                    $logger->info("Zeile $rowIndex / $total ...");

                    // Zeile mappen => einheitliche Struktur
                    $mapped = CsvRowMapper::mapRow($rows[$i], $csvId);

                    // Falls EAN + AAN fehlen => überspringen
                    if (empty($mapped['ean']) && empty($mapped['aan'])) {
                        $logger->warning("Zeile #$rowIndex: Weder EAN noch AAN => überspringe Zeile.");
                        $skippedCount++;
                        continue;
                    }

                    // Hersteller (aus CSV oder ENV-Fallback)
                    $manufacturerId = $shopwareService->findOrCreateManufacturerForCsv(
                        (string) $idx,
                        $mapped['manufacturer']
                    );

                    // Preis-Logik
                    $brutto       = (float) \str_replace(',', '.', $mapped['priceBrutto']);
                    $streichpreis = (float) \str_replace(',', '.', $mapped['listPrice']);

                    $actualPrice = $brutto;  
                    $listPrice   = $streichpreis > 0 ? $streichpreis : 0.0;

                    // Prüfen ob Produkt existiert
                    $existing = $shopwareService->findProductByNumber($mapped['productNumber']);

                    if (!$existing) {
                        // ==========================
                        // PRODUKT ANLEGEN
                        // ==========================

                        // Bilder hochladen => Array von Media-IDs
                        $mediaIds = $shopwareService->uploadImages([$mapped['imageUrl']]);

                        if (empty($mediaIds)) {
                            $logger->warning("Konnte Bilder nicht hochladen. Überspringe Produkt [{$mapped['title']}]");
                            $skippedCount++;
                            continue; // geht zur nächsten Zeile in der CSV
                        }

                        // Kategorie nur für neue Produkte
                        $catId = null;
                        if (!empty($mapped['categoryHint'])) {
                            $bestCatPath = $openAiService->bestCategory(
                                $mapped['title'],
                                $mapped['description'],
                                $mapped['categoryHint'],
                                \array_keys($categoryMap)
                            );
                        
                            // Wenn false => OpenAI call fehlgeschlagen => skip
                            if ($bestCatPath === false) {
                                $logger->warning("OpenAI-Kategorievorschlag fehlgeschlagen => Produkt wird übersprungen.");
                                $skippedCount++;
                                continue;
                            }
                        
                            if (isset($categoryMap[$bestCatPath])) {
                                $catId = $categoryMap[$bestCatPath];
                            }
                        }

                        // Lieferzeit nur für neue Produkte
                        $deliveryTimeId = null;
                        if (!empty($mapped['deliveryTimeCsv'])) {
                            $bestDt = $openAiService->bestDeliveryTime(
                                $mapped['deliveryTimeCsv'],
                                \array_keys($deliveryTimes)
                            );

                            // Bei false => skip
                            if ($bestDt === false) {
                                $logger->warning("OpenAI-Lieferzeitvorschlag fehlgeschlagen => Produkt wird übersprungen.");
                                $skippedCount++;
                                continue;
                            }

                            if (isset($deliveryTimes[$bestDt])) {
                                $deliveryTimeId = $deliveryTimes[$bestDt];
                            }
                        }

                        // Beschreibung umschreiben
                        $rewrittenDesc = $openAiService->rewriteDescription(
                            $mapped['title'],
                            $mapped['description']
                        );

                        // Falls false => skip
                        if ($rewrittenDesc === false) {
                            $logger->warning("OpenAI-Beschreibung fehlgeschlagen => Produkt wird übersprungen.");
                            $skippedCount++;
                            continue;
                        }

                        // Payload fürs Erstellen
                        $payload = [
                            'name'           => $mapped['title'],
                            'productNumber'  => $mapped['productNumber'],
                            'stock'          => 9999,
                            'description'    => $rewrittenDesc,
                            'ean'            => $mapped['ean'],
                            'manufacturerId' => $manufacturerId,
                            'price' => [[
                                'currencyId' => 'b7d2554b0ce847cd82f3ac9bd1c0dfca', // Standardwährung
                                'gross'      => $actualPrice,
                                'net'        => ($actualPrice / 1.19),
                                'linked'     => false,
                            ]],
                            'active' => true,
                        ];

                        // Kategorie?
                        if ($catId) {
                            $payload['categories'] = [[ 'id' => $catId ]];
                        }

                        // AAN => manufacturerNumber
                        if (!empty($mapped['aan'])) {
                            $payload['manufacturerNumber'] = $mapped['aan'];
                        }

                        // Falls Streichpreis > 0
                        if ($listPrice > 0) {
                            $payload['price'][0]['listPrice'] = [
                                'gross'  => $listPrice,
                                'net'    => ($listPrice / 1.19),
                                'linked' => false,
                            ];
                        } else {
                            $payload['price'][0]['listPrice'] = null;
                        }

                        // Lieferzeit?
                        if ($deliveryTimeId) {
                            $payload['deliveryTimeId'] = $deliveryTimeId;
                        }

                        // Custom Fields
                        $cfDeeplink = $_ENV['SHOPWARE_CUSTOMFIELD_DEEPLINK'] ?? 'real_productlink';
                        $cfShipping = $_ENV['SHOPWARE_CUSTOMFIELD_SHIPPING_GENERAL'] ?? 'shipping_general';
                        $payload['customFields'] = [
                            $cfDeeplink => $mapped['deeplink'] ?? '',
                            $cfShipping => $mapped['shippingGeneral'] ?? '',
                        ];

                        // Cover & Media in einem Aufruf
                        if (!empty($mediaIds)) {
                            $payload['cover'] = ['mediaId' => $mediaIds[0]];
                            $payload['media'] = \array_map(
                                fn (string $mId): array => ['mediaId' => $mId],
                                $mediaIds
                            );
                        }

                        // Produkt erzeugen
                        if ($shopwareService->createProduct($payload)) {
                            $logger->info("Neues Produkt erstellt: [{$mapped['title']}]");
                            $createdCount++;
                        } else {
                            $logger->warning("Fehler beim Erstellen des Produkts [{$mapped['title']}]");
                            $skippedCount++;
                        }
                    } else {
                        // ==========================
                        // PRODUKT UPDATEN
                        // ==========================
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
                        } else {
                            $updatePayload['price'][0]['listPrice'] = null;
                        }

                        // Custom Fields
                        $cfDeeplink = $_ENV['SHOPWARE_CUSTOMFIELD_DEEPLINK'] ?? 'real_productlink';
                        $cfShipping = $_ENV['SHOPWARE_CUSTOMFIELD_SHIPPING_GENERAL'] ?? 'shipping_general';
                        $updatePayload['customFields'] = [
                            $cfDeeplink => $mapped['deeplink'] ?? '',
                            $cfShipping => $mapped['shippingGeneral'] ?? '',
                        ];

                        // Update
                        if ($shopwareService->updateProduct($existingId, $updatePayload)) {
                            $logger->info("Produkt aktualisiert: [{$mapped['title']}]");
                            $updatedCount++;
                        } else {
                            $logger->warning("Fehler beim Aktualisieren des Produkts [{$mapped['title']}]");
                            $skippedCount++;
                        }
                    }
                } // Ende for rows

                $logger->info(
                    "CSV '$csvId' fertig. " .
                    "Created=$createdCount, Updated=$updatedCount, Skipped=$skippedCount"
                );
            } // Ende foreach CSV

        } catch (\Throwable $e) {
            $logger->error("Sync-Fehler: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $logger->info("Alle CSVs wurden erfolgreich verarbeitet.");
        return Command::SUCCESS;
    }

    /**
     * Liest CSV-Definitionen aus den ENV-Variablen aus:
     *  CSV_URL_1, CSV_ID_1, CSV_MAPPING_1, usw.
     *
     * @param array $envVars Die gesamten ENV-Variablen.
     * @return array Array mit allen CSV-Definitionen.
     */
    private function getCsvDefinitions(array $envVars): array
    {
        $defs = [];
        foreach ($envVars as $key => $val) {
            if (\str_starts_with($key, 'CSV_URL_')) {
                // Index extrahieren
                $index = \substr($key, \strlen('CSV_URL_'));
                $defs[$index]['url']     = $val;
                $defs[$index]['id']      = $envVars["CSV_ID_{$index}"]      ?? "CSV{$index}";
                $defs[$index]['mapping'] = $envVars["CSV_MAPPING_{$index}"] ?? '';
            }
        }
        \ksort($defs);
        return $defs;
    }
}
