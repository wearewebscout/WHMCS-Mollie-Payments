<?php
/**
 * Webscout: Mollie Recurring, kredietkaart, Mollie-mandaat type creditcard.
 * Gedeelde logica in mollie/subscribe/lib.php.
 */

if (!defined('WHMCS'))
    die('This file cannot be accessed directly');

require_once __DIR__ . '/mollie/mollie.php';
require_once __DIR__ . '/mollie/subscribe/lib.php';

function molliesubscribecard_devapp_MetaData()
{
    return array(
        'DisplayName' => 'Mollie automatisch (kaart)',
        'APIVersion' => '1.1',
        'failedEmail' => 'Credit Card Payment Failed',
        'successEmail' => 'Credit Card Payment Confirmation',
    );
}

function molliesubscribecard_devapp_config()
{
    return mollie_subscribe_config('Mollie automatisch (kaart)', 'creditcard');
}

function molliesubscribecard_devapp_nolocalcc()
{
}

function molliesubscribecard_devapp_remoteinput($params)
{
    return mollie_subscribe_remoteinput($params, 'molliesubscribecard_devapp');
}

function molliesubscribecard_devapp_remoteupdate($params)
{
    return mollie_subscribe_remoteupdate($params);
}

function molliesubscribecard_devapp_storeremote($params)
{
    return mollie_subscribe_storeremote($params, 'molliesubscribecard_devapp');
}

function molliesubscribecard_devapp_capture($params)
{
    return mollie_subscribe_capture($params, 'molliesubscribecard_devapp');
}

function molliesubscribecard_devapp_refund($params)
{
    return mollie_subscribe_refund_from_whmcs($params);
}

function molliesubscribecard_devapp_adminstatusmsg($params)
{
    return mollie_subscribe_adminstatusmsg($params);
}
