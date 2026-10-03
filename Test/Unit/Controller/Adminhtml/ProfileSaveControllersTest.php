<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Controller\Adminhtml;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AdvancedSEO\Controller\Adminhtml\Feed\Save as FeedSave;
use Panth\AdvancedSEO\Controller\Adminhtml\Feed\SaveField;
use Panth\AdvancedSEO\Controller\Adminhtml\Rule\Save as RuleSave;
use Panth\AdvancedSEO\Model\Cache\SeoCacheInvalidator;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProfileSaveControllersTest extends TestCase
{
    use ControllerHarness;
    use ConnectionRecorder;

    private array $cleaned = [];

    private function dateTime(): DateTime
    {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-10-03 00:00:00');
        return $dateTime;
    }

    private function failingResource(): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willThrowException(new \RuntimeException('db offline'));
        return $resource;
    }

    private function ruleSave(array $post, array $params = [], bool $fails = false): RuleSave
    {
        $this->cleaned = [];
        $typeList = $this->createStub(TypeListInterface::class);
        $typeList->method('cleanType')->willReturnCallback(function ($type): void {
            $this->cleaned[] = $type;
        });
        $invalidator = $this->createStub(SeoCacheInvalidator::class);
        $invalidator->method('invalidateAll')->willReturnCallback(function (): void {
            $this->cleaned[] = 'seo';
        });

        return new RuleSave(
            $this->controllerContext($params, $post),
            $fails ? $this->failingResource() : $this->recordingResource(),
            new Json(),
            $this->dateTime(),
            $typeList,
            $invalidator
        );
    }

    public function testRuleInsertSerialisesConditionsAndActions(): void
    {
        $this->dbLastInsertId = '11';
        $this->ruleSave([
            'name' => 'Noindex sale', 'entity_type' => 'category', 'priority' => '5', 'stop_on_match' => '1',
            'condition_attribute' => 'is_anchor', 'condition_value' => ' 1 ',
            'action_noindex' => '1', 'action_title_template' => '{{name}}', 'action_description_template' => 'D', 'action_canonical' => 'C',
        ], ['back' => 1])->execute();

        [$table, $row] = $this->db['insert'][0];
        $this->assertSame('panth_seo_rule', $table);
        $this->assertSame(5, $row['priority']);
        $this->assertSame(1, $row['stop_on_match']);
        $this->assertSame('{"type":"all","conditions":[{"attribute":"is_anchor","operator":"in","value":"1"}]}', $row['conditions_serialized']);
        $this->assertSame('{"noindex":"1","title_template":"{{name}}","description_template":"D","canonical":"C"}', $row['actions_serialized']);
        $this->assertSame('2026-10-03 00:00:00', $row['created_at']);
        $this->assertSame(['config', 'seo'], $this->cleaned);
        $this->assertSame(['*/*/edit', ['id' => 11]], $this->redirect);
    }

    public function testRuleUpdateKeepsRawSerializedValues(): void
    {
        $this->ruleSave(['rule_id' => 3, 'conditions_serialized' => '{"c":1}', 'actions_serialized' => '{"a":1}'])->execute();

        $row = $this->db['update'][0][1];
        $this->assertSame('{"c":1}', $row['conditions_serialized']);
        $this->assertSame('{"a":1}', $row['actions_serialized']);
        $this->assertSame(['rule_id = ?' => 3], $this->db['update'][0][2]);
        $this->assertSame(['*/*/', []], $this->redirect);

        $this->ruleSave(['rule_id' => 3])->execute();
        $this->assertSame('{}', $this->db['update'][0][1]['conditions_serialized']);
        $this->assertSame('{}', $this->db['update'][0][1]['actions_serialized']);
    }

    public function testRuleSaveValidationAndErrors(): void
    {
        $this->ruleSave([])->execute();
        $this->assertSame(['*/*/', []], $this->redirect);

        $this->ruleSave(['rule_id' => 3, 'entity_type' => 'brand'])->execute();
        $this->assertSame([['error', 'Invalid entity type.']], $this->messages);
        $this->assertSame(['*/*/edit', ['id' => 3]], $this->redirect);

        $this->ruleSave(['rule_id' => 3], [], true)->execute();
        $this->assertSame([['error', 'db offline']], $this->messages);
        $this->assertSame([], $this->cleaned);
    }

    private function feedSave(array $post, array $params = [], bool $fails = false): FeedSave
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(static fn($v) => 'enc(' . $v . ')');

        return new FeedSave(
            $this->controllerContext($params, $post),
            $fails ? $this->failingResource() : $this->recordingResource(),
            $this->dateTime(),
            $encryptor
        );
    }

    public function testFeedInsertEncryptsThePasswordAndFlattensFilters(): void
    {
        $this->dbLastInsertId = '8';
        $this->feedSave([
            'name' => 'Google', 'filename' => ' feed-1.xml ', 'delivery_password' => 'secret',
            'category_filter' => ['3', '4'], 'attribute_set_filter' => '9', 'delivery_country' => 'GBR', 'currency' => 'EURO',
        ], ['back' => 1])->execute();

        $row = $this->db['insert'][0][1];
        $this->assertSame('feed-1.xml', $row['filename']);
        $this->assertSame('enc(secret)', $row['delivery_password']);
        $this->assertSame('3,4', $row['category_filter']);
        $this->assertSame('9', $row['attribute_set_filter']);
        $this->assertSame('GB', $row['delivery_country']);
        $this->assertSame('EUR', $row['currency']);
        $this->assertSame(1, $row['store_id']);
        $this->assertSame('0 1 * * *', $row['cron_schedule']);
        $this->assertSame([['success', 'Feed profile saved.']], $this->messages);
        $this->assertSame(['*/*/edit', ['id' => 8]], $this->redirect);
    }

    public function testFeedUpdateWithoutAPasswordKeepsTheStoredOne(): void
    {
        $this->feedSave(['feed_id' => 4, 'filename' => 'a.csv', 'attribute_set_filter' => ['1', '2']])->execute();

        $row = $this->db['update'][0][1];
        $this->assertArrayNotHasKey('delivery_password', $row);
        $this->assertArrayNotHasKey('created_at', $row);
        $this->assertSame('1,2', $row['attribute_set_filter']);
        $this->assertSame(['feed_id = ?' => 4], $this->db['update'][0][2]);
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public static function badFilenames(): array
    {
        return [['../etc/passwd'], ['feed.php'], ['.hidden.xml'], ['spaces in.xml']];
    }

    #[DataProvider('badFilenames')]
    public function testFeedFilenamesAreValidated(string $filename): void
    {
        $this->feedSave(['feed_id' => 4, 'filename' => $filename])->execute();

        $this->assertSame('error', $this->messages[0][0]);
        $this->assertSame(['*/*/edit', ['id' => 4]], $this->redirect);
        $this->assertSame([], $this->db['update']);
    }

    public function testFeedSaveEdgeCases(): void
    {
        $this->feedSave([])->execute();
        $this->assertSame(['*/*/', []], $this->redirect);

        $this->feedSave(['filename' => 'bad name'])->execute();
        $this->assertSame(['*/*/edit', []], $this->redirect);

        $this->feedSave(['filename' => 'ok.xml'], [], true)->execute();
        $this->assertSame([['error', 'db offline']], $this->messages);
        $this->assertSame(['*/*/edit', ['id' => 0]], $this->redirect);
    }

    private function saveField(array $post, bool $fails = false): SaveField
    {
        return new SaveField(
            $this->controllerContext([], $post),
            $fails ? $this->failingResource() : $this->recordingResource(),
            $this->jsonFactory()
        );
    }

    public function testFieldInsertAndUpdate(): void
    {
        $this->saveField([
            'feed_id' => 5, 'feed_field' => ' g:title ', 'source_type' => 'magic', 'source_value' => 'name ',
            'default_value' => '  ', 'sort_order' => '-3', 'is_required' => '1',
        ])->execute();

        $this->assertSame([['panth_seo_feed_field', [
            'feed_id' => 5, 'feed_field' => 'g:title', 'source_type' => 'attribute', 'source_value' => 'name',
            'default_value' => null, 'sort_order' => 0, 'is_required' => 1,
        ]]], $this->db['insert']);
        $this->assertSame(['*/feed/fields', ['feed_id' => 5]], $this->redirect);

        $this->saveField(['feed_id' => 5, 'field_id' => 9, 'feed_field' => 'g:id', 'source_type' => 'parent_attribute', 'default_value' => 'x'])->execute();
        $this->assertSame('parent_attribute', $this->db['update'][0][1]['source_type']);
        $this->assertSame('x', $this->db['update'][0][1]['default_value']);
        $this->assertSame(['field_id = ?' => 9], $this->db['update'][0][2]);
    }

    public function testFieldValidationAndErrors(): void
    {
        $this->saveField(['feed_field' => 'g:id'])->execute();
        $this->assertSame([['error', 'Feed ID is required.']], $this->messages);
        $this->assertSame(['*/*/index', []], $this->redirect);

        $this->saveField(['feed_id' => 5, 'feed_field' => '  '])->execute();
        $this->assertSame([['error', 'Feed field name is required.']], $this->messages);

        $this->saveField(['feed_id' => 5, 'feed_field' => 'g:id'], true)->execute();
        $this->assertSame([['error', 'db offline']], $this->messages);
        $this->assertSame(['*/feed/fields', ['feed_id' => 5]], $this->redirect);
    }

    public function testInlineGridEditUpdatesOnlyKnownColumns(): void
    {
        $this->saveField(['items' => [
            3 => ['feed_field' => 'g:brand', 'evil' => 'x', 'sort_order' => 4],
            4 => ['unknown' => 1],
        ]])->execute();

        $this->assertSame([['panth_seo_feed_field', ['feed_field' => 'g:brand', 'sort_order' => 4], ['field_id = ?' => 3]]], $this->db['update']);
        $this->assertSame(['messages' => [], 'error' => false], $this->json);
    }
}
