<?php

namespace JUNU\RealADCELL\Service;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * Class OpenAiService
 * Now rewriting descriptions in German, excluding any mention of the product title.
 */
class OpenAiService
{
    private Client $client;
    private LoggerInterface $logger;
    private string $openAiKey;
    private string $model;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->openAiKey = $_ENV['OPENAI_API_KEY'] ?? '';
        $this->model     = $_ENV['OPENAI_MODEL']   ?? 'gpt-4o-mini';

        $this->client = new Client([
            'base_uri' => 'https://api.openai.com',
            'timeout'  => 30,
        ]);
    }

    /**
     * Rewrite the product description in German, do NOT repeat the title.
     */
    public function rewriteDescription(string $title, string $description): string
    {
        // If everything empty, return empty
        if (empty(trim($title)) && empty(trim($description))) {
            return '';
        }

        $prompt = 
            "Bitte schreibe eine deutsche Produktbeschreibung, " .
            "ohne den Produkt-Titel zu wiederholen. " .
            "Nutze nur diese vorhandenen Texte:\n\n" .
            "Beschreibung:\n" . $description . "\n\n" .
            "Produktname:\n" . $title . "\n\n" .
            "Schreibe Sie Conversion-stark, ansprechend und positiv in deutscher Sprache.";

        try {
            $response = $this->client->post('/v1/chat/completions', [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                    'Authorization' => "Bearer {$this->openAiKey}",
                ],
                'json' => [
                    'model'    => $this->model,
                    'messages' => [
                        [ 'role' => 'user', 'content' => $prompt ]
                    ],
                    'max_tokens' => 400,
                ],
            ]);

            $json = json_decode($response->getBody(), true);
            $rewritten = $json['choices'][0]['message']['content'] ?? '';
            return trim($rewritten);

        } catch (\Throwable $e) {
            $this->logger->error("OpenAI rewriteDescription error: {$e->getMessage()}");
            return $description; // fallback
        }
    }

    public function bestCategory(
        string $title, 
        string $description, 
        string $csvCategory, 
        array $shopwareCategoryNames
    ): ?string {
        if (empty($shopwareCategoryNames)) {
            return null;
        }

        $categoryList = "- " . implode("\n- ", $shopwareCategoryNames);

        $prompt = "We have a product with:\n"
                . "- Title: {$title}\n"
                . "- Description: {$description}\n"
                . "- CSV-suggested category: {$csvCategory}\n\n"
                . "We have the following existing categories:\n{$categoryList}\n\n"
                . "Which ONE category best fits this product? Reply with the exact name from the list above.";

        try {
            $response = $this->client->post('/v1/chat/completions', [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => "Bearer {$this->openAiKey}",
                ],
                'json' => [
                    'model'    => $this->model,
                    'messages' => [
                        [ 'role' => 'user', 'content' => $prompt ]
                    ],
                    'max_tokens' => 50,
                ],
            ]);

            $json = json_decode($response->getBody(), true);
            $best = trim($json['choices'][0]['message']['content'] ?? '');
            return $best ?: null;

        } catch (\Throwable $e) {
            $this->logger->error("OpenAI bestCategory error: {$e->getMessage()}");
            return null;
        }
    }

    public function bestDeliveryTime(
        string $csvDeliveryTime, 
        array $shopwareDeliveryTimeNames
    ): ?string {
        if (empty($shopwareDeliveryTimeNames)) {
            return null;
        }

        $dtList = "- " . implode("\n- ", $shopwareDeliveryTimeNames);

        $prompt = "We have a product with CSV delivery time: '{$csvDeliveryTime}'.\n"
                . "We have the following existing Shopware delivery times:\n{$dtList}\n\n"
                . "Which one best matches the CSV-provided delivery time? Reply with the exact name.";

        try {
            $response = $this->client->post('/v1/chat/completions', [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => "Bearer {$this->openAiKey}",
                ],
                'json' => [
                    'model'    => $this->model,
                    'messages' => [
                        [ 'role' => 'user', 'content' => $prompt ]
                    ],
                    'max_tokens' => 50,
                ],
            ]);

            $json = json_decode($response->getBody(), true);
            $best = trim($json['choices'][0]['message']['content'] ?? '');
            return $best ?: null;

        } catch (\Throwable $e) {
            $this->logger->error("OpenAI bestDeliveryTime error: {$e->getMessage()}");
            return null;
        }
    }
}
