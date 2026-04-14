<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Generator;

use Panth\AdvancedSEO\Api\MetaGeneratorInterface;

/**
 * OpenAI GPT-4o meta generator.
 */
class OpenAiAdapter extends AbstractHttpAdapter implements MetaGeneratorInterface
{
    public function getProvider(): string { return 'openai'; }

    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    private const MODEL = 'gpt-4o';
    private const PROVIDER = 'openai';
    private const DEFAULT_MAX_TOKENS = 600;

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function generate(array $context, array $fields = [], array $options = []): array
    {
        $apiKey = $this->getApiKey('panth_seo/ai/openai_api_key');
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
            $this->logger->warning('Panth SEO: OpenAI request rejected — monthly token budget not configured (0).');
            return ['title' => '', 'description' => '', 'confidence' => 0.0, 'error' => 'budget_not_configured'];
        }
        if (!$this->reserveBudget(self::PROVIDER, $estimate, $budget)) {
            $this->logger->warning('Panth SEO: OpenAI monthly budget exhausted');
            return ['title' => '', 'description' => '', 'confidence' => 0.0, 'error' => 'budget_exhausted'];
        }

        // Build user message content: use multimodal format if images are provided
        $images = $context['images'] ?? [];
        if (!empty($images) && is_array($images)) {
            $userContent = [];
            foreach ($images as $imageData) {
                $base64 = (string)$imageData;
                if (!str_starts_with($base64, 'data:')) {
                    $base64 = 'data:image/jpeg;base64,' . $base64;
                }
                $userContent[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => $base64],
                ];
            }
            $userContent[] = [
                'type' => 'text',
                'text' => $prompt,
            ];
        } else {
            $userContent = $prompt;
        }

        $payload = [
            'model' => self::MODEL,
            'max_tokens' => $maxTokens,
            'temperature' => $this->getTemperature(),
            'logprobs' => true,
            'top_logprobs' => 1,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an SEO expert. Respond with strict JSON only.'],
                ['role' => 'user', 'content' => $userContent],
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        $response = $this->curlPost(
            self::API_URL,
            [
                'authorization' => 'Bearer ' . $apiKey,
                'content-type' => 'application/json',
            ],
            $payload
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            // Do NOT log request/response bodies: could leak prompts or keys.
            $this->logger->warning('Panth SEO OpenAI call failed', [
                'status' => $response['status'],
            ]);
            $this->releaseBudget(self::PROVIDER, $estimate);
            return ['title' => '', 'description' => '', 'confidence' => 0.0];
        }

        $decoded = json_decode($response['body'], true);
        // Validate response shape before trusting the data. No eval/unserialize.
        if (!is_array($decoded) || !isset($decoded['choices']) || !is_array($decoded['choices'])) {
            $this->logger->warning('Panth SEO OpenAI: unexpected response structure');
            $this->releaseBudget(self::PROVIDER, $estimate);
            return ['title' => '', 'description' => '', 'confidence' => 0.0];
        }
        $text = (string)($decoded['choices'][0]['message']['content'] ?? '');

        $usage = (int)($decoded['usage']['total_tokens'] ?? 0);
        $this->lastUsageTokens = $usage;
        // Adjust from estimated reservation to actual usage.
        $this->adjustUsage(self::PROVIDER, $usage - $estimate);

        // If logprobs returned, use average probability as confidence proxy.
        $logprobConfidence = null;
        $tokens = $decoded['choices'][0]['logprobs']['content'] ?? null;
        if (is_array($tokens) && $tokens !== []) {
            $sum = 0.0;
            $count = 0;
            foreach ($tokens as $t) {
                if (isset($t['logprob'])) {
                    $sum += exp((float)$t['logprob']);
                    $count++;
                }
            }
            if ($count > 0) {
                $logprobConfidence = round($sum / $count, 3);
            }
        }

        $parsed = $this->parseJsonReply($text);
        $title = (string)($parsed['title'] ?? $parsed['meta_title'] ?? '');
        $description = (string)($parsed['description'] ?? $parsed['meta_description'] ?? '');
        if ($title === '' && $description === '') {
            return ['title' => '', 'description' => '', 'confidence' => 0.0];
        }

        $confidence = $logprobConfidence ?? $this->heuristicConfidence($title, $description);
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
