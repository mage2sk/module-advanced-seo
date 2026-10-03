<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Queue;

use Panth\AdvancedSEO\Api\SeoScorerInterface;
use Panth\AdvancedSEO\Model\Queue\ScoreConsumer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScoreConsumerTest extends TestCase
{
    public function testValidMessageIsScored(): void
    {
        $scorer = $this->createMock(SeoScorerInterface::class);
        $scorer->expects($this->once())->method('score')->with('product', 42, 3);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $logger->expects($this->never())->method('error');

        (new ScoreConsumer($scorer, $logger))->process('{"entity_type":"product","entity_id":"42","store_id":3}');
    }

    public function testMissingKeysDefaultToEmptyValues(): void
    {
        $scorer = $this->createMock(SeoScorerInterface::class);
        $scorer->expects($this->once())->method('score')->with('', 0, 0);

        (new ScoreConsumer($scorer, $this->createStub(LoggerInterface::class)))->process('{}');
    }

    public function testInvalidMessageIsLogged(): void
    {
        $scorer = $this->createMock(SeoScorerInterface::class);
        $scorer->expects($this->never())->method('score');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('Panth SEO score: invalid message', ['message' => 'nope']);

        (new ScoreConsumer($scorer, $logger))->process('nope');
    }

    public function testScorerExceptionIsLogged(): void
    {
        $scorer = $this->createStub(SeoScorerInterface::class);
        $scorer->method('score')->willThrowException(new \RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Panth SEO score consumer failed: boom');

        (new ScoreConsumer($scorer, $logger))->process('{"entity_type":"cms","entity_id":1,"store_id":1}');
    }
}
