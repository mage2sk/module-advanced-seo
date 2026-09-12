# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.6.0] - 2026-09-12

### Changed
- **"Run Crawl" in the admin no longer blocks the request.** It ran the whole crawl inline, so at the default depth of 100 pages the admin waited roughly a minute and a proxy or `max_execution_time` could kill it part way through, leaving a half-written grid and no message. The button now queues the crawl and a dedicated cron job runs it in its own process, while the page shows live progress (pages done, pages queued) and refreshes itself when the crawl finishes. A **Stop Crawl** button cancels a queued or running crawl; the pages already crawled stay in the grid.
  - Where Magento cron has not run in the last hour the button cannot queue anything, so it falls back to crawling **up to 25 pages inside the request** and says so, naming the CLI command for a full crawl. No silent truncation.
  - Crawl work moved into a shared `CrawlRunner`, so the admin, the daily cron and `bin/magento panth:seo:crawl` all run, persist and report the same way.
  - The crawl jobs run in their own `panth_advancedseo` cron group with `use_separate_process`, so a long crawl no longer occupies the `default` group behind every other Magento job.
- **The Crawl Audit page is now per store view.** The page crawled whatever store the admin session resolved to and then showed totals summed over *every* store, so a two-store site showed one store's crawl mixed into the other's summary. There is a store view selector, and the crawl, the summary, the low-scoring table and the duplicate table all follow it.
- **`panth:seo:crawl` defaults to the configured Crawl Depth** instead of a hardcoded 100, so the CLI and the scheduled crawl audit agree. `--limit` still overrides it.

### Fixed
- **A failed crawl wiped the previous results.** Persistence deleted the store's rows and then inserted the new ones, so a crawl that reached nothing (an unreachable host, a cancelled run) left the Crawl Results grid empty and the earlier audit gone. Writes now happen in one transaction and an empty result set is never written at all.
- **Two crawls of the same store could run at once** - cron, CLI and the admin button knew nothing about each other. Each store now has a single crawl state: the admin button and the scheduled audit stand down while a crawl is in flight, and the CLI says so and skips (`--force` overrides).
- **An admin-triggered crawl lost its redirect findings.** The controller called the issue detector without the crawler's redirect map, so a `301` crawled from the admin was stored with no destination and no redirect-chain check, while the same crawl from the CLI reported both. All three entry points share one code path now.
- **A crawl killed mid-run left the page claiming it was still running.** The worker heartbeats while it crawls; a run with no progress for 15 minutes is reported as stopped instead of hanging there forever.

### Added
- `bin/magento panth:seo:crawl --force` to crawl even when a crawl is already queued or running for that store.

## [1.5.2] - 2026-09-12

### Fixed
- **The SEO Audit table showed wrong and inconsistent grades.** Rows scored before 1.5.0 kept the grade that version wrote, including the retired `E`, so the table showed score 50 as `E` on one row and `F` on another, and grades that no longer exist on the A-F scale. Grading moved into a single `GradeCalculator`, the audit table now derives the grade from the score instead of trusting the stored column, and a data patch rewrites any stored grade that disagrees with its score. `Scorer` uses the same calculator, so there is one definition of the scale.

### Note on the Crawl Results grid
The empty-cell rendering reported against 1.5.0 and listed as a known issue in 1.5.1 **is not a defect**. It was a measurement artifact: `querySelectorAll('td')` returns 0 while the grid is still binding, and these grids take 20-30 seconds to finish rendering in developer mode. Verified with data present: 10 rows, 80 cells, every column populated, including the new issue labels.

## [1.5.1] - 2026-09-12

Follow-up to the live-store verification of 1.5.0.

### Fixed
- **`tel:` links were crawled as pages.** A header phone link was resolved against the base URL and fetched as `https://<store>/tel:01234567890`, producing a phantom 404 in every report, and re-resolved relative to other directories for more of the same. The check only skipped `#`, `javascript:` and `mailto:`. Any href whose scheme is not `http` or `https` is now rejected, which also covers `sms:`, `callto:`, `whatsapp:`, `skype:`, `ftp:` and `data:` URIs. Scheme detection no longer relies on `parse_url()`, which reads `sms:12345` as a host and port rather than a scheme.
- **Cloudflare's `/cdn-cgi/l/email-protection` link was crawled** and reported as a 404. `/cdn-cgi/*` is now in the default Crawl Exclude Paths.
- **Faceted and other `noindex` URLs inflated the issue counts.** On a live catalogue a 500-page crawl reported 337 missing canonicals and 382 duplicate titles, nearly all of them filter URLs the storefront already marks `noindex`. A page whose own robots meta says `noindex` is now counted in its own `noindex_pages` bucket, skipped for content checks, and excluded from duplicate-title matching, so the findings that matter are no longer buried.
- **The unreachable-host guard only checked the first URL.** If the host stopped answering part way through a run, every remaining URL paid a full 10 second timeout — roughly 17 minutes at the default depth. The crawl now stops after five consecutive unreachable responses and logs why.
- **The Crawl Results grid showed an issue count, not the issues.** `issues_json` already held readable text but the column rendered only a number, so a merchant saw `1` with no way to learn what it was. The column now renders the issue labels, with the remainder in the hover tooltip.
- **`Crawl Depth` was default-scope only** while `Enable Crawl Audit` was store-view scoped, so a store view could switch the audit on but its depth field simply vanished. Both are store-view scoped now.

### Added
- **`Verify TLS Certificate While Crawling`**, default Yes. Certificate verification was disabled unconditionally, which a security review will flag. Turn it off only for a local or staging site with a self-signed certificate.

## [1.5.0] - 2026-09-12

### Fixed
- **`panth:seo:crawl` never saved anything.** The CLI printed its findings and threw them away, so the Crawl Results grid still showed the last cron run, or nothing at all. A CLI run and the grid could disagree completely. The command now writes its results the same way the cron does, replacing the previous run for that store, and reports how many rows it saved. Pass `--dry-run` to print without saving.
- **A redirect was recorded as a healthy page.** The crawler followed redirects internally, so a URL that answered `301` was stored against the original URL with the final page's `200` status, title and canonical. The audit could not tell you a single URL redirected. Redirects are now recorded as redirects, with the target, and the destination is queued so the crawl still reaches the content behind them.
- **Redirect chains were never detected.** `IssueDetector::detectRedirectChains()` existed, was documented in the admin as something the audit checks, and was never called by anything. It now runs, and a chain longer than two hops is reported against the URL that starts it.
- **Paginated category URLs ate the crawl budget.** `?p=2`, `?p=3` and so on were followed while other query URLs were skipped, so a single deep category could consume most of the page limit on repeats of one template. Any URL with a query string is now skipped by default; `Follow Filtered And Sorted URLs` brings them all back.

### Fixed - SEO score accuracy
The score did not describe the page a visitor or a crawler actually gets, and several checks could not be satisfied, so healthy pages graded F.

- **The score now reads the meta the storefront actually serves.** It read raw `meta_title` and `meta_description` straight off the entity and ignored this module's own resolver, so a product whose title comes from a template, a rule or a fallback scored as if it had no title at all. On the test catalogue this alone moved products from 29-32 to 54-58.
- **A page with no meta scored zero for duplication.** The duplicate check reported "cannot evaluate" and then returned 0 out of 100 at the heaviest weight in the system, punishing a page twice for one missing field. A check that cannot evaluate is now excluded from the average instead of scored as a failure.
- **Duplicate scoring was a straight line from raw similarity**, so every product in a catalogue lost points simply for resembling other products. Similarity up to 0.7 now scores full marks and only falls away as it approaches the 0.9 duplicate threshold.
- **Missing meta keywords cost 30 points.** Search engines have ignored that tag since 2009; the check pushed merchants toward it. Absence is no longer scored at all. Keywords that a merchant has set are still evaluated.
- **The brand attribute was read from a data key that does not exist** on a standard install, so every product permanently reported "Missing: brand". It now uses the configured Brand Attribute, the same one the Merchant feed and structured data use.
- **Non-English stores were scored with English-only tools.** Word counting used `str_word_count`, which counts nothing outside ASCII, so keyword density came out as a meaningless number and the AI Overview passage checks scored zero. Readability applied Flesch Reading Ease, which is defined for English, to any language. Word counting is now multibyte-aware, and readability is skipped with an explanation for non-Latin content and for text too short to measure.
- **The AI Overview check could never score well on a product page.** It looks for 134-167 word passages, FAQ blocks and headings, which belong to long-form content, and it was weighted into every product's score. It is now skipped, with a message saying why, when there are fewer than 120 words of body content.
- **The scorer emitted grade `E`,** which `SeoScorerInterface` does not define. The scale is now A, B, C, D, F as declared.
- **The issues list was always empty.** Every check scoring below 60 is now reported there with its message.

A deliberately well-optimised product page scored 91 before these changes and 99 after; the point is that the number now moves for the right reasons.

## [1.4.3] - 2026-09-12

### Fixed
- **1.4.2 broke every `bin/magento` command.** The `Follow Filtered And Sorted URLs` comment added in 1.4.2 contained a raw `<code>` tag outside a CDATA block, which Magento's `system.xsd` rejects with `Element 'code': This element is not expected`. Because the config structure is read during console bootstrap, the CLI aborted before running any command and the admin configuration section could not be opened. The comment is now wrapped in CDATA. Anyone on 1.4.2 should upgrade immediately.

## [1.4.2] - 2026-09-09

### Fixed
- **The crawl audit spent most of its page budget on pages that can never rank.** The link extractor skipped `rel="nofollow"`, off-host links and assets, but nothing else, so it followed the storefront's own links into `/customer/account/*`, `/checkout/*` and every layered-navigation permutation. On a Luma-style theme the login link carries a base64 referer in the path, so **every crawled page produced a different `/customer/account/login/referer/<hash>/` URL** and the queue filled with them. Against a real storefront the crawler now queues 49% fewer URLs on the first three pages, and all of the ones it dropped were noindex by design.

### Added
- **`Crawl Exclude Paths`** under `Stores > Configuration > Panth Extensions > Advanced SEO > Reports & Diagnostics`. One path per line, `*` wildcards supported, pre-filled with the customer, checkout, wishlist, compare, search, newsletter and payment paths. Clear the field to crawl everything as before.
- **`Follow Filtered And Sorted URLs`**, default No. Layered navigation, sorting and page-size URLs multiply without bound and are noindex by design, so they are skipped. Paging (`?p=`) is always followed.

## [1.4.1] - 2026-09-09

### Fixed
- **Applying a meta template overwrote every hand-written meta title and description.** `Force Template Over Existing Meta` is off by default and the live meta resolver honours it, but the Apply Now button and the cron applier both ignored it and wrote the rendered pattern over whatever the merchant had typed, across the whole catalogue, with no undo. Both paths now skip products and categories that already carry a value unless the setting is on.
- **Applying a template wrote every value into the All Store Views scope**, whatever the template's own Store View was set to. A template scoped to one store view silently replaced the meta of every other store view. The rendered values are now written in the scope the template targets.
- **`{{store.name}}` and the other store tokens always rendered with the first store view.** An All Store Views template therefore stamped the first store's name onto every store. A global template whose patterns use a store token is now rendered once per store view and stored per store view; templates without store tokens keep writing a single default-scope value as before.
- **Applied templates wrote their pre-computed rows against store 0**, which the frontend resolver never reads (it looks up a real store view id), so the rows were dead weight and the pages fell back to a live render. Rows are now written per store view. Rows where every field ended up empty are no longer written at all.
- **`Last Applied At` and `Apply Count` were never updated**, so both columns in the Meta Templates grid stayed blank and 0 no matter how often a template was applied.
- **Saving or deleting an SEO Rule or a Meta Template had no effect until a full manual reindex.** The admin explains that rules are evaluated on every page load with no Apply step; in practice the pre-computed meta index and the one-hour rule cache were never invalidated, so nothing changed. Rules and overrides now take effect on the next page load, and the resolved-meta indexer plus the rule cache are invalidated on save, delete and apply.
- **The Google Merchant feed never emitted `brand`, `gtin` or `mpn`.** The helper still read the `panth_seo/structured_data/...` paths, which no admin field has written since structured data moved to its own module, so every item shipped with `identifier_exists=false`. The three attribute settings, and Default Brand Name, now read the Structured Data section and fall back to the legacy path for stores that still carry the old rows.
- **Every feed item declared the Google product category `Apparel & Accessories > Jewelry`.** That value was a hardcoded fallback used whenever no category attribute is configured, which is the default. The element is now omitted when it cannot be resolved, and Merchant Center assigns the category itself.
- **The SEO Dashboard linked to three admin routes that no longer exist** (`panth_seo/hreflang/index`, `panth_seo/filterrewrite/index`, `panth_seo/sitemap/index`). They now point at Hreflang, Filter SEO and XML Sitemap, and the cards are hidden when the module that owns them is not installed.
- **The dashboard's feed status always read "0 total products" and never showed a last-generated date.** It queried a `generated_at` column that does not exist; the column is `last_generated_at`. The failing query also aborted the sitemap and feed statistics that ran in the same try block, so those are now independent.
- **The admin menu asked for `Panth_AdvancedSEO::manage` on every entry** while the controllers check granular resources, so a role granted only, say, Meta Templates saw an empty menu. Each menu entry now declares the resource its controller actually checks, and the missing `Product Feeds` and `Bulk Meta Editor` ACL resources have been added.
- A rule action key outside the known set raised an undefined array key warning on every page load that matched the rule.

### Added
- **Store view scope for the Bulk Meta Editor and the Missing Meta report.** Both tools read and wrote default-scope values only, with nothing in the UI to say so, which made them unusable on a multi store install: a merchant who fills meta per store view saw the whole catalogue reported as missing. Both screens now carry a scope switcher, read the selected store view, and the inline editor saves in that scope. The default-scope option now genuinely reads the default scope rather than falling through to the first store view.

### Removed
- 66 configuration getters that read `panth_seo/breadcrumbs`, `filter_meta`, `filter_urls`, `hreflang`, `image`, `indexnow`, `llms_txt`, `organization`, `social`, `social_profiles` and `structured_data` paths. Those settings moved to their own modules when this one was split, and nothing in the module had called the getters since; the paths have had neither an admin field nor a default here for several releases.

## [1.3.20] - 2026-09-09

### Fixed
- **Pages owned by another module carried two canonical tags.** Modules that register their own canonical on the page config, such as the blog listing, post, category, tag, author and search pages, plus dynamic form and FAQ pages, ended up with their tag and this module's tag on the same page. Two conflicting canonicals are usually ignored altogether by search engines, so those pages had no effective canonical at all. The view model already carried a `hasCanonicalInPageConfig()` check for exactly this, but nothing ever called it. It is now consulted first, and the owning module's tag is left alone. The suppression is reported as `already_in_page_config` in the debug log. Product, category, CMS and home pages are unaffected.

## [1.3.19] - 2026-09-09

### Fixed
- **The resolved-meta indexer never wrote a debug line.** `etc/di.xml` bound its config argument as `config`, but the constructor parameter is `$seoConfig`. Magento matches di.xml arguments by parameter name, so the binding was ignored and the nullable argument stayed `null`, which made the indexer's `debug()` return before doing anything. With the name corrected a full reindex now writes the trace it was always meant to. Same trap as the canonical view model in 1.3.18, one level up.

### Added
- A unit test walks every `<argument>` in the module's `di.xml` files and fails if its name is not a real constructor parameter of the target class, so a binding can no longer be silently ignored.

## [1.3.18] - 2026-09-09

### Added
- **A suppressed canonical now says why.** When no `<link rel="canonical">` is emitted, the reason is written to the module debug log as `panth_seo: canonical.suppressed` with a `decision` key. Until now an intentional suppression and a thrown exception were byte-for-byte identical from outside, and because this module also suppresses Magento's native canonical tag, a page could end up with no canonical from any source and no trace anywhere. Decisions reported: `noindex_page`, `ignored_path`, `noroute_noindex`, `canonical_disabled`, `no_url_built`, `build_failed`, `exception` and `is_enabled_threw`. An exception also carries its message, class, file and line, and the request URI.
- The reasons are reported from both places that can decide it: `Model\Canonical\Resolver`, which is what runs for a product, category or CMS page, and `ViewModel\Canonical`, which runs for everything else. The resolver previously logged only its successful decisions.
- Logging is behind the existing **Debug Logging** setting, so nothing is written on a production store with debug off. Emitted canonicals are unchanged in every case; this release adds no output and removes none.

### Fixed
- `ViewModel\Canonical` discarded every exception, so a failure anywhere inside it, a bad store, a broken custom-canonical row, a repository error, silently became "no canonical on this page". The exception is now logged before the empty string is returned. The same applied to `isEnabled()`, which turned a config read failure into canonicals being off sitewide with no trace.
- `etc/di.xml` binds the debug logger into `ViewModel\Canonical`. Magento DI passes `null` for a nullable optional argument unless it is bound explicitly, so without this entry the new logging would never fire.

## [1.3.17] - 2026-09-09

Replaces 1.3.16, which was withdrawn. The code is identical. The sample strings
used by the new unit tests have been replaced with neutral catalogue text.

### Fixed
- **Meta titles and descriptions no longer cut a word in half.** Truncation kept a hard character count, so a title could end `... with Vacu...`. It now steps back to the last space, and only when that space keeps at least 60 per cent of the available room, so a long unbroken string still truncates instead of collapsing to a fragment. Trailing spaces and punctuation are trimmed before the ellipsis.
- **Multibyte meta was truncated by bytes and could be cut to a third of its length.** After the character-length check passed, a second byte-length check ran on the same value, so a 47 character Devanagari title with a 60 character limit was cut to 24 characters, and the cut could land inside a character and emit invalid UTF-8. The byte-length path is now only used when the mbstring extension is missing.
- The store name suffix path in the head plugin uses the same word-boundary rule, and the finished title still fits inside the configured title length.
- Google Merchant and custom feed descriptions use the same rule, so a feed description no longer ends mid-word.

### Changed
- The truncation logic lived in five places and has moved into one `Model\Text\Truncator` service, injected where it is needed, so the call sites cannot drift apart.
- `Plugin\PageConfig\HeadPlugin` builds the title through a `composeTitle` method instead of inline branching.

### Added
- Unit tests for the truncator and the head plugin title: word boundaries, an unbroken 200 character token, Devanagari, accented Latin and Japanese input, values under the limit, and a check that the title plus store name always fits the configured limit.

## [1.3.15] - 2026-08-18

### Fixed
- **404 (no-route) pages are no longer indexable.** The 404 page previously rendered `robots index,follow` (from the shipped CMS meta template applied to the no-route page) together with a self-referencing canonical pointing at the requested 4XX URL, which SEO crawlers flag as "Canonical points to 4XX". The no-route page now defaults to `robots noindex,follow`, and the existing "Disable Canonical for NOINDEX Pages" guard drops the canonical tag on it. A layout override in the theme could not fix this because the module's CMS metadata plugin overrides layout-declared robots.

### Added
- New setting Stores > Configuration > Panth > Advanced SEO > Meta Tags > **Noindex 404 (No-Route) Page** (`panth_seo/meta/noindex_noroute`, default Yes). Turning it off restores the previous 404 behaviour.

## [1.3.14]

### Changed
- Replaced typographic characters (em dashes, curly quotes, ellipsis) with plain ASCII punctuation. No functional changes.
- Meta title and description truncation now appends "..." instead of the single-character ellipsis; truncated output still fits within the configured maximum length.

## [1.3.13]

### Changed
- Code cleanup: removed redundant inline comments and docblocks from the PHP source. No functional changes.

## [1.3.12] - 2026-06-18

### Changed

- README rewritten to match the Panth Infotech documentation standard: gold-template structure, Quick Answer block, full configuration table sourced from system.xml, companion modules table, and updated SEO keywords.

## [1.3.11] - 2026-06-14

### Fixed

- **SEO scores are now persisted instead of silently failing.** `Model/Score/Scorer.php` wrote a non-existent `updated_at` column to `panth_seo_score`, so every persist threw `SQLSTATE[42S22] Unknown column 'updated_at'`. The exception was caught and logged, so the storefront kept working, but no score row was ever written and `var/log/system.log` filled with one error per entity × store on every recompute. The persist now uses the schema's `computed_at` column, referenced via the `SeoScoreInterface` constants so the column names cannot drift again.
- **Meta-embedding (duplicate-content) storage no longer fails on every score.** `Model/Score/EmbeddingIndex.php` wrote `dims`/`updated_at` to `panth_seo_meta_embedding` and omitted the required `field`/`hash` columns, none of which matched the schema (`dimensions`, `field`, `hash`, `created_at`). Every `DuplicateCheck` run logged `Unknown column 'dims'`. The writer now matches the schema, keying one combined title+description vector per entity under a stable `field`, so near-duplicate detection works and the log stays clean.

## [1.3.9] - 2026-05-13

### Fixed

- **Frontend route `frontName` no longer collides with other extensions on the same project.** `etc/frontend/routes.xml` previously declared `frontName="seo"`, a generic value that any other installed SEO extension is likely to claim. Magento merges `routes.xml` across all modules and the XSD enforces uniqueness on `frontName`, so the duplicate caused `Element 'route': Duplicate key-sequence ['seo']` and 500'd every storefront request after both modules loaded. The route id `panth_seo` is unchanged - only the public URL prefix moves.

### Changed

- The Google Merchant feed URL moves from `/seo/feed/google` to `/panth_seo/feed/google`. Two admin-config comments (`etc/adminhtml/system.xml`) and one controller docblock (`Controller/Feed/Google.php`) were updated to match.

### Migration

Update the URL in any external integration that fetches the feed - typically the Merchant Center "Add a primary feed" URL, monitoring probes, and external test scripts - from `/seo/feed/google` to `/panth_seo/feed/google`.

## [1.2.0] - 2026-04-21

### BREAKING - XML Sitemap extracted

The XML Sitemap feature has been extracted into a dedicated Packagist
module:

- **XML Sitemap** -> `mage2kishan/module-xml-sitemap`
  Sharded XML sitemap generator with per-store profile CRUD, 7 entity-
  type contributors (product, category, CMS page, landing page, blog,
  video, additional links), hreflang + image + video tags, auto-split
  at configurable threshold, gzip compression, XSL stylesheet, search-
  engine ping, delta tracking, async shard queue, cron, CLI.
  Table names preserved (`panth_seo_sitemap_profile`,
  `panth_seo_sitemap_shard`) - zero data migration required.

### Removed

- `Controller/Adminhtml/Sitemap/*`, `Controller/Sitemap/Index`
- `Model/Sitemap/*`, `Model/SitemapProfile`, `Model/ResourceModel/SitemapProfile*`
- `Model/Queue/SitemapShardConsumer`
- `Model/Config/Source/SitemapChangefreq`
- `Block/Adminhtml/Sitemap*`, `Ui/Component/Form/DataProvider/SitemapProfileFormDataProvider`, `Ui/Component/Listing/Column/SitemapActions`
- `Plugin/Sitemap/{CategoryFormSitemapPlugin,ProductFormSitemapPlugin}`
- `Cron/SitemapRebuild`, `Console/Command/SitemapGenerateCommand`
- `Setup/Patch/Data/{AddDefaultSitemapProfile,AddSitemapExclusionAttributes}`
- `Setup/Patch/Schema/AddSitemapProfileTable`
- `Api/{SitemapBuilderInterface,SitemapContributorInterface}`
- System-config group "Sitemaps" under Panth Infotech -> SEO
- Admin menu item "Sitemaps"
- DB tables declaration (tables owned by sibling module now)
- Cron job `panth_seo_sitemap_rebuild`
- AMQP topic `panth_seo.sitemap_shard`
- CLI command `panth:seo:sitemap:generate` (now provided by the sibling module)

### Migration notes

- Install `mage2kishan/module-xml-sitemap` to restore functionality:
  ```
  composer require mage2kishan/module-xml-sitemap
  bin/magento module:enable Panth_XmlSitemap
  bin/magento setup:upgrade
  bin/magento setup:di:compile
  ```
- DB tables preserved; all existing sitemap profiles keep working.
- `/panth-sitemap.xml` URL remains unchanged (url_rewrite updated by the new module's patch).

## [1.1.0] - 2026-04-21

### BREAKING - feature split

Panth_AdvancedSEO has been refactored into a family of focused modules. Five
feature areas have been extracted into dedicated Packagist modules. Installing
only `mage2kishan/module-advanced-seo` after upgrading to 1.1.0 will remove
the following features; reinstall the corresponding sibling module to restore
each.

- **Cross-Links** -> `mage2kishan/module-crosslinks`
  Auto keyword -> internal-link replacement in CMS / product / category HTML.
  Same table name (`panth_seo_crosslink`) - zero data migration required.

- **Redirects & 404s** -> `mage2kishan/module-redirects`
  301/302/303/307/308/410/451 redirects, 404 log with clustering, CSV
  import/export, homepage-alias canonicaliser, lowercase + trailing-slash
  normalisers, expiry cron, loop detector, XHR guard. Table names preserved
  (`panth_seo_redirect`, `panth_seo_404_log`, `panth_seo_404_cluster`).

- **Robots & LLM Bots** -> `mage2kishan/module-robots-seo`
  Dedicated `/robots.txt` endpoint, `X-Robots-Tag` HTTP response header,
  per-entity `<meta name="robots">` pipeline, 14 LLM / AI crawler toggles
  (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, Bytespider, CCBot,
  Applebot-Extended, Meta-ExternalAgent, Amazonbot, Cohere-AI, ...). Table
  name preserved (`panth_seo_robots_policy`).

- **AI Meta Generation** -> `mage2kishan/module-pagebuilder-ai`
  OpenAI + Claude adapter factory, monthly token budget, response cache,
  async job queue, AI Prompts CRUD, AI Knowledge Base with seeded reference
  content, "Generate with AI" button injection on product / category / CMS
  Page / FAQ / Testimonial / Banner / Dynamic Form admin surfaces. Table
  names preserved (`panth_seo_ai_prompt`, `panth_seo_ai_knowledge`,
  `panth_seo_ai_usage`, `panth_seo_ai_cache`, `panth_seo_generation_job`).

- **HTML Sitemap** -> `mage2kishan/module-html-sitemap`
  Frontend `/sitemap` HTML page with categories, products, CMS pages, stores,
  custom links. Custom router rewriting `/sitemap` -> module controller.

### Removed

- ~70 files - controllers, models, blocks, plugins, UI components,
  layouts, templates, setup patches belonging exclusively to the five
  extracted features.
- System-config groups under `Stores -> Configuration -> Panth Infotech ->
  SEO`: Auto Cross-Links, Redirects, Robots & LLM Bots, AI Meta Generation,
  HTML Sitemap. Each is now exposed by its own module's config section.
- 9 LLM bot toggles + robots.txt custom-body override (moved to Panth_RobotsSeo).
- DB tables `panth_seo_crosslink`, `panth_seo_redirect`, `panth_seo_404_log`,
  `panth_seo_404_cluster`, `panth_seo_robots_policy`, `panth_seo_ai_prompt`,
  `panth_seo_ai_knowledge`, `panth_seo_ai_usage`, `panth_seo_ai_cache`,
  `panth_seo_generation_job` - Magento will NOT drop existing rows because
  the sibling module re-declares each table byte-identically.
- AI-approval columns (`ai_generated`, `ai_approved`) on `panth_seo_override`
  - Override usability no longer gates on AI approval. Panth_PageBuilderAi
  owns the AI review workflow separately.

### Changed

- Shared `Helper/Config.php`: removed ~40 deprecated constants + accessor
  methods for the five extracted features.
- `Plugin/PageConfig/HeadPlugin.php`: the "always set robots from config
  default" branch is removed. Per-entity `robots` column still feeds the
  `<meta name="robots">` tag via `Model/Meta/Resolver`. Install
  Panth_RobotsSeo to restore per-store default, noindex-paths, and
  advanced directives (`max-image-preview`, `max-snippet`).
- Admin dashboard (`Block/Adminhtml/Dashboard.php` + `dashboard.phtml`):
  dropped Active Crosslinks, Active Redirects, Recent 404s, AI Generation
  Status cards.
- `Plugin/Admin/{Product,Category,CmsPage}SeoFieldsPlugin.php`: "Generate
  with AI" button + prompt selector + image upload removed. Install
  Panth_PageBuilderAi to restore - it plugs into the same fieldsets via
  its own DI.

### Migration notes

- No database migration is required. Every table kept its pre-split name
  and shape; the sibling modules declare the same schemas.
- Composer users: after `composer update mage2kishan/module-advanced-seo`,
  run `composer require mage2kishan/module-<feature>` for each sibling you
  want, then `bin/magento setup:upgrade && setup:di:compile && cache:flush`.
- Admin config values saved under `panth_seo/*` paths for the extracted
  features are ignored by AdvancedSEO 1.1.0. The sibling modules expose
  their own config paths; re-save settings under the new paths.

## [1.0.6]

### Fixed
- **Category pages 500'd when a structured-data provider touched the product
  collection first.** `SaleEventProvider::deriveSaleDateRange()` and
  `ProductListProvider::buildListItems()` both iterated the shared category /
  layer product collection without forcing `DISTINCT` and without a
  try/catch around the load. On multi-source / shared-catalog setups, the
  default stock + category-product joins produce duplicate `entity_id` rows,
  which trip the collection's "Item with the same ID already exists" guard
  and 500 the whole page before `ListProduct` ever renders. Both providers
  now force `distinct(true)` on the select and load via `getItems()` inside
  a `try/catch`, silently skipping their structured-data contribution when
  the collection cannot be materialised, so the page always renders.

## [1.0.5]

### Performance
- **Removed the redundant `RemoveNativeOgPlugin` `afterToHtml` plugin.**
  It was declared on `Magento\Framework\View\Element\AbstractBlock`, so it
  fired for every block on every frontend page (400-1500 invocations per
  category render). After the 1.0.2 pattern fix, the observer on
  `layout_generate_blocks_after` reliably removes all native OG blocks from
  the layout, so the plugin's "safety net" role is obsolete. Deleting it
  removes the per-block overhead entirely. Native OG suppression behaviour
  is unchanged.

## [1.0.4]

### Fixed
- **GA4 `view_item_list` no longer 500s the category page.** When the layer
  product collection contained duplicate `entity_id` rows (stock joins on
  multi-source / shared catalog setups), iterating it raised
  `Item with the same ID already exists` from inside the GA4 block,
  bringing the entire category render down with a 500. The block now
  forces the load via `getItems()` inside a try/catch and silently skips
  the GA4 event when the underlying collection cannot be materialised,
  so the page always renders.

## [1.0.3]

### Fixed - Product feed generation
- **Stock filter no longer crashes feed generation.** The `joinField()` on
  `cataloginventory_stock_item` in `ProfileBasedFeedBuilder` could produce
  multiple rows per product (multi-source / shared catalog setups), tripping
  the collection's "Item with the same ID already exists" guard and aborting
  the feed run. Switched to a raw `getSelect()->joinLeft()` that preserves
  primary-key uniqueness.
- **`<g:shipping>` is now valid nested XML.** `XmlFeedWriter` previously
  emitted shipping as flat text (`<g:shipping>IN:::0 INR</g:shipping>`),
  which Google rejects. The writer now splits the `COUNTRY:::PRICE` payload
  on `:::` and emits proper `<g:country>` / `<g:price>` children.
- **`sale_price_effective_date` now matches Google's spec.** Format changed
  from `Y-m-d\TH:i:sO` to `Y-m-d\TH:iO` (no seconds), and partial ranges
  (`from/`, `/to`) are no longer emitted - Google rejects both. The element
  is now written only when both dates exist.
- **`<g:identifier_exists>` is always emitted.** Previously only the `false`
  case was written; now `true` is written whenever a brand, GTIN, or MPN is
  present. Removes Merchant Center "missing identifier" warnings for items
  that actually have brand/MPN.
- **Brand falls back to `panth_seo/structured_data/default_brand`.** When a
  product has no value for the configured brand attribute (commonly
  `manufacturer`), the feed now uses the store-level default brand instead
  of leaving the field blank.
- **`<g:google_product_category>` is always emitted.** Falls back to
  `Apparel & Accessories > Jewelry` when no attribute is configured or the
  product attribute is empty, so the field is never silently omitted.

## [1.0.2]

### Fixed
- OG block detection no longer false-matches `catalog.*` blocks. The `'og.'`
  pattern in `RemoveNativeOgObserver` and `RemoveNativeOgPlugin` was matched
  with `str_contains`, so any layout block whose name contains the substring
  `"og."` (every `catalog.*` block - `catalog.leftnav`,
  `catalog.navigation.state`, `catalog.list.item.addto`,
  `catalog.list.item.wishlist`, `catalog.compare.sidebar`, etc.) was silently
  removed from the layout and blanked in `toHtml()` on every frontend
  category render. The pattern is now matched as a prefix (`str_starts_with`)
  while `'opengraph'` continues to match as a substring. Restores layered
  navigation, add-to-cart toolbar, wishlist, and compare blocks on category
  pages. Search-result pages are unaffected (the substring is `"ogs"`, not
  `"og."`, so they were never matched).

## [1.0.1]

### Changed
- Documentation tidy-up; no functional changes.

## [1.0.0] - Initial release

### Added - Meta templates & resolution
- **Smarty-lite token engine** with `{name}`, `{price}`, `{sku}`,
  `{category}`, `{store}`, `{attribute:X}`, `{description}` tokens
  for product, category, and CMS page meta titles and descriptions.
- **Token registry** (`Panth\AdvancedSEO\Model\Meta\TokenRegistry`)
  allowing third-party modules to register custom tokens.
- **Per-entity override** fields on product, category, and CMS edit
  pages - overrides always take precedence over templates.
- **Bulk editor** admin grid for viewing and editing resolved meta
  across hundreds of entities at once.
- **Resolved meta indexer** (`panth_seo_resolved_meta`) with mview
  support for incremental reindexing.
- **Resolved meta cache** layer to avoid redundant resolution on
  every page load.

### Added - Canonical URL resolver
- **Automatic canonical resolution** with query-parameter stripping,
  pagination awareness, and layered-navigation handling.
- Configurable per store view.

### Added - Robots & LLM bot control
- **Dynamic robots.txt** served from database via a dedicated
  controller (`Panth\AdvancedSEO\Controller\Robots\Index`).
- **Per-LLM-bot allow/deny** for GPTBot, ClaudeBot,
  Google-Extended, CCBot, PerplexityBot, and Bytespider.
- **Default robots policy** installed via data patch
  (`Setup\Patch\Data\InstallDefaultRobotsPolicy`).

### Added - Hreflang
- **Hreflang group management** with locale-to-store-view mapping
  and x-default designation.
- **Auto-binder** (`Panth\AdvancedSEO\Model\Hreflang\AutoBinder`)
  that matches entities across store views by SKU / URL key.
- **Reciprocity validation** flagging broken hreflang pairs.
- **Hreflang indexer** (`panth_seo_hreflang`) with mview support.

### Added - Redirects & 404 management
- **Redirect matcher** supporting literal and PCRE regex source paths
  with 301, 302, and 503 (maintenance) redirect types.
- **Loop detection** (`Panth\AdvancedSEO\Model\Redirect\Loop`)
  preventing redirect chains and loops.
- **Bulk CSV import** via CLI
  (`bin/magento panth:seo:redirect-import --file=X`).
- **404 logging** with deduplication, hit counting, and referer
  tracking.
- **404 clustering** grouping similar missing URLs for bulk redirect
  creation.
- **Suggestion engine** (`Panth\AdvancedSEO\Model\Redirect\SuggestionEngine`)
  recommending redirect targets based on URL similarity.

### Added - Structured data (JSON-LD)
- **Six providers** out of the box: Product, Breadcrumb, Organization,
  WebSite, FAQPage, Article, plus a Video provider.
- **Structured data validator** checking generated JSON-LD against
  schema.org requirements.
- Server-rendered output injected via layout XML - no frontend JS.

### Added - Social meta (OpenGraph & Twitter)
- Automatic `og:*` and `twitter:*` tag generation for all pages.
- Per-entity override fields for OG title, description, and image.
- Configurable default images, Twitter card type, and Facebook App ID.

### Added - SEO rules engine
- **Condition-combine tree** matching on entity type, attributes,
  stock status, price range, category membership, and URL patterns.
- **Three action types**: Template (apply meta template), Canonical
  (override canonical), and Noindex (set robots directive).
- Priority-based evaluation - first matching rule wins.

### Added - SEO scoring & audit
- **Five check types**: Length, Duplicate, Readability, Entity
  (structured data completeness), and Keyword presence.
- **0-100 score** per entity with weighted scoring.
- **Audit dashboard** showing store-wide SEO health, issue
  distribution, and trend tracking.
- **Queue-based scoring** via `panth.seo.score` message queue
  with async consumer.
- **Embedding index** for semantic duplicate detection.
- **CLI audit** command: `bin/magento panth:seo:audit`.

### Added - AI content generation
- **Three adapters**: OpenAI (GPT-4o/4/3.5), Claude (Sonnet/Opus),
  and Null (disabled).
- **Monthly budget control** with spend tracking.
- **Result caching** with configurable TTL.
- **Generation job tracking** grid in admin.
- **CLI generation**: `bin/magento panth:seo:generate-meta`.

### Added - Sitemaps (XML & HTML)
- **Sharded XML sitemaps** with configurable entries per file.
- **Image extension** including product images as `<image:image>`.
- **Hreflang extension** including alternate URLs as `<xhtml:link>`.
- **Delta tracker** for incremental sitemap regeneration.
- **HTML sitemap** page with pagination, respecting noindex rules.

### Added - Image SEO
- **Alt-text templates** using the same token engine as meta templates.
- **Vision adapter interface** for AI-powered alt-text generation.
- Null adapter shipped as default (disabled).

### Added - Cross-linking & internal links
- **Internal link graph** builder.
- **PageRank calculator** with configurable decay factor.
- **Link suggester** recommending contextual internal links.
- **RelatedLinks ViewModel** rendering link suggestions on frontend.
- **CLI**: `bin/magento panth:seo:pagerank`.

### Added - Filter URL control
- Noindex/nofollow rules for layered navigation filtered pages.
- Canonical-to-parent for filtered URLs.
- Whitelist for indexable filter combinations (e.g., brand pages).

### Added - IndexNow & Search Console
- **IndexNow integration** pinging Bing, Yandex, Seznam, and Naver
  on content save.
- Optional Google Search Console API integration for crawl error
  monitoring.

### Added - llms.txt
- **Dynamic `/llms.txt` endpoint** built by
  `Panth\AdvancedSEO\Model\LlmsTxt\Builder`.
- Auto-generated store information with custom directive support.

### Added - Product feeds
- Google Shopping XML, Facebook Catalog, and generic CSV formats.
- Attribute selection, category filtering, stock filtering.
- Cron-based and CLI generation.

### Added - Analytics integration
- GA4 and Matomo support for SEO event tracking.
- Internal link click tracking, structured data impression events.

### Added - Admin UI
- Dashboard, Templates grid, Rules grid, Bulk Editor, Redirects grid,
  Hreflang Groups, Sitemap settings, Robots/LLM policy, Audit,
  AI Settings, 404 Log, Generation Jobs.
- SERP preview JS component (`view/adminhtml/web/js/serp-preview.js`).

### Compatibility
- Magento Open Source / Commerce / Cloud 2.4.4 - 2.4.8
- PHP 8.1, 8.2, 8.3, 8.4
- Hyva theme - fully compatible (no jQuery, server-rendered output)
- Luma theme - fully compatible without modification

---

## Support

For all questions, bug reports, or feature requests:

- **Email:** kishansavaliyakb@gmail.com
- **Website:** https://kishansavaliya.com
- **WhatsApp:** +91 84012 70422
