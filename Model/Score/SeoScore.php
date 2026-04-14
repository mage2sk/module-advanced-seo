<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Score;

use Magento\Framework\DataObject;
use Panth\AdvancedSEO\Api\Data\SeoScoreInterface;

class SeoScore extends DataObject implements SeoScoreInterface
{
    public function getScoreId(): ?int
    {
        $v = $this->getData(self::SCORE_ID);
        return $v === null ? null : (int)$v;
    }

    public function getStoreId(): int { return (int)$this->getData(self::STORE_ID); }
    public function setStoreId(int $id): self { return $this->setData(self::STORE_ID, $id); }

    public function getEntityType(): string { return (string)$this->getData(self::ENTITY_TYPE); }
    public function setEntityType(string $type): self { return $this->setData(self::ENTITY_TYPE, $type); }

    public function getEntityId(): int { return (int)$this->getData(self::ENTITY_ID); }
    public function setEntityId(int $id): self { return $this->setData(self::ENTITY_ID, $id); }

    public function getScore(): int { return (int)$this->getData(self::SCORE); }
    public function setScore(int $score): self { return $this->setData(self::SCORE, $score); }

    public function getGrade(): string { return (string)$this->getData(self::GRADE); }
    public function setGrade(string $grade): self { return $this->setData(self::GRADE, $grade); }

    public function getBreakdown(): array { return (array)($this->getData(self::BREAKDOWN) ?? []); }
    public function setBreakdown(array $breakdown): self { return $this->setData(self::BREAKDOWN, $breakdown); }

    public function getIssues(): array { return (array)($this->getData(self::ISSUES) ?? []); }
    public function setIssues(array $issues): self { return $this->setData(self::ISSUES, $issues); }

    public function getComputedAt(): ?string
    {
        $v = $this->getData(self::COMPUTED_AT);
        return $v === null ? null : (string)$v;
    }
}
