# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-25

### Added

- Enabled inventory sources with "Use as a Pickup Location" that are assigned to the website's stock become pickup
  locations, through a provider (`msi_sources`) in the core's location composite. A source code that is not a valid
  location code is mapped to `s` plus 16 hex characters of its SHA-1 hash.
- Availability check (`msi_salable`) in the core's availability composite: a source location is offered only when every
  shippable cart item is in stock at that source. Reservations, `min_qty`, backorders and the stock-level salable
  flag are not considered.
- Ready-for-pickup orders ship from the pickup source, through a plugin on the core's shipment arguments builder.
- README with requirements, source fields used, code mapping, provider order, sort order, availability semantics
  and known limits.

[Unreleased]: https://github.com/pixelperfectat/magento2-module-store-pickup-inventory/compare/0.1.0...HEAD
[0.1.0]: https://github.com/pixelperfectat/magento2-module-store-pickup-inventory/releases/tag/0.1.0
