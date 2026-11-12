# Store Pickup for MSI

Multi-Source Inventory companion for
[`pixelperfectat/magento2-module-store-pickup`](https://github.com/pixelperfectat/magento2-module-store-pickup).
It offers pickup-active inventory sources as pickup locations, offers a location only where the cart is in stock, and
ships "ready for pickup" orders from that source. The module is not released yet.

![Pickup rates at checkout: only the source that holds the cart item
is offered](docs/screenshots/hyva-checkout-msi-pickup-rates.jpg)

- Every enabled source with "Use as a Pickup Location" that is assigned to the stock of the store's website becomes a
  pickup location, next to the locations configured in the core module.
- A source is offered at checkout only when every shippable item of the cart is in stock there.
- The core module's "Ready for Pickup" action creates the shipment from the source the order was placed for, and
  MSI deducts the quantity from that source.

Single-source shops do not need this module; the core module's configured locations are enough.

## Requirements

- PHP `^8.3`
- `pixelperfectat/magento2-module-store-pickup` `^0.1`
- Magento Open Source or Mage-OS with MSI (`magento/module-inventory-api`,
  `magento/module-inventory-in-store-pickup-api`, `magento/module-inventory-shipping`)

## Installation

```bash
composer require pixelperfectat/magento2-module-store-pickup-inventory
bin/magento setup:upgrade
```

## Configuration

There is no configuration of its own. Mark the sources under Stores > Inventory > Sources: enable the source, set
"Use as a Pickup Location" and fill in the address (and, if you like, the frontend name and description). Carrier
settings, payment rules and the configured locations live in the core module; see its
[README](https://github.com/pixelperfectat/magento2-module-store-pickup#configuration).

Source flags and fields that are used:

| Source field | Used for |
|---|---|
| Enabled, Use as a Pickup Location | A source is an active pickup location only when both are set. |
| Frontend Name, Frontend Description | Name and note of the location. The source name is the fallback for the name. |
| Street, postcode, city, country, region, phone | The location address. |
| Priority in the stock | The sort order, see below. |

## How it works

The module only adds members to composites of the core module and one plugin; it does not change how the core
works.

### Location providers and collisions

The core asks its location providers in order: the configured locations (`config`, sort order 10) first, the
inventory sources (`msi_sources`, sort order 20) second. The first provider that knows a code owns it, whether the
location is active or not. So a configured location with the same code as a source wins, and the source with that code
is not offered. Pick source codes that differ from your configured location codes.

### Location codes

A source code that is a valid location code (`a-z`, `0-9`, `_`, at most 64 characters) is used unchanged. Any other
source code becomes `s` plus the first 16 hex characters of its SHA-1 hash. The shipping method of the rate is
`pppickup_<location code>`, and the order stores the location code. The algorithm, hash length and prefix are
constructor arguments of `SourceCodeMapper`, overridable in `di.xml`; changing them changes the codes of all sources
with an invalid code.

### Sort order

The sort order of a location is the position of its source in the stock's priority order times 10, counting all
sources assigned to the stock (also those that are not pickup locations). Configured locations keep their own sort
order. Rates are sorted by that value.

### Availability

A location bound to a source is offered only when every shippable item of the cart is available there. "Available"
means the raw source quantity is at least the wanted quantity and the source item is in stock:

- Reservations, `min_qty`, backorders and the stock-level salable flag are not considered. The checkout's own stock
  validation still guards order placement.
- Virtual items are skipped, and so are configurable and bundle parents (their children are checked, with the
  parent's quantity multiplied in).
- SKUs with "Manage Stock" off are available everywhere.
- Configured locations have no source and are always offered.

### Shipping from the pickup source

An after-plugin on the core's shipment arguments builder sets the shipment's source to the source of the pickup
location. Without it, MSI takes the only source assigned to the stock, or otherwise the default source. For an order
placed for a configured location the arguments stay untouched.

### Store-view texts

Locations from sources have one name and note for all store views in `0.1.0`. Translated texts per store view are a
feature of the configured locations in the core module only.

### MSI's native pickup UI

MSI's own pickup features (such as the "Notify Order is Ready for Pickup" button) work on its
`pickup_location_code` order attribute, which this module does not set, so they stay silent for `pppickup_*` orders. The
native Ship button still shows. Mirroring the location code into the native attribute
is planned as a later processor of the core's order-placement composite and is not part of `0.1.0`.

## Known limits

- A website that uses the default stock only has the `default` source, so only that source can be a pickup location
  (when it is flagged), and its placeholder address shows unless you fill it in.
- Availability is the raw source quantity, see above; a cart can pass the check while reservations leave it not
  salable at that source.
- MSI refuses to split the components of a "Ship Together" bundle over several sources, so such components can only
  be stocked at one source.
- Source locations have no opening hours, price or store-view texts; they are free of charge.

## License

MIT, see [LICENSE](LICENSE).
