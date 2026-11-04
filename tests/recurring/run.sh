#!/usr/bin/env bash
# Kopieert de gateway uit src naar een nagebootste WHMCS en draait de testlijst.
# Eenmalig: (cd tests/recurring && composer install)
set -e
cd "$(dirname "$0")"
rm -rf root && mkdir -p root/modules/gateways
cp -R ../../src/. root/modules/gateways/
rm -rf root/modules/gateways/mollie/vendor
ln -s "$(pwd)/vendor" root/modules/gateways/mollie/vendor
php run.php
