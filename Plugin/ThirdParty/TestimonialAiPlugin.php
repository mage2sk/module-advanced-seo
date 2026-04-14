<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\ThirdParty;

use Panth\AdvancedSEO\Model\Admin\AiButtonRenderer;

/**
 * Adds AI generate buttons to the Testimonial edit form.
 *
 * Targets: Panth\Testimonials\Model\Testimonial\DataProvider (afterGetMeta)
 *
 * SAFE: If Panth_Testimonials is not installed, Magento silently skips the
 * plugin declaration in di.xml. The class_exists() check provides extra safety.
 */
class TestimonialAiPlugin
{
    public function __construct(
        private readonly AiButtonRenderer $aiButtonRenderer
    ) {
    }

    /**
     * Inject AI generate buttons into the testimonial form.
     *
     * @param mixed                $subject  Panth\Testimonials\Model\Testimonial\DataProvider
     * @param array<string,mixed>  $result
     * @return array<string,mixed>
     */
    public function afterGetMeta($subject, array $result): array
    {
        // Safety check: only proceed if Testimonials module is actually installed
        if (!class_exists(\Panth\Testimonials\Model\Testimonial::class, false)
            && !class_exists(\Panth\Testimonials\Model\Testimonial::class)
        ) {
            return $result;
        }

        if (!$this->aiButtonRenderer->isAvailable()) {
            return $result;
        }

        // Field map: AI field key => form input name
        $fieldMap = [
            'content'       => 'content',
            'short_content' => 'short_content',
            'title'         => 'title',
        ];

        // Per-field config for individual AI buttons
        $perFieldConfig = [
            'content' => [
                'label'  => 'Testimonial Content',
                'field'  => 'content',
                'prompt' => 'Write a polished, authentic-sounding testimonial based on the existing content. Keep the customer\'s voice and sentiment. 2-4 sentences, professional but personal.',
            ],
            'short_content' => [
                'label'  => 'Short Excerpt',
                'field'  => 'short_content',
                'prompt' => 'Write a short testimonial excerpt suitable for a card display. 1-2 sentences, max 150 characters. Capture the key sentiment.',
            ],
            'title' => [
                'label'  => 'Testimonial Title',
                'field'  => 'title',
                'prompt' => 'Write a compelling, concise testimonial headline/title that captures the key sentiment. Keep it under 60 characters.',
            ],
        ];

        // Inject into the "general" fieldset
        $result['general']['children']['ai_generate_container'] = $this->aiButtonRenderer->buildContainerMeta(
            'testimonial',
            'testimonial_id',
            '',
            $fieldMap,
            $perFieldConfig,
            'testimonial',
            'The AI will use the saved testimonial data to generate or improve content.',
            5
        );

        return $result;
    }
}
