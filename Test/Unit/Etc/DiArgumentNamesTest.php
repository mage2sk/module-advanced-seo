<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Etc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DiArgumentNamesTest extends TestCase
{
    private static function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function diArguments(): array
    {
        $cases = [];
        foreach (glob(self::moduleRoot() . '/etc/{,*/}di.xml', GLOB_BRACE) ?: [] as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false) {
                continue;
            }
            foreach ($xml->type as $type) {
                $class = (string) $type['name'];
                if (strpos($class, 'Panth\\AdvancedSEO\\') !== 0) {
                    continue;
                }
                foreach ($type->arguments->argument ?? [] as $argument) {
                    $name = (string) $argument['name'];
                    $label = basename($file) . ' ' . $class . '::$' . $name;
                    $cases[$label] = [$class, $name];
                }
            }
        }

        return $cases ?: ['no di arguments' => ['', '']];
    }

    #[DataProvider('diArguments')]
    public function testEveryDiArgumentMatchesAConstructorParameter(string $class, string $name): void
    {
        if ($class === '') {
            $this->assertTrue(true);

            return;
        }
        if (!class_exists($class) && !interface_exists($class)) {
            $this->markTestSkipped($class . ' is not autoloadable in the unit context');
        }

        $constructor = (new \ReflectionClass($class))->getConstructor();
        $this->assertNotNull($constructor, $class . ' has no constructor but di.xml passes arguments');

        $parameters = array_map(
            static fn(\ReflectionParameter $p) => $p->getName(),
            $constructor->getParameters()
        );

        $this->assertContains(
            $name,
            $parameters,
            $class . ' has no constructor parameter $' . $name
            . '. Magento matches di.xml arguments by parameter name, so this binding is silently ignored'
            . ' and a nullable optional argument stays null. Parameters are: ' . implode(', ', $parameters)
        );
    }
}
