<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Audit;

class IssueCatalog
{
    private const E = Issue::SEVERITY_ERROR;
    private const W = Issue::SEVERITY_WARNING;
    private const N = Issue::SEVERITY_NOTICE;

    private const DEFINITIONS = [
        'missing_title' => [self::E, Issue::CATEGORY_META, 'Missing title tag',
            'Give the page a unique <title>: set Meta Title on the product, category or CMS page, or add a meta template.'],
        'missing_description' => [self::W, Issue::CATEGORY_META, 'Missing meta description',
            'Set Meta Description on the entity or add a meta template so every indexable page has one.'],
        'title_too_long' => [self::W, Issue::CATEGORY_META, 'Title too long',
            'Keep titles under 60 characters so search results do not truncate them.'],
        'description_too_long' => [self::N, Issue::CATEGORY_META, 'Meta description too long',
            'Keep meta descriptions under 160 characters.'],
        'missing_canonical' => [self::N, Issue::CATEGORY_META, 'No canonical URL',
            'Enable Stores > Configuration > Panth Advanced SEO > Canonical URL so every page declares its canonical.'],
        'status_404' => [self::E, Issue::CATEGORY_CRAWLABILITY, 'Internal page returns 404',
            'Restore the page, 301-redirect the URL to its replacement, or remove the links that point to it.'],
        'status_5xx' => [self::E, Issue::CATEGORY_CRAWLABILITY, 'Internal page returns 5xx',
            'Check var/log/exception.log for the error behind this URL and fix it.'],
        'fetch_errors' => [self::E, Issue::CATEGORY_CRAWLABILITY, 'Page could not be fetched',
            'The server did not answer. Check DNS, TLS and firewall rules for the crawler.'],
        'redirects' => [self::N, Issue::CATEGORY_CRAWLABILITY, 'Crawled URL redirects',
            'Link straight to the final URL instead of a redirecting one.'],
        'redirect_chains' => [self::W, Issue::CATEGORY_CRAWLABILITY, 'Redirect chain',
            'Point the first redirect straight at the final URL so there is only one hop.'],
        'structured_data_invalid' => [self::E, Issue::CATEGORY_STRUCTURED_DATA, 'Structured data invalid',
            'Remove properties the schema.org type does not define (for example datePublished and dateModified on '
            . 'Product, which is not a CreativeWork), fix invalid JSON, add @context and @type, and give every Product '
            . 'offers, review or aggregateRating. Opt-in fix: Advanced SEO > Site Audit Fixes > Strip Invalid Schema '
            . 'Properties.'],
        'broken_internal_image' => [self::E, Issue::CATEGORY_CONTENT, 'Broken internal image',
            'Re-upload the image or correct its path. For README-based descriptions, make links absolute or turn on '
            . 'Site Audit Fixes > Rewrite Relative README Links.'],
        'relative_readme_link' => [self::E, Issue::CATEGORY_LINKS, 'Relative README link',
            'A relative href such as docs/x.png resolves against the store URL and 404s. Use the absolute '
            . 'raw.githubusercontent.com URL, convert links at import time, or turn on Rewrite Relative README Links.'],
        'hreflang_conflict' => [self::E, Issue::CATEGORY_INTERNATIONAL, 'Hreflang conflict',
            'A hreflang group must hold translations of one page: one member per locale, a self-reference for the '
            . 'page locale, return links on every member, canonical 200 targets and ISO 639-1 (+ ISO 3166-1) codes. '
            . 'Use internal links, not hreflang, for topic clusters. Opt-in guard: Site Audit Fixes > Hreflang Group Guard.'],
        'duplicate_title' => [self::E, Issue::CATEGORY_META, 'Duplicate title tag',
            'Give each indexable page its own title, or consolidate pages that target the same intent.'],
        'possible_cannibalisation' => [self::N, Issue::CATEGORY_CONTENT, 'Possible keyword cannibalisation',
            'These pages share a title and near-identical H1s. Merge them with a 301, or differentiate their intent.'],
        'slow_page' => [self::E, Issue::CATEGORY_PERFORMANCE, 'Slow page load',
            'Reduce server response time: check full page cache hit rates, slow blocks and uncached layout handles, '
            . 'and keep HTML small.'],
        'large_html' => [self::W, Issue::CATEGORY_PERFORMANCE, 'Large HTML',
            'Move inline CSS/JS/SVG into cached files and trim repeated markup so the HTML stays under 300 KB.'],
        'unminified_js_css' => [self::W, Issue::CATEGORY_PERFORMANCE, 'Unminified JavaScript or CSS',
            'Set dev/js/minify_files=1 and dev/css/minify_files=1 (Stores > Configuration > Advanced > Developer) and '
            . 'redeploy static content in the same release. Hyva ships its own built, minified styles.css.'],
        'nofollow_internal_link' => [self::W, Issue::CATEGORY_LINKS, 'Nofollow internal link',
            'Remove rel="nofollow" from internal links to indexable pages so link equity flows to them.'],
        'nofollow_internal_link_allowed' => [self::N, Issue::CATEGORY_LINKS, 'Nofollow internal link (expected)',
            'Expected: cart, checkout, account, wishlist, compare, login and layered-navigation links may stay nofollow.'],
        'broken_external_link' => [self::W, Issue::CATEGORY_LINKS, 'Broken external link',
            'Update or remove links whose target returns 4xx/5xx or fails DNS/TLS. 403/405/429 from social networks '
            . 'are reported as unverifiable, not broken.'],
        'readme_anchor_link' => [self::W, Issue::CATEGORY_LINKS, 'Malformed README anchor link',
            'github.com/<org>/<repo>/raw/main/#anchor does not resolve. Use https://github.com/<org>/<repo>#anchor.'],
        'low_text_html_ratio' => [self::W, Issue::CATEGORY_CONTENT, 'Low text to HTML ratio',
            'Move large inline style, script and SVG blocks into cached files, or add more visible content.'],
        'duplicate_h1_title' => [self::W, Issue::CATEGORY_META, 'H1 duplicates the title',
            'Write a distinct <title> for search results and keep the H1 as the on-page heading. Controllers should '
            . 'only set the heading as the title when no title is set. Opt-in fix: SEO Title Wins Over H1.'],
        'multiple_h1' => [self::W, Issue::CATEGORY_CONTENT, 'Multiple H1 headings',
            'Keep one H1 per page and demote the others to H2.'],
        'missing_h1' => [self::W, Issue::CATEGORY_CONTENT, 'Missing H1 heading',
            'Add one H1 that describes the page.'],
        'nofollow_external_link' => [self::N, Issue::CATEGORY_LINKS, 'Nofollow external link',
            'Informational. Use rel="sponsored" or rel="ugc" where they describe the link better.'],
        'blocked_by_robots' => [self::N, Issue::CATEGORY_CRAWLABILITY, 'Blocked from crawling or indexing',
            'Expected for cart, checkout, customer and search pages. For any other page remove the robots.txt '
            . 'Disallow, the noindex meta, the X-Robots-Tag or the path rule (panth_robots_seo/general/noindex_paths).'],
        'query_param_noindex' => [self::N, Issue::CATEGORY_CRAWLABILITY, 'Query URL set to noindex',
            'Informational: URLs with unknown query parameters get noindex,follow, which is correct.'],
        'permanent_redirect_link' => [self::N, Issue::CATEGORY_LINKS, 'Link to a permanent redirect',
            'Link to the final URL. For CMS or URL-rewrite slugs use $block->getUrl(\'\', [\'_direct\' => \'slug\']) '
            . 'instead of $block->getUrl(\'slug\'), which appends a trailing slash that 301s.'],
        'trailing_slash_internal_link' => [self::N, Issue::CATEGORY_LINKS, 'Trailing-slash internal link',
            'Build the link with $block->getUrl(\'\', [\'_direct\' => \'slug\']) so it matches the canonical URL.'],
        'resource_as_page_link' => [self::N, Issue::CATEGORY_LINKS, 'Resource formatted as page link',
            'Do not wrap images, PDFs, CSS or JS in <a href> links unless the download is intended.'],
        'single_incoming_link' => [self::N, Issue::CATEGORY_LINKS, 'Page has one incoming internal link',
            'Link to the page from menus, related content or category pages.'],
        'orphan_in_sitemap' => [self::N, Issue::CATEGORY_CRAWLABILITY, 'Sitemap URL without incoming links',
            'Link to the page internally, or remove it from the XML sitemap if it should not rank.'],
        'duplicate_trailing_slash' => [self::W, Issue::CATEGORY_CRAWLABILITY, 'Trailing-slash duplicate',
            'Both the URL and its trailing-slash twin return 200. 301-redirect one to the other.'],
        'subdomain_no_hsts' => [self::N, Issue::CATEGORY_CRAWLABILITY, 'No HSTS header',
            'Send Strict-Transport-Security (for example max-age=31536000; includeSubDomains) from the web server or CDN.'],
        'empty_anchor_text' => [self::N, Issue::CATEGORY_LINKS, 'Link without anchor text',
            'Give the link visible text, an aria-label, a title or an image with alt text.'],
        'low_semantic_html' => [self::N, Issue::CATEGORY_CONTENT, 'Low semantic HTML',
            'Wrap content in main, article, nav, header, footer and section instead of plain div/span.'],
        'duplicate_main_landmark' => [self::N, Issue::CATEGORY_CONTENT, 'More than one main landmark',
            'Keep a single <main> (or role="main") per page.'],
        'llms_txt' => [self::N, Issue::CATEGORY_AI_SEARCH, 'llms.txt problem',
            'Serve /llms.txt as text/plain under 500 KB, with a Priority URLs section that lists pages of this store.'],
        'robots_ai_bots' => [self::W, Issue::CATEGORY_AI_SEARCH, 'AI crawlers blocked in robots.txt',
            'Allow the AI search crawlers you want to be cited by, and add a Content-Signal line '
            . '(for example Content-Signal: search=yes, ai-input=yes, ai-train=no).'],
        'robots_group_precedence' => [self::E, Issue::CATEGORY_AI_SEARCH, 'robots.txt group precedence',
            'A named User-agent group replaces the * group for that bot. Repeat the * Disallow lines and '
            . 'Content-Signal in every named group, or remove Allow-only groups.'],
        'ai_answer_readiness' => [self::N, Issue::CATEGORY_AI_SEARCH, 'AI answer readiness',
            'Add FAQ or HowTo blocks and a visible "last updated" date; type free software products as '
            . 'SoftwareApplication instead of Product with shipping and return data.'],
        'thin_duplicate_pages' => [self::W, Issue::CATEGORY_CONTENT, 'Thin page duplicated by a hub page',
            'Canonicalise the thin page to the hub, or 301 it to the hub with an anchor and drop it from the sitemap.'],
        'meta_cache_stale' => [self::W, Issue::CATEGORY_META, 'Stale resolved meta cache',
            'The rendered title differs from the resolved meta. Run bin/magento cache:clean collections full_page '
            . 'and reindex panth_seo_resolved_meta.'],
        'link_to_disabled_entity' => [self::W, Issue::CATEGORY_LINKS, 'Link to disabled page',
            'Remove or repoint the menu item, SEO crosslink or footer link that targets the disabled entity.'],
    ];

    public function __construct(private readonly array $definitions = [])
    {
    }

    public function has(string $code): bool
    {
        return isset($this->definitions[$code]) || isset(self::DEFINITIONS[$code]);
    }

    public function definition(string $code): array
    {
        $definition = $this->definitions[$code] ?? self::DEFINITIONS[$code] ?? null;
        if (!is_array($definition)) {
            return [
                'severity' => Issue::SEVERITY_NOTICE,
                'category' => Issue::CATEGORY_CONTENT,
                'title'    => ucfirst(str_replace('_', ' ', $code)),
                'fix_hint' => '',
            ];
        }
        if (array_is_list($definition)) {
            return [
                'severity' => (string) $definition[0],
                'category' => (string) $definition[1],
                'title'    => (string) $definition[2],
                'fix_hint' => (string) ($definition[3] ?? ''),
            ];
        }

        return [
            'severity' => (string) ($definition['severity'] ?? Issue::SEVERITY_NOTICE),
            'category' => (string) ($definition['category'] ?? Issue::CATEGORY_CONTENT),
            'title'    => (string) ($definition['title'] ?? $code),
            'fix_hint' => (string) ($definition['fix_hint'] ?? ''),
        ];
    }

    public function codes(): array
    {
        return array_values(array_unique(array_merge(array_keys(self::DEFINITIONS), array_keys($this->definitions))));
    }

    public function title(string $code): string
    {
        return $this->definition($code)['title'];
    }

    public function fixHint(string $code): string
    {
        return $this->definition($code)['fix_hint'];
    }

    public function create(
        string $code,
        string $url,
        string $element = '',
        string $detail = '',
        ?string $severity = null
    ): Issue {
        $definition = $this->definition($code);

        return new Issue(
            $code,
            $severity ?? $definition['severity'],
            $definition['category'],
            $url,
            $element,
            $detail,
            $definition['fix_hint']
        );
    }
}
