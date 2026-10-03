<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

class PageParser
{
    private const MAIN_TEXT_LIMIT = 20000;

    private const NON_RENDERED = ['script', 'style', 'template', 'svg', 'noscript', 'head'];

    private const LANDMARKS = ['main', 'article', 'nav', 'header', 'footer', 'section'];

    private const NON_SEMANTIC = ['div', 'span', 'font', 'b', 'i', 'u', 'small', 'center'];

    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    public function parse(
        string $url,
        int $status,
        array $headers,
        string $body,
        float $ttfbMs = 0.0,
        float $totalMs = 0.0
    ): ParsedPage {
        $headers = $this->normalizeHeaders($headers);
        $xRobots = (string) ($headers['x-robots-tag'] ?? '');

        $dom = $this->looksLikeHtml($headers, $body) ? $this->load($body) : null;
        if ($dom === null) {
            return new ParsedPage(
                url: $url,
                status: $status,
                headers: $headers,
                ttfbMs: $ttfbMs,
                totalMs: $totalMs,
                htmlBytes: strlen($body),
                xRobotsTag: $xRobots
            );
        }

        $xpath = new \DOMXPath($dom);
        $host  = UrlHelper::host($url);
        $base  = $this->baseHref($xpath, $url);

        [$nodes, $blocks] = $this->jsonLd($xpath);
        $walk = $this->walkBody($dom);

        return new ParsedPage(
            url: $url,
            status: $status,
            headers: $headers,
            ttfbMs: $ttfbMs,
            totalMs: $totalMs,
            htmlBytes: strlen($body),
            title: $this->firstText($xpath, '(//title[not(ancestor::svg)])[1]'),
            metaDescription: $this->metaContent($xpath, 'description'),
            h1: $this->texts($xpath, '//h1[not(ancestor::template)][not(ancestor::noscript)]'),
            canonical: $this->canonical($xpath, $base),
            robotsMeta: $this->metaContent($xpath, 'robots'),
            xRobotsTag: $xRobots,
            hreflang: $this->hreflang($xpath, $base),
            links: $this->links($xpath, $base, $host),
            images: $this->images($xpath, $base),
            jsonLd: $nodes,
            scripts: $this->resourceUrls($xpath, '//script[@src]', 'src', $base),
            styles: $this->resourceUrls($xpath, $this->relXpath('link', 'stylesheet') . '[@href]', 'href', $base),
            textBytes: strlen($walk['text']),
            jsonLdBlocks: $blocks,
            inlineBytes: $this->inlineBytes($dom, $xpath),
            landmarks: $walk['landmarks'],
            divSpanTextShare: $walk['total'] > 0 ? $walk['divspan'] / $walk['total'] : 0.0,
            hasVisibleDate: $this->hasVisibleDate($xpath, $walk['text']),
            hasFaqOrHowTo: $this->hasFaqOrHowTo($xpath, $nodes),
            mainText: $this->mainText($xpath, $walk['text'])
        );
    }

    public function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            if (is_array($value)) {
                $value = strtolower($name) === 'x-robots-tag' ? implode(', ', $value) : (string) end($value);
            }
            $out[strtolower(trim($name))] = trim((string) $value);
        }

        return $out;
    }

    private function looksLikeHtml(array $headers, string $body): bool
    {
        if ($body === '') {
            return false;
        }
        $type = (string) ($headers['content-type'] ?? '');
        if ($type !== '' && stripos($type, 'html') === false) {
            return false;
        }

        return stripos(substr($body, 0, 4096), '<') !== false;
    }

    private function load(string $body): ?\DOMDocument
    {
        $dom      = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadHTML(
                '<?xml encoding="UTF-8"?>' . $body,
                LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE
            );
        } catch (\Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? $dom : null;
    }

    private function lower(string $expr): string
    {
        return "translate(" . $expr . ", '" . self::UPPER . "', '" . self::LOWER . "')";
    }

    private function relXpath(string $tag, string $token): string
    {
        return '//' . $tag . "[contains(concat(' ', " . $this->lower('normalize-space(@rel)') . ", ' '), ' "
            . $token . " ')]";
    }

    private function baseHref(\DOMXPath $xpath, string $url): string
    {
        $href = $this->firstAttr($xpath, '(//base[@href])[1]', 'href');
        if ($href === '') {
            return $url;
        }

        return UrlHelper::resolve($href, $url) ?? $url;
    }

    private function firstAttr(\DOMXPath $xpath, string $query, string $attr): string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $node = $nodes->item(0);

        return $node instanceof \DOMElement ? trim($node->getAttribute($attr)) : '';
    }

    private function firstText(\DOMXPath $xpath, string $query): string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        return $this->clean((string) $nodes->item(0)?->textContent);
    }

    private function texts(\DOMXPath $xpath, string $query): array
    {
        $out   = [];
        $nodes = $xpath->query($query);
        if ($nodes === false) {
            return [];
        }
        foreach ($nodes as $node) {
            $out[] = $this->visibleText($node);
        }

        return $out;
    }

    private function metaContent(\DOMXPath $xpath, string $name): string
    {
        return $this->clean(html_entity_decode(
            $this->firstAttr($xpath, '(//meta[' . $this->lower('@name') . "='" . $name . "'])[1]", 'content'),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ));
    }

    private function canonical(\DOMXPath $xpath, string $base): string
    {
        $href = $this->firstAttr($xpath, '(' . $this->relXpath('link', 'canonical') . ')[1]', 'href');
        if ($href === '') {
            return '';
        }

        return UrlHelper::resolve($href, $base) ?? $href;
    }

    private function hreflang(\DOMXPath $xpath, string $base): array
    {
        $out   = [];
        $nodes = $xpath->query($this->relXpath('link', 'alternate') . '[@hreflang][@href]');
        if ($nodes === false) {
            return [];
        }
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $raw = trim($node->getAttribute('href'));
            $out[] = [
                'locale' => trim($node->getAttribute('hreflang')),
                'href'   => UrlHelper::resolve($raw, $base) ?? $raw,
            ];
        }

        return $out;
    }

    private function links(\DOMXPath $xpath, string $base, string $host): array
    {
        $out   = [];
        $nodes = $xpath->query('//a[@href][not(ancestor::template)][not(ancestor::noscript)]');
        if ($nodes === false) {
            return [];
        }
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $raw      = trim($node->getAttribute('href'));
            $resolved = UrlHelper::resolve($raw, $base);
            if ($resolved === null) {
                continue;
            }
            $alts = [];
            foreach ($node->getElementsByTagName('img') as $img) {
                $alt = trim($img->getAttribute('alt'));
                if ($alt !== '') {
                    $alts[] = $alt;
                }
            }
            $out[] = [
                'href'        => $resolved,
                'rawHref'     => $raw,
                'rel'         => strtolower($this->clean($node->getAttribute('rel'))),
                'anchorText'  => $this->visibleText($node),
                'isInternal'  => UrlHelper::sameHost($resolved, $host),
                'ariaLabel'   => $this->clean($node->getAttribute('aria-label')),
                'titleAttr'   => $this->clean($node->getAttribute('title')),
                'imgAlt'      => implode(' ', $alts),
                'hasImage'    => $node->getElementsByTagName('img')->length > 0
                    || $node->getElementsByTagName('svg')->length > 0,
                'inFilterNav' => $this->inFilterNav($node),
            ];
        }

        return $out;
    }

    private function inFilterNav(\DOMElement $node): bool
    {
        for ($parent = $node; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
            $marker = $parent->getAttribute('class') . ' ' . $parent->getAttribute('id');
            if (preg_match('/(filter|layered|facet|swatch)/i', $marker) === 1) {
                return true;
            }
        }

        return false;
    }

    private function images(\DOMXPath $xpath, string $base): array
    {
        $out   = [];
        $nodes = $xpath->query('//img[not(ancestor::template)][not(ancestor::noscript)]');
        if ($nodes === false) {
            return [];
        }
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $rawSrc  = trim($node->getAttribute('src'));
            $dataSrc = trim($node->getAttribute('data-src'));
            $srcset  = [];
            foreach (explode(',', $node->getAttribute('srcset')) as $candidate) {
                $candidateUrl = trim((string) preg_split('/\s+/', trim($candidate))[0]);
                $resolved     = $candidateUrl !== '' ? UrlHelper::resolve($candidateUrl, $base) : null;
                if ($resolved !== null) {
                    $srcset[] = $resolved;
                }
            }
            $linkRaw = '';
            for ($parent = $node->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
                if (strtolower($parent->nodeName) === 'a' && $parent->hasAttribute('href')) {
                    $linkRaw = trim($parent->getAttribute('href'));
                    break;
                }
            }
            $out[] = [
                'src'         => $rawSrc !== '' && !str_starts_with($rawSrc, 'data:')
                    ? (UrlHelper::resolve($rawSrc, $base) ?? '') : '',
                'rawSrc'      => $rawSrc,
                'srcset'      => array_values(array_unique($srcset)),
                'dataSrc'     => $dataSrc !== '' ? (UrlHelper::resolve($dataSrc, $base) ?? '') : '',
                'alt'         => $node->hasAttribute('alt') ? trim($node->getAttribute('alt')) : null,
                'linkHref'    => $linkRaw !== '' ? (UrlHelper::resolve($linkRaw, $base) ?? '') : '',
                'rawLinkHref' => $linkRaw,
            ];
        }

        return $out;
    }

    private function resourceUrls(\DOMXPath $xpath, string $query, string $attr, string $base): array
    {
        $out   = [];
        $nodes = $xpath->query($query);
        if ($nodes === false) {
            return [];
        }
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement || $node->parentNode === null) {
                continue;
            }
            $resolved = UrlHelper::resolve($node->getAttribute($attr), $base);
            if ($resolved !== null) {
                $out[] = $resolved;
            }
        }

        return array_values(array_unique($out));
    }

    private function jsonLd(\DOMXPath $xpath): array
    {
        $nodes  = [];
        $blocks = [];
        $query  = $xpath->query('//script[' . $this->lower('normalize-space(@type)') . "='application/ld+json']");
        if ($query === false) {
            return [[], []];
        }
        foreach ($query as $script) {
            $raw = trim((string) $script->textContent);
            if ($raw === '') {
                continue;
            }
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\JsonException $e) {
                $blocks[] = ['valid' => false, 'hasContext' => false, 'error' => $e->getMessage(), 'raw' => substr($raw, 0, 200)];
                continue;
            }
            if (!is_array($decoded)) {
                $blocks[] = ['valid' => false, 'hasContext' => false, 'error' => 'JSON-LD is not an object', 'raw' => substr($raw, 0, 200)];
                continue;
            }
            $documents = array_is_list($decoded) ? $decoded : [$decoded];
            foreach ($documents as $document) {
                if (!is_array($document)) {
                    continue;
                }
                $hasContext = isset($document['@context']);
                $graph      = isset($document['@graph']) && is_array($document['@graph'])
                    ? (array_is_list($document['@graph']) ? $document['@graph'] : [$document['@graph']])
                    : [$document];
                $types = [];
                foreach ($graph as $node) {
                    if (!is_array($node)) {
                        continue;
                    }
                    unset($node['@context']);
                    $nodes[] = $node;
                    $types[] = $node['@type'] ?? null;
                }
                $blocks[] = ['valid' => true, 'hasContext' => $hasContext, 'error' => '', 'types' => $types];
            }
        }

        return [$nodes, $blocks];
    }

    private function inlineBytes(\DOMDocument $dom, \DOMXPath $xpath): array
    {
        $bytes = ['style' => 0, 'script' => 0, 'svg' => 0];
        foreach (['style' => '//style', 'script' => '//script[not(@src)]'] as $key => $query) {
            $nodes = $xpath->query($query);
            foreach ($nodes === false ? [] : $nodes as $node) {
                $bytes[$key] += strlen((string) $node->textContent);
            }
        }
        $svgs = $xpath->query('//svg[not(ancestor::svg)]');
        foreach ($svgs === false ? [] : $svgs as $svg) {
            $bytes['svg'] += strlen((string) $dom->saveHTML($svg));
        }

        return $bytes;
    }

    private function walkBody(\DOMDocument $dom): array
    {
        $acc = [
            'text'      => '',
            'total'     => 0,
            'divspan'   => 0,
            'landmarks' => array_fill_keys(self::LANDMARKS, 0),
        ];
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body !== null) {
            $parts = [];
            $this->walk($body, false, $acc, $parts);
            $acc['text'] = $this->clean(implode(' ', $parts));
        }

        return $acc;
    }

    private function walk(\DOMNode $node, bool $semantic, array &$acc, array &$parts): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text = $this->clean($child->textContent);
                if ($text === '') {
                    continue;
                }
                $parts[]       = $text;
                $bytes         = strlen($text);
                $acc['total'] += $bytes;
                if (!$semantic) {
                    $acc['divspan'] += $bytes;
                }
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $name = strtolower($child->nodeName);
            if (in_array($name, self::NON_RENDERED, true)) {
                continue;
            }
            if (in_array($name, self::LANDMARKS, true)) {
                $acc['landmarks'][$name]++;
            } elseif (strtolower($child->getAttribute('role')) === 'main') {
                $acc['landmarks']['main']++;
            }
            $this->walk($child, $semantic || !in_array($name, self::NON_SEMANTIC, true), $acc, $parts);
        }
    }

    private function visibleText(\DOMNode $node): string
    {
        $acc   = ['text' => '', 'total' => 0, 'divspan' => 0, 'landmarks' => array_fill_keys(self::LANDMARKS, 0)];
        $parts = [];
        $this->walk($node, true, $acc, $parts);

        return $this->clean(implode(' ', $parts));
    }

    private function mainText(\DOMXPath $xpath, string $bodyText): string
    {
        $main = $xpath->query('(//main[not(ancestor::template)])[1]');
        $text = $main !== false && $main->length > 0 && $main->item(0) !== null
            ? $this->visibleText($main->item(0))
            : $bodyText;

        return mb_substr($text, 0, self::MAIN_TEXT_LIMIT);
    }

    private function hasVisibleDate(\DOMXPath $xpath, string $text): bool
    {
        $time = $xpath->query('//time[not(ancestor::template)][not(ancestor::noscript)]');
        if ($time !== false && $time->length > 0) {
            return true;
        }

        return preg_match(
            '/\b(last\s+updated|updated\s+on|updated:|last\s+modified|published\s+on|date\s+modified)\b/i',
            $text
        ) === 1;
    }

    private function hasFaqOrHowTo(\DOMXPath $xpath, array $nodes): bool
    {
        foreach ($nodes as $node) {
            $types = (array) ($node['@type'] ?? []);
            if (array_intersect($types, ['FAQPage', 'HowTo', 'QAPage']) !== []) {
                return true;
            }
        }
        $micro = $xpath->query("//*[contains(@itemtype, 'FAQPage') or contains(@itemtype, 'HowTo')]");
        if ($micro !== false && $micro->length > 0) {
            return true;
        }
        foreach ($this->texts($xpath, '//h2 | //h3') as $heading) {
            if (preg_match('/\b(faq|frequently asked|how to)\b/i', $heading) === 1) {
                return true;
            }
        }

        return false;
    }

    private function clean(string $text): string
    {
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', $text);

        return trim(is_string($text) ? $text : '');
    }
}
