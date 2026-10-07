# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.8.10] - 2026-10-07

### Fixed
- Bulk Meta Editor: on Categories and CMS Pages, filtering or sorting by the SKU and Name columns no longer fails with "attribute name is invalid". Each column now uses the field of the selected entity type (categories: URL key and name; CMS pages: identifier and title), and the Identifier and Page Title filters also work on products.
