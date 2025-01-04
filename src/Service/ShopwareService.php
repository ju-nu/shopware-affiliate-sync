<?php
// src/Service/ShopwareService.php

namespace JUNU\RealADCELL\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use JUNU\RealADCELL\Service\UuidService;

/**
 * ShopwareService
 * ---------------
 * - Authentifizierung via Admin API
 * - Laden aller Kategorien (Baum) => Flatten "Parent > Child" => ID
 * - DefaultTaxId (Vollsteuer, position=1)
 * - Sales-Channel per ENV
 * - Anlegen/Updaten von Produkten (coverId in second step)
 * - listPrice => original/streichpreis
 * - Manufacturer Cache => no duplicates
 * - findOrCreateManufacturerForCsv => fallback from env
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
    private ?string $defaultTaxId   = null;

    /**
     * Cache manufacturer name => id so we don't re-create them
     */
    private static array $manufacturerCache = [];

    /**
     * For categories
     */
    private array $rawCategories = [];
    private array $flattenedCategoryPaths = [];

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
     * 1) Auth => token
     * 2) fetchSalesChannelId
     * 3) fetchDefaultTaxId
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
            $data = json_decode((string)$resp->getBody(), true);
            $this->token = $data['access_token'] ?? null;
            if (!$this->token) {
                throw new \RuntimeException("No token in authentication response.");
            }
            $this->logger->info("Shopware authentication succeeded.");

            $this->fetchSalesChannelId();
            $this->fetchDefaultTaxId();

        } catch (GuzzleException $e) {
            $this->logger->error("Shopware authentication failed: {$e->getMessage()}");
            throw new \RuntimeException("Shopware authentication failed.", 0, $e);
        }
    }

    private function fetchSalesChannelId(): void
    {
        $desiredName = $_ENV['SHOPWARE_SALES_CHANNEL_NAME'] ?? 'Storefront';
        $this->salesChannelId = null;

        try {
            $resp = $this->client->get('/api/sales-channel', [
                'headers' => $this->getDefaultHeaders(),
                'query'   => [
                    'filter[name]' => $desiredName,
                    'limit'        => 1,
                ],
            ]);
            $data  = json_decode($resp->getBody(), true);
            $items = $data['data'] ?? [];
            if (!empty($items[0]['id'])) {
                $this->salesChannelId = $items[0]['id'];
                $this->logger->info("Sales-Channel '$desiredName' => ID {$this->salesChannelId}");
            } else {
                $this->logger->warning("Sales-Channel '$desiredName' not found.");
            }
        } catch (GuzzleException $e) {
            $this->logger->error("fetchSalesChannelId error: {$e->getMessage()}");
        }
    }

    /**
     * Use the tax entry with "position" == 1 as default
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
            $data  = json_decode($resp->getBody(), true);
            $items = $data['data'] ?? [];

            $foundTax = null;
            foreach ($items as $item) {
                $pos = $item['position'] ?? ($item['attributes']['position'] ?? null);
                if ((int)$pos === 1) {
                    $foundTax = $item;
                    break;
                }
            }
            if ($foundTax && !empty($foundTax['id'])) {
                $this->defaultTaxId = $foundTax['id'];
                $taxName = $foundTax['name'] ?? ($foundTax['attributes']['name'] ?? 'unknown');
                $this->logger->info("Default tax => {$this->defaultTaxId} (name=$taxName, position=1)");
            } else {
                $this->logger->warning("No tax entry found with position=1. Possibly empty /api/tax?");
            }

        } catch (GuzzleException $e) {
            $this->logger->error("fetchDefaultTaxId error: {$e->getMessage()}");
        }
    }

    public function getDefaultTaxId(): ?string
    {
        return $this->defaultTaxId;
    }

    // ---------------------------------------
    // CATEGORIES
    // ---------------------------------------
    public function getAllCategories(): array
    {
        $this->rawCategories = [];
        $this->flattenedCategoryPaths = [];

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
                $data  = json_decode($resp->getBody(), true);
                $items = $data['data'] ?? [];
                if (empty($items)) {
                    break;
                }

                foreach ($items as $cat) {
                    $catId    = $cat['id'] ?? '';
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

                if (count($items) < $limit) {
                    break; 
                }
                $page++;
                if ($page > 20) {
                    break; 
                }
            } catch (GuzzleException $e) {
                $this->logger->error("getAllCategories page=$page error: " . $e->getMessage());
                break;
            }
        }

        // Build tree
        $this->buildCategoryTree();
        // Flatten
        $this->flattenCategoryTree();

        // e.g. [ "Elektronik > iPads" => "someId", ... ]
        return $this->flattenedCategoryPaths;
    }

    private function buildCategoryTree(): void
    {
        foreach ($this->rawCategories as $catId => $cat) {
            $pId = $cat['parentId'];
            if ($pId && isset($this->rawCategories[$pId])) {
                $this->rawCategories[$pId]['children'][] = $catId;
            }
        }
    }

    private function flattenCategoryTree(): void
    {
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

    private function dfsCategory(string $catId, string $currentPath): void
    {
        $this->flattenedCategoryPaths[$currentPath] = $catId;

        $children = $this->rawCategories[$catId]['children'] ?? [];
        foreach ($children as $childId) {
            $childName = $this->rawCategories[$childId]['name'] ?? '(no name)';
            $nextPath  = $currentPath . ' > ' . $childName;
            $this->dfsCategory($childId, $nextPath);
        }
    }

    // ---------------------------------------
    // DELIVERY TIMES
    // ---------------------------------------
    public function getAllDeliveryTimes(): array
    {
        $allDts = [];
        $page   = 1;
        $limit  = 100;

        while (true) {
            try {
                $resp = $this->client->get('/api/delivery-time', [
                    'headers' => $this->getDefaultHeaders(),
                    'query' => [
                        'page'  => $page,
                        'limit' => $limit,
                    ],
                ]);
                $data  = json_decode($resp->getBody(), true);
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

                if (count($items) < $limit) {
                    break;
                }
                $page++;
                if ($page > 20) break;
            } catch (GuzzleException $e) {
                $this->logger->error("getAllDeliveryTimes p=$page error: " . $e->getMessage());
                break;
            }
        }

        return $allDts;
    }

    // ---------------------------------------
    // MANUFACTURER
    // ---------------------------------------

    /**
     * If CSV row has an empty manufacturer, fallback to e.g. CSV_DEFAULT_MANUFACTURER_1
     */
    public function findOrCreateManufacturerForCsv(string $csvIndex, string $csvManufacturerName): ?string
    {
        $name = trim($csvManufacturerName);
        if (empty($name)) {
            // e.g. CSV_DEFAULT_MANUFACTURER_1
            $envKey = "CSV_DEFAULT_MANUFACTURER_{$csvIndex}";
            $fallback = $_ENV[$envKey] ?? 'Default Hersteller';
            $name = $fallback;
        }
        return $this->findOrCreateManufacturer($name);
    }

    public function findOrCreateManufacturer(string $name): ?string
    {
        $name = trim($name);
        if (empty($name)) {
            $name = 'Default Hersteller';
        }

        $key = mb_strtolower($name);
        if (isset(self::$manufacturerCache[$key])) {
            return self::$manufacturerCache[$key];
        }

        try {
            $resp = $this->client->get('/api/product-manufacturer', [
                'headers' => $this->getDefaultHeaders(),
                'query'   => [
                    'filter[name]' => $name,
                    'limit'        => 50,
                ],
            ]);
            $data  = json_decode($resp->getBody(), true);
            $items = $data['data'] ?? [];

            foreach ($items as $m) {
                $mId   = $m['id']   ?? '';
                $mName = $m['name'] ?? '';
                if (mb_strtolower($mName) === $key) {
                    self::$manufacturerCache[$key] = $mId;
                    return $mId;
                }
            }
        } catch (GuzzleException $e) {
            $this->logger->warning("Manufacturer search error: " . $e->getMessage());
        }

        // create
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
            self::$manufacturerCache[$key] = $uuid;
            return $uuid;

        } catch (GuzzleException $e) {
            $this->logger->error("createManufacturer error: " . $e->getMessage());
            return null;
        }
    }

    // ---------------------------------------
    // PRODUCTS
    // ---------------------------------------
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
            $data = json_decode($resp->getBody(), true);
            return $data['data'][0] ?? null;
        } catch (GuzzleException $e) {
            $this->logger->error("findProductByNumber error: " . $e->getMessage());
            return null;
        }
    }

    public function createProduct(array $payload): bool
    {
        // Force ID => ^[0-9a-f]{32}$
        if (empty($payload['id'])) {
            $payload['id'] = UuidService::generate();
        }
        // Default tax if missing
        if (empty($payload['taxId']) && $this->defaultTaxId) {
            $payload['taxId'] = $this->defaultTaxId;
        }
        // categories => []
        if (!isset($payload['categories'])) {
            $payload['categories'] = [];
        }
        // salesChannel => visibilities
        if (!empty($this->salesChannelId)) {
            $payload['visibilities'] = [[
                'salesChannelId' => $this->salesChannelId,
                'visibility'     => 30, 
            ]];
        }
        // No auto coverId => we do that after creation

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
    // MEDIA
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
        $payload = [ 'id' => $mediaId ];
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
                $body = (string)$resp->getBody();
                $this->logger->error("uploadImageFromUrl error: $body");
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

                // check local cache
                if (isset($cache[$fileNameWithoutExt])) {
                    $mediaIds[] = $cache[$fileNameWithoutExt];
                    continue;
                }

                // check if exists
                $existing = $this->findMediaByFilename($fileNameWithoutExt);
                if ($existing) {
                    $this->logger->info("Media already exists: $filename => $existing");
                    $mediaIds[] = $existing;
                    $cache[$fileNameWithoutExt] = $existing;
                    continue;
                }

                // else create + upload
                $newMediaId = $this->createMediaEntity();
                if (!$newMediaId) {
                    $this->logger->error("Failed to create media entity for $imageUrl");
                    continue;
                }
                if (!$this->uploadImageFromUrl($newMediaId, $imageUrl, $fileNameWithoutExt)) {
                    $this->logger->error("Failed to upload image from $imageUrl");
                    continue;
                }

                $mediaIds[] = $newMediaId;
                $cache[$fileNameWithoutExt] = $newMediaId;
                $this->logger->info("Uploaded image: $imageUrl => $newMediaId");

            } catch (\Throwable $th) {
                $this->logger->error("uploadImages error: " . $th->getMessage());
            }
        }

        return $mediaIds;
    }
}

