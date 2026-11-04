# WHMCS Mollie Payments gateway ![GitHub All Releases](https://img.shields.io/github/downloads/0100Dev/WHMCS-Mollie-Payments/total) ![GitHub release (latest by date)](https://img.shields.io/github/v/release/0100Dev/WHMCS-Mollie-Payments)
Unofficial Mollie Payments gateway for WHMCS. This free gateway does NOT support Mollie Recurring, only Molie Payments. For Mollie Recurring we have a [paid](https://0100dev.nl/modules/whmcs#WHMCS%20Mollie%20Recurring) gateway. These gateways are not dependent on each other and can operate side by side, but also without each other.

Compatible with **all** WHMCS versions that are [supported by WHMCS](https://docs.whmcs.com/Long_Term_Support#WHMCS_Version_.26_LTS_Schedule).

### Installation
+ Log in to your (s)FTP.
+ Download the `WHMCS-Mollie-Payments.zip` from the [releases page](https://github.com/0100Dev/WHMCS-Mollie/releases) (**PLEASE NOTE:** **not** `Source code (zip)` or `Source code (tar.gz)`!).
+ Upload all the files from the `src` folder to the `/modules/gateways` folder in your WHMCS installation.

### Payment Methodes
All payment methods from Mollie are supported (which is also supported by their API). Enable the desired payment methods by activating the gateway in WHMCS.

Support for new payment methods must be added manually, due to the structure of this gateway. It can therefore take a while before a new payment method is supported. Is it urgent? Contact our paid support or add support for it yourself and contribute it back using a pull request.

You can use `Mollie Checkout` to use the Mollie Payments checkout pages. In this case it'll use the Mollie Payments checkout screen and show all enabled payment methodes in your Mollie account.

### Support
Support is best-effort through the Github issue tracker. Business support (responsetime within 24 hours, normally less then 1 hour) through our [website](https://0100dev.nl/) against our hourly rate at € 75,- excl. VAT. Please create an account at our website before contacting us.

[More information through Mollie about Mollie Payments](https://www.mollie.com/en/payments)

## Webscout-fork (wearewebscout)

Versie van de fork: `MOLLIE_WS_VERSION` in `src/mollie/mollie.php` (nu 1.1.0).

### Automatisch betalen (Mollie Recurring), sinds 1.1.0

2 tokenised gateways bovenop dezelfde Mollie-account:

| Gateway | WHMCS-type | Eerste betaling | Mandaat |
|---|---|---|---|
| `molliesubscribecard_devapp` (Mollie automatisch, kaart) | kaart | creditcard | creditcard |
| `molliesubscribesepa_devapp` (Mollie automatisch, domiciliëring) | Bank | bancontact, ideal, belfius, kbc | directdebit (SEPA) |

- Logica in `src/mollie/subscribe/lib.php`, eindpunten `start.php` (eerste betaling, uit de iframe naar Mollie), `return.php` (klant terug van Mollie) en `webhook.php`.
- Hooks in `src/mollie/subscribe/hooks.php` (InvoicePaid en DailyCronJob), in te laden via `includes/hooks/mollie_subscribe.php` met 1 regel: `<?php require_once ROOTDIR . '/modules/gateways/mollie/subscribe/hooks.php';`
- Eigen tabellen: `gateway_mollie_customers`, `gateway_mollie_mandates`, `gateway_mollie_recurring` (maakt de gateway zelf aan).
- Remote token in WHMCS: `cst_...:mdt_...`.
- Tests: `cd tests/recurring && composer install && ./run.sh` (nagebootste WHMCS en Mollie). Rooktest tegen de Mollie-testmodus: `MOLLIE_TEST_KEY=test_... php tests/recurring/mollie_testmode.php`.
