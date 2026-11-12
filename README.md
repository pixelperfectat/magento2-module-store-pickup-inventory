# pixelperfectat/magento2-module-store-pickup-inventory

MSI companion for `pixelperfectat/magento2-module-store-pickup`: offers pickup-active inventory sources as pickup locations, only where the cart is salable, and ships pickup orders from that source.

Work in progress, not released yet.

## Pickup locations from inventory sources

Every source assigned to the stock of the store's website that is enabled and has "Use as a Pickup Location" set becomes a pickup location. The location code is the source code when that is a valid location code (`a-z`, `0-9`, `_`, at most 64 characters); any other source code becomes `s` plus the first 16 hex characters of its SHA-1 hash. The name and description come from the source's frontend name and frontend description, the sort order from the source's priority in the stock.

If a location configured in the core module has the same code as a source, the configured location wins and the collision is logged.

Store-view texts (translated names and notes per store view) stay a feature of the core's configured locations in `0.1.0`; locations from inventory sources have one text for all store views.
