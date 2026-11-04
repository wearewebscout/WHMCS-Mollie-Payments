<?php
/**
 * Webscout: Mollie Recurring, SEPA-domiciliëring, mandaat type directdebit via een eerste betaling met Bancontact, iDEAL, Belfius of KBC.
 * Gedeelde logica in mollie/subscribe/lib.php.
 */

if (!defined('WHMCS'))
    die('This file cannot be accessed directly');

require_once __DIR__ . '/mollie/mollie.php';
require_once __DIR__ . '/mollie/subscribe/lib.php';

function molliesubscribesepa_devapp_MetaData()
{
    return array(
        'DisplayName' => 'Mollie automatisch (domiciliëring)',
        'APIVersion' => '1.1',
        'gatewayType' => 'Bank',
        'failedEmail' => 'Direct Debit Payment Failed',
        'successEmail' => 'Direct Debit Payment Confirmation',
        'pendingEmail' => 'Direct Debit Payment Pending',
    );
}

function molliesubscribesepa_devapp_config()
{
    return mollie_subscribe_config('Mollie automatisch (domiciliëring)', 'bancontact,ideal,belfius,kbc');
}

function molliesubscribesepa_devapp_nolocalcc()
{
}

function molliesubscribesepa_devapp_remoteinput($params)
{
    return mollie_subscribe_remoteinput($params, 'molliesubscribesepa_devapp');
}

function molliesubscribesepa_devapp_remoteupdate($params)
{
    return mollie_subscribe_remoteupdate($params);
}

function molliesubscribesepa_devapp_storeremote($params)
{
    return mollie_subscribe_storeremote($params, 'molliesubscribesepa_devapp');
}

function molliesubscribesepa_devapp_capture($params)
{
    return mollie_subscribe_capture($params, 'molliesubscribesepa_devapp');
}

function molliesubscribesepa_devapp_refund($params)
{
    return mollie_subscribe_refund_from_whmcs($params);
}

function molliesubscribesepa_devapp_adminstatusmsg($params)
{
    return mollie_subscribe_adminstatusmsg($params);
}
