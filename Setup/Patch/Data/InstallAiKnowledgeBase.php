<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

class InstallAiKnowledgeBase implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_seo_ai_knowledge');

        if (!$connection->isTableExists($table)) {
            // Create table if not exists
            $connection->query("
                CREATE TABLE IF NOT EXISTS {$table} (
                    knowledge_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    category VARCHAR(64) NOT NULL DEFAULT 'general',
                    subcategory VARCHAR(128) DEFAULT NULL,
                    title VARCHAR(255) NOT NULL,
                    content MEDIUMTEXT NOT NULL,
                    tags VARCHAR(512) DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    sort_order INT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (knowledge_id),
                    INDEX IDX_CATEGORY (category),
                    INDEX IDX_SUBCATEGORY (subcategory),
                    INDEX IDX_ACTIVE (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Panth SEO AI Knowledge Base'
            ");
        }

        $now = $this->dateTime->gmtDate();

        // Load entries from main method + all batch files
        $allEntries = $this->getKnowledgeEntries();

        // Load additional batch files
        $batchFiles = [
            'panth_modules_knowledge_batch1.php',
            'panth_modules_knowledge_batch2.php',
            'panth_modules_knowledge_batch3.php',
            'panth_modules_knowledge_batch4.php',
            'pagebuilder_knowledge.php',
            'ecommerce_knowledge.php',
            'seo_technical_knowledge.php',
            'accessibility_html_knowledge.php',
            'response_format_knowledge.php',
            'pagebuilder_output_examples.php',
            'conversion_copywriting_knowledge.php',
        ];

        $dataDir = dirname(__DIR__, 2) . '/Setup/Data/';
        foreach ($batchFiles as $file) {
            $path = $dataDir . $file;
            if (file_exists($path)) {
                $batchEntries = include $path; // phpcs:ignore Magento2.Security.IncludeFile
                if (is_array($batchEntries)) {
                    $allEntries = array_merge($allEntries, $batchEntries);
                }
            }
        }

        // Use title + category as unique key to avoid duplicates on re-install
        foreach ($allEntries as $entry) {
            if (is_array($entry['tags'] ?? null)) {
                $entry['tags'] = implode(',', $entry['tags']);
            }
            $row = [
                'category'    => substr((string) ($entry['category'] ?? 'general'), 0, 64),
                'subcategory' => substr((string) ($entry['subcategory'] ?? ''), 0, 128),
                'title'       => substr((string) ($entry['title'] ?? ''), 0, 255),
                'content'     => (string) ($entry['content'] ?? ''),
                'tags'        => substr((string) ($entry['tags'] ?? ''), 0, 512),
                'is_active'   => (int) ($entry['is_active'] ?? 1),
                'sort_order'  => (int) ($entry['sort_order'] ?? 0),
                'created_at'  => $now,
                'updated_at'  => $now,
            ];

            // Check if entry with same title+category exists
            $exists = $connection->fetchOne(
                $connection->select()
                    ->from($table, ['knowledge_id'])
                    ->where('title = ?', $row['title'])
                    ->where('category = ?', $row['category'])
                    ->limit(1)
            );

            if (!$exists) {
                try {
                    $connection->insert($table, $row);
                } catch (\Throwable) {
                    // Skip on error
                }
            }
        }

        $this->moduleDataSetup->endSetup();
        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getKnowledgeEntries(): array
    {
        $entries = [];
        $sort = 0;

        // =====================================================================
        // CATEGORY: pagebuilder - Core Components (50+ entries)
        // =====================================================================

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'row_component',
            'title' => 'PageBuilder Row Structure',
            'content' => 'Magento PageBuilder rows are the primary container. HTML: <div data-content-type="row" data-appearance="contained"><div class="row-full-width-inner">CONTENT</div></div>. Always wrap content in rows. Rows can be full-width or contained. Use data-appearance="full-width" for edge-to-edge sections or "contained" for centered content with max-width.',
            'tags' => 'pagebuilder, row, container, layout, structure',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'row_component',
            'title' => 'Row Full-Width vs Contained',
            'content' => 'Full-width rows: <div data-content-type="row" data-appearance="full-width"><div class="row-full-width-inner">CONTENT</div></div>. Use full-width for hero banners, color backgrounds, and visual impact sections. Use contained rows for text-heavy content, forms, and standard page sections.',
            'tags' => 'pagebuilder, row, full-width, contained, appearance',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'row_component',
            'title' => 'Row Background Options',
            'content' => 'Rows support background images and colors via inline styles: style="background-image:url(IMAGE_URL); background-size:cover; background-position:center;". Use background-color for solid fills. Combine with padding for spacing: style="padding:40px 20px;".',
            'tags' => 'pagebuilder, row, background, image, styling',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'column_component',
            'title' => 'PageBuilder Column Layout',
            'content' => 'Columns use CSS grid. HTML: <div data-content-type="column-group"><div data-content-type="column" style="width:50%">LEFT</div><div data-content-type="column" style="width:50%">RIGHT</div></div>. Use 2-column for product features, 3-column for comparison. Columns stack on mobile automatically.',
            'tags' => 'pagebuilder, column, grid, layout, responsive',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'column_component',
            'title' => 'Three-Column Layout',
            'content' => 'Three-column layout: <div data-content-type="column-group"><div data-content-type="column" style="width:33.33%">COL1</div><div data-content-type="column" style="width:33.33%">COL2</div><div data-content-type="column" style="width:33.33%">COL3</div></div>. Ideal for feature comparisons, benefit highlights, and category showcases.',
            'tags' => 'pagebuilder, column, three-column, grid, layout',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'column_component',
            'title' => 'Asymmetric Column Layout',
            'content' => 'Asymmetric layouts use unequal widths: <div data-content-type="column-group"><div data-content-type="column" style="width:66.66%">MAIN CONTENT</div><div data-content-type="column" style="width:33.33%">SIDEBAR</div></div>. Use 2/3 + 1/3 for content with sidebar, 1/3 + 2/3 for image + text layouts.',
            'tags' => 'pagebuilder, column, asymmetric, sidebar, layout',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'column_component',
            'title' => 'Four-Column Layout',
            'content' => 'Four-column grid: <div data-content-type="column-group"><div data-content-type="column" style="width:25%">COL</div> (repeat 4x)</div>. Use for USP icons, feature grids, team members, or category thumbnails. Ensure content is concise as each column is narrow.',
            'tags' => 'pagebuilder, column, four-column, grid, icons',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'heading_component',
            'title' => 'PageBuilder Heading',
            'content' => 'Use <div data-content-type="heading"><h2>Heading Text</h2></div>. Use h2 for main sections, h3 for sub-sections. Never skip heading levels (h2 to h4). Include keywords naturally in headings. Only one h1 per page (usually the product/page title).',
            'tags' => 'pagebuilder, heading, h2, h3, seo, hierarchy',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'heading_component',
            'title' => 'Heading Alignment and Styling',
            'content' => 'Headings support alignment via style attribute: <div data-content-type="heading"><h2 style="text-align:center;">Centered Heading</h2></div>. Use center alignment for hero sections and page titles. Left-align headings in content-heavy sections for readability.',
            'tags' => 'pagebuilder, heading, alignment, styling, center',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'text_component',
            'title' => 'PageBuilder Text Block',
            'content' => 'Text blocks: <div data-content-type="text"><p>Content here</p></div>. Use <p> tags for paragraphs. Use <strong> for emphasis (not <b>). Use <ul>/<li> for feature lists. Keep paragraphs short (2-3 sentences) for readability.',
            'tags' => 'pagebuilder, text, paragraph, content, formatting',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'text_component',
            'title' => 'Text Formatting Best Practices',
            'content' => 'Use semantic HTML in text blocks: <strong> for important terms (not <b>), <em> for emphasis (not <i>), <a href="URL"> for links. Use <ul> for unordered lists, <ol> for sequential steps. Break content into scannable paragraphs with clear structure.',
            'tags' => 'pagebuilder, text, semantic, html, formatting',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'text_component',
            'title' => 'Text Block with Lists',
            'content' => 'Feature lists in text blocks: <div data-content-type="text"><ul><li><strong>Feature Name:</strong> Feature description with benefits</li><li><strong>Feature Name:</strong> Feature description</li></ul></div>. Lists improve scannability and are favored by search engines for featured snippets.',
            'tags' => 'pagebuilder, text, list, features, bullet-points',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'button_component',
            'title' => 'PageBuilder Button/CTA',
            'content' => 'Buttons: <div data-content-type="buttons"><div data-content-type="button-item"><a class="pagebuilder-button-primary" href="URL">CTA Text</a></div></div>. Use action-oriented text: "Shop Now", "Add to Cart", "Learn More". Primary buttons for main CTA, secondary for alternative actions.',
            'tags' => 'pagebuilder, button, cta, call-to-action, link',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'button_component',
            'title' => 'Button Styles and Variants',
            'content' => 'Button classes: pagebuilder-button-primary (main CTA, filled), pagebuilder-button-secondary (alternative action, outlined), pagebuilder-button-link (text-only link style). Use one primary button per section. Align buttons with the overall page hierarchy.',
            'tags' => 'pagebuilder, button, primary, secondary, styling',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'button_component',
            'title' => 'Multiple Buttons in a Row',
            'content' => 'Multiple buttons: <div data-content-type="buttons"><div data-content-type="button-item"><a class="pagebuilder-button-primary" href="URL1">Primary CTA</a></div><div data-content-type="button-item"><a class="pagebuilder-button-secondary" href="URL2">Secondary CTA</a></div></div>. Limit to 2 buttons per group for clarity.',
            'tags' => 'pagebuilder, button, multiple, group, cta',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'image_component',
            'title' => 'PageBuilder Image',
            'content' => 'Images: <figure data-content-type="image"><img src="URL" alt="Descriptive alt text" title="Title"></figure>. ALWAYS include descriptive alt text. Use WebP format. Optimize for mobile (max-width:100%). Set explicit width and height attributes to prevent layout shifts.',
            'tags' => 'pagebuilder, image, alt-text, seo, media',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'image_component',
            'title' => 'Image with Link and Caption',
            'content' => 'Linked image with caption: <figure data-content-type="image"><a href="LINK_URL"><img src="IMAGE_URL" alt="Alt text" width="800" height="600"></a><figcaption>Image caption text</figcaption></figure>. Use figcaption for additional context. Link images to relevant product or category pages.',
            'tags' => 'pagebuilder, image, link, caption, figure',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'image_component',
            'title' => 'Image Optimization Guidelines',
            'content' => 'Image optimization: Use WebP format (30-50% smaller than JPEG). Compress to under 100KB for content images, under 200KB for hero images. Always set width and height attributes. Use loading="lazy" for below-fold images. Name files descriptively: blue-cotton-shirt.webp not IMG_001.webp.',
            'tags' => 'pagebuilder, image, optimization, webp, performance',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'banner_component',
            'title' => 'PageBuilder Banner',
            'content' => 'Banners: <div data-content-type="banner" data-appearance="poster"><div class="pagebuilder-banner-wrapper"><div class="pagebuilder-overlay"><div class="pagebuilder-banner-content"><h2>Headline</h2><p>Description</p><a href="URL">CTA</a></div></div></div></div>. Use for promotional content, hero sections, and featured announcements.',
            'tags' => 'pagebuilder, banner, hero, promotion, overlay',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'banner_component',
            'title' => 'Banner Appearances',
            'content' => 'Banner appearances: "poster" (overlay on image), "collage-left" (content left, image right), "collage-right" (content right, image left), "collage-centered" (centered content over image). Choose appearance based on content hierarchy and visual design.',
            'tags' => 'pagebuilder, banner, appearance, collage, poster',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'tab_component',
            'title' => 'PageBuilder Tabs',
            'content' => 'Tabs: <div data-content-type="tabs"><div data-content-type="tab-item" data-tab-name="Tab 1">Content 1</div><div data-content-type="tab-item" data-tab-name="Tab 2">Content 2</div></div>. Use tabs for product details, specifications, reviews. Limit to 3-5 tabs. First tab should contain the most important content.',
            'tags' => 'pagebuilder, tabs, navigation, product-details, organize',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'tab_component',
            'title' => 'Tab Content Organization',
            'content' => 'Recommended tab structure for products: Tab 1 "Description" (detailed product info), Tab 2 "Specifications" (technical details table), Tab 3 "Reviews" (customer testimonials), Tab 4 "Shipping & Returns" (policy info). Keep tab names short (1-2 words).',
            'tags' => 'pagebuilder, tabs, organization, product, structure',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'slider_component',
            'title' => 'PageBuilder Slider/Carousel',
            'content' => 'Sliders: <div data-content-type="slider"><div data-content-type="slide">Slide content</div></div>. Use for hero banners, testimonials. Limit to 3-5 slides. Include alt text on all images. First slide is the most important as many users do not advance slides.',
            'tags' => 'pagebuilder, slider, carousel, hero, slideshow',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'slider_component',
            'title' => 'Slider Performance Considerations',
            'content' => 'Slider performance: Limit slides to 3-5 to reduce page weight. Lazy-load off-screen slides. Ensure first slide image is optimized (it blocks LCP). Add descriptive content to each slide for SEO (search engines may not see dynamic slide content). Avoid auto-play that distracts users.',
            'tags' => 'pagebuilder, slider, performance, lcp, optimization',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'block_component',
            'title' => 'PageBuilder Block Reference',
            'content' => 'Static blocks: <div data-content-type="block" data-block-id="BLOCK_ID"></div>. Use for reusable content like trust badges, shipping info, return policy. Block content is rendered server-side. Ideal for content that appears on multiple pages.',
            'tags' => 'pagebuilder, block, static-block, reusable, cms',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'html_component',
            'title' => 'PageBuilder HTML Code',
            'content' => 'Custom HTML: <div data-content-type="html"><custom HTML here></div>. Use for structured data (JSON-LD), custom scripts, advanced layouts, embedded widgets, or third-party integrations not possible with standard PageBuilder components.',
            'tags' => 'pagebuilder, html, custom, script, structured-data',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'map_component',
            'title' => 'PageBuilder Map',
            'content' => 'Google Maps: <div data-content-type="map" data-locations="[{lat,lng}]"></div>. Use for store locations, contact page. Include store name and address as text content nearby for SEO (map content is not indexable). Add schema.org LocalBusiness markup.',
            'tags' => 'pagebuilder, map, google-maps, location, contact',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'video_component',
            'title' => 'PageBuilder Video',
            'content' => 'Video embed: <div data-content-type="video" data-video-url="YouTube/Vimeo URL"></div>. Include video descriptions for SEO. Use schema.org VideoObject markup. Add a text transcript nearby for accessibility and SEO. Lazy-load videos to improve page performance.',
            'tags' => 'pagebuilder, video, youtube, vimeo, embed, schema',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'divider_component',
            'title' => 'PageBuilder Divider',
            'content' => 'Line divider: <div data-content-type="divider"><hr></div>. Use sparingly between major content sections. Dividers provide visual separation without adding semantic meaning. Prefer whitespace (padding/margin) over dividers when possible.',
            'tags' => 'pagebuilder, divider, hr, separator, spacing',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'products_component',
            'title' => 'PageBuilder Products Widget',
            'content' => 'Products widget: <div data-content-type="products" data-appearance="grid">{{widget type="Magento\\CatalogWidget\\Block\\Product\\ProductsList" ...}}</div>. Use to display product grids within CMS content. Options: grid or carousel appearance. Filter by category, SKU, or conditions.',
            'tags' => 'pagebuilder, products, widget, grid, catalog',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        // PageBuilder Layout Patterns

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'layout_pattern',
            'title' => 'Product Landing Page Layout',
            'content' => 'Recommended product landing page structure: Row 1 (hero banner with product image + headline + CTA) -> Row 2 (2-col: key features list + lifestyle image) -> Row 3 (3-col: benefit icons with short descriptions) -> Row 4 (full-width testimonials slider) -> Row 5 (specs table in tabs) -> Row 6 (CTA button + urgency message).',
            'tags' => 'pagebuilder, layout, landing-page, product, structure',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'layout_pattern',
            'title' => 'Category Page Content Layout',
            'content' => 'Category page content structure: Row 1 (category description with primary keywords, 2-3 paragraphs) -> Row 2 (featured/best-seller products widget) -> Row 3 (buying guide with tips, 2-col layout) -> Row 4 (FAQ accordion with schema markup) -> Row 5 (related categories links).',
            'tags' => 'pagebuilder, layout, category, content, structure',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'layout_pattern',
            'title' => 'Homepage Layout',
            'content' => 'Homepage structure: Row 1 (hero slider, 3-5 slides with CTAs) -> Row 2 (featured categories in 3-col grid with images) -> Row 3 (new arrivals product carousel) -> Row 4 (USP icons in 4-col: free shipping, returns, secure payment, support) -> Row 5 (testimonials/reviews slider) -> Row 6 (newsletter signup with value proposition).',
            'tags' => 'pagebuilder, layout, homepage, hero, structure',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'layout_pattern',
            'title' => 'About Us Page Layout',
            'content' => 'About Us page structure: Row 1 (company story heading + intro paragraph) -> Row 2 (2-col: mission statement + company photo) -> Row 3 (timeline or milestones) -> Row 4 (team members in 3-col grid with photos) -> Row 5 (values/achievements in icon grid) -> Row 6 (CTA to contact or shop).',
            'tags' => 'pagebuilder, layout, about-us, company, cms',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'layout_pattern',
            'title' => 'Contact Page Layout',
            'content' => 'Contact page structure: Row 1 (heading + intro text) -> Row 2 (2-col: contact form + Google Map) -> Row 3 (office locations with addresses in 2 or 3 columns) -> Row 4 (FAQ section for common inquiries). Include LocalBusiness schema markup.',
            'tags' => 'pagebuilder, layout, contact, form, map',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'layout_pattern',
            'title' => 'FAQ Page Layout',
            'content' => 'FAQ page structure: Row 1 (page title + search/filter) -> Row 2+ (FAQ categories as sections, each with h2 heading + accordion-style Q&A). Use FAQPage schema markup for rich results. Group questions by topic. Include internal links in answers.',
            'tags' => 'pagebuilder, layout, faq, accordion, schema',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'layout_pattern',
            'title' => 'Blog Post Layout',
            'content' => 'Blog/article page structure: Row 1 (hero image + title + author + date) -> Row 2 (article body with headings h2/h3, images, lists) -> Row 3 (author bio with photo) -> Row 4 (related articles in 3-col grid) -> Row 5 (comments or CTA). Use Article schema markup.',
            'tags' => 'pagebuilder, layout, blog, article, content',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'layout_pattern',
            'title' => 'Promotion/Sale Landing Page',
            'content' => 'Sale landing page structure: Row 1 (hero banner with sale headline, percentage off, countdown timer) -> Row 2 (featured deals in product grid) -> Row 3 (category deals in 3-col with images) -> Row 4 (trust badges + urgency messaging) -> Row 5 (newsletter signup for sale alerts).',
            'tags' => 'pagebuilder, layout, promotion, sale, landing-page',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'layout_pattern',
            'title' => 'Shipping & Returns Page',
            'content' => 'Shipping info page: Row 1 (page title + summary) -> Row 2 (shipping methods table: method, time, cost) -> Row 3 (2-col: shipping zones map + international info) -> Row 4 (returns policy with step-by-step process) -> Row 5 (contact info for shipping questions).',
            'tags' => 'pagebuilder, layout, shipping, returns, policy',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'layout_pattern',
            'title' => 'Size Guide Page',
            'content' => 'Size guide page: Row 1 (page title + how to measure instructions with diagram) -> Row 2 (size chart table with measurements) -> Row 3 (2-col: measurement tips + image) -> Row 4 (fit recommendations by body type) -> Row 5 (link to contact for sizing help).',
            'tags' => 'pagebuilder, layout, size-guide, table, measurement',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'layout_pattern',
            'title' => 'Brand Story Page',
            'content' => 'Brand page structure: Row 1 (brand logo + hero image) -> Row 2 (brand story/history) -> Row 3 (2-col: brand values + lifestyle image) -> Row 4 (popular products from this brand) -> Row 5 (testimonials from brand customers) -> Row 6 (shop all CTA button).',
            'tags' => 'pagebuilder, layout, brand, story, cms',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        // PageBuilder Content Tips

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'content_tips',
            'title' => 'PageBuilder Content Length Guidelines',
            'content' => 'Recommended content lengths: Product descriptions 300-1000 words. Category descriptions 150-300 words. CMS landing pages 500-2000 words. Hero banner headlines 5-10 words. Button text 2-4 words. Paragraph length 2-3 sentences max for scannability.',
            'tags' => 'pagebuilder, content, length, guidelines, words',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'content_tips',
            'title' => 'PageBuilder Mobile Responsiveness',
            'content' => 'Mobile considerations: Columns stack vertically on small screens. Test all layouts on mobile. Use relative widths (%, vw) not fixed pixels. Ensure touch targets are at least 44x44px. Reduce image sizes for mobile. Hide decorative elements on small screens if needed.',
            'tags' => 'pagebuilder, mobile, responsive, design, layout',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'content_tips',
            'title' => 'PageBuilder Spacing and Padding',
            'content' => 'Use consistent spacing: Row padding 20-60px vertical, 15-30px horizontal. Column gaps via padding 10-20px. Section spacing between rows 30-60px. Use margin-top/margin-bottom on content elements for vertical rhythm. Maintain visual consistency across sections.',
            'tags' => 'pagebuilder, spacing, padding, margin, design',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'content_tips',
            'title' => 'PageBuilder Visual Hierarchy',
            'content' => 'Establish visual hierarchy: Use larger fonts and bold for headings. Primary CTA buttons should be the most prominent element. Use color contrast to draw attention. Progress from general to specific: hero -> features -> details -> CTA. White space guides the eye naturally.',
            'tags' => 'pagebuilder, hierarchy, visual, design, ux',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'content_tips',
            'title' => 'PageBuilder Content Nesting Rules',
            'content' => 'Nesting rules: Rows contain column-groups or direct content. Column-groups contain columns. Columns contain any content type. Do not nest rows inside rows. Do not nest column-groups inside column-groups. Keep nesting depth to 2-3 levels maximum.',
            'tags' => 'pagebuilder, nesting, structure, rules, hierarchy',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'content_tips',
            'title' => 'PageBuilder Performance Best Practices',
            'content' => 'Performance tips: Minimize the number of PageBuilder components per page. Lazy-load images below the fold. Avoid excessive inline styles. Use CSS classes over inline styles where possible. Limit sliders/carousels to reduce JS payload. Compress all images before upload.',
            'tags' => 'pagebuilder, performance, optimization, speed, loading',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'content_tips',
            'title' => 'PageBuilder Accessibility in Content',
            'content' => 'Accessibility in PageBuilder content: Always add alt text to images. Use proper heading hierarchy (h1->h2->h3). Ensure sufficient color contrast (4.5:1 for text). Make links descriptive (not "click here"). Ensure interactive elements are keyboard accessible. Add ARIA labels where needed.',
            'tags' => 'pagebuilder, accessibility, a11y, alt-text, wcag',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        // =====================================================================
        // CATEGORY: seo (30+ entries)
        // =====================================================================

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'meta_title',
            'title' => 'Meta Title Best Practices',
            'content' => '50-60 characters. Include primary keyword near the beginning. Add brand name at the end separated by a pipe or dash. Make it compelling and unique for each page. Avoid keyword stuffing. Use title case. Format: "Primary Keyword - Secondary Keyword | Brand Name".',
            'tags' => 'seo, meta-title, title, keywords, character-limit',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'meta_title',
            'title' => 'Meta Title for Products',
            'content' => 'Product meta title format: "[Product Name] - [Key Feature/Benefit] | [Brand]". Example: "Organic Cotton T-Shirt - Breathable & Sustainable | EcoWear". Include product type, material, or key differentiator. Avoid generic titles like "Product Page".',
            'tags' => 'seo, meta-title, product, format, example',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'meta_title',
            'title' => 'Meta Title for Categories',
            'content' => 'Category meta title format: "[Category Name] - [Qualifier] | [Brand]". Example: "Women\'s Running Shoes - Shop the Latest Styles | SportShop". Include category name, shopping intent words (Shop, Buy, Browse), and a differentiator.',
            'tags' => 'seo, meta-title, category, format, shopping',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'meta_description',
            'title' => 'Meta Description Best Practices',
            'content' => '140-156 characters. Include primary keyword naturally. Add a call-to-action (Shop Now, Learn More, Discover). Mention unique selling points (free shipping, discounts). Make each one unique. Write for humans first, search engines second. Create urgency or curiosity.',
            'tags' => 'seo, meta-description, description, cta, character-limit',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'meta_description',
            'title' => 'Meta Description for Products',
            'content' => 'Product meta description template: "Discover [Product Name] featuring [key benefit]. [Unique selling point]. [CTA - Shop now/Order today] with [incentive - free shipping/returns]." Example: "Discover our Organic Cotton T-Shirt featuring breathable comfort. Made from 100% certified organic cotton. Shop now with free shipping on orders over $50."',
            'tags' => 'seo, meta-description, product, template, example',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'meta_description',
            'title' => 'Meta Description for Categories',
            'content' => 'Category meta description template: "Browse our collection of [category]. [What makes it special]. [Incentive]. [CTA]." Example: "Browse our collection of women\'s running shoes from top brands. Find your perfect fit with free returns and expert reviews. Shop the latest styles today."',
            'tags' => 'seo, meta-description, category, template, collection',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'heading_hierarchy',
            'title' => 'Heading Hierarchy Rules',
            'content' => 'Only one H1 per page (product/category name). Use H2 for main sections. H3 for sub-sections within H2. H4 for details within H3. Never skip levels (e.g., H2 directly to H4). Include keywords naturally in headings. Keep headings concise and descriptive.',
            'tags' => 'seo, headings, h1, h2, h3, hierarchy, structure',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'image_seo',
            'title' => 'Image SEO Best Practices',
            'content' => 'Use descriptive file names (blue-cotton-shirt.jpg not IMG_001.jpg). Always add alt text describing the image content. Use WebP format for smaller file sizes. Compress images under 100KB. Include width/height attributes to prevent CLS. Use lazy loading for below-fold images. Add title attributes for additional context.',
            'tags' => 'seo, image, alt-text, webp, optimization, file-name',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'internal_linking',
            'title' => 'Internal Linking Strategy',
            'content' => 'Link to related products/categories within content. Use descriptive anchor text (not "click here" or "read more"). Maintain reasonable link density: 2-5 internal links per 1000 words. Link from high-authority pages to important pages. Use breadcrumbs for structural internal linking.',
            'tags' => 'seo, internal-links, anchor-text, linking, structure',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'url_structure',
            'title' => 'URL Structure Best Practices',
            'content' => 'Use lowercase letters. Use hyphens to separate words (not underscores). Keep URLs short and descriptive. Include primary keyword. Avoid URL parameters when possible. Remove stop words (a, the, and). Example: /mens-running-shoes not /category.php?id=123&type=shoes.',
            'tags' => 'seo, url, structure, slug, url-key',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'schema_markup',
            'title' => 'Schema Markup Essentials',
            'content' => 'Use JSON-LD format (preferred by Google). Required schemas: Product (for product pages), Organization (site-wide), BreadcrumbList (navigation). Recommended: FAQPage, HowTo, Review, AggregateRating. Test with Google Rich Results Test tool. Keep schema accurate and up-to-date.',
            'tags' => 'seo, schema, json-ld, structured-data, rich-results',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'schema_markup',
            'title' => 'Product Schema Markup',
            'content' => 'Product schema must include: name, description, image, sku, brand, offers (price, currency, availability). Optional but recommended: aggregateRating, review, gtin/mpn. Example: {"@type":"Product","name":"...","offers":{"@type":"Offer","price":"29.99","priceCurrency":"USD","availability":"https://schema.org/InStock"}}.',
            'tags' => 'seo, schema, product, json-ld, offers, price',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'schema_markup',
            'title' => 'FAQ Schema Markup',
            'content' => 'FAQPage schema for rich results: {"@context":"https://schema.org","@type":"FAQPage","mainEntity":[{"@type":"Question","name":"Question text?","acceptedAnswer":{"@type":"Answer","text":"Answer text."}}]}. Include 3-10 questions. Answers should be concise but complete.',
            'tags' => 'seo, schema, faq, rich-results, structured-data',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'mobile_seo',
            'title' => 'Mobile SEO Requirements',
            'content' => 'Mobile-first design is essential (Google uses mobile-first indexing). Touch-friendly buttons minimum 44x44px. Fast loading under 3 seconds. Readable text without zooming (16px minimum font). No horizontal scrolling. Responsive images. Avoid intrusive interstitials.',
            'tags' => 'seo, mobile, responsive, mobile-first, usability',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'core_web_vitals',
            'title' => 'Core Web Vitals Targets',
            'content' => 'LCP (Largest Contentful Paint) < 2.5s: optimize hero images, preload critical resources, use CDN. FID (First Input Delay) < 100ms: minimize JavaScript, defer non-critical scripts. CLS (Cumulative Layout Shift) < 0.1: set image dimensions, avoid dynamic content injection, use font-display:swap.',
            'tags' => 'seo, core-web-vitals, lcp, fid, cls, performance',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'eeat',
            'title' => 'E-E-A-T Guidelines',
            'content' => 'Demonstrate Experience, Expertise, Authoritativeness, Trustworthiness. Add author bios with credentials. Include citations and references. Display trust badges and certifications. Show customer reviews and ratings. Link to authoritative sources. Maintain consistent NAP (Name, Address, Phone) across the web.',
            'tags' => 'seo, eeat, trust, authority, expertise, experience',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'keyword_usage',
            'title' => 'Keyword Placement Strategy',
            'content' => 'Include primary keyword in: meta title (near beginning), meta description, H1 heading, first 100 words of content, URL slug, image alt text. Use secondary keywords in H2/H3 headings and body text. Maintain natural keyword density (1-2%). Avoid keyword stuffing.',
            'tags' => 'seo, keywords, placement, density, optimization',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'keyword_usage',
            'title' => 'LSI Keywords and Semantic SEO',
            'content' => 'Use LSI (Latent Semantic Indexing) keywords: related terms and synonyms that help search engines understand content context. For "running shoes": include "athletic footwear", "jogging sneakers", "marathon trainers". Use tools like Google related searches for ideas.',
            'tags' => 'seo, lsi, semantic, keywords, related-terms',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'content_quality',
            'title' => 'Content Quality Signals',
            'content' => 'Quality content: Unique and original (no duplicate content). Comprehensive coverage of the topic. Well-structured with headings and lists. Updated regularly. Answers user intent. Includes multimedia (images, videos). Proper grammar and spelling. Provides value beyond what competitors offer.',
            'tags' => 'seo, content, quality, unique, comprehensive',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'technical_seo',
            'title' => 'Technical SEO Checklist',
            'content' => 'Technical SEO essentials: XML sitemap submitted to Google Search Console. Robots.txt properly configured. Canonical tags on all pages. HTTPS everywhere. Clean URL structure. Proper 301 redirects for moved pages. Hreflang for multi-language. No broken links (404s). Fast server response time (TTFB < 200ms).',
            'tags' => 'seo, technical, sitemap, robots, canonical, https',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'technical_seo',
            'title' => 'Canonical URL Best Practices',
            'content' => 'Set self-referencing canonical tags on every page. Use canonical to handle duplicate content from URL parameters, sorting, and filtering. Canonical URL should be the preferred version (with or without trailing slash, www vs non-www). Cross-domain canonicals are supported but use cautiously.',
            'tags' => 'seo, canonical, duplicate-content, url, technical',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'local_seo',
            'title' => 'Local SEO for E-Commerce',
            'content' => 'Local SEO: Create Google Business Profile. Use LocalBusiness schema markup. Include city/region in meta tags for local relevance. Add store locations with maps. Build local citations (NAP consistency). Encourage local reviews. Create location-specific landing pages if multiple stores.',
            'tags' => 'seo, local, google-business, location, store',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'meta_keywords',
            'title' => 'Meta Keywords Guidelines',
            'content' => 'While meta keywords have minimal direct SEO value, they can be useful for internal site search and organization. Include 5-10 relevant keywords separated by commas. Focus on primary and secondary target keywords. Do not stuff with irrelevant terms.',
            'tags' => 'seo, meta-keywords, keywords, tags',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'social_seo',
            'title' => 'Open Graph and Social SEO',
            'content' => 'Open Graph tags for social sharing: og:title (60-90 chars, engaging), og:description (100-200 chars), og:image (1200x630px recommended), og:type (product, article, website). Twitter Card: use summary_large_image for visual content. Ensure social preview looks compelling.',
            'tags' => 'seo, open-graph, og, twitter, social, sharing',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'content_freshness',
            'title' => 'Content Freshness Strategy',
            'content' => 'Keep content fresh: Update product descriptions seasonally. Refresh category content with new trends/products. Add new FAQ entries based on customer queries. Update statistics and facts annually. Add a "last updated" date to informational pages. Monitor and fix thin content pages.',
            'tags' => 'seo, freshness, update, content, strategy',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'pagination_seo',
            'title' => 'Pagination SEO for Categories',
            'content' => 'Pagination best practices: Use rel="next" and rel="prev" links. Set canonical to the first page or use self-referencing canonicals. Ensure all paginated pages are indexable. Include unique content on page 1. Use "View All" option if product count is manageable. Avoid noindex on paginated pages.',
            'tags' => 'seo, pagination, category, navigation, crawling',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        // =====================================================================
        // CATEGORY: ecommerce (30+ entries)
        // =====================================================================

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'product_description',
            'title' => 'Product Description Structure',
            'content' => 'Start with a compelling hook sentence. List key features with bullet points. Include specifications (material, dimensions, weight). Address common customer questions preemptively. Highlight benefits over features. End with a clear call-to-action. Minimum 150 words for SEO value.',
            'tags' => 'ecommerce, product, description, structure, content',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'product_description',
            'title' => 'Product Description Keywords',
            'content' => 'Include primary keyword in first 100 words. Use LSI keywords naturally throughout. Mention material, size, color, and brand name. Include use cases and scenarios. Use product-specific terminology (e.g., "moisture-wicking" for sportswear). Avoid generic filler text.',
            'tags' => 'ecommerce, product, keywords, lsi, description',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'product_description',
            'title' => 'Product Description Tone and Voice',
            'content' => 'Write in second person ("you" and "your"). Be conversational but professional. Match the brand voice consistently. Use sensory language for physical products. Create emotional connection with benefits. Avoid jargon unless your audience expects it. Be specific, not vague.',
            'tags' => 'ecommerce, product, tone, voice, copywriting',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'product_description',
            'title' => 'Product Short Description',
            'content' => 'Short description: 1-3 sentences, 50-150 words. Focus on the primary benefit and key differentiator. Include the primary keyword. Make it scannable. Used in category grids, search results, and social sharing. Example: "Our Organic Cotton T-Shirt delivers all-day comfort with sustainable style. Made from 100% GOTS-certified organic cotton."',
            'tags' => 'ecommerce, product, short-description, summary, snippet',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'product_description',
            'title' => 'Product Features vs Benefits',
            'content' => 'Always translate features into benefits. Feature: "Made from 100% organic cotton." Benefit: "Feels incredibly soft against your skin while reducing environmental impact." Feature: "Waterproof to 10,000mm." Benefit: "Stay completely dry even in heavy downpours." Lead with benefits, follow with features as supporting evidence.',
            'tags' => 'ecommerce, product, features, benefits, copywriting',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'category_description',
            'title' => 'Category Description Guidelines',
            'content' => '2-3 paragraphs, 150-300 words. Include category name and related keywords in the first paragraph. Describe what products are available and their range. Mention benefits of shopping this category. Include internal links to popular subcategories or products. End with a guiding statement.',
            'tags' => 'ecommerce, category, description, content, seo',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'category_description',
            'title' => 'Category Description Example Format',
            'content' => 'Category description format: Paragraph 1 - What the category offers + primary keywords. Paragraph 2 - Key features/benefits of products in this category + buying guidance. Paragraph 3 - Why shop here (trust signals, expertise, selection) + link to related categories or buying guide.',
            'tags' => 'ecommerce, category, description, format, template',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'cms_content',
            'title' => 'CMS Page Content Guidelines',
            'content' => 'Clear heading with primary keyword. Concise paragraphs (2-3 sentences each). Use bullet points for readability. Include 2-5 internal links to relevant pages. Add images with descriptive alt text. Structure with H2/H3 subheadings. Include a CTA appropriate to the page purpose.',
            'tags' => 'ecommerce, cms, content, page, guidelines',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'trust_signals',
            'title' => 'Trust Signals for E-Commerce',
            'content' => 'Essential trust signals: Money-back guarantee badge. Free shipping threshold. Secure payment icons (Visa, MC, PayPal, Apple Pay). Customer reviews count and rating. Years in business. SSL certificate indicator. Return policy summary. Industry certifications. Real customer testimonials with photos.',
            'tags' => 'ecommerce, trust, signals, badges, security, reviews',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'trust_signals',
            'title' => 'Social Proof Elements',
            'content' => 'Social proof types: Customer reviews and ratings (star ratings visible). Number of customers served ("Join 50,000+ happy customers"). User-generated content (customer photos). Press mentions and awards. Influencer endorsements. Real-time purchase notifications. Bestseller/popular item badges.',
            'tags' => 'ecommerce, social-proof, reviews, testimonials, trust',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'cta_patterns',
            'title' => 'Call-to-Action Patterns',
            'content' => 'Action verbs: Shop, Buy, Get, Discover, Explore, Save, Try, Order. Urgency: Limited Time, Only X Left, Ends Today, While Supplies Last. Value: Free Shipping, Save X%, Exclusive Deal, Buy One Get One. Best CTA button text: "Add to Cart", "Shop Now", "Get Yours", "Claim Your Discount".',
            'tags' => 'ecommerce, cta, call-to-action, button, conversion',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'cta_patterns',
            'title' => 'CTA Placement Strategy',
            'content' => 'Place primary CTA above the fold on product pages. Include CTAs after every major content section on landing pages. Use contrasting colors for CTA buttons. Ensure CTAs are visible on mobile without scrolling. Repeat CTA at the end of long content pages. Test button size (minimum 44x44px touch target).',
            'tags' => 'ecommerce, cta, placement, button, above-fold',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'pricing_display',
            'title' => 'Price Display Best Practices',
            'content' => 'Show original price crossed out with sale price highlighted. Use per-unit pricing where applicable. Display savings percentage or amount. Include tax information (incl./excl. VAT). Show payment plan options (e.g., "4 payments of $12.50"). Free shipping threshold messaging near price.',
            'tags' => 'ecommerce, price, display, sale, savings',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'product_attributes',
            'title' => 'Product Attribute Content',
            'content' => 'Key attributes to include: Material/composition, Dimensions/size, Weight, Color options, Care instructions, Country of origin, SKU/model number, Warranty information, Compatibility (if applicable). Present as a clean specifications table or definition list.',
            'tags' => 'ecommerce, product, attributes, specifications, details',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'cross_selling',
            'title' => 'Cross-Selling Content Patterns',
            'content' => 'Cross-sell messaging: "Customers also bought", "Complete the look", "Frequently bought together", "You may also like", "Goes well with". Include 3-6 complementary products. Show cross-sells after the main product description. Use product images for visual appeal.',
            'tags' => 'ecommerce, cross-sell, upsell, related, products',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'urgency_scarcity',
            'title' => 'Urgency and Scarcity Messaging',
            'content' => 'Urgency patterns: "Sale ends in [countdown]", "Order within X hours for next-day delivery", "Limited time offer". Scarcity patterns: "Only X left in stock", "Selling fast", "Limited edition". Use authentically - fake urgency erodes trust. Combine with genuine offers.',
            'tags' => 'ecommerce, urgency, scarcity, conversion, messaging',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'shipping_info',
            'title' => 'Shipping Information Content',
            'content' => 'Include near the product price: Estimated delivery date/range. Free shipping threshold. Available shipping methods. International shipping availability. Express shipping option. Return shipping policy. Order cutoff time for same-day dispatch. Format: "Free shipping on orders over $50 | Delivered in 2-5 business days".',
            'tags' => 'ecommerce, shipping, delivery, free-shipping, returns',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'review_content',
            'title' => 'Product Review Display',
            'content' => 'Display reviews prominently: Show aggregate star rating and total count. Feature top positive and most helpful reviews. Include verified purchase badges. Allow sorting by rating, date, helpfulness. Show review photos/videos. Respond to negative reviews professionally. Use AggregateRating schema markup.',
            'tags' => 'ecommerce, reviews, ratings, social-proof, schema',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'email_capture',
            'title' => 'Newsletter/Email Capture Content',
            'content' => 'Newsletter signup best practices: Offer clear value proposition ("Get 10% off your first order"). Keep form simple (email only, or email + first name). Place at footer and strategic points. Use compelling CTA ("Join & Save", "Get Exclusive Deals"). Include privacy reassurance ("We respect your privacy").',
            'tags' => 'ecommerce, newsletter, email, signup, conversion',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'seasonal_content',
            'title' => 'Seasonal Content Strategy',
            'content' => 'Plan seasonal content: Update hero banners for holidays/seasons. Create gift guide landing pages. Adjust meta descriptions with seasonal keywords ("Summer Sale", "Holiday Gift Ideas"). Feature seasonal products prominently. Create urgency around seasonal deadlines (shipping cutoffs).',
            'tags' => 'ecommerce, seasonal, holiday, gift-guide, content',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'mobile_commerce',
            'title' => 'Mobile Commerce Content',
            'content' => 'Mobile-optimized content: Shorter paragraphs (1-2 sentences). Larger CTA buttons (full-width on mobile). Collapsible sections for long descriptions. Swipeable image galleries. Sticky add-to-cart button. Quick-view product cards. Simplified navigation. Touch-friendly filters.',
            'tags' => 'ecommerce, mobile, mcommerce, responsive, ux',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'brand_storytelling',
            'title' => 'Brand Storytelling in E-Commerce',
            'content' => 'Tell your brand story: Share your origin and mission. Explain what makes your products unique. Highlight craftsmanship or manufacturing process. Feature the people behind the brand. Connect products to lifestyle and values. Use storytelling in about pages, product descriptions, and social content.',
            'tags' => 'ecommerce, brand, storytelling, mission, values',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'conversion_optimization',
            'title' => 'Conversion Rate Optimization Content',
            'content' => 'Content that converts: Clear value proposition above the fold. Benefit-driven headlines. Social proof near CTAs. Risk-reduction messaging (guarantees, free returns). Clear and simple checkout messaging. Progress indicators for multi-step processes. Error prevention and clear error messages.',
            'tags' => 'ecommerce, conversion, cro, optimization, ux',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'product_comparison',
            'title' => 'Product Comparison Content',
            'content' => 'Comparison content structure: Side-by-side feature table. Key differences highlighted. "Best for" recommendations per product. Price comparison. Star ratings for each option. Clear winner/recommendation. Help undecided customers make informed choices. Use comparison table HTML pattern.',
            'tags' => 'ecommerce, comparison, table, products, decision',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'buying_guide',
            'title' => 'Buying Guide Content Structure',
            'content' => 'Buying guide structure: Introduction (who this guide is for). Key factors to consider. Product type explanations. Comparison of options. Budget recommendations. Expert tips. FAQ section. Featured products with links. Use H2 for each section and include internal links to relevant products.',
            'tags' => 'ecommerce, buying-guide, content, education, seo',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'return_policy',
            'title' => 'Return Policy Content',
            'content' => 'Clear return policy content: Timeframe (30/60/90 days). Condition requirements. Process steps (numbered list). Refund method and timeline. Exceptions clearly stated. Contact information for returns. Free return shipping if offered. Link to return initiation form.',
            'tags' => 'ecommerce, returns, policy, refund, customer-service',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        // =====================================================================
        // CATEGORY: accessibility (20+ entries)
        // =====================================================================

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'alt_text',
            'title' => 'Alt Text Guidelines',
            'content' => 'Describe the image content and function. Be concise (125 characters max). Do not start with "image of" or "picture of". For decorative images, use empty alt="". For product images, include product name, color, and key visual details. For infographics, provide a summary of the data.',
            'tags' => 'accessibility, alt-text, images, screen-reader, wcag',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'alt_text',
            'title' => 'Alt Text for E-Commerce Images',
            'content' => 'Product image alt text: Include product name, color, material, and notable visual features. Example: "Navy blue organic cotton crew-neck t-shirt, front view" not "t-shirt" or "product image". For lifestyle images: describe the scene and product usage. For size/color swatches: describe the option.',
            'tags' => 'accessibility, alt-text, product, ecommerce, images',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'color_contrast',
            'title' => 'Color Contrast Requirements',
            'content' => 'WCAG AA requirements: Normal text (below 18px) must have at least 4.5:1 contrast ratio against background. Large text (18px+ or 14px+ bold) needs minimum 3:1 ratio. UI components and graphics need 3:1 ratio. Do not rely on color alone to convey information. Test with contrast checker tools.',
            'tags' => 'accessibility, color, contrast, wcag, readability',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'keyboard_navigation',
            'title' => 'Keyboard Navigation Requirements',
            'content' => 'All interactive elements must be keyboard-accessible. Use proper tab order (logical, follows visual layout). Provide visible focus indicators (outline or highlight). Ensure dropdown menus, modals, and accordions work with keyboard. Support Escape key to close overlays. No keyboard traps.',
            'tags' => 'accessibility, keyboard, navigation, focus, tabindex',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'aria_labels',
            'title' => 'ARIA Labels Best Practices',
            'content' => 'Use aria-label for elements without visible text (icon buttons). Use aria-describedby for additional descriptions. Use aria-labelledby to reference existing visible text. Do not override native HTML semantics with ARIA. First rule of ARIA: do not use ARIA if native HTML can do the job.',
            'tags' => 'accessibility, aria, labels, screen-reader, semantics',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'form_accessibility',
            'title' => 'Form Accessibility Requirements',
            'content' => 'Label all form fields with <label for="id">. Use fieldset and legend for related field groups. Provide clear error messages linked to fields with aria-describedby. Mark required fields with aria-required="true" and visual indicator. Include input purpose with autocomplete attributes.',
            'tags' => 'accessibility, form, label, input, validation',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'semantic_html',
            'title' => 'Semantic HTML for Accessibility',
            'content' => 'Use semantic elements: <header>, <nav>, <main>, <article>, <section>, <aside>, <footer>. Use <button> for actions, <a> for navigation. Use heading levels correctly (h1-h6). Use <table> with <th> for data tables. Use <ul>/<ol> for lists. Semantic HTML provides structure for assistive technology.',
            'tags' => 'accessibility, semantic, html, structure, landmarks',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'text_content',
            'title' => 'Accessible Text Content',
            'content' => 'Write in plain language (8th-grade reading level). Use short sentences and paragraphs. Avoid jargon without explanation. Provide text alternatives for non-text content. Use descriptive link text (not "click here"). Ensure text can be resized to 200% without loss of functionality.',
            'tags' => 'accessibility, text, readability, plain-language, content',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'multimedia',
            'title' => 'Accessible Multimedia Content',
            'content' => 'Videos: Provide captions/subtitles. Include audio descriptions for visual content. Offer transcripts. Do not autoplay with sound. Images: Alt text for informative images, empty alt for decorative. Audio: Provide transcripts. Animations: Respect prefers-reduced-motion. Allow pause/stop for moving content.',
            'tags' => 'accessibility, video, audio, captions, transcripts',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'navigation',
            'title' => 'Accessible Navigation Patterns',
            'content' => 'Provide skip navigation link ("Skip to main content"). Use consistent navigation structure across pages. Breadcrumbs for location awareness. Current page indicator in navigation. Dropdown menus accessible with keyboard and screen readers. Mobile hamburger menu with proper ARIA.',
            'tags' => 'accessibility, navigation, skip-nav, breadcrumb, menu',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'tables',
            'title' => 'Accessible Data Tables',
            'content' => 'Use <table> element for tabular data (not for layout). Include <caption> for table title. Use <th> with scope="col" or scope="row" for headers. Complex tables need id/headers attributes. Keep tables simple when possible. Provide a text summary for complex data tables.',
            'tags' => 'accessibility, table, data, headers, scope',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'error_handling',
            'title' => 'Accessible Error Handling',
            'content' => 'Error messages: Be specific about what went wrong and how to fix it. Associate errors with form fields using aria-describedby. Use role="alert" for dynamic error messages. Do not rely solely on color to indicate errors. Provide error summary at the top of forms. Focus management: move focus to first error.',
            'tags' => 'accessibility, errors, validation, aria, forms',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'motion_animation',
            'title' => 'Accessible Motion and Animation',
            'content' => 'Respect prefers-reduced-motion media query. Allow users to pause, stop, or hide auto-updating content. Avoid content that flashes more than 3 times per second (seizure risk). Parallax effects should be optional. Carousel auto-advance should have pause control. Transitions should be subtle.',
            'tags' => 'accessibility, motion, animation, reduced-motion, seizure',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'touch_mobile',
            'title' => 'Mobile Accessibility',
            'content' => 'Touch targets minimum 44x44px. Adequate spacing between interactive elements (at least 8px). Support both portrait and landscape orientations. Do not disable pinch-to-zoom. Ensure gestures have alternatives (swipe = buttons). Input fields sized appropriately for mobile keyboards.',
            'tags' => 'accessibility, mobile, touch, target-size, responsive',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'language',
            'title' => 'Language and Internationalization',
            'content' => 'Set lang attribute on <html> element. Mark language changes within content with lang attribute on the element. Provide translations for multi-language sites. Use Unicode for special characters. Avoid text in images (cannot be translated). Support right-to-left (RTL) layouts if applicable.',
            'tags' => 'accessibility, language, i18n, rtl, translations',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'focus_management',
            'title' => 'Focus Management Patterns',
            'content' => 'Manage focus for dynamic content: Move focus to new content after AJAX load. Return focus to trigger element when modal closes. Trap focus within modals and dialogs. Use tabindex="-1" for programmatically focusable elements. Never use tabindex greater than 0. Ensure logical focus order.',
            'tags' => 'accessibility, focus, tabindex, modal, dialog',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'live_regions',
            'title' => 'ARIA Live Regions',
            'content' => 'Use aria-live for dynamic content updates: aria-live="polite" for non-urgent updates (search results, cart count). aria-live="assertive" for urgent messages (errors, alerts). Use role="status" for status messages. Use role="alert" for important alerts. Avoid excessive live region announcements.',
            'tags' => 'accessibility, aria-live, dynamic, updates, screen-reader',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'wcag_compliance',
            'title' => 'WCAG 2.1 AA Compliance Summary',
            'content' => 'WCAG 2.1 AA key requirements: Perceivable (text alternatives, captions, contrast, reflow). Operable (keyboard, timing, seizures, navigation). Understandable (readable, predictable, error prevention). Robust (compatible with assistive technology). Test with screen readers, keyboard-only, and automated tools.',
            'tags' => 'accessibility, wcag, compliance, aa, requirements',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        // =====================================================================
        // CATEGORY: html_patterns (30+ entries)
        // =====================================================================

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'feature_list',
            'title' => 'Feature List HTML Pattern',
            'content' => '<ul class="feature-list"><li><strong>Feature Name:</strong> Feature description with customer benefit</li><li><strong>Feature Name:</strong> Feature description with customer benefit</li><li><strong>Feature Name:</strong> Feature description with customer benefit</li></ul>',
            'tags' => 'html, pattern, feature-list, ul, li, product',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'feature_list',
            'title' => 'Icon Feature List HTML',
            'content' => '<div class="feature-grid"><div class="feature-item"><span class="feature-icon">ICON</span><h3>Feature Title</h3><p>Brief description of the feature and its benefit to the customer.</p></div></div>. Use with 3 or 4-column grid layout. Replace ICON with actual icon HTML or image.',
            'tags' => 'html, pattern, feature, icon, grid',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'comparison_table',
            'title' => 'Comparison Table HTML Pattern',
            'content' => '<table class="comparison-table"><thead><tr><th>Feature</th><th>Basic</th><th>Premium</th><th>Pro</th></tr></thead><tbody><tr><td>Feature 1</td><td>Yes</td><td>Yes</td><td>Yes</td></tr><tr><td>Feature 2</td><td>No</td><td>Yes</td><td>Yes</td></tr></tbody></table>. Use scope="col" on th elements for accessibility.',
            'tags' => 'html, pattern, table, comparison, products',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'specifications_table',
            'title' => 'Specifications Table HTML',
            'content' => '<table class="specs-table"><tbody><tr><th scope="row">Material</th><td>100% Organic Cotton</td></tr><tr><th scope="row">Weight</th><td>180 GSM</td></tr><tr><th scope="row">Dimensions</th><td>S / M / L / XL</td></tr><tr><th scope="row">Care</th><td>Machine wash cold</td></tr></tbody></table>',
            'tags' => 'html, pattern, table, specifications, product',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'faq_section',
            'title' => 'FAQ Section with Schema HTML',
            'content' => '<div itemscope itemtype="https://schema.org/FAQPage"><div itemscope itemprop="mainEntity" itemtype="https://schema.org/Question"><h3 itemprop="name">What is your return policy?</h3><div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer"><p itemprop="text">We offer a 30-day return policy for all unused items in original packaging.</p></div></div></div>',
            'tags' => 'html, pattern, faq, schema, structured-data',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'testimonial',
            'title' => 'Testimonial HTML Pattern',
            'content' => '<blockquote class="testimonial"><p>"This is the best product I have ever purchased. The quality exceeded my expectations and customer service was outstanding."</p><cite>-- Jane Doe, Verified Buyer</cite><div class="rating">5 out of 5 stars</div></blockquote>',
            'tags' => 'html, pattern, testimonial, review, blockquote',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'testimonial',
            'title' => 'Testimonial Slider HTML',
            'content' => '<div class="testimonial-slider"><div class="testimonial-slide"><div class="stars">5 stars</div><blockquote><p>"Quote text here"</p></blockquote><div class="author"><img src="avatar.jpg" alt="Customer Name" width="50" height="50"><cite>Customer Name</cite><span>Location</span></div></div></div>',
            'tags' => 'html, pattern, testimonial, slider, carousel',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'responsive_image',
            'title' => 'Responsive Image HTML Pattern',
            'content' => '<picture><source srcset="image.webp" type="image/webp"><source srcset="image.jpg" type="image/jpeg"><img src="image.jpg" alt="Descriptive alt text" width="800" height="600" loading="lazy"></picture>. Use picture element for format fallbacks. Always include width/height to prevent CLS.',
            'tags' => 'html, pattern, image, responsive, picture, webp',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'responsive_image',
            'title' => 'Hero Image with Overlay Text',
            'content' => '<div class="hero-banner" style="position:relative;"><img src="hero.webp" alt="Hero description" width="1920" height="600" style="width:100%;height:auto;"><div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;color:#fff;"><h2>Headline Text</h2><p>Supporting message</p><a href="URL" class="pagebuilder-button-primary">Shop Now</a></div></div>',
            'tags' => 'html, pattern, hero, banner, overlay, image',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'trust_badges',
            'title' => 'Trust Badges HTML Pattern',
            'content' => '<div class="trust-badges" style="display:flex;justify-content:center;gap:30px;padding:20px 0;"><div class="badge" style="text-align:center;"><img src="icon-shipping.svg" alt="" width="48" height="48"><p><strong>Free Shipping</strong><br>On orders over $50</p></div><div class="badge" style="text-align:center;"><img src="icon-returns.svg" alt="" width="48" height="48"><p><strong>Easy Returns</strong><br>30-day money back</p></div><div class="badge" style="text-align:center;"><img src="icon-secure.svg" alt="" width="48" height="48"><p><strong>Secure Payment</strong><br>SSL encrypted</p></div></div>',
            'tags' => 'html, pattern, trust, badges, shipping, security',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'breadcrumb',
            'title' => 'Breadcrumb with Schema HTML',
            'content' => '<nav aria-label="Breadcrumb"><ol itemscope itemtype="https://schema.org/BreadcrumbList"><li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem"><a itemprop="item" href="/"><span itemprop="name">Home</span></a><meta itemprop="position" content="1"></li><li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem"><a itemprop="item" href="/category"><span itemprop="name">Category</span></a><meta itemprop="position" content="2"></li></ol></nav>',
            'tags' => 'html, pattern, breadcrumb, schema, navigation',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'accordion',
            'title' => 'Accordion/Collapsible HTML Pattern',
            'content' => '<details class="accordion-item"><summary><h3>Accordion Title / Question</h3></summary><div class="accordion-content"><p>Detailed answer or content that is revealed when the user expands this section. Use for FAQ, product details, shipping info, etc.</p></div></details>. Native HTML details/summary requires no JavaScript and is accessible by default.',
            'tags' => 'html, pattern, accordion, collapsible, details, faq',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'card_layout',
            'title' => 'Product/Category Card HTML',
            'content' => '<div class="card"><a href="PRODUCT_URL"><img src="product.webp" alt="Product Name" width="400" height="400" loading="lazy"></a><div class="card-body"><h3><a href="PRODUCT_URL">Product Name</a></h3><p class="price">$29.99</p><p class="description">Short product description</p><a href="PRODUCT_URL" class="pagebuilder-button-primary">Shop Now</a></div></div>',
            'tags' => 'html, pattern, card, product, category, grid',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'card_layout',
            'title' => 'Category Card Grid HTML',
            'content' => '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;"><div class="category-card" style="text-align:center;"><a href="CATEGORY_URL"><img src="category.webp" alt="Category Name" width="400" height="300" loading="lazy" style="width:100%;height:auto;border-radius:8px;"><h3 style="margin-top:10px;">Category Name</h3></a></div><!-- Repeat for each category --></div>',
            'tags' => 'html, pattern, category, card, grid, layout',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'usp_bar',
            'title' => 'USP Bar HTML Pattern',
            'content' => '<div class="usp-bar" style="display:flex;justify-content:space-around;padding:15px;background:#f5f5f5;"><div class="usp-item"><strong>Free Shipping</strong> on $50+</div><div class="usp-item"><strong>30-Day</strong> Returns</div><div class="usp-item"><strong>Secure</strong> Payment</div><div class="usp-item"><strong>24/7</strong> Support</div></div>',
            'tags' => 'html, pattern, usp, bar, trust, shipping',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'newsletter',
            'title' => 'Newsletter Signup HTML Pattern',
            'content' => '<div class="newsletter-section" style="text-align:center;padding:40px 20px;background:#f0f0f0;"><h2>Join Our Newsletter</h2><p>Get 10% off your first order plus exclusive deals and new arrivals.</p><form action="NEWSLETTER_URL" method="post"><div style="display:flex;justify-content:center;gap:10px;max-width:500px;margin:0 auto;"><input type="email" name="email" placeholder="Enter your email" aria-label="Email address" required style="flex:1;padding:10px;"><button type="submit" class="pagebuilder-button-primary">Subscribe</button></div></form></div>',
            'tags' => 'html, pattern, newsletter, email, signup, form',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'pricing_table',
            'title' => 'Pricing Table HTML Pattern',
            'content' => '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;"><div class="pricing-card" style="border:1px solid #ddd;padding:30px;text-align:center;border-radius:8px;"><h3>Basic</h3><p class="price" style="font-size:2em;font-weight:bold;">$9.99<span style="font-size:0.5em;">/mo</span></p><ul style="list-style:none;padding:0;"><li>Feature 1</li><li>Feature 2</li></ul><a href="URL" class="pagebuilder-button-primary">Choose Plan</a></div></div>',
            'tags' => 'html, pattern, pricing, table, plans, subscription',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'timeline',
            'title' => 'Timeline/Process Steps HTML',
            'content' => '<div class="steps-timeline"><div class="step"><div class="step-number">1</div><h3>Step Title</h3><p>Description of what happens in this step.</p></div><div class="step"><div class="step-number">2</div><h3>Step Title</h3><p>Description of what happens in this step.</p></div><div class="step"><div class="step-number">3</div><h3>Step Title</h3><p>Description of what happens in this step.</p></div></div>',
            'tags' => 'html, pattern, timeline, steps, process, how-to',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'call_to_action',
            'title' => 'CTA Banner HTML Pattern',
            'content' => '<div class="cta-banner" style="background:#1a1a2e;color:#fff;padding:40px;text-align:center;border-radius:8px;"><h2>Ready to Get Started?</h2><p style="margin:10px 0 20px;">Join thousands of satisfied customers and experience the difference.</p><a href="URL" class="pagebuilder-button-primary" style="display:inline-block;">Shop Now</a></div>',
            'tags' => 'html, pattern, cta, banner, conversion',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'video_embed',
            'title' => 'Responsive Video Embed HTML',
            'content' => '<div class="video-wrapper" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;"><iframe src="https://www.youtube.com/embed/VIDEO_ID" title="Video title for accessibility" style="position:absolute;top:0;left:0;width:100%;height:100%;" frameborder="0" allowfullscreen loading="lazy"></iframe></div>. The 56.25% padding maintains 16:9 aspect ratio.',
            'tags' => 'html, pattern, video, embed, youtube, responsive',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'stat_counter',
            'title' => 'Statistics/Counter HTML Pattern',
            'content' => '<div style="display:flex;justify-content:space-around;padding:40px 0;text-align:center;"><div class="stat"><div style="font-size:2.5em;font-weight:bold;color:#1976D2;">10,000+</div><div>Happy Customers</div></div><div class="stat"><div style="font-size:2.5em;font-weight:bold;color:#1976D2;">500+</div><div>Products</div></div><div class="stat"><div style="font-size:2.5em;font-weight:bold;color:#1976D2;">15+</div><div>Years Experience</div></div></div>',
            'tags' => 'html, pattern, statistics, counter, numbers, social-proof',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'team_grid',
            'title' => 'Team Members Grid HTML',
            'content' => '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:30px;"><div class="team-member" style="text-align:center;"><img src="person.webp" alt="Person Name, Job Title" width="200" height="200" style="border-radius:50%;"><h3>Person Name</h3><p style="color:#666;">Job Title</p><p>Brief bio or description of expertise.</p></div></div>',
            'tags' => 'html, pattern, team, grid, about-us, people',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'alert_banner',
            'title' => 'Alert/Notice Banner HTML',
            'content' => '<div role="alert" style="background:#FFF3E0;border-left:4px solid #FF9800;padding:15px 20px;margin:20px 0;border-radius:4px;"><strong>Important:</strong> Message text here. Use for shipping delays, policy changes, promotions, or important announcements.</div>. Use role="alert" for dynamic content, or role="note" for static info.',
            'tags' => 'html, pattern, alert, notice, banner, announcement',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'definition_list',
            'title' => 'Definition List HTML Pattern',
            'content' => '<dl class="product-specs"><dt>Material</dt><dd>100% Organic Cotton</dd><dt>Weight</dt><dd>180 GSM</dd><dt>Origin</dt><dd>Made in Portugal</dd><dt>Certification</dt><dd>GOTS Certified</dd></dl>. Use <dl> for name-value pairs like product specifications, glossary terms, or metadata.',
            'tags' => 'html, pattern, definition-list, specs, dl, dt, dd',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'social_links',
            'title' => 'Social Media Links HTML',
            'content' => '<div class="social-links" style="display:flex;gap:15px;justify-content:center;"><a href="FACEBOOK_URL" aria-label="Follow us on Facebook" target="_blank" rel="noopener noreferrer">Facebook Icon</a><a href="INSTAGRAM_URL" aria-label="Follow us on Instagram" target="_blank" rel="noopener noreferrer">Instagram Icon</a><a href="TWITTER_URL" aria-label="Follow us on X (Twitter)" target="_blank" rel="noopener noreferrer">X Icon</a></div>. Always include aria-label and rel="noopener noreferrer" for external links.',
            'tags' => 'html, pattern, social, links, facebook, instagram',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'shipping_table',
            'title' => 'Shipping Methods Table HTML',
            'content' => '<table class="shipping-table"><thead><tr><th scope="col">Method</th><th scope="col">Delivery Time</th><th scope="col">Cost</th></tr></thead><tbody><tr><td>Standard Shipping</td><td>5-7 Business Days</td><td>Free on $50+</td></tr><tr><td>Express Shipping</td><td>2-3 Business Days</td><td>$9.99</td></tr><tr><td>Next-Day Delivery</td><td>1 Business Day</td><td>$19.99</td></tr></tbody></table>',
            'tags' => 'html, pattern, shipping, table, delivery, methods',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'size_chart',
            'title' => 'Size Chart Table HTML',
            'content' => '<table class="size-chart"><thead><tr><th scope="col">Size</th><th scope="col">Chest (in)</th><th scope="col">Waist (in)</th><th scope="col">Length (in)</th></tr></thead><tbody><tr><td>S</td><td>34-36</td><td>28-30</td><td>27</td></tr><tr><td>M</td><td>38-40</td><td>32-34</td><td>28</td></tr><tr><td>L</td><td>42-44</td><td>36-38</td><td>29</td></tr><tr><td>XL</td><td>46-48</td><td>40-42</td><td>30</td></tr></tbody></table>',
            'tags' => 'html, pattern, size-chart, table, measurements, apparel',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'howto_schema',
            'title' => 'HowTo Schema HTML Pattern',
            'content' => '<div itemscope itemtype="https://schema.org/HowTo"><h2 itemprop="name">How to [Do Something]</h2><div itemprop="step" itemscope itemtype="https://schema.org/HowToStep"><h3 itemprop="name">Step 1: Title</h3><p itemprop="text">Step description with details.</p></div><div itemprop="step" itemscope itemtype="https://schema.org/HowToStep"><h3 itemprop="name">Step 2: Title</h3><p itemprop="text">Step description with details.</p></div></div>',
            'tags' => 'html, pattern, howto, schema, steps, structured-data',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'contact_info',
            'title' => 'Contact Information HTML',
            'content' => '<address itemscope itemtype="https://schema.org/Organization"><strong itemprop="name">Company Name</strong><br><span itemprop="address" itemscope itemtype="https://schema.org/PostalAddress"><span itemprop="streetAddress">123 Main St</span><br><span itemprop="addressLocality">City</span>, <span itemprop="addressRegion">State</span> <span itemprop="postalCode">12345</span></span><br>Phone: <a href="tel:+1234567890" itemprop="telephone">(123) 456-7890</a><br>Email: <a href="mailto:info@example.com" itemprop="email">info@example.com</a></address>',
            'tags' => 'html, pattern, contact, address, schema, organization',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'quote_block',
            'title' => 'Pull Quote / Highlight Block HTML',
            'content' => '<div style="border-left:4px solid #1976D2;padding:20px;margin:20px 0;background:#f8f9fa;font-style:italic;font-size:1.1em;"><p>"A compelling quote or key takeaway from the content that deserves emphasis and draws the reader\'s attention."</p></div>',
            'tags' => 'html, pattern, quote, highlight, pullquote, emphasis',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'html_patterns',
            'subcategory' => 'return_steps',
            'title' => 'Return Process Steps HTML',
            'content' => '<div class="return-process"><ol><li><strong>Initiate Return:</strong> Log into your account and select the order to return.</li><li><strong>Print Label:</strong> Download and print the prepaid return shipping label.</li><li><strong>Ship Item:</strong> Pack the item in original packaging and drop off at any carrier location.</li><li><strong>Receive Refund:</strong> Refund processed within 5-7 business days of receiving your return.</li></ol></div>',
            'tags' => 'html, pattern, return, steps, process, ecommerce',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'structured_data',
            'title' => 'Organization Schema Markup',
            'content' => 'Organization schema (site-wide): {"@context":"https://schema.org","@type":"Organization","name":"Company Name","url":"https://example.com","logo":"https://example.com/logo.png","contactPoint":{"@type":"ContactPoint","telephone":"+1-123-456-7890","contactType":"customer service"},"sameAs":["https://facebook.com/brand","https://instagram.com/brand"]}.',
            'tags' => 'seo, schema, organization, json-ld, structured-data',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'seo',
            'subcategory' => 'structured_data',
            'title' => 'BreadcrumbList Schema Markup',
            'content' => 'BreadcrumbList schema: {"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","position":1,"name":"Home","item":"https://example.com/"},{"@type":"ListItem","position":2,"name":"Category","item":"https://example.com/category"}]}. Implement on all pages for better search appearance.',
            'tags' => 'seo, schema, breadcrumb, json-ld, navigation',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'product_description',
            'title' => 'Product Description for Different Industries',
            'content' => 'Fashion: Focus on fabric, fit, styling tips, occasions. Electronics: Specs, compatibility, use cases, warranty. Food: Ingredients, taste profile, dietary info, serving suggestions. Home: Dimensions, materials, room suitability, care. Beauty: Ingredients, skin type, application tips, results timeline.',
            'tags' => 'ecommerce, product, description, industry, vertical',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'ecommerce',
            'subcategory' => 'product_description',
            'title' => 'Avoiding Common Description Mistakes',
            'content' => 'Common mistakes to avoid: Copying manufacturer descriptions (duplicate content). Using vague superlatives ("best ever", "amazing"). Neglecting to mention the target audience. Writing walls of text without formatting. Missing key specifications. Ignoring search intent. Not addressing objections or concerns.',
            'tags' => 'ecommerce, product, description, mistakes, copywriting',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'layout_pattern',
            'title' => 'Warranty/Guarantee Page Layout',
            'content' => 'Warranty page structure: Row 1 (page title + trust messaging) -> Row 2 (warranty types and coverage in comparison table) -> Row 3 (how to make a warranty claim - numbered steps) -> Row 4 (2-col: FAQ about warranty + contact info) -> Row 5 (CTA to register product or contact support).',
            'tags' => 'pagebuilder, layout, warranty, guarantee, policy, cms',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'pagebuilder',
            'subcategory' => 'layout_pattern',
            'title' => 'Gift Guide Landing Page',
            'content' => 'Gift guide page: Row 1 (hero banner with "Gift Guide for [Occasion]" headline) -> Row 2 (gift categories by price range: Under $25, $25-$50, $50-$100, $100+) -> Row 3 (curated product picks in grid) -> Row 4 (gift wrapping and personalization options) -> Row 5 (shipping deadline banner).',
            'tags' => 'pagebuilder, layout, gift-guide, holiday, seasonal, landing-page',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        $entries[] = [
            'category' => 'accessibility',
            'subcategory' => 'skip_links',
            'title' => 'Skip Navigation Links',
            'content' => 'Add skip links at the very beginning of the page: <a href="#main-content" class="skip-link">Skip to main content</a>. Style to be visually hidden until focused: .skip-link { position:absolute; left:-9999px; } .skip-link:focus { position:static; }. Essential for keyboard users to bypass repetitive navigation.',
            'tags' => 'accessibility, skip-link, navigation, keyboard, a11y',
            'is_active' => 1,
            'sort_order' => $sort++,
        ];

        return $entries;
    }
}
