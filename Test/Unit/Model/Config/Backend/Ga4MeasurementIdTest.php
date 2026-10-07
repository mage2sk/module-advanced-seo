<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Config\Backend;

use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Panth\AdvancedSEO\Model\Config\Backend\Ga4MeasurementId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Ga4MeasurementIdTest extends TestCase
{
    private function model(mixed $value): Ga4MeasurementId
    {
        $reflection = new \ReflectionClass(Ga4MeasurementId::class);
        /** @var Ga4MeasurementId $model */
        $model = $reflection->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty($model, '_eventManager');
        $property->setValue($model, $this->createStub(ManagerInterface::class));
        $data = new \ReflectionProperty($model, '_data');
        $data->setValue($model, []);
        $model->setValue($value);

        return $model;
    }

    public static function validProvider(): array
    {
        return [
            'standard' => ['G-ABC123XYZ', 'G-ABC123XYZ'],
            'trimmed' => ['  G-ABC123  ', 'G-ABC123'],
            'underscore' => ['UA_1234', 'UA_1234'],
            'empty' => ['', ''],
            'whitespace only' => ['   ', ''],
            'null' => [null, ''],
            'max length' => [str_repeat('a', 64), str_repeat('a', 64)],
        ];
    }

    #[DataProvider('validProvider')]
    public function testValidValuesAreAcceptedAndTrimmed(mixed $input, string $expected): void
    {
        $model = $this->model($input);

        $this->assertSame($model, $model->beforeSave());
        $this->assertSame($expected, $model->getValue());
    }

    public static function invalidProvider(): array
    {
        return [
            'space inside' => ['G ABC'],
            'script' => ['<script>'],
            'quote' => ["G-1'2"],
            'too long' => [str_repeat('a', 65)],
            'dot' => ['G-1.2'],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidValuesThrow(string $input): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('GA4 Measurement ID must match');

        $this->model($input)->beforeSave();
    }
}
