<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Redirect;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Panth\AdvancedSEO\Api\Data\RedirectRuleInterface;
use Panth\AdvancedSEO\Api\RedirectMatcherInterface;
use Panth\AdvancedSEO\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Two-tier redirect matcher:
 *  - Tier 1: hash table for literal path lookups (O(1))
 *  - Tier 2: compiled regex list (priority-ordered)
 *
 * Loaded once per request/process and cached in memory AND in Magento cache
 * (tag-invalidated on save/delete via the observer in ResourceModel).
 */
class Matcher implements RedirectMatcherInterface
{
    public const CACHE_TAG = 'panth_seo_redirect';
    private const CACHE_KEY_PREFIX = 'panth_seo_redirect_table_';

    /** @var array<int,array{literal:array<string,array<string,mixed>>,regex:array<int,array<string,mixed>>}> */
    private array $memo = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly RedirectModelFactory $redirectFactory,
        private readonly NotFoundLogger $notFoundLogger,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function match(string $requestPath, int $storeId): ?RedirectRuleInterface
    {
        $table = $this->loadTable($storeId);
        $normalized = $this->normalize($requestPath);

        if (isset($table['literal'][$normalized])) {
            $row = $table['literal'][$normalized];
            if (!$this->isWithinDateRange($row)) {
                // Skip: redirect outside its active date range.
            } else {
                $result = $this->hydrate($row);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        foreach ($table['regex'] as $row) {
            $pattern = $this->compile((string) $row['pattern']);
            if ($pattern === null) {
                continue;
            }
            try {
                if (preg_match($pattern, $normalized) === 1) {
                    if (!$this->isWithinDateRange($row)) {
                        continue;
                    }
                    // Expand backreferences in target
                    $target = (string) $row['target'];
                    $expanded = preg_replace($pattern, $target, $normalized);
                    if (is_string($expanded) && $expanded !== '') {
                        $row['target'] = $expanded;
                    }
                    $result = $this->hydrate($row);
                    if ($result !== null) {
                        return $result;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[PanthSEO] regex match failed: ' . $e->getMessage(), [
                    'pattern' => $row['pattern'] ?? '',
                ]);
            }
        }

        return null;
    }

    public function recordHit(int $redirectId): void
    {
        try {
            $conn = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_redirect');
            $conn->update(
                $table,
                [
                    'hit_count'   => new \Zend_Db_Expr('hit_count + 1'),
                    'last_hit_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                ],
                ['redirect_id = ?' => $redirectId]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] recordHit failed: ' . $e->getMessage());
        }
    }

    public function log404(string $requestPath, int $storeId, ?string $referer = null): void
    {
        if (!$this->config->isLog404Enabled($storeId)) {
            return;
        }
        $this->notFoundLogger->log($requestPath, $storeId, $referer);
    }

    /**
     * Check whether the redirect is within its scheduled date range.
     *
     * @param array<string,mixed> $row
     */
    private function isWithinDateRange(array $row): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $startAt  = $row['start_at'] ?? null;
        $finishAt = $row['finish_at'] ?? null;

        if ($startAt !== null && $startAt !== '') {
            try {
                $start = new \DateTimeImmutable($startAt, new \DateTimeZone('UTC'));
                if ($now < $start) {
                    return false; // start_at is in the future
                }
            } catch (\Throwable) {
                // Invalid date, ignore the constraint.
            }
        }

        if ($finishAt !== null && $finishAt !== '') {
            try {
                $finish = new \DateTimeImmutable($finishAt, new \DateTimeZone('UTC'));
                if ($now > $finish) {
                    return false; // finish_at is in the past
                }
            } catch (\Throwable) {
                // Invalid date, ignore the constraint.
            }
        }

        return true;
    }

    /**
     * @return array{literal:array<string,array<string,mixed>>,regex:array<int,array<string,mixed>>}
     */
    private function loadTable(int $storeId): array
    {
        if (isset($this->memo[$storeId])) {
            return $this->memo[$storeId];
        }

        $cacheKey = self::CACHE_KEY_PREFIX . $storeId;
        $cached   = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            try {
                /** @var array{literal:array,regex:array} $decoded */
                $decoded = $this->serializer->unserialize($cached);
                if (is_array($decoded) && isset($decoded['literal'], $decoded['regex'])) {
                    return $this->memo[$storeId] = $decoded;
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_redirect');
        $select = $conn->select()
            ->from($table)
            ->where('is_active = ?', 1)
            ->where('store_id IN (?)', [0, $storeId])
            ->order(['priority ASC', 'redirect_id ASC']);

        $literal = [];
        $regex   = [];
        foreach ($conn->fetchAll($select) as $row) {
            $matchType = (string) ($row['match_type'] ?? RedirectRuleInterface::MATCH_LITERAL);
            if ($matchType === RedirectRuleInterface::MATCH_LITERAL) {
                $literal[$this->normalize((string) $row['pattern'])] = $row;
            } else {
                $regex[] = $row;
            }
        }

        $data = ['literal' => $literal, 'regex' => $regex];
        try {
            $this->cache->save(
                $this->serializer->serialize($data),
                $cacheKey,
                [self::CACHE_TAG],
                3600
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] redirect cache save failed: ' . $e->getMessage());
        }

        return $this->memo[$storeId] = $data;
    }

    private function normalize(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        // strip query string
        $q = strpos($path, '?');
        if ($q !== false) {
            $path = substr($path, 0, $q);
        }
        // ensure leading slash, no trailing slash (except root)
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        if (strlen($path) > 1 && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    /**
     * @return string|null Compiled PCRE pattern or null on failure.
     */
    private function compile(string $pattern): ?string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return null;
        }
        // If user supplies ~..~ or /../ treat as pre-delimited, else wrap in ~...~
        if (preg_match('/^([~\/#!@%]).*\1[a-zA-Z]*$/s', $pattern)) {
            return $pattern;
        }
        return '~' . str_replace('~', '\\~', $pattern) . '~';
    }

    /**
     * Validates that the redirect target is safe (no javascript:/data:/vbscript: URIs).
     * Returns the sanitized target or null if unsafe.
     */
    private function sanitizeTarget(string $target): ?string
    {
        $target = trim($target);
        if ($target === '') {
            return null;
        }
        // Block dangerous URI schemes
        if (preg_match('#^(javascript|data|vbscript):#i', $target)) {
            return null;
        }
        return $target;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): ?RedirectRuleInterface
    {
        $target = $this->sanitizeTarget((string) ($row['target'] ?? ''));
        if ($target === null) {
            $this->logger->warning('[PanthSEO] Blocked unsafe redirect target', [
                'pattern' => $row['pattern'] ?? '',
                'target'  => $row['target'] ?? '',
            ]);
            return null;
        }
        $row['target'] = $target;
        $model = $this->redirectFactory->create();
        $model->setData($row);
        return $model;
    }
}
