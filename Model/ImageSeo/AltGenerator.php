<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\ImageSeo;

use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Psr\Log\LoggerInterface;

/**
 * Generates alt/title text from a template via the existing TemplateRenderer
 * with an optional vision adapter fallback. Templates use tokens like
 *   {{name}} – {{category}}    for alt
 *   {{name|truncate:120}}      for title
 */
class AltGenerator
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly LoggerInterface $logger,
        private readonly ?VisionAdapterInterface $visionAdapter = null
    ) {
    }

    /**
     * @param array<string,mixed> $context entity context for token resolution
     * @return array{alt:string,title:string}
     */
    public function generate(
        string $altTemplate,
        string $titleTemplate,
        mixed $entity,
        array $context = [],
        ?string $absoluteImagePath = null
    ): array {
        $alt   = '';
        $title = '';

        try {
            if ($altTemplate !== '') {
                $alt = trim($this->renderer->render($altTemplate, $entity, $context));
            }
            if ($titleTemplate !== '') {
                $title = trim($this->renderer->render($titleTemplate, $entity, $context));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PanthSEO] alt template render failed: ' . $e->getMessage());
        }

        if ($alt === '' && $this->visionAdapter !== null && $absoluteImagePath !== null && is_file($absoluteImagePath)) {
            try {
                $vision = $this->visionAdapter->describe($absoluteImagePath, $context);
                if (is_array($vision)) {
                    $alt   = (string) ($vision['alt'] ?? $alt);
                    if ($title === '') {
                        $title = (string) ($vision['title'] ?? $alt);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[PanthSEO] vision adapter failed: ' . $e->getMessage());
            }
        }

        if ($alt === '') {
            $alt = (string) ($context['name'] ?? (is_object($entity) && method_exists($entity, 'getName') ? (string) $entity->getName() : ''));
        }
        if ($title === '') {
            $title = $alt;
        }
        if (mb_strlen($alt, 'UTF-8') > 250) {
            $alt = mb_substr($alt, 0, 247, 'UTF-8') . '...';
        }
        if (mb_strlen($title, 'UTF-8') > 250) {
            $title = mb_substr($title, 0, 247, 'UTF-8') . '...';
        }
        return ['alt' => $alt, 'title' => $title];
    }
}
