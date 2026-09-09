<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Rule;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\AdvancedSEO\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Panth\AdvancedSEO\Model\Rule\Condition\Combine;
use Panth\AdvancedSEO\Model\Rule\Engine;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EngineActionMergeTest extends TestCase
{
    private function engineWithRules(array $rules): Engine
    {
        $serializer = new Json();

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn($serializer->serialize($rules));

        $combine = $this->createStub(Combine::class);
        $combine->method('evaluate')->willReturn(true);

        return new Engine(
            $this->createStub(RuleCollectionFactory::class),
            $combine,
            $serializer,
            $cache,
            $this->createStub(LoggerInterface::class)
        );
    }

    public function testKnownActionKeysAreMerged(): void
    {
        $engine = $this->engineWithRules([[
            'rule_id' => 7,
            'conditions_serialized' => '[]',
            'actions_serialized' => '{"noindex":"1","title_template":"Rule {{name}}"}',
            'stop_on_match' => 0,
        ]]);

        $result = $engine->evaluate('product', 1, 1);

        $this->assertSame('1', $result['noindex']);
        $this->assertSame('Rule {{name}}', $result['title_template']);
        $this->assertSame([7], $result['matched_rules']);
    }

    public function testUnknownActionKeyDoesNotRaiseAnUndefinedIndexError(): void
    {
        $engine = $this->engineWithRules([[
            'rule_id' => 9,
            'conditions_serialized' => '[]',
            'actions_serialized' => '{"meta_title":"legacy key","canonical":"https://example.com/x"}',
            'stop_on_match' => 0,
        ]]);

        $result = $engine->evaluate('product', 1, 1);

        $this->assertSame('legacy key', $result['meta_title']);
        $this->assertSame('https://example.com/x', $result['canonical']);
    }

    public function testFirstMatchingRuleWinsForTheSameKey(): void
    {
        $engine = $this->engineWithRules([
            ['rule_id' => 1, 'conditions_serialized' => '[]', 'actions_serialized' => '{"title_template":"first"}', 'stop_on_match' => 0],
            ['rule_id' => 2, 'conditions_serialized' => '[]', 'actions_serialized' => '{"title_template":"second"}', 'stop_on_match' => 0],
        ]);

        $result = $engine->evaluate('product', 1, 1);

        $this->assertSame('first', $result['title_template']);
        $this->assertSame([1, 2], $result['matched_rules']);
    }

    public function testStopOnMatchHaltsEvaluation(): void
    {
        $engine = $this->engineWithRules([
            ['rule_id' => 1, 'conditions_serialized' => '[]', 'actions_serialized' => '{"noindex":"1"}', 'stop_on_match' => 1],
            ['rule_id' => 2, 'conditions_serialized' => '[]', 'actions_serialized' => '{"title_template":"never"}', 'stop_on_match' => 0],
        ]);

        $result = $engine->evaluate('product', 1, 1);

        $this->assertSame([1], $result['matched_rules']);
        $this->assertNull($result['title_template']);
    }
}
