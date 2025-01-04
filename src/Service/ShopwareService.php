<?php

namespace JUNU\RealADCELL\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * ShopwareService
 * --------------
 * - Authentifizierung via Admin API
 * - Abruf von Kategorien, Lieferzeiten, Tax-IDs, Sales-Channels
 * - Hersteller anlegen / suchen
 * - Produkte erstellen / updaten (mit taxId, media etc.)
 * - u.a.
 */
class ShopwareService
{
    private Client $client;
    private LoggerInterface $logger;
    private string $apiUrl;
    private string $clientId;
    private string $clientSecret;
    private ?string $token = null;

    private ?string $salesChannelId = null; 
    private ?string $defaultTaxId   = null; // neu: Standard-Steuer-ID speichern

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

    private function getDefaultHeaders(): array
    {
        if (!$this->token) {
            $this->authenticate();
        }
        return [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization'=> "Bearer {$this->token}",
        ];
    }

    /**
     * 1) Authentifizierung
     * 2) salesChannelId laden
     * 3) defaultTaxId laden
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

            $data        = json_decode((string)$resp->getBody(), true);
            $this->token = $data['access_token'] ?? null;
            if (!$this->token) {
                throw new \RuntimeException("No token found in authentication response.");
            }

            $this->logger->info("Shopware authentication succeeded.");
            // Now load sales channel + default tax
            $this->fetchSalesChannelId();
            $this->fetchDefaultTaxId();

        } catch (GuzzleException $e) {
            $this->logger->error("Shopware authentication failed: " . $e->getMessage());
            throw new \RuntimeException("Shopware authentication failed.", 0, $e);
        }
    }

    /**
     * Ermittelt den Sales-Channel (Name aus .env => SHOPWARE_SALES_CHANNEL_NAME)
     */
    private function fetchSalesChannelId(): void
    {
        $desiredName = $_ENV['SHOPWARE_SALES_CHANNEL_NAME'] ?? 'Storefront';
        $this->salesChannelId = null;

        try {
            $response = $this->client->get('/api/sales-channel', [
                'headers' => $this->getDefaultHeaders(),
                'query'   => [
                    'filter[name]' => $desiredName,
                    'limit'        => 1,
                ],
            ]);
            $data  = json_decode($response->getBody(), true);
            $items = $data['data'] ?? [];

            if (!empty($items[0]['id'])) {
                $this->salesChannelId = $items[0]['id'];
                $this->logger->info("Sales-Channel '$desiredName' => ID {$this->salesChannelId}");
            } else {
                $this->logger->warning("Sales-Channel '$desiredName' nicht gefunden.");
            }
        } catch (GuzzleException $e) {
            $this->logger->error("fetchSalesChannelId error: " . $e->getMessage());
        }
    }

    /**
     * Lädt die erste gefundene Tax-ID, die (meist) die Standard-Steuer repräsentiert.
     * Alternativ könnte man via Name='Standard rate' filtern.
     */
    private function fetchDefaultTaxId(): void
    {
        $this->defaultTaxId = null;

        try {
            // Evtl. filtern via 'filter[name]' => 'Standard rate' oder so
            // Hier holen wir einfach die Liste und nehmen den ersten Datensatz
            $resp = $this->client->get('/api/tax', [
                'headers' => $this->getDefaultHeaders(),
                'query'   => [
                    'page'  => 1,
                    'limit' => 10,
                ],
            ]);
            $data = json_decode($resp->getBody(), true);
            $items= $data['data'] ?? [];

            if (!empty($items[0]['id'])) {
                $this->defaultTaxId = $items[0]['id'];
                $name = $items[0]['attributes']['name'] ?? 'N/A';
                $this->logger->info("Default Tax found: $name => {$this->defaultTaxId}");
            } else {
                $this->logger->warning("No default tax found. Possibly missing tax data in Shopware?");
            }

        } catch (GuzzleException $e) {
            $this->logger->error("fetchDefaultTaxId error: " . $e->getMessage());
        }
    }

    /**
     * Getter für die Default-Tax-ID
     */
    public function getDefaultTaxId(): ?string
    {
        return $this->defaultTaxId;
    }

    // ---------------------------------------
    // CATEGORIES
    // ---------------------------------------
    public function getAllCategories(): array
    {
        $allCats = [];
        $page    = 1;
        do {
            try {
                $response = $this->client->get('/api/category', [
                    'headers' => $this->getDefaultHeaders(),
                    'query'   => ['page' => $page, 'limit' => 50],
                ]);
                $data  = json_decode((string)$response->getBody(), true);
                $items = $data['data'] ?? [];

                foreach ($items as $cat) {
                    $catName = $cat['attributes']['name'] ?? '';
                    $catId   = $cat['id']                ?? '';
                    if ($catName && $catId) {
                        $allCats[$catName] = $catId;
                    }
                }
                if (count($items) < 50) {
                    break;
                }
                $page++;
            } catch (GuzzleException $e) {
                $this->logger->error("getAllCategories page $page error: " . $e->getMessage());
                break;
            }
        } while (true);

        return $allCats;
    }

    // ---------------------------------------
    // DELIVERY TIMES
    // ---------------------------------------
    public function getAllDeliveryTimes(): array
    {
        $allDts = [];
        $page   = 1;
        do {
            try {
                $resp = $this->client->get('/api/delivery-time', [
                    'headers' => $this->getDefaultHeaders(),
                    'query'   => ['page' => $page, 'limit' => 50],
                ]);
                $data  = json_decode((string)$resp->getBody(), true);
                $items = $data['data'] ?? [];

                foreach ($items as $dt) {
                    $dtName = $dt['attributes']['name'] ?? '';
                    $dtId   = $dt['id']                ?? '';
                    if ($dtName && $dtId) {
                        $allDts[$dtName] = $dtId;
                    }
                }

                if (count($items) < 50) {
                    break;
                }
                $page++;
            } catch (GuzzleException $e) {
                $this->logger->error("getAllDeliveryTimes page $page error: " . $e->getMessage());
                break;
            }
        } while (true);

        return $allDts;
    }

    // ---------------------------------------
    // MANUFACTURER
    // ---------------------------------------
    public function findOrCreateManufacturer(string $name): ?string
    {
        $name = trim($name);
        if (empty($name)) {
            // Fallback
            $name = 'Default Hersteller';
        }

        // 1) Suchen
        try {
            $resp = $this->client->get('/api/product-manufacturer', [
                'headers' => $this->getDefaultHeaders(),
                'query'   => [
                    'filter[name]' => $name,
                    'limit'        => 50,
                ],
            ]);
            $data  = json_decode((string)$resp->getBody(), true);
            $items = $data['data'] ?? [];

            foreach ($items as $m) {
                $mName = mb_strtolower(trim($m['attributes']['name'] ?? ''));
                if ($mName === mb_strtolower($name)) {
                    return $m['id'];
                }
            }
        } catch (GuzzleException $e) {
            $this->logger->warning("Manufacturer search error: " . $e->getMessage());
        }

        // 2) Erstellen
        $uuid = UuidService::generate();
        try {
            $resp = $this->client->post('/api/product-manufacturer', [
                'headers' => $this->getDefaultHeaders(),
                'json'    => [
                    'id'   => $uuid,
                    'name' => $name,
                ],
            ]);

            if ($resp->getStatusCode() !== 204) {
                $body = (string)$resp->getBody();
                $this->logger->error("Failed to create manufacturer: $body");
                return null;
            }

            $this->logger->info("Created manufacturer '$name' => $uuid");
            return $uuid;

        } catch (GuzzleException $e) {
            $this->logger->error("createManufacturer error: " . $e->getMessage());
            return null;
        }
    }

    // ---------------------------------------
    // PRODUCT
    // ---------------------------------------
    public function findProductByNumber(string $productNumber): ?array
    {
        if (!$productNumber) return null;
        try {
            $resp = $this->client->get('/api/product', [
                'headers' => $this->getDefaultHeaders(),
                'query'   => [
                    'filter[productNumber]' => $productNumber,
                    'limit'                 => 1,
                ],
            ]);
            $data = json_decode((string)$resp->getBody(), true);
            return $data['data'][0] ?? null;
        } catch (GuzzleException $e) {
            $this->logger->error("findProductByNumber error: " . $e->getMessage());
            return null;
        }
    }

    public function createProduct(array $payload): bool
    {
        // Wir erwarten: 'id', 'taxId', 'categories' (mind. leeres Array) 
        // Falls noch nicht gesetzt, füllen wir sie:
        if (empty($payload['id'])) {
            $payload['id'] = UuidService::generate();
        }
        if (empty($payload['taxId']) && !empty($this->defaultTaxId)) {
            $payload['taxId'] = $this->defaultTaxId;
        }
        if (!isset($payload['categories'])) {
            $payload['categories'] = []; 
        }

        // Visibility für den Sales Channel
        if (!empty($this->salesChannelId)) {
            $payload['visibilities'] = [[
                'salesChannelId' => $this->salesChannelId,
                'visibility'     => 30, // All
            ]];
        }

        try {
            $resp = $this->client->post('/api/product', [
                'headers' => $this->getDefaultHeaders(),
                'json'    => $payload,
            ]);

            if ($resp->getStatusCode() !== 204) {
                $body = (string)$resp->getBody();
                $this->logger->error("Create product failed: $body");
                return false;
            }
            return true;

        } catch (GuzzleException $e) {
            $this->logger->error("createProduct error: " . $e->getMessage());
            return false;
        }
    }

    public function updateProduct(string $id, array $payload): bool
    {
        try {
            $resp = $this->client->patch("/api/product/$id", [
                'headers' => $this->getDefaultHeaders(),
                'json'    => $payload,
            ]);

            if ($resp->getStatusCode() !== 204) {
                $body = (string)$resp->getBody();
                $this->logger->error("Update product failed: $body");
                return false;
            }
            return true;

        } catch (GuzzleException $e) {
            $this->logger->error("updateProduct error: " . $e->getMessage());
            return false;
        }
    }

    // ---------------------------------------
    // MEDIA / IMAGES
    // ---------------------------------------
    public function findMediaByFilename(string $fileNameWithoutExt): ?string
    {
        try {
            $resp = $this->client->get('/api/media', [
                'headers' => $this->getDefaultHeaders(),
                'query'   => [
                    'filter[fileName]' => $fileNameWithoutExt,
                    'limit'            => 1,
                ],
            ]);
            $data = json_decode($resp->getBody(), true);

            if (!empty($data['data'][0]['id'])) {
                return $data['data'][0]['id'];
            }
        } catch (GuzzleException $e) {
            $this->logger->error("findMediaByFilename error: " . $e->getMessage());
        }
        return null;
    }

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
                $this->logger->error("createMediaEntity failed: " . (string)$resp->getBody());
                return null;
            }
            return $mediaId;
        } catch (GuzzleException $e) {
            $this->logger->error("createMediaEntity error: " . $e->getMessage());
            return null;
        }
    }

    public function uploadImageFromUrl(string $mediaId, string $imageUrl, string $fileNameWithoutExt): bool
    {
        try {
            $uploadUrl = "/api/_action/media/{$mediaId}/upload?fileName=" . urlencode($fileNameWithoutExt);

            $resp = $this->client->post($uploadUrl, [
                'headers' => $this->getDefaultHeaders(),
                'json'    => [
                    'url' => $imageUrl,
                ],
            ]);

            if ($resp->getStatusCode() !== 204) {
                $this->logger->error("uploadImageFromUrl error: " . (string)$resp->getBody());
                return false;
            }
            return true;

        } catch (GuzzleException $e) {
            $this->logger->error("uploadImageFromUrl exception: " . $e->getMessage());
            return false;
        }
    }

    public function uploadImages(array $imageUrls): array
    {
        static $cache = [];
        $mediaIds     = [];

        foreach ($imageUrls as $imageUrl) {
            if (empty($imageUrl)) {
                continue;
            }
            try {
                $filename           = basename(parse_url($imageUrl, PHP_URL_PATH) ?? '');
                $fileNameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

                if (isset($cache[$fileNameWithoutExt])) {
                    $mediaIds[] = $cache[$fileNameWithoutExt];
                    continue;
                }

                $existing = $this->findMediaByFilename($fileNameWithoutExt);
                if ($existing) {
                    $this->logger->info("Media already exists $filename => $existing");
                    $mediaIds[] = $existing;
                    $cache[$fileNameWithoutExt] = $existing;
                    continue;
                }

                $newMediaId = $this->createMediaEntity();
                if (!$newMediaId) {
                    $this->logger->error("Fehler beim Anlegen media für: $imageUrl");
                    continue;
                }

                if (!$this->uploadImageFromUrl($newMediaId, $imageUrl, $fileNameWithoutExt)) {
                    $this->logger->error("Fehler beim Upload von: $imageUrl");
                    continue;
                }

                $mediaIds[] = $newMediaId;
                $cache[$fileNameWithoutExt] = $newMediaId;
                $this->logger->info("Uploaded image $imageUrl => $newMediaId");

            } catch (\Throwable $th) {
                $this->logger->error("uploadImages error: " . $th->getMessage());
            }
        }

        return $mediaIds;
    }
}
