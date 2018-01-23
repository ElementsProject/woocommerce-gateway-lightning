#!/bin/bash

set -eo pipefail
set -x

[ -z "$1" ] && { echo >&2 version required; exit 1; }

sed -i 's!"version": ".*"!"version": "'"$1"'"!' composer.json
sed -ri "s!Version:( *).*!Version:\\1$1!" woocommerce-gateway-lightning.php
sed -ri s!download/v[^/]+/woocommerce-gateway-lightning.zip!download/v$1/woocommerce-gateway-lightning.zip! README.md

./build.sh

read -p "Release v$1 ready, press Enter to publish"

git add README.md composer.json woocommerce-gateway-lightning.php
git commit -m v$1 && git tag v$1
git push && git push --tags

echo Attach zip file: https://github.com/ElementsProject/woocommerce-gateway-lightning/releases/edit/v$1
