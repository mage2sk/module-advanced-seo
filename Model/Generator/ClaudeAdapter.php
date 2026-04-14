<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Generator;

use Panth\AdvancedSEO\Api\MetaGeneratorInterface;

/**
 * Anthropic Claude meta generator (claude-opus-4-6).
 */
class ClaudeAdapter extends AbstractHttpAdapter implements MetaGeneratorInterface
{
    public function getProvider(): string { return 'claude'; }

    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-opus-4-6';
    private const PROVIDER = 'claude';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MAX_TOKENS = 600;

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function generate(array $context, array $fields = [], array $options = []): array
    {
        $apiKey = $this->getApiKey('panth_seo/ai/claude_api_key');
        if ($apiKey === '') {
            return ['title' => '', 'description' => '', 'confidence' => 0.0];
        }

        // Inject requested fields into context for multi-field prompt generation
        if (!empty($fields) && !isset($context['fields'])) {
            $context['fields'] = $fields;
        }

        $prompt = $this->buildPrompt($context);
        $hash = $this->promptHash(self::PROVIDER, self::MODEL, $prompt);

        $cached = $this->loadCached($hash);
        if ($cached !== null) {
            $cached['confidence'] = (float)($cached['confidence'] ?? 0.0);
            return $cached;
        }

        $budget = $this->getMonthlyBudget();
        $maxTokens = $this->getMaxTokens(self::DEFAULT_MAX_TOKENS);
        $estimate = $maxTokens * 2; // rough estimate for input + output
        if ($budget <= 0) {
            $this->logger->warning('Panth SEO: Claude request rejected — monthly token budget not configured (0).');
            return ['title' => '', 'description' => '', 'confidence' => 0.0, 'error' => 'budget_not_configured'];
        }
        if (!$this->reserveBudget(self::PROVIDER, $estimate, $budget)) {
            $this->logger->warning('Panth SEO: Claude monthly budget exhausted');
            return ['title' => '', 'description' => '', 'confidence' => 0.0, 'error' => 'budget_exhausted'];
        }

        // Build message content: use multimodal format if images are provided
        $images = $context['images'] ?? [];
        if (!empty($images) && is_array($images)) {
            $messageContent = [];
            foreach ($images as $imageData) {
                $base64 = preg_replace('/^data:image\/\w+;base64,/', '', (string)$imageData);
                $mediaType = 'image/jpeg';
                if (preg_match('/^data:(image\/\w+);base64,/', (string)$imageData, $m)) {
                    $mediaType = $m[1];
                }
                $messageContent[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $mediaType,
                        'data' => $base64,
                    ],
                ];
            }
            $messageContent[] = [
                'type' => 'text',
                'text' => $prompt,
            ];
            $userContent = $messageContent;
        } else {
            $userContent = $prompt;
        }

        $payload = [
            'model' => self::MODEL,
            'max_tokens' => $maxTokens,
            'temperature' => $this->getTemperature(),
            'messages' => [
                ['role' => 'user', 'content' => $userContent],
            ],
        ];

        $response = $this->curlPost(
            self::API_URL,
            [
                'x-api-key' => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type' => 'application/json',
            ],
            $payload
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            // Do NOT log request/response bodies: could leak prompts or keys.
            $this->logger->warning('Panth SEO Claude call failed', [
                'status' => $response['status'],
            ]);
            $this->releaseBudget(self::PROVIDER, $estimate);
            return ['title' => '', 'description' => '', 'confidence' => 0.0];
        }

        $decoded = json_decode($response['body'], true);
        // Validate response shape before trusting the data. No eval/unserialize.
        if (!is_array($decoded) || !isset($decoded['content']) || !is_array($decoded['content'])) {
            $this->logger->warning('Panth SEO Claude: unexpected response structure');
            $this->releaseBudget(self::PROVIDER, $estimate);
            return ['title' => '', 'description' => '', 'confidence' => 0.0];
        }

        $text = '';
        foreach ($decoded['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= (string)($block['text'] ?? '');
            }
        }

        $usage = (int)($decoded['usage']['input_tokens'] ?? 0) + (int)($decoded['usage']['output_tokens'] ?? 0);
        $this->lastUsageTokens = $usage;
        // Adjust from estimated reservation to actual usage.
        $this->adjustUsage(self::PROVIDER, $usage - $estimate);

        $parsed = $this->parseJsonReply($text);
        $title = (string)($parsed['title'] ?? $parsed['meta_title'] ?? '');
        $description = (string)($parsed['description'] ?? $parsed['meta_description'] ?? '');
        if ($title === '' && $description === '') {
            return ['title' => '', 'description' => '', 'confidence' => 0.0];
        }

        $confidence = $this->heuristicConfidence($title, $description);
        $parsed['confidence'] = (float)$confidence;
        // Ensure legacy keys are present
        if (!isset($parsed['title'])) {
            $parsed['title'] = $title;
        }
        if (!isset($parsed['description'])) {
            $parsed['description'] = $description;
        }
        $this->saveCached($hash, $parsed, self::PROVIDER);
        return $parsed;
    }
}
