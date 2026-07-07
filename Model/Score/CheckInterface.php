<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Score;

interface CheckInterface
{
    public function getCode(): string;

    public function run(array $context): array;
}
