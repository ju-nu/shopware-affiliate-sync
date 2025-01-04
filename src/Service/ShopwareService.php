<?php

namespace JUNU\RealADCELL\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Class ShopwareService
 * Handles interaction with the Shopware Admin API.
 */
class ShopwareService
{
    private Client $client;
    private LoggerInterface $logger;
    private string $apiUrl;
    private string $clientId;
    private string $clientSecret;
    private ?string $token = null;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger       = $logger;
        $this->apiUrl       = $_ENV['SHOPWARE_API_URL']      ?? '';
        $this->clientId     = $_ENV['SHOPWARE_CLIENT_ID']     ?? '';
        $this->clientSecret = $_ENV['SHOPWARE_CLIENT_SECRET'] ?? '';

        $this->client = new Client([
            'timeout' => 30,
        ]);
    }

    public function authenticate(): void
    {
        try {
            $response = $this->client->post("{$this->apiUrl}/api/oauth/token", [
                'json' => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]
            ]);
            $data       = json_decode((string)$response->getBody(), true);
            $this->token = $data['access_token'];
            $this->logger->info("Shopware authentication succeeded.");
        } catch (GuzzleException $e) {
            $this->logger->error("Shopware authentication failed: " . $e->getMessage());
            throw new \RuntimeException("Shopware authentication failed.", 0, $e);
        }
    }

    public function getToken(): string
    {
        if (!$this->token) {
            $this->authenticate();
        }
        return $this->token;
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
                $response = $this->client->get("{$this->apiUrl}/api/category", [
                    'headers' => ['Authorization' => "Bearer " . $this->getToken()],
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
                $this->logger->error("Failed fetching categories page $page: " . $e->getMessage());
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
                $response = $this->client->get("{$this->apiUrl}/api/delivery-time", [
                    'headers' => ['Authorization' => "Bearer " . $this->getToken()],
                    'query'   => ['page' => $page, 'limit' => 50],
                ]);
                $data  = json_decode((string)$response->getBody(), true);
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
                $this->logger->error("Failed fetching delivery times page $page: " . $e->getMessage());
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
            return null;
        }

        // Search manufacturer by name
        try {
            $response = $this->client->get("{$this->apiUrl}/api/product-manufacturer", [
                'headers' => ['Authorization' => "Bearer " . $this->getToken()],
                'query'   => [
                    'filter[name]' => $name,
                    'limit'        => 50,
                ]
            ]);
            $data  = json_decode($response->getBody(), true);
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

        // Create
        $uuid = UuidService::generate();
        try {
            $resp = $this->client->post("{$this->apiUrl}/api/product-manufacturer", [
                'headers' => ['Authorization' => "Bearer " . $this->getToken()],
                'json'    => [
                    'id'   => $uuid,
                    'name' => $name
                ]
            ]);
            if ($resp->getStatusCode() !== 204) {
                $body = (string) $resp->getBody();
                $this->logger->error("Failed to create manufacturer: $body");
                return null;
            }
            $this->logger->info("Created new manufacturer: {$name} ({$uuid})");
            return $uuid;
        } catch (GuzzleException $e) {
            $this->logger->error("Create manufacturer error: " . $e->getMessage());
            return null;
        }
    }

    // ---------------------------------------
    // PRODUCT
    // ---------------------------------------
    public function findProductByEan(string $ean): ?array
    {
        if (!$ean) return null;
        try {
            $resp = $this->client->get("{$this->apiUrl}/api/product", [
                'headers' => ['Authorization' => "Bearer " . $this->getToken()],
                'query'   => [
                    'filter[ean]' => $ean,
                    'limit'       => 1,
                ]
            ]);
            $data = json_decode($resp->getBody(), true);
            return $data['data'][0] ?? null;
        } catch (GuzzleException $e) {
            $this->logger->error("findProductByEan error: " . $e->getMessage());
            return null;
        }
    }

    public function findProductByNumber(string $productNumber): ?array
    {
        if (!$productNumber) return null;
        try {
            $resp = $this->client->get("{$this->apiUrl}/api/product", [
                'headers' => ['Authorization' => "Bearer " . $this->getToken()],
                'query'   => [
                    'filter[productNumber]' => $productNumber,
                    'limit'                 => 1,
                ]
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
        try {
            $resp = $this->client->post("{$this->apiUrl}/api/product", [
                'headers' => ['Authorization' => "Bearer " . $this->getToken()],
                'json'    => $payload,
            ]);
            if ($resp->getStatusCode() !== 204) {
                $this->logger->error("Create product failed: " . (string) $resp->getBody());
                return false;
            }
            return true;
        } catch (GuzzleException $e) {
            $this->logger->error("Create product error: " . $e->getMessage());
            return false;
        }
    }

    public function updateProduct(string $id, array $payload): bool
    {
        try {
            $resp = $this->client->patch("{$this->apiUrl}/api/product/$id", [
                'headers' => ['Authorization' => "Bearer " . $this->getToken()],
                'json'    => $payload,
            ]);
            if ($resp->getStatusCode() !== 204) {
                $this->logger->error("Update product failed: " . (string) $resp->getBody());
                return false;
            }
            return true;
        } catch (GuzzleException $e) {
            $this->logger->error("Update product error: " . $e->getMessage());
            return false;
        }
    }

    // ---------------------------------------
    // IMAGES
    // ---------------------------------------
    public function findMediaByFilename(string $fileNameWithoutExt): ?string
    {
        try {
            $resp = $this->client->get("{$this->apiUrl}/api/media", [
                'headers' => ['Authorization' => "Bearer " . $this->getToken()],
                'query'   => [
                    'filter[fileName]' => $fileNameWithoutExt,
                    'limit'            => 1,
                ]
            ]);
            $data = json_decode($resp->getBody(), true);
            if (!empty($data['data'])) {
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
            $resp = $this->client->post("{$this->apiUrl}/api/media", [
                'headers' => ['Authorization' => "Bearer " . $this->getToken()],
                'json'    => $payload,
            ]);
            if ($resp->getStatusCode() !== 204) {
                $this->logger->error("Failed to create media entity: " . $resp->getBody());
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
            $uploadUrl = "{$this->apiUrl}/api/_action/media/{$mediaId}/upload?fileName=" 
                . urlencode($fileNameWithoutExt);

            $resp = $this->client->post($uploadUrl, [
                'headers' => ['Authorization' => "Bearer " . $this->getToken()],
                'json'    => [
                    'url' => $imageUrl,
                ],
            ]);
            if ($resp->getStatusCode() !== 204) {
                $this->logger->error("Upload image error: " . $resp->getBody());
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
        $mediaIds = [];
        static $cache = [];

        foreach ($imageUrls as $url) {
            if (!$url) continue;
            $filename            = basename(parse_url($url, PHP_URL_PATH) ?? '');
            $fileNameWithoutExt  = pathinfo($filename, PATHINFO_FILENAME);

            if (isset($cache[$fileNameWithoutExt])) {
                $mediaIds[] = $cache[$fileNameWithoutExt];
                continue;
            }

            // Check if media with this filename already exists
            $existing = $this->findMediaByFilename($fileNameWithoutExt);
            if ($existing) {
                $this->logger->info("Media already exists for $filename => $existing");
                $mediaIds[] = $existing;
                $cache[$fileNameWithoutExt] = $existing;
                continue;
            }

            // Create new media
            $mediaId = $this->createMediaEntity();
            if (!$mediaId) {
                $this->logger->error("Failed to create media entity for $url");
                continue;
            }

            if (!$this->uploadImageFromUrl($mediaId, $url, $fileNameWithoutExt)) {
                $this->logger->error("Failed to upload $url");
                continue;
            }

            $mediaIds[] = $mediaId;
            $cache[$fileNameWithoutExt] = $mediaId;
            $this->logger->info("Uploaded image: $url => $mediaId");
        }

        return $mediaIds;
    }
}
