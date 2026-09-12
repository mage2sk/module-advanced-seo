<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;

class CrawlState
{
    public const STATUS_IDLE      = 'idle';
    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETE  = 'complete';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STALE_AFTER_SECONDS = 900;

    private const FLAG_PREFIX = 'panth_seo_crawl_state_';

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly DateTime $dateTime
    ) {
    }

    public function get(int $storeId): array
    {
        $data = $this->flagManager->getFlagData(self::FLAG_PREFIX . $storeId);
        if (!is_array($data)) {
            $data = [];
        }

        $state = [
            'status'           => (string) ($data['status'] ?? self::STATUS_IDLE),
            'store_id'         => $storeId,
            'max_pages'        => (int) ($data['max_pages'] ?? 0),
            'requested_at'     => $data['requested_at'] ?? null,
            'requested_by'     => (string) ($data['requested_by'] ?? ''),
            'started_at'       => $data['started_at'] ?? null,
            'finished_at'      => $data['finished_at'] ?? null,
            'heartbeat_at'     => $data['heartbeat_at'] ?? null,
            'crawled'          => (int) ($data['crawled'] ?? 0),
            'queued'           => (int) ($data['queued'] ?? 0),
            'pages'            => (int) ($data['pages'] ?? 0),
            'issues'           => (int) ($data['issues'] ?? 0),
            'saved'            => (int) ($data['saved'] ?? 0),
            'message'          => (string) ($data['message'] ?? ''),
            'cancel_requested' => (bool) ($data['cancel_requested'] ?? false),
        ];

        $state['stale'] = $state['status'] === self::STATUS_RUNNING && $this->isStale($state);

        return $state;
    }

    public function queue(int $storeId, int $maxPages, string $requestedBy = ''): array
    {
        return $this->save($storeId, [
            'status'           => self::STATUS_PENDING,
            'max_pages'        => max(1, $maxPages),
            'requested_at'     => $this->dateTime->gmtDate(),
            'requested_by'     => $requestedBy,
            'started_at'       => null,
            'finished_at'      => null,
            'heartbeat_at'     => null,
            'crawled'          => 0,
            'queued'           => 0,
            'pages'            => 0,
            'issues'           => 0,
            'saved'            => 0,
            'message'          => '',
            'cancel_requested' => false,
        ]);
    }

    public function markRunning(int $storeId, int $maxPages): array
    {
        $state    = $this->get($storeId);
        $queued   = $state['status'] === self::STATUS_PENDING;
        $nowGmt   = $this->dateTime->gmtDate();

        return $this->save($storeId, [
            'status'           => self::STATUS_RUNNING,
            'max_pages'        => max(1, $maxPages),
            'requested_at'     => $queued ? ($state['requested_at'] ?? $nowGmt) : $nowGmt,
            'requested_by'     => $queued ? $state['requested_by'] : '',
            'started_at'       => $nowGmt,
            'finished_at'      => null,
            'heartbeat_at'     => $nowGmt,
            'crawled'          => 0,
            'queued'           => 0,
            'pages'            => 0,
            'issues'           => 0,
            'saved'            => 0,
            'message'          => '',
            'cancel_requested' => false,
        ]);
    }

    public function reportProgress(int $storeId, int $crawled, int $queued): array
    {
        $state                 = $this->get($storeId);
        $state['crawled']      = $crawled;
        $state['queued']       = $queued;
        $state['heartbeat_at'] = $this->dateTime->gmtDate();

        return $this->save($storeId, $state);
    }

    public function markComplete(int $storeId, int $pages, int $issues, int $saved): array
    {
        $state                = $this->get($storeId);
        $state['status']      = self::STATUS_COMPLETE;
        $state['finished_at'] = $this->dateTime->gmtDate();
        $state['crawled']     = $pages;
        $state['queued']      = 0;
        $state['pages']       = $pages;
        $state['issues']      = $issues;
        $state['saved']       = $saved;
        $state['message']     = '';

        return $this->save($storeId, $state);
    }

    public function markCancelled(int $storeId, int $pages, int $saved): array
    {
        $state                     = $this->get($storeId);
        $state['status']           = self::STATUS_CANCELLED;
        $state['finished_at']      = $this->dateTime->gmtDate();
        $state['queued']           = 0;
        $state['pages']            = $pages;
        $state['saved']            = $saved;
        $state['cancel_requested'] = false;

        return $this->save($storeId, $state);
    }

    public function markFailed(int $storeId, string $message): array
    {
        $state                = $this->get($storeId);
        $state['status']      = self::STATUS_FAILED;
        $state['finished_at'] = $this->dateTime->gmtDate();
        $state['queued']      = 0;
        $state['message']     = $message;

        return $this->save($storeId, $state);
    }

    public function requestCancel(int $storeId): bool
    {
        $state = $this->get($storeId);

        if ($state['status'] === self::STATUS_PENDING) {
            $this->markCancelled($storeId, 0, 0);
            return true;
        }

        if ($state['status'] !== self::STATUS_RUNNING) {
            return false;
        }

        if ($state['stale']) {
            $this->markCancelled($storeId, (int) $state['crawled'], (int) $state['saved']);
            return true;
        }

        $state['cancel_requested'] = true;
        $this->save($storeId, $state);

        return true;
    }

    public function isCancelRequested(int $storeId): bool
    {
        return $this->get($storeId)['cancel_requested'];
    }

    public function isActive(int $storeId): bool
    {
        $state = $this->get($storeId);

        if ($state['status'] === self::STATUS_PENDING) {
            return true;
        }

        return $state['status'] === self::STATUS_RUNNING && !$state['stale'];
    }

    public function isStale(array $state): bool
    {
        $last = $state['heartbeat_at'] ?? $state['started_at'] ?? null;

        if (!is_string($last) || $last === '') {
            return true;
        }

        $timestamp = strtotime($last . ' UTC');
        if ($timestamp === false) {
            return true;
        }

        return ($this->dateTime->gmtTimestamp() - $timestamp) > self::STALE_AFTER_SECONDS;
    }

    public function clear(int $storeId): void
    {
        $this->flagManager->deleteFlag(self::FLAG_PREFIX . $storeId);
    }

    private function save(int $storeId, array $state): array
    {
        unset($state['stale']);
        $state['store_id'] = $storeId;

        $this->flagManager->saveFlag(self::FLAG_PREFIX . $storeId, $state);

        return $this->get($storeId);
    }
}
