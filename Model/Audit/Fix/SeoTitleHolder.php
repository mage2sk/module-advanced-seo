<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit\Fix;

class SeoTitleHolder
{
    private ?string $title = null;

    public function set(?string $title): void
    {
        $this->title = $title !== null && trim($title) !== '' ? $title : null;
    }

    public function get(): ?string
    {
        return $this->title;
    }
}
