# WooCommerce Gateway Plugin for Lightning

Gateway plugin to accept Lightning payments at [WooCommerce](https://woocommerce.com) stores,
based on [Lightning Charge](https://github.com/ElementsProject/lightning-charge).

## Installation

Requires PHP >= 5.6 and the `php-curl` and `php-gd` extensions.

1. Setup [Lightning Charge](https://github.com/ElementsProject/lightning-charge).

2. [Download woocommerce-gateway-lightning.zip](https://github.com/ElementsProject/woocommerce-gateway-lightning/releases/download/v0.2.5/woocommerce-gateway-lightning.zip)

3. Install and enable the plugin on your WordPress installation.

4. Under the WordPress administration panel, go to `WooCommerce -> Settings -> Checkout -> Lightning` to configure your Lightning Charge server URL and API token.

That's it! The "Bitcoin Lightning" payment option should now be available in your checkout page.

## Screenshots

<img src="https://i.imgur.com/Q67y5l2.png" width="45%"></img>
<img src="https://i.imgur.com/958Bm64.png" width="45%"></img>
<img src="https://i.imgur.com/QbWiks1.png" width="45%"></img>
<a href="https://i.imgur.com/UBCdmLR.png"><img src="https://i.imgur.com/JgwuFSl.png" width="45%"></img></a>

## License

MIT
