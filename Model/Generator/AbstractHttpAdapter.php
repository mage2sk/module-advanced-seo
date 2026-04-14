<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Generator;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Shared behaviour for HTTP-based LLM generators.
 * Handles config, encryption, budget tracking, idempotency and retry.
 */
abstract class AbstractHttpAdapter
{
    protected const MAX_RETRIES = 3;
    protected const BACKOFF_BASE_MS = 500;

    protected int $lastUsageTokens = 0;

    public function getLastUsageTokens(): int
    {
        return $this->lastUsageTokens;
    }

    abstract public function getProvider(): string;

    public function __construct(
        protected readonly ScopeConfigInterface $scopeConfig,
        protected readonly EncryptorInterface $encryptor,
        protected readonly ResourceConnection $resource,
        protected readonly DateTime $dateTime,
        protected readonly LoggerInterface $logger
    ) {
    }

    /**
     * Fetches + decrypts the API key from store config.
     */
    protected function getApiKey(string $path): string
    {
        $raw = (string)$this->scopeConfig->getValue($path);
        if ($raw === '') {
            return '';
        }
        try {
            $decrypted = $this->encryptor->decrypt($raw);
        } catch (\Throwable $e) {
            $decrypted = '';
        }
        return $decrypted !== '' ? $decrypted : $raw;
    }

    /**
     * Returns the monthly token budget in tokens.
     * A value of 0 (or missing) is treated as "no budget configured" and the
     * adapter MUST reject requests. Callers should check the return value
     * before making API calls.
     */
    protected function getMonthlyBudget(): int
    {
        return (int)$this->scopeConfig->getValue('panth_seo/ai/monthly_budget');
    }

    /**
     * Returns the configured AI sampling temperature in the 0.0-2.0 range.
     * Falls back to 0.4 (a balanced default) when unset or out of range.
     */
    protected function getTemperature(): float
    {
        $raw = $this->scopeConfig->getValue('panth_seo/ai/temperature');
        if ($raw === null || $raw === '') {
            return 0.4;
        }
        $value = (float)$raw;
        if ($value < 0.0 || $value > 2.0) {
            return 0.4;
        }
        return $value;
    }

    /**
     * Returns the configured per-request max token cap.
     * Falls back to $default when unset or <= 0.
     */
    protected function getMaxTokens(int $default = 600): int
    {
        $raw = (int)$this->scopeConfig->getValue('panth_seo/ai/max_tokens');
        return $raw > 0 ? $raw : $default;
    }

    /**
     * Returns the response cache TTL in seconds. 0 disables caching entirely.
     */
    protected function getCacheTtl(): int
    {
        $raw = $this->scopeConfig->getValue('panth_seo/ai/cache_ttl');
        if ($raw === null || $raw === '') {
            return 0;
        }
        return max(0, (int)$raw);
    }

    /**
     * Returns the number of tokens already used this calendar month.
     */
    protected function getMonthlyUsage(string $provider): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_ai_usage');
        if (!$connection->isTableExists($table)) {
            return 0;
        }
        $select = $connection->select()
            ->from($table, ['used' => 'SUM(total_tokens)'])
            ->where('provider = ?', $provider)
            ->where('period = ?', date('Y-m'));
        $row = $connection->fetchRow($select);
        return (int)($row['used'] ?? 0);
    }

    /**
     * Atomically reserve a token budget slot before making an API call.
     *
     * Inserts or increments a usage row with the estimated tokens. If the
     * resulting total exceeds the monthly budget, the reservation is rolled
     * back. This prevents two concurrent requests from both passing the
     * budget check (TOCTOU race condition).
     *
     * @param string $provider  AI provider key (e.g. 'openai', 'claude')
     * @param int    $estimate  Estimated token cost to reserve
     * @param int    $budget    Monthly budget limit
     * @return bool True if reservation succeeded, false if budget would be exceeded
     */
    protected function reserveBudget(string $provider, int $estimate, int $budget): bool
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_ai_usage');
        if (!$connection->isTableExists($table)) {
            return true; // No tracking table — allow the request
        }

        $period = date('Y-m');
        $now = $this->dateTime->gmtDate();

        try {
            // Ensure the row exists
            $connection->insertOnDuplicate(
                $table,
                ['provider' => $provider, 'period' => $period, 'total_tokens' => 0, 'created_at' => $now],
                ['created_at']
            );

            // Atomically increment only if the result stays within budget
            $affected = $connection->update(
                $table,
                [
                    'total_tokens' => new \Zend_Db_Expr('total_tokens + ' . (int)$estimate),
                    'created_at' => $now,
                ],
                [
                    'provider = ?' => $provider,
                    'period = ?' => $period,
                    new \Zend_Db_Expr('total_tokens + ' . (int)$estimate . ' <= ' . (int)$budget),
                ]
            );

            return $affected > 0;
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO AI budget reservation failed: ' . $e->getMessage());
            return false; // Fail closed — deny when uncertain
        }
    }

    /**
     * Adjust the reserved token count after the actual API call completes.
     *
     * If the actual usage differs from the estimate, this corrects the
     * stored counter. Call with the delta = (actual - estimate).
     */
    protected function adjustUsage(string $provider, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_ai_usage');
        if (!$connection->isTableExists($table)) {
            return;
        }
        try {
            $expr = $delta > 0
                ? 'total_tokens + ' . $delta
                : 'GREATEST(0, total_tokens - ' . abs($delta) . ')';
            $connection->update(
                $table,
                [
                    'total_tokens' => new \Zend_Db_Expr($expr),
                    'created_at' => $this->dateTime->gmtDate(),
                ],
                [
                    'provider = ?' => $provider,
                    'period = ?' => date('Y-m'),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO AI usage adjustment failed: ' . $e->getMessage());
        }
    }

    /**
     * Release a previously reserved budget (e.g. when the API call fails
     * before consuming tokens).
     */
    protected function releaseBudget(string $provider, int $estimate): void
    {
        $this->adjustUsage($provider, -$estimate);
    }

    protected function recordUsage(string $provider, int $tokens): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_ai_usage');
        if (!$connection->isTableExists($table)) {
            return;
        }
        try {
            $connection->insertOnDuplicate(
                $table,
                [
                    'provider' => $provider,
                    'period' => date('Y-m'),
                    'total_tokens' => $tokens,
                    'created_at' => $this->dateTime->gmtDate(),
                ],
                ['total_tokens' => new \Zend_Db_Expr('total_tokens + VALUES(total_tokens)'), 'created_at']
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO AI usage record failed: ' . $e->getMessage());
        }
    }

    /**
     * Cached generation lookup: returns a previously-generated result for an identical prompt.
     *
     * Honours the `panth_seo/ai/cache_ttl` configuration. A row whose
     * `expires_at` is in the past is ignored (so it is effectively expired).
     *
     * @return array<string,mixed>|null
     */
    protected function loadCached(string $promptHash): ?array
    {
        $ttl = $this->getCacheTtl();
        if ($ttl <= 0) {
            return null; // caching disabled
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_ai_cache');
        if (!$connection->isTableExists($table)) {
            return null;
        }
        $select = $connection->select()
            ->from($table, ['response', 'expires_at'])
            ->where('cache_key = ?', $promptHash)
            ->where('expires_at > ?', time())
            ->limit(1);
        $row = $connection->fetchRow($select);
        if (!$row) {
            return null;
        }
        $decoded = json_decode((string)$row['response'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $response
     */
    protected function saveCached(string $promptHash, array $response, string $provider = ''): void
    {
        $ttl = $this->getCacheTtl();
        if ($ttl <= 0) {
            return; // caching disabled
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_ai_cache');
        if (!$connection->isTableExists($table)) {
            return;
        }
        try {
            $connection->insertOnDuplicate(
                $table,
                [
                    'cache_key' => $promptHash,
                    'provider' => $provider,
                    'response' => json_encode($response, JSON_UNESCAPED_UNICODE),
                    'expires_at' => time() + $ttl,
                    'created_at' => $this->dateTime->gmtDate(),
                ],
                ['response', 'expires_at', 'created_at']
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO AI cache save failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $payload
     * @return array{status:int, body:string}
     */
    /** Allowed API domains for SSRF prevention */
    private const ALLOWED_API_HOSTS = [
        'api.openai.com',
        'api.anthropic.com',
    ];

    protected function curlPost(string $url, array $headers, array $payload): array
    {
        // SSRF prevention: only allow HTTPS to known AI API domains
        $parsedUrl = parse_url($url);
        $host = strtolower($parsedUrl['host'] ?? '');
        $scheme = strtolower($parsedUrl['scheme'] ?? '');

        if ($scheme !== 'https' || !in_array($host, self::ALLOWED_API_HOSTS, true)) {
            $this->logger->warning('Panth SEO AI: blocked request to disallowed host: ' . $host);
            return ['status' => 0, 'body' => '{"error":"Request blocked: disallowed API host"}'];
        }

        $attempt = 0;
        $lastStatus = 0;
        $lastBody = '';
        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('curl_init failed');
            }
            $hdrs = [];
            foreach ($headers as $k => $v) {
                $hdrs[] = $k . ': ' . $v;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $hdrs,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($body === false || $body === true) {
                $lastBody = $err ?: 'curl failed';
                $lastStatus = 0;
            } else {
                $lastBody = (string)$body;
                $lastStatus = $status;
            }

            if ($lastStatus >= 200 && $lastStatus < 300) {
                return ['status' => $lastStatus, 'body' => $lastBody];
            }
            if ($lastStatus === 429 || $lastStatus >= 500 || $lastStatus === 0) {
                usleep((int)(self::BACKOFF_BASE_MS * 1000 * (2 ** ($attempt - 1))));
                continue;
            }
            // 4xx other → don't retry
            break;
        }
        return ['status' => $lastStatus, 'body' => $lastBody];
    }

    /**
     * Heuristic confidence based on length stability: tighter outputs are more confident.
     * Produces a value in [0.0, 1.0].
     */
    protected function heuristicConfidence(string $title, string $description): float
    {
        $tLen = mb_strlen($title);
        $dLen = mb_strlen($description);
        $tPart = ($tLen >= 30 && $tLen <= 60) ? 1.0 : max(0.0, 1.0 - abs(45 - $tLen) / 45.0);
        $dPart = ($dLen >= 120 && $dLen <= 160) ? 1.0 : max(0.0, 1.0 - abs(140 - $dLen) / 140.0);
        return round(($tPart * 0.4) + ($dPart * 0.6), 3);
    }

    protected function promptHash(string $provider, string $model, string $prompt): string
    {
        return hash('sha256', $provider . '|' . $model . '|' . $prompt);
    }

    /**
     * @param array<string,mixed> $context
     */
    protected function buildPrompt(array $context): string
    {
        $entityType = (string)($context['entity_type'] ?? 'product');
        $attrs = (array)($context['attributes'] ?? []);
        $content = trim(strip_tags((string)($context['content'] ?? '')));
        if (mb_strlen($content) > 1200) {
            $content = mb_substr($content, 0, 1200);
        }

        $requestedFields = (array)($context['fields'] ?? []);

        // If a custom prompt was provided by the user, use it directly with placeholder replacement
        $customPrompt = (string)($context['custom_prompt'] ?? '');
        if ($customPrompt !== '') {
            $prompt = $this->renderPromptTemplate($customPrompt, $attrs, $content, $entityType);
            return $this->appendKnowledgeGuidelines($prompt, $entityType, $requestedFields);
        }

        // Try to load a prompt template from the database
        $promptTemplate = $this->loadPromptTemplate($entityType, $context);
        if ($promptTemplate !== null) {
            $prompt = $this->renderPromptTemplate($promptTemplate, $attrs, $content, $entityType);
            return $this->appendKnowledgeGuidelines($prompt, $entityType, $requestedFields);
        }

        // Multi-field generation prompt (when fields are explicitly requested)
        if (!empty($requestedFields)) {
            $prompt = $this->buildMultiFieldPrompt($entityType, $attrs, $content, $requestedFields);
            return $this->appendKnowledgeGuidelines($prompt, $entityType, $requestedFields);
        }

        // Fallback: hardcoded default prompt (legacy two-field)
        $lines = [];
        $lines[] = 'You are an SEO expert. Generate a meta title and meta description for the following ' . $entityType . '.';
        $lines[] = 'Title must be 50–60 characters, description 140–156 characters.';
        $lines[] = 'Return strict JSON: {"title":"...","description":"..."}';
        $lines[] = '';
        foreach ($attrs as $k => $v) {
            if (is_scalar($v) && $v !== '') {
                $lines[] = ucfirst((string)$k) . ': ' . (string)$v;
            }
        }
        if ($content !== '') {
            $lines[] = '';
            $lines[] = 'Content:';
            $lines[] = $content;
        }
        $prompt = implode("\n", $lines);
        return $this->appendKnowledgeGuidelines($prompt, $entityType, $requestedFields);
    }

    /**
     * Build a prompt that requests multiple SEO fields in a single call.
     *
     * @param array<string,mixed> $attrs
     * @param string[] $requestedFields
     */
    private function buildMultiFieldPrompt(
        string $entityType,
        array $attrs,
        string $content,
        array $requestedFields
    ): string {
        $fieldSpecs = [
            'meta_title'        => '"meta_title": "50-60 characters, optimized for search engines"',
            'meta_description'  => '"meta_description": "140-156 characters, compelling with a call to action"',
            'meta_keywords'     => '"meta_keywords": "5-10 comma-separated relevant keywords"',
            'og_title'          => '"og_title": "60-90 characters, engaging for social media sharing"',
            'og_description'    => '"og_description": "100-200 characters, social media friendly summary"',
            'short_description' => '"short_description": "1-2 sentences summarizing the ' . $entityType . '"',
        ];

        $jsonFields = [];
        foreach ($requestedFields as $field) {
            if (isset($fieldSpecs[$field])) {
                $jsonFields[] = '  ' . $fieldSpecs[$field];
            }
        }

        // If no recognized fields, default to title + description
        if (empty($jsonFields)) {
            $jsonFields[] = '  ' . $fieldSpecs['meta_title'];
            $jsonFields[] = '  ' . $fieldSpecs['meta_description'];
        }

        $lines = [];
        $lines[] = 'You are an SEO expert. Generate optimized SEO content for the following ' . $entityType . '.';
        $lines[] = '';
        $lines[] = 'Return strict JSON with ALL of these fields:';
        $lines[] = '{';
        $lines[] = implode(",\n", $jsonFields);
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'Important rules:';
        $lines[] = '- meta_title: Must be unique, include primary keyword, and stay within character limit';
        $lines[] = '- meta_description: Must be persuasive, include a call to action, and stay within character limit';
        $lines[] = '- meta_keywords: Use relevant search terms separated by commas';
        $lines[] = '- og_title: Slightly more engaging than meta_title for social sharing';
        $lines[] = '- og_description: Social-friendly version of description';
        $lines[] = '- short_description: Concise marketing-oriented summary';
        $lines[] = '- Do NOT use generic filler text. Base all content on the entity data provided.';
        $lines[] = '- Return ONLY the JSON object, no other text.';
        $lines[] = '';
        $lines[] = '--- Entity Data ---';
        foreach ($attrs as $k => $v) {
            if (is_scalar($v) && $v !== '') {
                $lines[] = ucfirst((string)$k) . ': ' . (string)$v;
            }
        }
        if ($content !== '') {
            $lines[] = '';
            $lines[] = 'Content:';
            $lines[] = $content;
        }
        return implode("\n", $lines);
    }

    /**
     * Load a prompt template from the panth_seo_ai_prompt table.
     *
     * If a specific prompt_id is passed in context options, use that.
     * Otherwise, load the active default prompt for the entity type.
     *
     * @param array<string,mixed> $context
     */
    private function loadPromptTemplate(string $entityType, array $context): ?string
    {
        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_ai_prompt');
            if (!$connection->isTableExists($table)) {
                return null;
            }

            // Check if a specific prompt_id was requested
            $promptId = (int)($context['prompt_id'] ?? $context['options']['prompt_id'] ?? 0);
            if ($promptId > 0) {
                $select = $connection->select()
                    ->from($table, ['prompt_template'])
                    ->where('prompt_id = ?', $promptId)
                    ->where('is_active = ?', 1)
                    ->limit(1);
                $template = $connection->fetchOne($select);
                if ($template) {
                    return (string)$template;
                }
            }

            // Load default prompt for entity type (or 'all' fallback)
            $select = $connection->select()
                ->from($table, ['prompt_template'])
                ->where('is_active = ?', 1)
                ->where('is_default = ?', 1)
                ->where('entity_type IN (?)', [$entityType, 'all'])
                ->order(new \Zend_Db_Expr("FIELD(entity_type, " . $connection->quote($entityType) . ", 'all')"))
                ->limit(1);
            $template = $connection->fetchOne($select);
            if ($template) {
                return (string)$template;
            }

            // No default found; try any active prompt for this entity type
            $select = $connection->select()
                ->from($table, ['prompt_template'])
                ->where('is_active = ?', 1)
                ->where('entity_type IN (?)', [$entityType, 'all'])
                ->order('sort_order ASC')
                ->limit(1);
            $template = $connection->fetchOne($select);
            return $template ? (string)$template : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO AI prompt load failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Replace placeholders in a prompt template with actual entity data.
     *
     * @param array<string,mixed> $attrs
     */
    private function renderPromptTemplate(
        string $template,
        array $attrs,
        string $content,
        string $entityType
    ): string {
        $placeholders = [
            '{{name}}'              => (string)($attrs['name'] ?? ''),
            '{{sku}}'               => (string)($attrs['sku'] ?? ''),
            '{{price}}'             => (string)($attrs['price'] ?? ''),
            '{{brand}}'             => (string)($attrs['brand'] ?? $attrs['manufacturer'] ?? ''),
            '{{category}}'          => (string)($attrs['category'] ?? $attrs['category_name'] ?? ''),
            '{{short_description}}' => trim(strip_tags((string)($attrs['short_description'] ?? ''))),
            '{{description}}'       => $content,
            '{{store_name}}'        => (string)($attrs['store_name'] ?? $this->scopeConfig->getValue('general/store_information/name') ?? ''),
            '{{url}}'               => (string)($attrs['url'] ?? $attrs['url_key'] ?? ''),
            '{{entity_type}}'       => $entityType,
        ];

        return str_replace(array_keys($placeholders), array_values($placeholders), $template);
    }

    /**
     * Load relevant knowledge entries from the AI Knowledge Base and append as guidelines.
     *
     * Selects up to 10 most relevant entries based on entity type and requested fields,
     * then appends them as a GUIDELINES section to the prompt.
     *
     * @param string[] $requestedFields
     */
    private function appendKnowledgeGuidelines(string $prompt, string $entityType, array $requestedFields = []): string
    {
        try {
            $lines = [];

            // Add live store context
            $storeContext = $this->getStoreContext();
            if ($storeContext !== '') {
                $lines[] = '';
                $lines[] = '--- STORE CONTEXT (use this information) ---';
                $lines[] = $storeContext;
                $lines[] = '--- END STORE CONTEXT ---';
            }

            // Add concise Google 2026 rules (always included, token-efficient)
            $lines[] = '';
            $lines[] = '--- RULES (Google 2026 SEO) ---';
            $lines[] = 'Meta title: 50-60 chars, primary keyword first, brand last. Meta description: 140-156 chars, include CTA. Use E-E-A-T. Mobile-first. Core Web Vitals (LCP<2.5s, CLS<0.1). No keyword stuffing. Natural language. Unique per page.';
            $lines[] = 'IMPORTANT: Never use emojis in any output. No emoji characters anywhere in titles, descriptions, keywords, or content. Use professional language only.';
            $lines[] = '--- END RULES ---';

            // Add top 5 most relevant knowledge entries (truncated for token efficiency)
            $entries = $this->loadKnowledgeEntries($entityType, $requestedFields);
            if (!empty($entries)) {
                $lines[] = '';
                $lines[] = '--- GUIDELINES ---';
                foreach ($entries as $i => $entry) {
                    // Truncate content to 200 chars max per entry to save tokens
                    $content = (string) ($entry['content'] ?? '');
                    if (mb_strlen($content) > 200) {
                        $content = mb_substr($content, 0, 200) . '...';
                    }
                    $lines[] = ($i + 1) . '. ' . ($entry['title'] ?? '') . ': ' . $content;
                }
                $lines[] = '---';
            }

            return empty($lines) ? $prompt : $prompt . "\n" . implode("\n", $lines);
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO AI knowledge load failed: ' . $e->getMessage());
            return $prompt;
        }
    }

    /**
     * Get live store configuration values as context for AI generation.
     */
    private function getStoreContext(): string
    {
        try {
            $lines = [];
            $lines[] = 'Store Name: ' . ($this->scopeConfig->getValue('general/store_information/name') ?: 'N/A');
            $lines[] = 'Store Phone: ' . ($this->scopeConfig->getValue('general/store_information/phone') ?: 'N/A');
            $lines[] = 'Country: ' . ($this->scopeConfig->getValue('general/store_information/country_id') ?: 'N/A');
            $lines[] = 'Currency: ' . ($this->scopeConfig->getValue('currency/options/base') ?: 'USD');
            $lines[] = 'Locale: ' . ($this->scopeConfig->getValue('general/locale/code') ?: 'en_US');

            // Shipping info
            $freeShippingEnabled = $this->scopeConfig->isSetFlag('carriers/freeshipping/active');
            if ($freeShippingEnabled) {
                $freeShippingThreshold = $this->scopeConfig->getValue('carriers/freeshipping/free_shipping_subtotal');
                $lines[] = 'Free Shipping: Yes (over ' . ($freeShippingThreshold ?: '0') . ')';
            }

            // SEO config
            $lines[] = 'Default Meta Title Suffix: ' . ($this->scopeConfig->getValue('design/head/default_title') ?: 'N/A');
            $lines[] = 'Title Separator: ' . ($this->scopeConfig->getValue('catalog/seo/title_separator') ?: '-');

            return implode("\n", $lines);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Load relevant knowledge entries from the panth_seo_ai_knowledge table.
     *
     * Selection logic:
     * - Always include 'seo' category entries (core best practices)
     * - Include 'ecommerce' entries for product/category entity types
     * - Include 'pagebuilder' entries when generating content/descriptions
     * - Include 'accessibility' entries for content generation
     * - Include 'html_patterns' entries when generating content with HTML
     * - Match tags against entity type and requested fields
     * - Limit to 10 entries to keep prompt size manageable
     *
     * @param string[] $requestedFields
     * @return array<int, array<string, string>>
     */
    private function loadKnowledgeEntries(string $entityType, array $requestedFields = []): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_ai_knowledge');

        if (!$connection->isTableExists($table)) {
            return [];
        }

        // Determine which categories are relevant based on entity type and fields
        $categories = ['seo']; // Always include SEO best practices

        if (in_array($entityType, ['product', 'category'], true)) {
            $categories[] = 'ecommerce';
        }

        // If generating content fields (descriptions, short_description), include more categories
        $contentFields = ['short_description', 'og_description', 'meta_description'];
        $isContentGeneration = !empty($requestedFields)
            && !empty(array_intersect($requestedFields, $contentFields));

        if ($isContentGeneration || empty($requestedFields)) {
            $categories[] = 'accessibility';
            $categories[] = 'response_format';
        }

        // Include pagebuilder and html_patterns for CMS page content or when no specific fields
        if ($entityType === 'cms_page' || empty($requestedFields)) {
            $categories[] = 'pagebuilder';
            $categories[] = 'html_patterns';
        }

        $categories = array_unique($categories);

        // Build tag matching terms for relevance
        $tagTerms = [$entityType];
        foreach ($requestedFields as $field) {
            $tagTerms[] = str_replace('_', '-', $field);
            $tagTerms[] = str_replace('_', ' ', $field);
        }

        // Map entity types to relevant tag terms
        $entityTagMap = [
            'product'   => ['product', 'description', 'features', 'ecommerce'],
            'category'  => ['category', 'collection', 'navigation', 'ecommerce'],
            'cms_page'  => ['cms', 'page', 'content', 'layout', 'pagebuilder'],
        ];
        if (isset($entityTagMap[$entityType])) {
            $tagTerms = array_merge($tagTerms, $entityTagMap[$entityType]);
        }

        // Build query: select active entries from relevant categories, prioritize by tag match
        $select = $connection->select()
            ->from($table, ['category', 'title', 'content', 'tags'])
            ->where('is_active = ?', 1)
            ->where('category IN (?)', $categories)
            ->order('sort_order ASC')
            ->limit(30); // Fetch more than needed to allow tag-based re-ranking

        $rows = $connection->fetchAll($select);

        if (empty($rows)) {
            return [];
        }

        // Score entries by tag relevance
        $scored = [];
        foreach ($rows as $row) {
            $tags = strtolower((string)($row['tags'] ?? ''));
            $score = 0;
            foreach ($tagTerms as $term) {
                if (stripos($tags, $term) !== false) {
                    $score += 2;
                }
            }
            // Boost SEO entries slightly (always relevant)
            if (($row['category'] ?? '') === 'seo') {
                $score += 1;
            }
            $scored[] = ['row' => $row, 'score' => $score];
        }

        // Sort by score descending, then by original order
        usort($scored, static function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Take top 5 (reduced from 10 for token efficiency)
        $result = [];
        $limit = 5;
        foreach ($scored as $item) {
            if (count($result) >= $limit) {
                break;
            }
            $result[] = $item['row'];
        }

        return $result;
    }

    /**
     * Parse JSON reply from AI. Supports both legacy two-field and multi-field responses.
     *
     * @return array<string,string>
     */
    protected function parseJsonReply(string $text): array
    {
        $text = trim($text);
        // Strip markdown code fences
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/```\s*$/', '', $text) ?? $text;
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return ['title' => '', 'description' => ''];
        }

        // Multi-field response (has meta_title key)
        if (isset($decoded['meta_title']) || isset($decoded['meta_description'])) {
            $result = [];
            $allowedFields = [
                'meta_title', 'meta_description', 'meta_keywords',
                'og_title', 'og_description', 'short_description',
            ];
            foreach ($allowedFields as $field) {
                if (isset($decoded[$field]) && $decoded[$field] !== '') {
                    $result[$field] = (string)$decoded[$field];
                }
            }
            // Also populate legacy keys for backward compatibility
            if (!isset($result['title']) && isset($result['meta_title'])) {
                $result['title'] = $result['meta_title'];
            }
            if (!isset($result['description']) && isset($result['meta_description'])) {
                $result['description'] = $result['meta_description'];
            }
            return $result;
        }

        // Legacy two-field response
        return [
            'title' => (string)($decoded['title'] ?? ''),
            'description' => (string)($decoded['description'] ?? ''),
        ];
    }
}
