<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\AuditContext;
use Panth\AdvancedSEO\Model\Audit\Issue;
use Panth\AdvancedSEO\Model\Audit\IssueCatalog;
use Panth\AdvancedSEO\Model\Audit\PageParser;
use Panth\AdvancedSEO\Model\Audit\ParsedPage;
use PHPUnit\Framework\TestCase;

abstract class AbstractCheckTestCase extends TestCase
{
    protected const BASE = 'https://example.com/';

    protected static function fixturePath(string $name): string
    {
        return dirname(__DIR__, 3) . '/_files/audit/' . $name;
    }

    protected static function fixture(string $name): string
    {
        return (string) file_get_contents(self::fixturePath($name));
    }

    protected function page(
        string $fixture,
        string $url = 'https://example.com/page',
        int $status = 200,
        array $headers = ['Content-Type' => 'text/html; charset=UTF-8'],
        float $totalMs = 100.0
    ): ParsedPage {
        return (new PageParser())->parse($url, $status, $headers, self::fixture($fixture), $totalMs, $totalMs);
    }

    protected function catalog(): IssueCatalog
    {
        return new IssueCatalog();
    }

    protected function ctx(array $args = []): AuditContext
    {
        return new AuditContext(...array_merge(['storeId' => 1, 'baseUrl' => self::BASE], $args));
    }

    protected function codes(array $issues): array
    {
        return array_map(static fn (Issue $issue): string => $issue->code, $issues);
    }

    protected function elements(array $issues, ?string $code = null): array
    {
        $out = [];
        foreach ($issues as $issue) {
            if ($code === null || $issue->code === $code) {
                $out[] = $issue->element;
            }
        }

        return $out;
    }
}
