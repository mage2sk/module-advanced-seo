# Panth_AdvancedSEO

Enterprise-grade SEO module for Magento 2 (Adobe Commerce).

## Features

- **Meta templates** — Smarty-lite variable tokens (`{name}`, `{price}`, `{sku}`, `{category}`, `{store}`, `{attribute:X}`) for product, category, and CMS pages with per-entity overrides and bulk editor
- **Canonical URL resolver** — query-parameter stripping, pagination awareness, layered-navigation handling
- **Robots & LLM bot control** — dynamic database-driven robots.txt with per-bot allow/deny for GPTBot, ClaudeBot, Google-Extended, CCBot, PerplexityBot, Bytespider
- **Hreflang** — group management with auto-binding by SKU/URL key, x-default, and reciprocity validation
- **Redirects** — literal + regex matching, 301/302/503 support, loop detection, bulk CSV import, auto-suggestion engine
- **Structured data (JSON-LD)** — Product, Breadcrumb, Organization, WebSite, FAQPage, Article, Video providers with schema.org validation
- **Social meta** — OpenGraph and Twitter Card tags with per-entity overrides and fallback images
- **SEO rules engine** — condition-combine tree (attributes, stock, price, category, URL) with template, canonical, and noindex actions
- **SEO scoring & audit** — 0-100 score with length, duplicate, readability, entity, and keyword checks; dashboard with trends
- **AI content generation** — OpenAI and Claude adapters with monthly budget control, caching, and generation job tracking
- **XML sitemaps** — sharded with image and hreflang extensions, delta tracking
- **HTML sitemap** — paginated, noindex-aware, Hyva and Luma compatible
- **Image SEO** — alt-text templates, optional vision adapter for AI-generated alt text
- **Cross-linking** — internal link graph, PageRank calculator, contextual link suggestions
- **Filter URL control** — noindex/nofollow for layered navigation, canonical-to-parent, indexable filter whitelist
- **404 management** — logging with clustering, auto-suggest redirects, bulk redirect creation
- **IndexNow** — instant search engine notification on content changes (Bing, Yandex, Seznam, Naver)
- **llms.txt** — dynamic endpoint for LLM site information
- **Product feeds** — Google Shopping, Facebook Catalog, CSV export with cron generation
- **Analytics** — GA4 and Matomo integration for SEO event tracking
- **Indexers** — `panth_seo_resolved_meta` and `panth_seo_hreflang` with mview support

## Installation

### Composer (recommended)

```bash
composer require mage2kishan/module-advanced-seo
bin/magento module:enable Panth_Core Panth_AdvancedSEO
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento indexer:reindex panth_seo_resolved_meta panth_seo_hreflang
bin/magento cache:flush
```

### Manual zip

1. Download the extension package from the Adobe Commerce Marketplace
2. Extract to `app/code/Panth/AdvancedSEO`
3. Ensure `app/code/Panth/Core` is also present (required dependency)
4. Run the same `module:enable ... cache:flush` commands above

### Confirm

```bash
bin/magento module:status Panth_AdvancedSEO
# Module is enabled
```

## Hyva compatibility

The module is Hyva-first:

- No `requirejs-config.js` in `view/frontend/`
- No jQuery widgets or `mage/*` JS dependencies in frontend templates
- Output is server-rendered meta / JSON-LD injected via layout XML

A `view/frontend/hyva.xml` marker is shipped so that `hyva-themes/hyva-compat`
recognizes the module as compatible out of the box. The module also works on
the stock Luma theme without modification.

## Requirements

- PHP 8.1+
- Magento 2.4.4+
- Panth_Core (`mage2kishan/module-core`) ^1.0

## Support

For all questions, bug reports, or feature requests:

- **Email:** kishansavaliyakb@gmail.com
- **Website:** https://kishansavaliya.com
- **WhatsApp:** +91 84012 70422

## License

Proprietary — see [LICENSE.txt](LICENSE.txt) for full terms.
