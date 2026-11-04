<?php
/**
 * Rooktest tegen de echte Mollie-API in TESTMODUS, zonder WHMCS. Controleert dat de payloads
 * van de gateway door Mollie aanvaard worden.
 *
 *   MOLLIE_TEST_KEY=test_xxx php tests/recurring/mollie_testmode.php
 *
 * Wat het doet: customer maken, eerste betaling (sequenceType=first) maken en de checkout-URL tonen,
 * een SEPA-mandaat rechtstreeks aanmaken (kan enkel in testmodus), een recurring betaling op dat
 * mandaat, en het mandaat weer intrekken. Weigert een live-sleutel.
 */

require __DIR__ . '/vendor/autoload.php';

$key = trim((string) getenv('MOLLIE_TEST_KEY'));

if (strpos($key, 'test_') !== 0) {
    fwrite(STDERR, "Zet MOLLIE_TEST_KEY op een Mollie-TESTsleutel (test_...). Live-sleutels worden geweigerd.\n");
    exit(2);
}

$mollie = new \Mollie\Api\MollieApiClient();
$mollie->setApiKey($key);

$customer = $mollie->customers->create(array('name' => 'Webscout rooktest', 'email' => 'test@webscout.be', 'locale' => 'nl_BE', 'metadata' => array('whmcs_client_id' => 0)));
echo "customer:  {$customer->id}\n";

$first = $mollie->payments->create(array(
    'amount' => array('currency' => 'EUR', 'value' => '0.01'),
    'customerId' => $customer->id,
    'sequenceType' => 'first',
    'method' => array('bancontact', 'ideal', 'belfius', 'kbc'),
    'description' => 'Webscout betaalmethode bevestigen (rooktest)',
    'locale' => 'nl_BE',
    'redirectUrl' => 'https://example.org/return',
    'metadata' => array('whmcs_row' => 0),
));
echo "first:     {$first->id} {$first->status}\n           checkout {$first->getCheckoutUrl()}\n";

$mandate = $mollie->mandates->createForId($customer->id, array(
    'method' => 'directdebit',
    'consumerName' => 'T. Tester',
    'consumerAccount' => 'BE68539007547034',
    'signatureDate' => date('Y-m-d'),
    'mandateReference' => 'WS-ROOKTEST-' . time(),
));
echo "mandate:   {$mandate->id} {$mandate->status} {$mandate->method}\n";

$mollie->setIdempotencyKey('ws-rooktest-' . bin2hex(random_bytes(6)));
$recurring = $mollie->payments->create(array(
    'amount' => array('currency' => 'EUR', 'value' => '1.00'),
    'customerId' => $customer->id,
    'mandateId' => $mandate->id,
    'sequenceType' => 'recurring',
    'description' => 'Webscout factuur ROOKTEST',
    'metadata' => array('whmcs_row' => 0, 'whmcs_invoice_id' => 0),
));
$mollie->resetIdempotencyKey();
echo "recurring: {$recurring->id} {$recurring->status} (SEPA verwacht: pending)\n";

$page = $mollie->customerPayments->pageForId($customer->id, null, 50);
echo 'list:      ' . count($page) . " betalingen voor deze customer\n";

if ($recurring->isCancelable) {
    $mollie->payments->cancel($recurring->id);
    echo "cancel:    recurring geannuleerd\n";
}

$mollie->mandates->revokeForId($customer->id, $mandate->id);
echo "revoke:    mandaat ingetrokken\n";

try {
    $mollie->mandates->getForId($customer->id, $mandate->id);
    echo "check:     mandaat nog op te halen (status na intrekken)\n";
} catch (\Mollie\Api\Exceptions\ApiException $e) {
    echo "check:     mandaat weg, code {$e->getCode()} (gateway verwacht 404 of 410)\n";
}

echo "OK\n";
