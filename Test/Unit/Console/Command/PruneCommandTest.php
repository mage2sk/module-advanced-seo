<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Console\Command;

use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\LocalizedException;
use Panth\AdvancedSEO\Console\Command\PruneCommand;
use Panth\AdvancedSEO\Model\Maintenance\OrphanCleaner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PruneCommandTest extends TestCase
{
    private array $calls = [];

    private function tester(array|\Throwable $outcome): CommandTester
    {
        $this->calls = [];
        $cleaner = $this->createStub(OrphanCleaner::class);
        foreach (['count', 'sweep'] as $method) {
            $cleaner->method($method)->willReturnCallback(
                function (bool $authored = false) use ($method, $outcome): array {
                    $this->calls[] = [$method, $authored];
                    if ($outcome instanceof \Throwable) {
                        throw $outcome;
                    }
                    return $outcome;
                }
            );
        }

        $state = $this->createStub(AppState::class);
        $state->method('setAreaCode')->willThrowException(new LocalizedException(__('Area code is already set')));

        return new CommandTester(new PruneCommand($cleaner, $state));
    }

    public function testCommandName(): void
    {
        $command = new PruneCommand($this->createStub(OrphanCleaner::class), $this->createStub(AppState::class));

        $this->assertSame('panth:seo:prune', $command->getName());
        $this->assertTrue($command->getDefinition()->hasOption('dry-run'));
        $this->assertTrue($command->getDefinition()->hasOption('include-authored'));
    }

    public function testSweepDeletesAndReportsTotals(): void
    {
        $tester = $this->tester(['panth_seo_score' => 3, 'panth_seo_resolved' => 4]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame([['sweep', false]], $this->calls);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Pass --include-authored', $display);
        $this->assertMatchesRegularExpression('/panth_seo_score\s+3 row\(s\)/', $display);
        $this->assertStringContainsString('Deleted 7 row(s) for entities that no longer exist.', $display);
    }

    public function testDryRunOnlyCountsAndIncludeAuthoredIsPassedThrough(): void
    {
        $tester = $this->tester(['panth_seo_override' => 2]);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true, '--include-authored' => true]));
        $this->assertSame([['count', true]], $this->calls);
        $this->assertStringNotContainsString('Pass --include-authored', $tester->getDisplay());
        $this->assertStringContainsString('Dry run: 2 row(s) would be deleted.', $tester->getDisplay());
    }

    public function testNothingToPrune(): void
    {
        $tester = $this->tester([]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('No SEO rows for deleted entities were found.', $tester->getDisplay());
    }

    public function testFailureReturnsFailureCode(): void
    {
        $tester = $this->tester(new \RuntimeException('lock wait'));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Prune failed: lock wait', $tester->getDisplay());
    }
}
