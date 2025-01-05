<?php

/**
 * Autor:    Sebastian Gräbner (sebastian@ju.nu)
 * Firma:    JUNU Marketing Group LTD
 * Datum:    2025-01-05
 * Zweck:    Service-Klasse für Aufrufe gegen die Shopware-API.
 */

namespace JUNU\ShopwareAffiliateSync\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

final class ShopwareService
{
    private Client $client;
    private LoggerInterface $logger;

    private string $apiUrl;
    private string $clientId;
    private string $clientSecret;
    private ?string $token = null;

    /**
     * Speichert das Ablaufdatum des Tokens als Unix-Zeitstempel.
     */
    private ?int $tokenExpiresAt = null;

    private ?string $salesChannelId = null;
    private ?string $defaultTaxId   = null;

    /**
     * Cache für Hersteller-IDs pro Name.
     */
    private static array $manufacturerCache = [];

    /**
     * Rohe Kategorien (unstrukturierte Daten).
     */
    private array $rawCategories = [];

    /**
     * Mapping Pfad => categoryId
     */
    private array $flattenedCategoryPaths = [];

    /**
     * Konstruktor
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger       = $logger;
        $this->apiUrl       = $_ENV['SHOPWARE_API_URL']      ?? '';
        $this->clientId     = $_ENV['SHOPWARE_CLIENT_ID']     ?? '';
        $this->clientSecret = $_ENV['SHOPWARE_CLIENT_SECRET'] ?? '';

        $this->client = new Client([
            'base_uri' => $this->apiUrl,
            'timeout'  => 30,
        ]);
    }

    /**
     * Prüft, ob das Token abgelaufen ist oder bald abläuft.
     */
    private function isTokenExpired(): bool
    {
        if ($this->tokenExpiresAt === null) {
            return true;
        }
        // 30-Sek-Puffer
        return (\time() >= ($this->tokenExpiresAt - 30));
    }

    /**
     * Gibt Standard-Header (inkl. Authorization) zurück,
     * erneuert ggf. das Token, wenn es abgelaufen ist.
     */
    private function getDefaultHeaders(): array
    {
        if ($this->token === null || $this->isTokenExpired()) {
            $this->authenticate();
        }
        return [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => "Bearer {$this->token}",
        ];
    }

    /**
     * Authentifiziert gegen Shopware und speichert das Token.
     * Lädt zusätzlich salesChannelId und defaultTaxId.
     */
    public function authenticate(): void
    {
        try {
            $resp = $this->client->post('/api/oauth/token', [
                'headers' => [
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
            ]);

            $data = \json_decode($resp->getBody()->getContents(), true);
            $this->token = $data['access_token'] ?? null;
            $expiresInSeconds = $data['expires_in'] ?? 3600;
            $this->tokenExpiresAt = \time() + $expiresInSeconds;

            if (!$this->token) {
                throw new \RuntimeException("Token fehlte in der Authentifizierungsantwort.");
            }

            $this->logger->info(
                \sprintf(
                    "Shopware-Auth OK. Token läuft ab in %d Sekunden (um %s).",
                    $expiresInSeconds,
                    \date('Y-m-d H:i:s', $this->tokenExpiresAt)
                )
            );

            $this->fetchSalesChannelId();
            $this->fetchDefaultTaxId();
        } catch (GuzzleException $e) {
            $this->logger->error("Shopware-Authentifizierung fehlgeschlagen: " . $e->getMessage());
            throw new \RuntimeException("Shopware-Auth fehlgeschlagen.", 0, $e);
        }
    }

    /**
     * Sales-Channel-ID ermitteln
     */
    private function fetchSalesChannelId(): void
    {
        $desiredName = $_ENV['SHOPWARE_SALES_CHANNEL_NAME'] ?? 'Storefront';
        $this->salesChannelId = null;

        try {
            $resp = $this->client->get('/api/sales-channel', [
                'headers' => $this->getDefaultHeaders(),
                'query' => [
                    'filter[name]' => $desiredName,
                    'limit'        => 1,
                ],
            ]);

            $data  = \json_decode($resp->getBody()->getContents(), true);
            $items = $data['data'] ?? [];

            if (!empty($items[0]['id'])) {
                $this->salesChannelId = $items[0]['id'];
                $this->logger->info("Sales-Channel '{$desiredName}' => ID {$this->salesChannelId}");
            } else {
                $this->logger->warning("Sales-Channel '{$desiredName}' nicht gefunden.");
            }
        } catch (GuzzleException $e) {
            $this->logger->error("fetchSalesChannelId Fehler: " . $e->getMessage());
        }
    }

    /**
     * Standard-Tax ermitteln
     */
    private function fetchDefaultTaxId(): void
    {
        $this->defaultTaxId = null;
        try {
            $resp = $this->client->get('/api/tax', [
                'headers' => $this->getDefaultHeaders(),
                'query'   => [
                    'limit' => 50,
                ],
            ]);
            $data  = \json_decode($resp->getBody()->getContents(), true);
            $items = $data['data'] ?? [];

            $foundTax = null;
            foreach ($items as $item) {
                // Manche Shopware-Versionen legen "position=1" als Standard an
                $pos = $item['position'] ?? ($item['attributes']['position'] ?? null);
                if ((int)$pos === 1) {
                    $foundTax = $item;
                    break;
                }
            }
            if ($foundTax && !empty($foundTax['id'])) {
                $this->defaultTaxId = $foundTax['id'];
                $taxName = $foundTax['name'] ?? ($foundTax['attributes']['name'] ?? 'unbekannt');
                $this->logger->info("Default-Steuer: {$this->defaultTaxId} (Name={$taxName}, Position=1)");
            } else {
                $this->logger->warning("Keine Steuer mit position=1 unter /api/tax gefunden.");
            }
        } catch (GuzzleException $e) {
            $this->logger->error("fetchDefaultTaxId Fehler: " . $e->getMessage());
        }
    }

    /**
     * Gibt die Default-Tax-ID zurück.
     */
    public function getDefaultTaxId(): ?string
    {
        return $this->defaultTaxId;
    }

    // ----------------------------------------------------------------
    // Kategorien
    // ----------------------------------------------------------------
    /**
     * Lädt alle Kategorien, baut eine flache "Pfad => CatId"-Liste.
     */
    public function getAllCategories(): array
    {
        $this->rawCategories           = [];
        $this->flattenedCategoryPaths  = [];

        $page  = 1;
        $limit = 100;

        while (true) {
            try {
                $resp = $this->client->get('/api/category', [
                    'headers' => $this->getDefaultHeaders(),
                    'query' => [
                        'page'  => $page,
                        'limit' => $limit,
                    ],
                ]);
                $data  = \json_decode($resp->getBody()->getContents(), true);
                $items = $data['data'] ?? [];
                if (empty($items)) {
                    break;
                }

                foreach ($items as $cat) {
                    $catId    = $cat['id']   ?? '';
                    $catName  = $cat['name'] ?? '';
                    $parentId = $cat['parentId'] ?? null;

                    if ($catId && $catName) {
                        $this->rawCategories[$catId] = [
                            'id'       => $catId,
                            'parentId' => $parentId,
                            'name'     => $catName,
                            'children' => [],
                        ];
                    }
                }

                if (\count($items) < $limit) {
                    break;
                }
                $page++;
                // Sicherheitsgrenze
                if ($page > 20) {
                    break;
                }
            } catch (GuzzleException $e) {
                $this->logger->error("getAllCategories Seite=$page Fehler: " . $e->getMessage());
                break;
            }
        }

        $this->buildCategoryTree();
        $this->flattenCategoryTree();

        return $this->flattenedCategoryPaths;
    }

    /**
     * Baut einen rudimentären Baum aus den rohen Kategorien.
     */
    private function buildCategoryTree(): void
    {
        foreach ($this->rawCategories as $catId => $cat) {
            $pId = $cat['parentId'];
            if ($pId && isset($this->rawCategories[$pId])) {
                $this->rawCategories[$pId]['children'][] = $catId;
            }
        }
    }

    /**
     * Durchläuft den Baum rekursiv und baut Pfade wie "Parent > Child".
     */
    private function flattenCategoryTree(): void
    {
        // Wurzelknoten herausfinden
        $rootIds = [];
        foreach ($this->rawCategories as $catId => $cat) {
            $pId = $cat['parentId'];
            if (!$pId || !isset($this->rawCategories[$pId])) {
                $rootIds[] = $catId;
            }
        }

        foreach ($rootIds as $rId) {
            $rootName = $this->rawCategories[$rId]['name'];
            $this->dfsCategory($rId, $rootName);
        }
    }

    /**
     * Tiefensuche (DFS), um jeden Kinder-Knoten aufzulisten und
     * Pfade in $this->flattenedCategoryPaths zu speichern.
     */
    private function dfsCategory(string $catId, string $currentPath): void
    {
        $this->flattenedCategoryPaths[$currentPath] = $catId;

        $children = $this->rawCategories[$catId]['children'] ?? [];
        foreach ($children as $childId) {
            $childName = $this->rawCategories[$childId]['name'] ?? '(NoName)';
            $path      = $currentPath . ' > ' . $childName;
            $this->dfsCategory($childId, $path);
        }
    }

    // ----------------------------------------------------------------
    // Lieferzeiten
    // ----------------------------------------------------------------
    /**
     * Liefert ein Array mit allen Lieferzeiten: name => id
     */
    public function getAllDeliveryTimes(): array
    {
        $allDts = [];
        $page   = 1;
        $limit  = 100;

        while (true) {
            try {
                $resp = $this->client->get('/api/delivery-time', [
                    'headers' => $this->getDefaultHeaders(),
                    'query'   => [
                        'page'  => $page,
                        'limit' => $limit,
                    ],
                ]);
                $data  = \json_decode($resp->getBody()->getContents(), true);
                $items = $data['data'] ?? [];
                if (empty($items)) {
                    break;
                }

                foreach ($items as $dt) {
                    $dtId   = $dt['id']   ?? '';
                    $dtName = $dt['name'] ?? '';
                    if ($dtId && $dtName) {
                        $allDts[$dtName] = $dtId;
                    }
                }

                if (\count($items) < $limit) {
                    break;
                }
                $page++;
                if ($page > 20) {
                    break;
                }
            } catch (GuzzleException $e) {
                $this->logger->error("getAllDeliveryTimes Seite=$page Fehler: " . $e->getMessage());
                break;
            }
        }
        return $allDts;
    }

    // ----------------------------------------------------------------
    // Hersteller
    // ----------------------------------------------------------------
    /**
     * Findet oder erstellt einen Hersteller für die entsprechende CSV.
     */
    public function findOrCreateManufacturerForCsv(string $csvIndex, string $csvManufacturerName): ?string
    {
        $name = \trim($csvManufacturerName);
        if (empty($name)) {
            $envKey  = "CSV_DEFAULT_MANUFACTURER_{$csvIndex}";
            $fallback = $_ENV[$envKey] ?? 'Default Hersteller';
            $name    = $fallback;
        }
        return $this->findOrCreateManufacturer($name);
    }

    /**
     * Prüft, ob der Hersteller bereits existiert. Falls nein, wird er neu angelegt.
     */
    public function findOrCreateManufacturer(string $name): ?string
    {
        $name = \trim($name);
        if (empty($name)) {
            $name = 'Default Hersteller';
        }

        $lowerName = \mb_strtolower($name);
        if (isset(self::$manufacturerCache[$lowerName])) {
            return self::$manufacturerCache[$lowerName];
        }

        // Suchen
        $existingId = $this->findManufacturerByName($name);
        if ($existingId) {
            self::$manufacturerCache[$lowerName] = $existingId;
            return $existingId;
        }

        // Anlegen
        $newId = UuidService::generate();
        try {
            $resp = $this->client->post('/api/product-manufacturer', [
                'headers' => $this->getDefaultHeaders(),
                'json'    => [
                    'id'   => $newId,
                    'name' => $name,
                ],
            ]);

            if ($resp->getStatusCode() !== 204) {
                $body = $resp->getBody()->getContents();
                $this->logger->error("Fehler beim Hersteller-Anlegen '{$name}'. Antwort: $body");
                return null;
            }

            $this->logger->info("Neuer Hersteller angelegt: '{$name}' => $newId");
            self::$manufacturerCache[$lowerName] = $newId;
            return $newId;
        } catch (GuzzleException $e) {
            $this->logger->error("createManufacturer exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Sucht Hersteller über Name.
     */
    private function findManufacturerByName(string $name): ?string
    {
        try {
            $searchBody = [
                'filter' => [
                    [
                        'field' => 'product_manufacturer.name',
                        'type'  => 'equals',
                        'value' => $name,
                    ]
                ],
                'limit' => 1
            ];

            $resp = $this->client->post('/api/search/product-manufacturer', [
                'headers' => $this->getDefaultHeaders(),
                'json'    => $searchBody,
            ]);
            $data  = \json_decode($resp->getBody()->getContents(), true);
            $items = $data['data'] ?? [];

            return !empty($items[0]['id']) ? $items[0]['id'] : null;
        } catch (GuzzleException $e) {
            $this->logger->warning("findManufacturerByName('$name') Fehler: " . $e->getMessage());
            return null;
        }
    }

    // ----------------------------------------------------------------
    // Produkte
    // ----------------------------------------------------------------
    /**
     * Sucht ein Produkt anhand der productNumber.
     */
    public function findProductByNumber(string $productNumber): ?array
    {
        if (!$productNumber) {
            return null;
        }
        try {
            $resp = $this->client->get('/api/product', [
                'headers' => $this->getDefaultHeaders(),
                'query'   => [
                    'filter[productNumber]' => $productNumber,
                    'limit'                 => 1,
                ],
            ]);
            $data = \json_decode($resp->getBody()->getContents(), true);
            return $data['data'][0] ?? null;
        } catch (GuzzleException $e) {
            $this->logger->error("findProductByNumber Fehler: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Legt ein Produkt neu an.
     */
    public function createProduct(array $payload): bool
    {
        // Füge ggf. eine ID und taxId hinzu
        $payload['id'] ??= UuidService::generate();
        $payload['taxId'] ??= $this->defaultTaxId;

        // Kategorien-Array absichern
        $payload['categories'] ??= [];

        // Sichtbarkeit für SalesChannel
        if (!empty($this->salesChannelId)) {
            $payload['visibilities'] = [[
                'salesChannelId' => $this->salesChannelId,
                'visibility'     => 30,
            ]];
        }

        try {
            $resp = $this->client->post('/api/product', [
                'headers' => $this->getDefaultHeaders(),
                'json'    => $payload,
            ]);
            if ($resp->getStatusCode() !== 204) {
                $body = $resp->getBody()->getContents();
                $this->logger->error("createProduct fehlgeschlagen: $body");
                return false;
            }
            return true;
        } catch (GuzzleException $e) {
            $this->logger->error("createProduct Fehler: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Aktualisiert ein existierendes Produkt.
     */
    public function updateProduct(string $id, array $payload): bool
    {
        try {
            $resp = $this->client->patch("/api/product/$id", [
                'headers' => $this->getDefaultHeaders(),
                'json'    => $payload,
            ]);
            if ($resp->getStatusCode() !== 204) {
                $body = $resp->getBody()->getContents();
                $this->logger->error("updateProduct fehlgeschlagen: $body");
                return false;
            }
            return true;
        } catch (GuzzleException $e) {
            $this->logger->error("updateProduct Fehler: " . $e->getMessage());
            return false;
        }
    }

    // ----------------------------------------------------------------
    // MEDIA
    // ----------------------------------------------------------------

    private function cleanFilename(string $name): ?string
    {
        $name = urldecode($name);
        $name = preg_replace('/[^\w.\-]+/', '_', $name);
        $name = trim($name, '_');
    
        return empty($name) ? null : $name;
    }    
    
    /**
     * Sucht eine Media-ID anhand des Dateinamens (ohne Extension).
     */
    public function findMediaByFilename(string $fileNameWithoutExt): ?string
    {

        $fileNameWithoutExt = $this->cleanFilename($fileNameWithoutExt);

        if ($fileNameWithoutExt === null) {
            return null;
        }

        
        try {
            $resp = $this->client->get('/api/media', [
                'headers' => $this->getDefaultHeaders(),
                'query'   => [
                    'filter[fileName]' => $fileNameWithoutExt,
                    'limit'            => 1,
                ],
            ]);
            $data = \json_decode($resp->getBody()->getContents(), true);
            return $data['data'][0]['id'] ?? null;
        } catch (GuzzleException $e) {
            $this->logger->error("findMediaByFilename Fehler: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Erstellt ein leeres Media-Objekt in Shopware.
     */
    public function createMediaEntity(?string $mediaFolderId = null): ?string
    {
        $mediaId = UuidService::generate();
        $payload = ['id' => $mediaId];
        if ($mediaFolderId) {
            $payload['mediaFolderId'] = $mediaFolderId;
        }

        try {
            $resp = $this->client->post('/api/media', [
                'headers' => $this->getDefaultHeaders(),
                'json'    => $payload,
            ]);
            if ($resp->getStatusCode() !== 204) {
                $body = $resp->getBody()->getContents();
                $this->logger->error("createMediaEntity fehlgeschlagen: $body");
                return null;
            }
            return $mediaId;
        } catch (GuzzleException $e) {
            $this->logger->error("createMediaEntity Fehler: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Lädt ein Bild direkt aus einer URL zu Shopware hoch.
     */
    public function uploadImageFromUrl(string $mediaId, string $imageUrl, string $fileNameWithoutExt): bool
    {
        $fileNameWithoutExt = $this->cleanFilename($fileNameWithoutExt);

        if ($fileNameWithoutExt === null) {
            return false;
        }

        try {
            $uploadUrl = "/api/_action/media/{$mediaId}/upload?fileName=" . \urlencode($fileNameWithoutExt);

            $resp = $this->client->post($uploadUrl, [
                'headers' => $this->getDefaultHeaders(),
                'json'    => [
                    'url' => $imageUrl,
                ],
            ]);
            if ($resp->getStatusCode() !== 204) {
                $body = $resp->getBody()->getContents();
                $this->logger->error("uploadImageFromUrl Fehler: $body");
                return false;
            }
            return true;
        } catch (GuzzleException $e) {
            $this->logger->error("uploadImageFromUrl Fehler: " . $e->getMessage());
            return false;
        }
    }
    

    /**
     * Lädt mehrere Bilder hoch und gibt die Media-IDs zurück.
     */
    public function uploadImages(array $imageUrls): array
    {
        static $cache = [];
        $mediaIds     = [];
    
        foreach ($imageUrls as $imageUrl) {
            if (empty($imageUrl)) {
                continue;
            }
    
            try {
                // Dateiname ohne Extension ermitteln
                $filename           = \basename(\parse_url($imageUrl, \PHP_URL_PATH) ?? '');
                $fileNameWithoutExt = \pathinfo($filename, \PATHINFO_FILENAME);
    
                // Falls schon im Cache -> direkt hinzufügen
                if (isset($cache[$fileNameWithoutExt])) {
                    $mediaIds[] = $cache[$fileNameWithoutExt];
                    continue;
                }
    
                // Prüfen, ob Media bereits in Shopware existiert
                $existing = $this->findMediaByFilename($fileNameWithoutExt);
                if ($existing) {
                    $this->logger->info("Media existiert bereits: $filename => $existing");
                    $mediaIds[] = $existing;
                    $cache[$fileNameWithoutExt] = $existing;
                    continue;
                }
    
                // Neues Media-Objekt erzeugen
                $newMediaId = $this->createMediaEntity();
                if (!$newMediaId) {
                    $this->logger->error("Fehler beim Erstellen der Media-Entity für $imageUrl");
                    // Wir brechen ab und geben ein leeres Array zurück
                    return [];
                }
    
                // Jetzt dreimal versuchen, das Bild hochzuladen:
                $attempt      = 1;
                $maxAttempts  = 3;
                $uploadOk     = false;
    
                while ($attempt <= $maxAttempts) {
                    // Upload-Versuch
                    if ($this->uploadImageFromUrl($newMediaId, $imageUrl, $fileNameWithoutExt)) {
                        // Erfolg!
                        $uploadOk = true;
                        break;
                    }
    
                    // Falls fehlgeschlagen -> warten und nochmal
                    if ($attempt === 1) {
                        $this->logger->warning("Erster Upload-Versuch fehlgeschlagen. Warte 10 Sekunden...");
                        \sleep(10);
                    } elseif ($attempt === 2) {
                        $this->logger->warning("Zweiter Upload-Versuch fehlgeschlagen. Warte 30 Sekunden...");
                        \sleep(30);
                    }
    
                    $attempt++;
                }
    
                // Falls alle 3 Versuche fehlschlagen => Produkt wird übersprungen
                if (!$uploadOk) {
                    $this->logger->error("Bild-Upload endgueltig fehlgeschlagen für $imageUrl. Produkt wird übersprungen.");
                    return []; 
                }
    
                // Cache & Rückgabe befüllen
                $mediaIds[] = $newMediaId;
                $cache[$fileNameWithoutExt] = $newMediaId;
    
                $this->logger->info("Bild hochgeladen: $imageUrl => $newMediaId");
    
            } catch (\Throwable $th) {
                $this->logger->error("uploadImages Fehler: " . $th->getMessage());
                return [];
            }
        }
    
        return $mediaIds;
    }
    
}
