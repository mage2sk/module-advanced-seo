<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

interface TokenInterface
{
    public function getValue(mixed $entity, array $context, ?string $argument = null): string;
}
