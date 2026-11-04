<?php
/**
 * Webscout: Mollie Recurring, hooks. Op de server inladen via includes/hooks/mollie_subscribe.php:
 *
 *   <?php require_once ROOTDIR . '/modules/gateways/mollie/subscribe/hooks.php';
 *
 * - InvoicePaid: lopende SEPA-inning annuleren als de klant de factuur intussen anders betaalde.
 * - DailyCronJob: nacontrole voor gemiste webhooks en late terugboekingen.
 */

if (!defined('WHMCS'))
    die('This file cannot be accessed directly');

function mollie_subscribe_hook_boot()
{
    if (!file_exists(__DIR__ . '/../vendor/autoload.php'))
        return false;

    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../mollie.php';
    require_once __DIR__ . '/lib.php';

    if (isset($GLOBALS['whmcs']) && is_object($GLOBALS['whmcs'])) {
        if (!function_exists('getGatewayVariables'))
            $GLOBALS['whmcs']->load_function('gateway');

        if (!function_exists('addInvoicePayment'))
            $GLOBALS['whmcs']->load_function('invoice');
    }

    return true;
}

add_hook('InvoicePaid', 1, function ($vars) {
    if (!mollie_subscribe_hook_boot())
        return;

    try {
        mollie_subscribe_on_invoice_paid((int) $vars['invoiceid']);
    } catch (\Exception $e) {
        mollie_subscribe_activity('hook InvoicePaid: ' . $e->getMessage());
    }
});

add_hook('DailyCronJob', 1, function ($vars) {
    if (!mollie_subscribe_hook_boot())
        return;

    try {
        $result = mollie_subscribe_daily_sweep();

        if ($result)
            mollie_subscribe_activity('nacontrole ' . count($result) . ' betalingen');
    } catch (\Exception $e) {
        mollie_subscribe_activity('nacontrole: ' . $e->getMessage());
    }
});
