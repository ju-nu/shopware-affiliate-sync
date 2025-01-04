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
 * - Authenticates with Shopware Admin API
 * - Loads category tree => flatten "Parent > Child" => catId
 * - Finds default tax where position=1
 * - Loads a sales channel from .env
 * - Creates/updates products in a single call (cover => [ 'mediaId' => ... ])
 * - Manufacturer searching via POST /api/search/product-manufacturer
 *   => no duplicates if the CSV has consistent name
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
     * Local static cache for manufacturer name => ID
     */
    private static array $manufacturerCache = [];

    // raw category data => build tree => flatten
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
     * authenticate => sets $this->token
     * also fetches salesChannelId and defaultTaxId
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
            $this->logger->error("Shopware authentication failed: " . $e->getMessage());
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
                'query' => [
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
            $this->logger->error("fetchSalesChannelId error: " . $e->getMessage());
        }
    }

    /**
     * fetchDefaultTaxId => find tax with position=1
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
                // Check item['position'] or item['attributes']['position']
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
                $this->logger->warning("No tax entry found with position=1 in /api/tax.");
            }

        } catch (GuzzleException $e) {
            $this->logger->error("fetchDefaultTaxId error: " . $e->getMessage());
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

                if (count($items) < $limit) {
                    break;
                }
                $page++;
                if ($page > 20) break;

            } catch (GuzzleException $e) {
                $this->logger->error("getAllCategories page=$page error: " . $e->getMessage());
                break;
            }
        }

        // Build tree + flatten
        $this->buildCategoryTree();
        $this->flattenCategoryTree();

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
            $path = $currentPath . ' > ' . $childName;
            $this->dfsCategory($childId, $path);
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
    public function findOrCreateManufacturerForCsv(string $csvIndex, string $csvManufacturerName): ?string
    {
        $name = trim($csvManufacturerName);
        if (empty($name)) {
            $envKey  = "CSV_DEFAULT_MANUFACTURER_{$csvIndex}";
            $fallback= $_ENV[$envKey] ?? 'Default Hersteller';
            $name    = $fallback;
        }
        return $this->findOrCreateManufacturer($name);
    }

    /**
     * 1) Check local static cache
     * 2) findManufacturerByName($name) => POST /api/search/product-manufacturer
     * 3) If not found => POST /api/product-manufacturer => create new
     */
    public function findOrCreateManufacturer(string $name): ?string
    {
        $name = trim($name);
        if (empty($name)) {
            $name = 'Default Hersteller';
        }

        $lowerName = mb_strtolower($name);
        if (isset(self::$manufacturerCache[$lowerName])) {
            return self::$manufacturerCache[$lowerName];
        }

        // Search
        $existingId = $this->findManufacturerByName($name);
        if ($existingId) {
            self::$manufacturerCache[$lowerName] = $existingId;
            return $existingId;
        }

        // Create
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
                $body = (string)$resp->getBody();
                $this->logger->error("Failed to create manufacturer '$name'. Response: $body");
                return null;
            }
            $this->logger->info("Created new manufacturer '$name' => $newId");
            self::$manufacturerCache[$lowerName] = $newId;
            return $newId;

        } catch (GuzzleException $e) {
            $this->logger->error("createManufacturer exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * findManufacturerByName => POST /api/search/product-manufacturer
     * {
     *   "filter": [ { "field":"product_manufacturer.name", "type":"equals", "value":"Acme" } ],
     *   "limit":1
     * }
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
            $data  = json_decode((string)$resp->getBody(), true);
            $items = $data['data'] ?? [];

            if (!empty($items[0]['id'])) {
                return $items[0]['id'];
            }
            return null;

        } catch (GuzzleException $e) {
            $this->logger->warning("findManufacturerByName('$name') error: " . $e->getMessage());
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
        // If no id => generate one
        if (empty($payload['id'])) {
            $payload['id'] = UuidService::generate();
        }
        // If no tax => defaultTax
        if (empty($payload['taxId']) && $this->defaultTaxId) {
            $payload['taxId'] = $this->defaultTaxId;
        }
        // If no categories => []
        if (!isset($payload['categories'])) {
            $payload['categories'] = [];
        }
        // If we have a salesChannel => add visibilities
        if (!empty($this->salesChannelId)) {
            $payload['visibilities'] = [[
                'salesChannelId' => $this->salesChannelId,
                'visibility'     => 30, 
            ]];
        }

        // We let the user set cover => [ 'mediaId' => ... ] if needed

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
                $body = (string)$resp->getBody();
                $this->logger->error("createMediaEntity failed: $body");
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

                // local cache
                if (isset($cache[$fileNameWithoutExt])) {
                    $mediaIds[] = $cache[$fileNameWithoutExt];
                    continue;
                }

                // check if media with this fileName exists
                $existing = $this->findMediaByFilename($fileNameWithoutExt);
                if ($existing) {
                    $this->logger->info("Media already exists: $filename => $existing");
                    $mediaIds[] = $existing;
                    $cache[$fileNameWithoutExt] = $existing;
                    continue;
                }

                // create + upload
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
