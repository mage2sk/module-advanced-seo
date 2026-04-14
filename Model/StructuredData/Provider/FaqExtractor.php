<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData\Provider;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Registry;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;

/**
 * Scans the current entity's HTML description for heading/paragraph pairs and
 * emits a FAQPage node. Recognized patterns:
 *
 *  - An <h2> or <h3> that ends in "?" followed by a sibling <p> or <div>.
 *  - A <strong>Question?</strong> followed by text.
 *
 * Only questions with non-empty answers are emitted; at least 2 are required
 * for the FAQPage node to render.
 *
 * Overlap protection: when Panth_Faq is installed, that module owns all FAQPage
 * emission on its own routes (faq/*) and on any page where its layout-mounted
 * Schema block (faq.schema) already produced output. This extractor defers to
 * Panth_Faq in those cases to guarantee a single FAQPage JSON-LD per page.
 */
class FaqExtractor extends AbstractProvider
{
    public function __construct(
        Registry $registry,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        Config $config,
        private readonly ModuleManager $moduleManager,
        private readonly LayoutInterface $layout
    ) {
        parent::__construct($registry, $request, $storeManager, $config);
    }

    public function getCode(): string
    {
        return 'faq';
    }

    public function getJsonLd(): array
    {
        // Defer to Panth_Faq when it owns the FAQPage schema for this page.
        if ($this->isPanthFaqOwnsPage()) {
            return [];
        }

        $html = $this->extractHtml();
        if ($html === '') {
            return [];
        }
        $pairs = $this->parse($html);
        if (count($pairs) < 2) {
            return [];
        }

        $entities = [];
        foreach ($pairs as $qa) {
            $entities[] = [
                '@type' => 'Question',
                'name' => $qa['q'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $qa['a'],
                ],
            ];
        }

        $base = $this->getBaseUrl();
        return [
            '@type' => 'FAQPage',
            '@id' => $base . '#faq-' . sha1((string) $this->request->getPathInfo()),
            'mainEntity' => $entities,
        ];
    }

    /**
     * Returns true when Panth_Faq is enabled AND either the current request is
     * a panth_faq/* route OR Panth_Faq's Schema block has already produced a
     * FAQPage JSON-LD payload for the current page. In both cases this
     * extractor must not emit a second FAQPage block.
     */
    private function isPanthFaqOwnsPage(): bool
    {
        if (!$this->moduleManager->isEnabled('Panth_Faq')) {
            return false;
        }

        // Route owned by Panth_Faq (faq/index/index, faq/index/view, etc).
        try {
            if ($this->request->getRouteName() === 'faq') {
                return true;
            }
        } catch (\Throwable) {
            // fall through to layout inspection
        }

        // Panth_Faq mounts its Schema block on CMS/product/category pages via
        // layout updates. When that block is present AND produces non-empty
        // schema data, it owns the FAQPage JSON-LD on this page.
        try {
            $block = $this->layout->getBlock('faq.schema');
            if ($block instanceof \Panth\Faq\Block\Schema && $block->isEnabled()) {
                $data = $block->getSchemaData();
                if (is_string($data) && $data !== '') {
                    return true;
                }
            }
        } catch (\Throwable) {
            // if layout is not yet generated or block missing, fall through
        }

        return false;
    }

    private function extractHtml(): string
    {
        $product = $this->getCurrentProduct();
        if ($product !== null) {
            return (string) ($product->getData('description') ?? '');
        }
        $cms = $this->getCurrentCmsPage();
        if ($cms !== null) {
            return (string) $cms->getContent();
        }
        $category = $this->getCurrentCategory();
        if ($category !== null) {
            return (string) $category->getData('description');
        }
        return '';
    }

    /**
     * @return array<int,array{q:string,a:string}>
     */
    private function parse(string $html): array
    {
        if ($html === '' || !str_contains($html, '<')) {
            return [];
        }

        $pairs = [];
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="utf-8"?><root>' . $html . '</root>';
        try {
            $loaded = $doc->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (\Throwable) {
            $loaded = false;
        }
        if (!$loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return [];
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($doc);
        // h2/h3 ending in "?"
        $nodes = $xpath->query('//h2|//h3|//h4');
        if ($nodes !== false) {
            foreach ($nodes as $heading) {
                $q = trim((string) $heading->textContent);
                if ($q === '' || !str_ends_with($q, '?')) {
                    continue;
                }
                $answer = '';
                $sibling = $heading->nextSibling;
                while ($sibling !== null) {
                    if ($sibling->nodeType === XML_ELEMENT_NODE) {
                        $tag = strtolower((string) $sibling->nodeName);
                        if (in_array($tag, ['h1', 'h2', 'h3', 'h4'], true)) {
                            break;
                        }
                        $answer .= ' ' . trim((string) $sibling->textContent);
                        if (in_array($tag, ['p', 'div', 'ul', 'ol'], true) && trim($answer) !== '') {
                            // stop after the first block answer
                            break;
                        }
                    }
                    $sibling = $sibling->nextSibling;
                }
                $answer = trim(preg_replace('/\s+/', ' ', $answer) ?? '');
                if ($answer !== '') {
                    $pairs[] = ['q' => $q, 'a' => $answer];
                }
            }
        }

        return $pairs;
    }
}
