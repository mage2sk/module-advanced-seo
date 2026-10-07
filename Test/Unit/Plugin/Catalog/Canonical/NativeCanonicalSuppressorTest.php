<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Catalog\Canonical;

use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Plugin\Catalog\Canonical\NativeCanonicalSuppressor;
use PHPUnit\Framework\TestCase;

class NativeCanonicalSuppressorTest extends TestCase
{
    private function plugin(bool $enabled, bool $canonical, bool $throws = false): NativeCanonicalSuppressor
    {
        $config = $this->createStub(Config::class);
        if ($throws) {
            $config->method('isEnabled')->willThrowException(new \RuntimeException('config'));
        } else {
            $config->method('isEnabled')->willReturn($enabled);
        }
        $config->method('isCanonicalEnabled')->willReturn($canonical);

        return new NativeCanonicalSuppressor($config);
    }

    public function testNativeCanonicalIsSuppressedWhileTheModuleOutputsItsOwn(): void
    {
        $this->assertFalse($this->plugin(true, true)->afterCanUseCanonicalTag(null, true));
        $this->assertFalse($this->plugin(true, true)->afterCanUseCanonicalTag(null, false));
    }

    public function testNativeCanonicalTakesOverWhenTheModuleIsDisabled(): void
    {
        $this->assertTrue($this->plugin(false, true)->afterCanUseCanonicalTag(null, false));
        $this->assertTrue($this->plugin(false, true)->afterCanUseCanonicalTag(null, true));
    }

    public function testCoreValueIsKeptWhenCanonicalTagsAreSwitchedOffOrConfigFails(): void
    {
        $this->assertTrue($this->plugin(true, false)->afterCanUseCanonicalTag(null, true));
        $this->assertFalse($this->plugin(false, false)->afterCanUseCanonicalTag(null, false));
        $this->assertTrue($this->plugin(true, true, true)->afterCanUseCanonicalTag(null, true));
        $this->assertFalse($this->plugin(true, true, true)->afterCanUseCanonicalTag(null, false));
    }
}
