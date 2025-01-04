<?php
// src/Service/ShopwareService.php

namespace JUNU\RealADCELL\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * ShopwareService
 * --------------
 * Verantwortlich für:
 *  - Authentifizierung über die Shopware Admin API (client_credentials)
 *  - Abfragen von Kategorien, Lieferzeiten, Sales-Channels
 *  - Hersteller erstellen / suchen
 *  - Produkte erstellen / aktualisieren
 *  - Medien / Bilder hochladen (zwei Schritte: media erstellen, dann upload)
 *  - Hinzufügen eines Sales-Channel-Visibilities, sofern in .env definiert
 */
class ShopwareService
{
    private Client $client;
    private LoggerInterface $logger;
    private string $apiUrl;
    private string $clientId;
    private string $clientSecret;
    private ?string $token = null;

    private ?string $salesChannelId = null; // aus dem Sales-Channel-Namen abgeleitet

    public function __construct(LoggerInterface $logger)
    {
        $this->logger       = $logger;
        $this->apiUrl       = $_ENV['SHOPWARE_API_URL']      ?? '';
        $this->clientId     = $_ENV['SHOPWARE_CLIENT_ID']     ?? '';
        $this->clientSecret = $_ENV['SHOPWARE_CLIENT_SECRET'] ?? '';

        // Guzzle-Client mit base_uri (für relative Pfade) und Timeout
        $this->client = new Client([
            'base_uri' => $this->apiUrl,
            'timeout'  => 30,
        ]);
    }

    /**
     * Gibt Standard-Header (Accept/Content-Type/Authorization) zurück.
     */
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
     * Authentifiziert gegen Shopware mit client_credentials.
     * Schreibt Access-Token in $this->token.
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
                throw new \RuntimeException("Kein Token in der Authentifizierungs-Antwort gefunden.");
            }
            $this->logger->info("Shopware-Authentifizierung erfolgreich.");

            // Nach erfolgreichem Login direkt den Sales-Channel suchen
            $this->fetchSalesChannelId();

        } catch (GuzzleException $e) {
            $this->logger->error("Shopware-Authentifizierung fehlgeschlagen: " . $e->getMessage());
            throw new \RuntimeException("Shopware authentication failed.", 0, $e);
        }
    }

    /**
     * Lädt (einmalig) die Sales-Channel-ID aus dem ENV-Variablennamen
     * SHOPWARE_SALES_CHANNEL_NAME. Speichert sie in $this->salesChannelId
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
                $this->logger->info("Sales-Channel '$desiredName' gefunden => ID {$this->salesChannelId}");
            } else {
                $this->logger->warning("Sales-Channel '$desiredName' nicht gefunden.");
            }
        } catch (GuzzleException $e) {
            $this->logger->error("fetchSalesChannelId error: " . $e->getMessage());
        }
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
                $this->logger->error("Kategorien Seite $page fehlgeschlagen: " . $e->getMessage());
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
                $response = $this->client->get('/api/delivery-time', [
                    'headers' => $this->getDefaultHeaders(),
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
                $this->logger->error("Lieferzeiten Seite $page fehlgeschlagen: " . $e->getMessage());
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
            // Fallback: ein Standard-Herstellername, wenn CSV nichts liefert
            $name = 'Default Hersteller';
        }

        // 1) Suchen nach gleichem Namen (Case-insensitive)
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
            $this->logger->warning("Hersteller-Suche fehlgeschlagen: " . $e->getMessage());
        }

        // 2) Anlegen
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
                $this->logger->error("Erstellen des Herstellers fehlgeschlagen: $body");
                return null;
            }

            $this->logger->info("Neuer Hersteller '$name' angelegt (ID $uuid)");
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
            $resp = $this->client->get('/api/product', [
                'headers' => $this->getDefaultHeaders(),
                'query'   => [
                    'filter[ean]' => $ean,
                    'limit'       => 1,
                ],
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
        // Wenn wir einen Sales-Channel ermittelt haben, Visibility setzen
        if (!empty($this->salesChannelId)) {
            $payload['visibilities'] = [[
                'salesChannelId' => $this->salesChannelId,
                'visibility'     => 30, // 'Alle' = 30
            ]];
        }

        var_dump($payload);

        try {
            $resp = $this->client->post('/api/product', [
                'headers' => $this->getDefaultHeaders(),
                'json'    => $payload,
            ]);
            if ($resp->getStatusCode() !== 204) {
                $body = (string)$resp->getBody();
                $this->logger->error("Produkt-Erstellung fehlgeschlagen: $body");
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
            $resp = $this->client->patch("/api/product/$id", [
                'headers' => $this->getDefaultHeaders(),
                'json'    => $payload,
            ]);
            if ($resp->getStatusCode() !== 204) {
                $body = (string)$resp->getBody();
                $this->logger->error("Produkt-Update fehlgeschlagen: $body");
                return false;
            }
            return true;
        } catch (GuzzleException $e) {
            $this->logger->error("Update product error: " . $e->getMessage());
            return false;
        }
    }

    // ---------------------------------------
    // IMAGES / MEDIA
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
        $mediaId = UuidService::generate(); // 32-stelliger Hex-String
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
                $this->logger->error("createMediaEntity fehlgeschlagen: " . (string)$resp->getBody());
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
                $this->logger->error("Upload-Image Fehler: " . (string)$resp->getBody());
                return false;
            }
            return true;

        } catch (GuzzleException $e) {
            $this->logger->error("uploadImageFromUrl exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Zweistufiges Hochladen von Bildern:
     *  1) media-Entität anlegen
     *  2) uploadImageFromUrl => /api/_action/media/{id}/upload
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
                $filename           = basename(parse_url($imageUrl, PHP_URL_PATH) ?? '');
                $fileNameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

                // Check Cache
                if (isset($cache[$fileNameWithoutExt])) {
                    $mediaIds[] = $cache[$fileNameWithoutExt];
                    continue;
                }

                // Schon in Shopware vorhanden?
                $existing = $this->findMediaByFilename($fileNameWithoutExt);
                if ($existing) {
                    $this->logger->info("Media existiert bereits: $filename => $existing");
                    $mediaIds[] = $existing;
                    $cache[$fileNameWithoutExt] = $existing;
                    continue;
                }

                // Neu anlegen + upload
                $newMediaId = $this->createMediaEntity();
                if (!$newMediaId) {
                    $this->logger->error("Fehler bei createMediaEntity für: $imageUrl");
                    continue;
                }

                if (!$this->uploadImageFromUrl($newMediaId, $imageUrl, $fileNameWithoutExt)) {
                    $this->logger->error("Fehler bei uploadImageFromUrl für: $imageUrl");
                    continue;
                }

                $mediaIds[] = $newMediaId;
                $cache[$fileNameWithoutExt] = $newMediaId;
                $this->logger->info("Bild hochgeladen: $imageUrl => $newMediaId");

            } catch (\Throwable $th) {
                $this->logger->error("Fehler in uploadImages für $imageUrl: " . $th->getMessage());
            }
        }

        return $mediaIds;
    }
}
