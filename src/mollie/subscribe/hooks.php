<?php
/**
 * Webscout: Mollie Recurring, hooks. Op de server inladen via includes/hooks/mollie_subscribe.php:
 *
 *   <?php require_once ROOTDIR . '/modules/gateways/mollie/subscribe/hooks.php';
 *
 * - InvoicePaid: lopende SEPA-inning annuleren als de klant de factuur intussen anders betaalde.
 * - DailyCronJob: nacontrole voor gemiste webhooks en late terugboekingen.
 * - ClientAreaFooterOutput: op de betaalpagina van een factuur op domiciliëring een knop naar Mollie in plaats van
 *   de rekeningvelden van WHMCS (WHMCS toont daar geen remote input voor een gateway van het type Bank).
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

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if (!isset($_GET['rp']) || strpos((string) $_GET['rp'], '/invoice/') !== 0 || !mollie_subscribe_hook_boot())
        return '';

    try {
        $userId = 0;
        if (class_exists('\\WHMCS\\Authentication\\CurrentUser')) {
            $current = new \WHMCS\Authentication\CurrentUser();
            $client = $current->client();
            $userId = $client ? (int) $client->id : 0;
        }
        if (!$userId && isset($_SESSION['uid']))
            $userId = (int) $_SESSION['uid'];

        return mollie_subscribe_invoice_pay_output((string) $_GET['rp'], $userId);
    } catch (\Exception $e) {
        mollie_subscribe_activity('hook betaalpagina: ' . $e->getMessage());
        return '';
    }
});
