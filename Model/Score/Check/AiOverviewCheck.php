<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Score\Check;

use Panth\AdvancedSEO\Model\Score\CheckInterface;

/**
 * AI Overview readiness check: scores product content for likelihood of being
 * cited in AI-generated search overviews (Google AI Overview, Bing Copilot, etc.).
 *
 * Criteria (0-100 scale):
 *   - Semantic-unit passages (134-167 words)  +20
 *   - Bullet / numbered lists                 +15
 *   - H2/H3 subheadings                       +15
 *   - FAQ-style Q&A content                   +15
 *   - Direct factual opening statement         +10
 *   - Specific data (dimensions/weights/etc.)  +15
 *   - Comparison-friendly attributes (brand)   +10
 */
class AiOverviewCheck implements CheckInterface
{
    /**
     * Optimal word-count range for an AI-citable "semantic unit" paragraph.
     */
    private const SEMANTIC_MIN_WORDS = 134;
    private const SEMANTIC_MAX_WORDS = 167;

    /**
     * Marketing-fluff openers that hurt AI citation likelihood.
     */
    private const FLUFF_PATTERNS = [
        '/^(introducing|discover|experience|unleash|unlock|elevate|transform|imagine|looking for|welcome to|say hello to|get ready)/i',
        '/^(amazing|incredible|unbelievable|stunning|gorgeous|fantastic|revolutionary|game.?changing|best.?ever)/i',
        '/^(don\'t miss|hurry|limited time|act now|buy now|shop now|order today)/i',
        '/^(you\'ll love|you deserve|treat yourself|why not|isn\'t it time)/i',
    ];

    /**
     * Patterns indicating specific product data (dimensions, weights, materials, etc.).
     */
    private const DATA_PATTERNS = [
        '/\b\d+(\.\d+)?\s*(cm|mm|in|inch|inches|ft|feet|m|meters?)\b/i',
        '/\b\d+(\.\d+)?\s*(g|kg|lb|lbs|oz|ounce|pound|pounds|gram|grams|kilogram)\b/i',
        '/\b\d+(\.\d+)?\s*(ml|l|liters?|litres?|fl\.?\s*oz|gallons?)\b/i',
        '/\b\d+(\.\d+)?\s*[xX×]\s*\d+(\.\d+)?(\s*[xX×]\s*\d+(\.\d+)?)?\s*(cm|mm|in|inch|inches|ft|m)?\b/',
        '/\b(cotton|polyester|nylon|leather|stainless\s+steel|aluminum|aluminium|wood|bamboo|ceramic|glass|silicone|titanium|carbon\s+fiber|polycarbonate|abs\s+plastic|rubber|wool|linen|silk|denim|canvas|suede|velvet|mesh|foam|latex|acrylic|copper|brass|iron|zinc|platinum|gold|silver)\b/i',
        '/\b(watt|volt|amp|mAh|kWh|BTU|lumens?|candela|hertz|Hz|GHz|MHz|dB|decibel|RPM)\b/i',
        '/\b\d+\s*%\b/',
        '/\bISO\s*\d+/i',
    ];

    public function getCode(): string
    {
        return 'ai_overview';
    }

    /**
     * @param array<string,mixed> $context
     * @return array{score:float, max:float, message:string, details?:array<string,mixed>}
     */
    public function run(array $context): array
    {
        $content = $this->resolveContent($context);
        $plainText = $this->stripToPlain($content);

        $details = [];
        $total = 0.0;

        // 1. Semantic unit passages (134-167 words) — +20
        $semanticResult = $this->scoreSemanticUnits($plainText);
        $total += $semanticResult['points'];
        $details['semantic_unit'] = $semanticResult;

        // 2. Bullet / numbered lists — +15
        $listResult = $this->scoreLists($content);
        $total += $listResult['points'];
        $details['lists'] = $listResult;

        // 3. H2/H3 subheadings — +15
        $headingResult = $this->scoreHeadings($content);
        $total += $headingResult['points'];
        $details['headings'] = $headingResult;

        // 4. FAQ-style Q&A — +15
        $faqResult = $this->scoreFaqContent($content, $plainText);
        $total += $faqResult['points'];
        $details['faq'] = $faqResult;

        // 5. Direct factual opening — +10
        $openingResult = $this->scoreFactualOpening($plainText);
        $total += $openingResult['points'];
        $details['factual_opening'] = $openingResult;

        // 6. Specific data — +15
        $dataResult = $this->scoreSpecificData($content);
        $total += $dataResult['points'];
        $details['specific_data'] = $dataResult;

        // 7. Comparison-friendly attributes — +10
        $comparisonResult = $this->scoreComparisonAttributes($context, $content);
        $total += $comparisonResult['points'];
        $details['comparison'] = $comparisonResult;

        $total = min(100.0, max(0.0, $total));

        $parts = [];
        if ($details['semantic_unit']['points'] > 0) {
            $parts[] = 'semantic units';
        }
        if ($details['lists']['points'] > 0) {
            $parts[] = 'lists';
        }
        if ($details['headings']['points'] > 0) {
            $parts[] = 'headings';
        }
        if ($details['faq']['points'] > 0) {
            $parts[] = 'FAQ';
        }
        if ($details['factual_opening']['points'] > 0) {
            $parts[] = 'factual opening';
        }
        if ($details['specific_data']['points'] > 0) {
            $parts[] = 'data points';
        }
        if ($details['comparison']['points'] > 0) {
            $parts[] = 'comparisons';
        }

        $msg = sprintf(
            'AI Overview score %d/100: %s',
            (int) round($total),
            $parts !== [] ? 'found ' . implode(', ', $parts) : 'no AI-optimised content signals detected'
        );

        return [
            'score'   => $total,
            'max'     => 100.0,
            'message' => $msg,
            'details' => $details,
        ];
    }

    /**
     * Extract the best available content from context.
     */
    private function resolveContent(array $context): string
    {
        // Prefer raw HTML content for structural analysis.
        $content = (string) ($context['content'] ?? '');
        if ($content !== '') {
            return $content;
        }

        // Fall back to meta description or entity description.
        $meta = $context['meta'] ?? [];
        $desc = (string) ($meta['description'] ?? '');
        if ($desc !== '') {
            return $desc;
        }

        return (string) ($context['description'] ?? '');
    }

    /**
     * Strip HTML to plain text, preserving word boundaries.
     */
    private function stripToPlain(string $html): string
    {
        // Replace block-level tags with newlines for paragraph splitting.
        $text = (string) preg_replace('/<\/(p|div|h[1-6]|li|tr|blockquote|section|article)>/i', "\n", $html);
        $text = (string) preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);

        return trim($text);
    }

    /**
     * Check for paragraphs in the 134-167 word "semantic unit" range.
     *
     * @return array{points:float, found:int, message:string}
     */
    private function scoreSemanticUnits(string $plainText): array
    {
        if ($plainText === '') {
            return ['points' => 0.0, 'found' => 0, 'message' => 'No content'];
        }

        $paragraphs = preg_split('/\n{1,}/', $plainText);
        if ($paragraphs === false) {
            return ['points' => 0.0, 'found' => 0, 'message' => 'Parse error'];
        }

        $matchCount = 0;
        $nearMissCount = 0;
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            $wordCount = str_word_count($para);
            if ($wordCount >= self::SEMANTIC_MIN_WORDS && $wordCount <= self::SEMANTIC_MAX_WORDS) {
                $matchCount++;
            } elseif ($wordCount >= 100 && $wordCount <= 200) {
                $nearMissCount++;
            }
        }

        if ($matchCount >= 1) {
            $points = 20.0;
        } elseif ($nearMissCount >= 1) {
            $points = 10.0;
        } else {
            $points = 0.0;
        }

        return [
            'points'  => $points,
            'found'   => $matchCount,
            'message' => sprintf('%d optimal passage(s), %d near-miss', $matchCount, $nearMissCount),
        ];
    }

    /**
     * Check for bullet points or numbered lists (HTML or Markdown-style).
     *
     * @return array{points:float, found:int, message:string}
     */
    private function scoreLists(string $content): array
    {
        $htmlListCount = 0;

        // Count <ul>/<ol> occurrences.
        if (preg_match_all('/<(ul|ol)\b/i', $content, $m)) {
            $htmlListCount = count($m[0]);
        }

        // Count <li> items.
        $liCount = 0;
        if (preg_match_all('/<li\b/i', $content, $m)) {
            $liCount = count($m[0]);
        }

        // Markdown-style bullets: lines starting with - or * or 1.
        $mdBullets = 0;
        if (preg_match_all('/^\s*[\-\*]\s+\S/m', $content, $m)) {
            $mdBullets = count($m[0]);
        }
        if (preg_match_all('/^\s*\d+[.)]\s+\S/m', $content, $m)) {
            $mdBullets += count($m[0]);
        }

        $totalItems = $liCount + $mdBullets;

        if ($totalItems >= 3) {
            $points = 15.0;
        } elseif ($totalItems >= 1 || $htmlListCount >= 1) {
            $points = 7.0;
        } else {
            $points = 0.0;
        }

        return [
            'points'  => $points,
            'found'   => $totalItems,
            'message' => sprintf('%d list(s), %d item(s)', $htmlListCount, $totalItems),
        ];
    }

    /**
     * Check for H2/H3 subheadings in the description.
     *
     * @return array{points:float, found:int, message:string}
     */
    private function scoreHeadings(string $content): array
    {
        $count = 0;
        if (preg_match_all('/<h[23]\b/i', $content, $m)) {
            $count = count($m[0]);
        }

        if ($count >= 2) {
            $points = 15.0;
        } elseif ($count === 1) {
            $points = 8.0;
        } else {
            $points = 0.0;
        }

        return [
            'points'  => $points,
            'found'   => $count,
            'message' => sprintf('%d H2/H3 heading(s)', $count),
        ];
    }

    /**
     * Check for FAQ-style question-and-answer content.
     *
     * @return array{points:float, found:int, message:string}
     */
    private function scoreFaqContent(string $content, string $plainText): array
    {
        $qCount = 0;

        // HTML: look for FAQ schema patterns, common CSS classes, or <strong>Q: ... </strong>
        if (preg_match_all('/<(dt|summary)\b[^>]*>.*?\?/i', $content, $m)) {
            $qCount += count($m[0]);
        }

        // Headings that end with a question mark.
        if (preg_match_all('/<h[2-4][^>]*>[^<]*\?\s*<\/h[2-4]>/i', $content, $m)) {
            $qCount += count($m[0]);
        }

        // Bold Q: patterns.
        if (preg_match_all('/<(strong|b)>\s*Q[:\.]?\s*/i', $content, $m)) {
            $qCount += count($m[0]);
        }

        // Plain text lines that look like questions.
        if (preg_match_all('/^(?:Q[:\.]|FAQ|question)\s*.+\?$/mi', $plainText, $m)) {
            $qCount += count($m[0]);
        }

        // General question sentences.
        if ($qCount === 0 && preg_match_all('/(?:^|\.\s+)(?:what|how|why|when|where|which|who|can|does|is|are|do|should|will)\s.+\?/mi', $plainText, $m)) {
            $qCount += count($m[0]);
        }

        if ($qCount >= 3) {
            $points = 15.0;
        } elseif ($qCount >= 1) {
            $points = 8.0;
        } else {
            $points = 0.0;
        }

        return [
            'points'  => $points,
            'found'   => $qCount,
            'message' => sprintf('%d FAQ/question(s) detected', $qCount),
        ];
    }

    /**
     * Check whether the opening sentence is a direct factual statement (not marketing fluff).
     *
     * @return array{points:float, factual:bool, message:string}
     */
    private function scoreFactualOpening(string $plainText): array
    {
        if ($plainText === '') {
            return ['points' => 0.0, 'factual' => false, 'message' => 'No content'];
        }

        // Grab the first sentence (up to first period, question mark, or newline).
        $firstLine = (string) strtok($plainText, "\n");
        $firstLine = trim($firstLine);

        if ($firstLine === '') {
            return ['points' => 0.0, 'factual' => false, 'message' => 'Empty opening'];
        }

        // Check against fluff patterns.
        foreach (self::FLUFF_PATTERNS as $pattern) {
            if (preg_match($pattern, $firstLine)) {
                return [
                    'points'  => 0.0,
                    'factual' => false,
                    'message' => 'Opening uses marketing language',
                ];
            }
        }

        // A factual opening typically starts with "The", "This", a proper noun,
        // a number, or the product/entity name — and contains concrete nouns.
        $wordCount = str_word_count($firstLine);
        if ($wordCount < 5) {
            return [
                'points'  => 3.0,
                'factual' => false,
                'message' => 'Opening too short to evaluate',
            ];
        }

        // Award full points: opener is not fluff and has reasonable length.
        return [
            'points'  => 10.0,
            'factual' => true,
            'message' => 'Direct factual opening detected',
        ];
    }

    /**
     * Check for specific data points (dimensions, weights, materials, etc.).
     *
     * @return array{points:float, found:int, message:string}
     */
    private function scoreSpecificData(string $content): array
    {
        $matchTypes = 0;

        foreach (self::DATA_PATTERNS as $pattern) {
            if (preg_match($pattern, $content)) {
                $matchTypes++;
            }
        }

        if ($matchTypes >= 3) {
            $points = 15.0;
        } elseif ($matchTypes >= 2) {
            $points = 10.0;
        } elseif ($matchTypes >= 1) {
            $points = 5.0;
        } else {
            $points = 0.0;
        }

        return [
            'points'  => $points,
            'found'   => $matchTypes,
            'message' => sprintf('%d data type(s) detected', $matchTypes),
        ];
    }

    /**
     * Check for comparison-friendly attributes (brand, "vs", competitor mentions).
     *
     * @param array<string,mixed> $context
     * @return array{points:float, found:int, message:string}
     */
    private function scoreComparisonAttributes(array $context, string $content): array
    {
        $signals = 0;
        $signalNames = [];

        // Brand present in context.
        $brand = (string) ($context['brand'] ?? '');
        if ($brand !== '') {
            $signals++;
            $signalNames[] = 'brand';
        }

        // "vs" or "compared to" or "alternative" language in content.
        if (preg_match('/\b(vs\.?|versus|compared?\s+to|alternative\s+to|better\s+than|similar\s+to)\b/i', $content)) {
            $signals++;
            $signalNames[] = 'comparison language';
        }

        // Specification tables.
        if (preg_match('/<table\b/i', $content) && preg_match('/<t[hd]\b/i', $content)) {
            $signals++;
            $signalNames[] = 'specification table';
        }

        // Star ratings or review mentions.
        if (preg_match('/\b(\d(\.\d)?)\s*\/\s*5\b|\b\d+\s*(stars?|rating|reviews?|out\s+of\s+5)\b/i', $content)) {
            $signals++;
            $signalNames[] = 'ratings';
        }

        if ($signals >= 2) {
            $points = 10.0;
        } elseif ($signals >= 1) {
            $points = 5.0;
        } else {
            $points = 0.0;
        }

        return [
            'points'  => $points,
            'found'   => $signals,
            'message' => $signalNames !== []
                ? sprintf('Comparison signals: %s', implode(', ', $signalNames))
                : 'No comparison signals',
        ];
    }
}
