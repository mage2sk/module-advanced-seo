<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Generator;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\AdvancedSEO\Api\MetaGeneratorInterface;

/**
 * Resolves the correct AI meta generator adapter based on configuration.
 * Returns NullAdapter when AI is disabled or provider is not configured.
 *
 * Legitimate ObjectManager usage: this is a factory class whose target
 * concrete type is chosen at runtime from a store-scope configuration value
 * ("claude" / "openai" / anything else). Because the type is only known at
 * call time, we cannot constructor-inject the adapter directly. Instead we
 * depend on Magento\Framework\ObjectManagerInterface (proper DI — NOT the
 * ObjectManager::getInstance() service locator) and look the concrete
 * adapter up lazily.
 */
class AdapterFactory implements MetaGeneratorInterface
{
    private ?MetaGeneratorInterface $resolved = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    public function getProvider(): string
    {
        return $this->resolve()->getProvider();
    }

    public function generate(array $context, array $fields = [], array $options = []): array
    {
        return $this->resolve()->generate($context, $fields, $options);
    }

    public function getLastUsageTokens(): int
    {
        return $this->resolve()->getLastUsageTokens();
    }

    private function resolve(): MetaGeneratorInterface
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $provider = (string) $this->scopeConfig->getValue('panth_seo/ai/provider', ScopeInterface::SCOPE_STORE);

        $this->resolved = match ($provider) {
            'claude' => $this->objectManager->get(ClaudeAdapter::class),
            'openai' => $this->objectManager->get(OpenAiAdapter::class),
            default => $this->objectManager->get(NullAdapter::class),
        };

        return $this->resolved;
    }
}
