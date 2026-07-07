<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

class RandomToken implements TokenInterface
{
    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        if ($argument === null || $argument === '') {
            return '';
        }

        $options = explode('|', $argument);
        $options = array_map('trim', $options);
        $options = array_values(array_filter($options, static fn (string $v): bool => $v !== ''));

        if ($options === []) {
            return '';
        }

        return $options[array_rand($options)];
    }
}
