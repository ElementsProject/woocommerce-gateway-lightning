#!/bin/sh
composer install --no-dev
zip -r woocommerce-gateway-lightning.zip composer.json woocommerce-gateway-lightning.php vendor templates

