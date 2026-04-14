<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class AiProvider implements ArrayInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'null', 'label' => __('Disabled (Null)')],
            ['value' => 'claude', 'label' => __('Anthropic Claude')],
            ['value' => 'openai', 'label' => __('OpenAI')],
        ];
    }
}
