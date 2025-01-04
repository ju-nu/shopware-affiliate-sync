<?php

namespace JUNU\RealADCELL\Service;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * Class OpenAiService
 * Wraps calls to OpenAI for rewriting product descriptions and category/delivery matching.
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
     * Rewrite the product description using the product title + original description.
     */
    public function rewriteDescription(string $title, string $description): string
    {
        if (empty(trim($title)) && empty(trim($description))) {
            return '';
        }

        $prompt = "Rewrite this product description in an appealing, fluent style.\n"
                . "Use the product title and the given description.\n\n"
                . "Title: {$title}\n"
                . "Description: {$description}\n\n"
                . "Rewrite:";

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
                    'max_tokens' => 300,
                ],
            ]);

            $json       = json_decode($response->getBody(), true);
            $rewritten  = $json['choices'][0]['message']['content'] ?? '';
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
