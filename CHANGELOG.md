# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.8.8] - 2026-10-03

### Fixed
- Product condition in structured data now follows the Merchant Feed Default Product Condition setting instead of failing with an error.
- The AI Overview score now detects the product brand, so the brand comparison signal is counted.
- A rule that sets noindex with nofollow now outputs noindex,nofollow on freshly rendered pages instead of noindex,follow.
- Google Merchant feed: a product that fails to render is skipped completely, so later products are no longer nested inside a broken item and the feed stays valid XML.
- Google Merchant feed: sale_price now uses the special price, matching sale_price_effective_date.
- Rule condition "is not empty" no longer matches the value 0, which already counts as empty.
- The bulk template cron log no longer counts entities that were skipped because they have no ID.
