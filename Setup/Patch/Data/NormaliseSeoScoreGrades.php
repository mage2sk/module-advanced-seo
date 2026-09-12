<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Panth\AdvancedSEO\Model\Score\GradeCalculator;
use Psr\Log\LoggerInterface;

class NormaliseSeoScoreGrades implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly GradeCalculator $gradeCalculator,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('panth_seo_score');

        if (!$connection->isTableExists($table)) {
            return $this;
        }

        try {
            $updated = 0;
            foreach ($this->gradeCalculator->all() as $grade) {
                $bounds = $this->boundsFor($grade);
                $where = [
                    'score >= ?' => $bounds[0],
                    'score <= ?' => $bounds[1],
                    'grade <> ?' => $grade,
                ];
                $updated += $connection->update($table, ['grade' => $grade], $where);
            }

            if ($updated > 0) {
                $this->logger->info(sprintf(
                    'Panth SEO: normalised %d stored SEO score grade(s) to the A-F scale.',
                    $updated
                ));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO: could not normalise stored score grades: ' . $e->getMessage());
        }

        return $this;
    }

    private function boundsFor(string $grade): array
    {
        return match ($grade) {
            'A' => [90, 100],
            'B' => [80, 89],
            'C' => [70, 79],
            'D' => [60, 69],
            default => [0, 59],
        };
    }
}
