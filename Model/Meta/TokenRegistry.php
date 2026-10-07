<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta;

use Panth\AdvancedSEO\Model\Meta\Token\TokenInterface;

class TokenRegistry
{
    private array $tokens;

    public function __construct(array $tokens = [])
    {
        $this->tokens = [];
        foreach ($tokens as $name => $token) {
            if ($token instanceof TokenInterface) {
                $this->tokens[strtolower((string) $name)] = $token;
            }
        }
    }

    public function has(string $name): bool
    {
        return isset($this->tokens[strtolower($name)]);
    }

    public function get(string $name): ?TokenInterface
    {
        return $this->tokens[strtolower($name)] ?? null;
    }

    public function all(): array
    {
        return $this->tokens;
    }
}
