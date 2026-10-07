<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Block;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;

trait BackendTemplateObjectManager
{
    private mixed $previousObjectManager = null;

    protected function setUp(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $this->previousObjectManager = $property->getValue();

        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn(string $class) => $this->createStub($class));
        ObjectManager::setInstance($objectManager);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ObjectManager::class, '_instance'))->setValue(null, $this->previousObjectManager);
    }
}
