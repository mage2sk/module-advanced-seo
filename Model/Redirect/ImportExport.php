<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Redirect;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Panth\AdvancedSEO\Api\Data\RedirectRuleInterface;
use Psr\Log\LoggerInterface;

/**
 * CSV import/export for redirects.
 *
 * CSV header:
 *   store_id,match_type,pattern,target,status_code,priority,is_active
 */
class ImportExport
{
    public const HEADER = [
        'store_id',
        'match_type',
        'pattern',
        'target',
        'status_code',
        'priority',
        'is_active',
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Loop $loopDetector,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{imported:int,skipped:int,errors:array<int,string>,rows:array<int,array<string,mixed>>}
     */
    public function import(string $filePath, bool $dryRun = false): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new LocalizedException(__('CSV file not found or not readable: %1', $filePath));
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new LocalizedException(__('Cannot open CSV file: %1', $filePath));
        }

        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $rows     = [];

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                throw new LocalizedException(__('CSV file is empty.'));
            }
            $header = array_map(static fn($h) => strtolower(trim((string) $h)), $header);
            $missing = array_diff(self::HEADER, $header);
            if (!empty($missing)) {
                throw new LocalizedException(__('Missing CSV columns: %1', implode(',', $missing)));
            }

            $lineNo = 1;
            while (($raw = fgetcsv($handle)) !== false) {
                $lineNo++;
                if ($raw === [null] || $raw === false) {
                    continue;
                }
                $row = array_combine($header, array_pad($raw, count($header), ''));
                if (!is_array($row)) {
                    $errors[] = "Line {$lineNo}: malformed";
                    $skipped++;
                    continue;
                }
                $validation = $this->validateRow($row);
                if ($validation !== null) {
                    $errors[] = "Line {$lineNo}: {$validation}";
                    $skipped++;
                    continue;
                }

                $storeId = (int) $row['store_id'];
                if ($row['match_type'] === RedirectRuleInterface::MATCH_LITERAL) {
                    $loop = $this->loopDetector->detect((string) $row['pattern'], (string) $row['target'], $storeId);
                    if (!empty($loop)) {
                        $errors[] = "Line {$lineNo}: loop detected (" . implode(' -> ', $loop) . ')';
                        $skipped++;
                        continue;
                    }
                }

                $rows[] = $row;
                if (!$dryRun) {
                    $this->persist($row);
                }
                $imported++;
            }
        } finally {
            fclose($handle);
        }

        return [
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'rows'     => $rows,
        ];
    }

    /**
     * @param int[]|null $storeIds
     */
    public function exportToStream($stream, ?array $storeIds = null): int
    {
        if (!is_resource($stream)) {
            throw new LocalizedException(__('Invalid export stream.'));
        }
        fputcsv($stream, self::HEADER);

        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_redirect');
        $select = $conn->select()->from($table, self::HEADER)->order('redirect_id ASC');
        if ($storeIds !== null && !empty($storeIds)) {
            $select->where('store_id IN (?)', $storeIds);
        }

        $count = 0;
        $stmt = $conn->query($select);
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            fputcsv($stream, [
                (int) $row['store_id'],
                (string) $row['match_type'],
                (string) $row['pattern'],
                (string) $row['target'],
                (int) $row['status_code'],
                (int) $row['priority'],
                (int) $row['is_active'],
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function validateRow(array $row): ?string
    {
        $matchType = (string) $row['match_type'];
        if (!in_array($matchType, [
            RedirectRuleInterface::MATCH_LITERAL,
            RedirectRuleInterface::MATCH_REGEX,
            RedirectRuleInterface::MATCH_MAINTENANCE,
        ], true)) {
            return 'invalid match_type';
        }
        $pattern = $this->sanitizeCsvValue(trim((string) $row['pattern']));
        $target  = $this->sanitizeCsvValue(trim((string) $row['target']));
        if ($pattern === '' || $target === '') {
            return 'empty pattern/target';
        }
        // Block dangerous URI schemes in target
        if (preg_match('#^(javascript|data|vbscript):#i', $target)) {
            return 'dangerous URI scheme in target';
        }
        $status = (int) $row['status_code'];
        if (!in_array($status, [301, 302, 307, 308, 410, 451, 503], true)) {
            return "invalid status_code {$status}";
        }
        if ($matchType === RedirectRuleInterface::MATCH_REGEX) {
            $wrapped = '~' . str_replace('~', '\\~', $pattern) . '~';
            try {
                $testResult = preg_match($wrapped, '');
            } catch (\Throwable) {
                $testResult = false;
            }
            if ($testResult === false) {
                return 'invalid regex';
            }
        }
        return null;
    }

    /**
     * Strip leading formula-injection characters from CSV cell values.
     * Prevents spreadsheet formula execution (=, +, -, @, \t, \r).
     */
    private function sanitizeCsvValue(string $value): string
    {
        return ltrim($value, "=+\-@\t\r");
    }

    /**
     * @param array<string,mixed> $row
     */
    private function persist(array $row): void
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_redirect');
        $data  = [
            'store_id'    => (int) $row['store_id'],
            'match_type'  => (string) $row['match_type'],
            'pattern'     => $this->sanitizeCsvValue((string) $row['pattern']),
            'target'      => $this->sanitizeCsvValue((string) $row['target']),
            'status_code' => (int) $row['status_code'],
            'priority'    => (int) ($row['priority'] ?? 10),
            'is_active'   => (int) ($row['is_active'] ?? 1),
        ];
        $conn->insertOnDuplicate($table, $data, ['target', 'status_code', 'priority', 'is_active', 'match_type']);
    }
}
