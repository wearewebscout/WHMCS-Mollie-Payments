<?php
/**
 * Webscout: Mollie Recurring, eindpunt start. Logica in lib.php.
 */
require_once __DIR__ . '/../../../../init.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../mollie.php';
require_once __DIR__ . '/lib.php';

$whmcs->load_function('gateway');
$whmcs->load_function('invoice');
$whmcs->load_function('client');

$result = mollie_subscribe_handle_start($_POST);
http_response_code($result[0]);
header('Content-Type: text/html; charset=utf-8');
echo $result[1];
