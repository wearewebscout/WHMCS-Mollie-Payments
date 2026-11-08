<?php
/**
 * Webscout: terugkerende betalingen via Mollie (Mollie Recurring).
 *
 * Gedeelde logica voor de 2 tokenised gateways:
 * - molliesubscribecard_devapp: kredietkaart, mandaat type creditcard
 * - molliesubscribesepa_devapp: SEPA-domiciliëring, mandaat type directdebit via een eerste
 *   betaling met Bancontact, iDEAL, Belfius of KBC
 *
 * Werking in het kort:
 * 1. De klant voegt in de klantenzone een betaalmethode toe (of betaalt een factuur met een nieuwe).
 *    WHMCS roept _remoteinput op, dat een formulier naar start.php stuurt. start.php maakt een
 *    Mollie-customer (1 per WHMCS-klant) en een eerste betaling (sequenceType=first) en stuurt de
 *    klant naar Mollie.
 * 2. Na betaling (webhook.php of return.php) bewaren we het mandaat en maken we de WHMCS-betaalmethode
 *    met als remote token "cst_...:mdt_...". Ging het om een factuur, dan zetten we die op betaald.
 *    Ging het om enkel een betaalmethode toevoegen, dan storten we het kleine bedrag terug.
 * 3. De WHMCS-cron roept _capture op. Wij maken 1 betaling (sequenceType=recurring) per factuur.
 *    SEPA is asynchroon: de factuur gaat pas op betaald na de webhook met status paid.
 * 4. Mislukte inning, terugboeking en ingetrokken mandaat: factuur terug open (Unpaid), betaalmethode
 *    laten vervallen en de klant mailen om een nieuwe te kiezen. Daarna volgt WS Invordering.
 *
 * Nooit dubbel innen: per factuur hoogstens 1 lopende betaling (open, pending of paid), een slot per
 * factuur tijdens het aanmaken, een idempotency key bij Mollie en een vlag "applied" per betaling.
 */

use WHMCS\Database\Capsule;

if (!defined('MOLLIE_SUBSCRIBE_VERSION'))
    define('MOLLIE_SUBSCRIBE_VERSION', '1.0.0');

const MOLLIE_SUBSCRIBE_TABLE_CUSTOMERS = 'gateway_mollie_customers';
const MOLLIE_SUBSCRIBE_TABLE_MANDATES = 'gateway_mollie_mandates';
const MOLLIE_SUBSCRIBE_TABLE_PAYMENTS = 'gateway_mollie_recurring';

/* ------------------------------------------------------------------------------------------------
 * Configuratie
 * --------------------------------------------------------------------------------------------- */

/**
 * Gatewayvelden die beide gateways delen.
 */
function mollie_subscribe_config($friendlyName, $defaultMethods)
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => $friendlyName,
        ),
        'key' => array(
            'FriendlyName' => 'API key',
            'Type' => 'text',
            'Size' => '35',
            'Description' => 'Mollie API-sleutel (test_... of live_...). Klanten en mandaten bestaan apart in test en live.',
        ),
        'firstMethods' => array(
            'FriendlyName' => 'Methodes eerste betaling',
            'Type' => 'text',
            'Size' => '40',
            'Default' => $defaultMethods,
            'Description' => 'Mollie-methodes voor de eerste betaling die het mandaat maakt, met komma\'s.',
        ),
        'verifyAmount' => array(
            'FriendlyName' => 'Bedrag bevestiging',
            'Type' => 'text',
            'Size' => '6',
            'Default' => '0.01',
            'Description' => 'Bedrag om een betaalmethode te bevestigen zonder factuur. Wordt meteen teruggestort.',
        ),
        'invalidateOnFailure' => array(
            'FriendlyName' => 'Betaalmethode laten vervallen na mislukte inning',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Aan: na een mislukte inning moet de klant een nieuwe betaalmethode kiezen. Uit: de WHMCS-cron probeert opnieuw volgens de retry-instellingen.',
        ),
        'refundDoublePayment' => array(
            'FriendlyName' => 'Dubbele betaling terugstorten',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Komt een inning binnen voor een factuur die intussen anders betaald is, dan storten we ze automatisch terug.',
        ),
    );
}

/* ------------------------------------------------------------------------------------------------
 * Hulpfuncties
 * --------------------------------------------------------------------------------------------- */

/**
 * Mollie-client. Tests zetten $GLOBALS['MOLLIE_SUBSCRIBE_HTTP_ADAPTER'] om geen echte API te bellen.
 */
function mollie_subscribe_client($apiKey)
{
    $adapter = isset($GLOBALS['MOLLIE_SUBSCRIBE_HTTP_ADAPTER']) ? $GLOBALS['MOLLIE_SUBSCRIBE_HTTP_ADAPTER'] : null;
    $mollie = new \Mollie\Api\MollieApiClient($adapter);
    $mollie->setApiKey(trim((string) $apiKey));

    return $mollie;
}

function mollie_subscribe_mode($apiKey)
{
    return (strpos(trim((string) $apiKey), 'live_') === 0) ? 'live' : 'test';
}

function mollie_subscribe_now()
{
    return date('Y-m-d H:i:s');
}

function mollie_subscribe_log($gateway, $data, $result)
{
    if (function_exists('logTransaction'))
        logTransaction($gateway, $data, $result);
}

function mollie_subscribe_activity($message, $userId = 0)
{
    if (function_exists('logActivity'))
        logActivity('Mollie automatisch: ' . $message, $userId);
}

/**
 * Remote token = "cst_xxx:mdt_xxx".
 */
function mollie_subscribe_token($customerId, $mandateId)
{
    return $customerId . ':' . $mandateId;
}

function mollie_subscribe_parse_token($token)
{
    $parts = explode(':', (string) $token);

    if (count($parts) != 2 || strpos($parts[0], 'cst_') !== 0 || strpos($parts[1], 'mdt_') !== 0)
        return null;

    return array('customerId' => $parts[0], 'mandateId' => $parts[1]);
}

/**
 * HMAC voor de links naar start.php en return.php, met de API-sleutel als geheim.
 */
function mollie_subscribe_sign(array $fields, $apiKey)
{
    ksort($fields);

    return hash_hmac('sha256', http_build_query($fields), 'mollie-subscribe|' . trim((string) $apiKey));
}

function mollie_subscribe_verify(array $fields, $signature, $apiKey)
{
    return is_string($signature) && hash_equals(mollie_subscribe_sign($fields, $apiKey), $signature);
}

/**
 * Slot per factuur of betaling, zodat cron, webhook en return-pagina niet tegelijk werken.
 * Op MySQL met GET_LOCK, elders (tests op SQLite) een no-op.
 */
function mollie_subscribe_lock($name, $callback)
{
    $connection = Capsule::connection();
    $isMysql = in_array($connection->getDriverName(), array('mysql', 'mariadb'), true);
    $lockName = 'mollie_subscribe_' . substr(sha1($name), 0, 40);

    if ($isMysql) {
        $got = $connection->selectOne('SELECT GET_LOCK(?, 20) AS l', array($lockName));

        if (!$got || (int) $got->l !== 1)
            throw new \RuntimeException('Kon slot ' . $name . ' niet krijgen');
    }

    try {
        return $callback();
    } finally {
        if ($isMysql)
            $connection->select('SELECT RELEASE_LOCK(?)', array($lockName));
    }
}

/**
 * Taal van de klant (WHMCS-taalnaam) en de teksten uit lang/.
 */
function mollie_subscribe_language($clientLanguage = '')
{
    $language = strtolower(basename((string) $clientLanguage));

    if ($language === '' && class_exists('\Lang'))
        $language = strtolower(basename((string) \Lang::getName()));

    if ($language === '' || !file_exists(__DIR__ . '/../lang/' . $language . '.php'))
        $language = 'english';

    return $language;
}

function mollie_subscribe_lang($clientLanguage = '')
{
    $_GATEWAYLANG = array();
    require __DIR__ . '/../lang/' . mollie_subscribe_language($clientLanguage) . '.php';

    return $_GATEWAYLANG;
}

function mollie_subscribe_amount($amount)
{
    return number_format((float) $amount, 2, '.', '');
}

function mollie_subscribe_systemurl($systemUrl)
{
    return rtrim((string) $systemUrl, '/') . '/';
}

/* ------------------------------------------------------------------------------------------------
 * Tabellen
 * --------------------------------------------------------------------------------------------- */

function mollie_subscribe_install()
{
    // 1 keer per request (tests zetten de vlag terug na een lege database)
    if (!empty($GLOBALS['MOLLIE_SUBSCRIBE_INSTALLED']))
        return;

    $schema = Capsule::schema();

    if (!$schema->hasTable(MOLLIE_SUBSCRIBE_TABLE_CUSTOMERS)) {
        $schema->create(MOLLIE_SUBSCRIBE_TABLE_CUSTOMERS, function ($table) {
            $table->increments('id');
            $table->integer('userid');
            $table->string('mode', 4);
            $table->string('customerid', 32);
            $table->timestamp('created')->nullable();
            $table->unique(array('userid', 'mode'));
        });
    }

    if (!$schema->hasTable(MOLLIE_SUBSCRIBE_TABLE_MANDATES)) {
        $schema->create(MOLLIE_SUBSCRIBE_TABLE_MANDATES, function ($table) {
            $table->increments('id');
            $table->integer('userid');
            $table->string('gateway', 64);
            $table->string('mode', 4);
            $table->string('customerid', 32);
            $table->string('mandateid', 32)->unique();
            $table->string('method', 20);
            $table->string('label', 64)->nullable();
            $table->string('last4', 8)->nullable();
            $table->date('expiry')->nullable();
            $table->string('status', 16)->default('valid'); // valid, invalid, revoked
            $table->string('reason', 255)->nullable();
            $table->timestamp('created')->nullable();
            $table->timestamp('updated')->nullable();
        });
    }

    if (!$schema->hasTable(MOLLIE_SUBSCRIBE_TABLE_PAYMENTS)) {
        $schema->create(MOLLIE_SUBSCRIBE_TABLE_PAYMENTS, function ($table) {
            $table->increments('id');
            $table->integer('userid');
            $table->integer('invoiceid')->default(0); // 0 = bevestiging zonder factuur
            $table->string('gateway', 64);
            $table->string('mode', 4);
            $table->string('customerid', 32);
            $table->string('mandateid', 32)->nullable();
            $table->string('paymentid', 32)->nullable()->unique();
            $table->string('sequencetype', 10); // first, recurring
            $table->string('idempotencykey', 64);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('status', 20)->default('creating'); // creating, open, pending, authorized, paid, failed, canceled, expired, error
            $table->tinyInteger('applied')->default(0); // betaling op factuur gezet
            $table->tinyInteger('refunded')->default(0); // bevestiging of dubbele betaling teruggestort
            $table->tinyInteger('paymethod_created')->default(0);
            $table->text('chargebacks')->nullable(); // verwerkte chargeback-id's, met komma's
            $table->string('returnto', 16)->default('invoice'); // invoice, paymethods
            $table->string('note', 255)->nullable();
            $table->timestamp('created')->nullable();
            $table->timestamp('updated')->nullable();
            $table->index(array('invoiceid', 'status'));
        });
    }

    $GLOBALS['MOLLIE_SUBSCRIBE_INSTALLED'] = true;
}

function mollie_subscribe_payment_row($id)
{
    return Capsule::table(MOLLIE_SUBSCRIBE_TABLE_PAYMENTS)->where('id', (int) $id)->first();
}

function mollie_subscribe_update_row($id, array $data)
{
    $data['updated'] = mollie_subscribe_now();
    Capsule::table(MOLLIE_SUBSCRIBE_TABLE_PAYMENTS)->where('id', (int) $id)->update($data);
}

/* ------------------------------------------------------------------------------------------------
 * Mollie-customer per WHMCS-klant
 * --------------------------------------------------------------------------------------------- */

function mollie_subscribe_customer($mollie, $apiKey, array $client)
{
    $userId = (int) (isset($client['id']) ? $client['id'] : $client['userid']);
    $mode = mollie_subscribe_mode($apiKey);

    return mollie_subscribe_lock('customer-' . $userId . '-' . $mode, function () use ($mollie, $client, $userId, $mode) {
        $existing = Capsule::table(MOLLIE_SUBSCRIBE_TABLE_CUSTOMERS)->where('userid', $userId)->where('mode', $mode)->value('customerid');

        if ($existing)
            return $existing;

        $name = trim((isset($client['companyname']) && $client['companyname'] !== '') ? $client['companyname'] : ($client['firstname'] . ' ' . $client['lastname']));
        $customer = $mollie->customers->create(array(
            'name' => $name,
            'email' => $client['email'],
            'locale' => mollie_locale(mollie_subscribe_language(isset($client['language']) ? $client['language'] : ''), isset($client['countrycode']) ? $client['countrycode'] : (isset($client['country']) ? $client['country'] : '')),
            'metadata' => array('whmcs_client_id' => $userId),
        ));

        Capsule::table(MOLLIE_SUBSCRIBE_TABLE_CUSTOMERS)->insert(array(
            'userid' => $userId,
            'mode' => $mode,
            'customerid' => $customer->id,
            'created' => mollie_subscribe_now(),
        ));

        return $customer->id;
    });
}

/* ------------------------------------------------------------------------------------------------
 * Stap 1: remote input en eerste betaling
 * --------------------------------------------------------------------------------------------- */

/**
 * HTML voor _remoteinput: een formulier naar start.php. WHMCS verstuurt het in een iframe,
 * start.php breekt daar uit naar Mollie (Mollie Checkout mag niet in een iframe).
 */
function mollie_subscribe_remoteinput($params, $gateway)
{
    $client = $params['clientdetails'];
    $fields = array(
        'gateway' => $gateway,
        'client' => (int) (isset($client['id']) ? $client['id'] : $client['userid']),
        'invoice' => (int) (isset($params['invoiceid']) ? $params['invoiceid'] : 0),
        'amount' => mollie_subscribe_amount(isset($params['amount']) ? $params['amount'] : 0),
        'currency' => isset($params['currency']) ? (string) $params['currency'] : 'EUR',
        'ts' => time(),
    );
    $fields['sig'] = mollie_subscribe_sign($fields, $params['key']);

    $action = mollie_subscribe_systemurl($params['systemurl']) . 'modules/gateways/mollie/subscribe/start.php';
    $lang = mollie_subscribe_lang(isset($client['language']) ? $client['language'] : '');

    $inputs = '';
    foreach ($fields as $name => $value)
        $inputs .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES) . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES) . '">' . PHP_EOL;

    return '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '">' . PHP_EOL . $inputs
        . '<noscript><input type="submit" value="' . htmlspecialchars($lang['subscribeContinue'], ENT_QUOTES) . '"></noscript>' . PHP_EOL
        . '</form>';
}

/**
 * Mandaten kun je bij Mollie niet aanpassen: de klant voegt een nieuwe betaalmethode toe.
 */
function mollie_subscribe_remoteupdate($params)
{
    $lang = mollie_subscribe_lang(isset($params['clientdetails']['language']) ? $params['clientdetails']['language'] : '');

    return '<div class="alert alert-info text-center">' . htmlspecialchars($lang['subscribeNoUpdate'], ENT_QUOTES) . '</div>';
}

/**
 * Methodes voor de eerste betaling. WHMCS bewaart de standaardwaarde van een gatewayveld pas als iemand de
 * gatewayinstellingen opslaat, dus een lege instelling valt terug op de standaard van de gateway. Zonder
 * method laat Mollie elke methode toe, en dan kan een iDEAL-betaling via de kaart-gateway een
 * domiciliëringsmandaat als kaart bewaren (gezien op hub-dev, 8 nov 2026). Daarom:
 * - kaart: altijd enkel creditcard, wat er ook ingevuld staat;
 * - domiciliëring: ingevulde methodes zonder creditcard, anders bancontact en ideal.
 */
function mollie_subscribe_first_methods($gateway, $setting)
{
    if ($gateway === 'molliesubscribecard_devapp')
        return array('creditcard');

    $methods = array_values(array_filter(array_map('trim', explode(',', strtolower((string) $setting))), function ($m) {
        return $m !== '' && $m !== 'creditcard';
    }));

    return $methods ? $methods : array('bancontact', 'ideal');
}

/**
 * Maakt de eerste betaling en geeft de checkout-URL terug. Opgeroepen vanuit start.php.
 *
 * @return array ['checkoutUrl' => ..., 'rowId' => ...]
 */
function mollie_subscribe_start_first(array $gatewayParams, $gateway, array $client, $invoiceId, $amount, $currency)
{
    mollie_subscribe_install();

    $apiKey = $gatewayParams['key'];
    $mollie = mollie_subscribe_client($apiKey);
    $userId = (int) (isset($client['id']) ? $client['id'] : $client['userid']);
    $lang = mollie_subscribe_lang(isset($client['language']) ? $client['language'] : '');
    $systemUrl = mollie_subscribe_systemurl($gatewayParams['systemurl']);
    $invoiceId = (int) $invoiceId;

    if ($invoiceId > 0) {
        // Bedrag en munt altijd uit de factuur zelf, niet uit het formulier
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->where('userid', $userId)->first();

        if (!$invoice || $invoice->status !== 'Unpaid')
            throw new \RuntimeException('Factuur niet open');

        $amount = mollie_subscribe_invoice_balance($invoiceId);
        $returnTo = 'invoice';
        $description = sprintf($lang['subscribeInvoiceDescription'], mollie_subscribe_invoice_number($invoiceId));
    } else {
        $amount = mollie_subscribe_amount(isset($gatewayParams['verifyAmount']) && (float) $gatewayParams['verifyAmount'] > 0 ? $gatewayParams['verifyAmount'] : '0.01');
        $returnTo = 'paymethods';
        $description = $lang['subscribeVerifyDescription'];
    }

    $customerId = mollie_subscribe_customer($mollie, $apiKey, $client);
    $methods = mollie_subscribe_first_methods($gateway, isset($gatewayParams['firstMethods']) ? $gatewayParams['firstMethods'] : '');

    $rowId = Capsule::table(MOLLIE_SUBSCRIBE_TABLE_PAYMENTS)->insertGetId(array(
        'userid' => $userId,
        'invoiceid' => $invoiceId,
        'gateway' => $gateway,
        'mode' => mollie_subscribe_mode($apiKey),
        'customerid' => $customerId,
        'sequencetype' => 'first',
        'idempotencykey' => 'whmcs-first-' . bin2hex(random_bytes(12)),
        'amount' => $amount,
        'currency' => $currency ?: 'EUR',
        'status' => 'creating',
        'returnto' => $returnTo,
        'created' => mollie_subscribe_now(),
        'updated' => mollie_subscribe_now(),
    ));

    $returnFields = array('row' => $rowId);
    $returnFields['sig'] = mollie_subscribe_sign($returnFields, $apiKey);

    $payload = array(
        'amount' => array('currency' => $currency ?: 'EUR', 'value' => mollie_subscribe_amount($amount)),
        'customerId' => $customerId,
        'sequenceType' => 'first',
        'description' => $description,
        'locale' => mollie_locale(mollie_subscribe_language(isset($client['language']) ? $client['language'] : ''), isset($client['countrycode']) ? $client['countrycode'] : ''),
        'redirectUrl' => $systemUrl . 'modules/gateways/mollie/subscribe/return.php?' . http_build_query($returnFields),
        'webhookUrl' => $systemUrl . 'modules/gateways/mollie/subscribe/webhook.php',
        'metadata' => array('whmcs_row' => $rowId, 'whmcs_invoice_id' => $invoiceId, 'whmcs_client_id' => $userId),
    );

    if (count($methods) == 1)
        $payload['method'] = $methods[0];
    else if (count($methods) > 1)
        $payload['method'] = $methods;

    try {
        $payment = $mollie->payments->create($payload);
    } catch (\Exception $e) {
        mollie_subscribe_update_row($rowId, array('status' => 'error', 'note' => substr($e->getMessage(), 0, 250)));
        throw $e;
    }

    mollie_subscribe_update_row($rowId, array('paymentid' => $payment->id, 'status' => $payment->status));
    mollie_subscribe_log($gateway, array('row' => $rowId, 'payment' => $payment->id, 'invoice' => $invoiceId, 'amount' => $amount), 'Eerste betaling aangemaakt');

    return array('checkoutUrl' => $payment->getCheckoutUrl(), 'rowId' => $rowId);
}

/* ------------------------------------------------------------------------------------------------
 * Facturen
 * --------------------------------------------------------------------------------------------- */

function mollie_subscribe_invoice_balance($invoiceId)
{
    $invoice = Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->first();

    if (!$invoice)
        return '0.00';

    $paid = (float) Capsule::table('tblaccounts')->where('invoiceid', (int) $invoiceId)->sum('amountin')
        - (float) Capsule::table('tblaccounts')->where('invoiceid', (int) $invoiceId)->sum('amountout');

    return mollie_subscribe_amount(max(0, (float) $invoice->total - $paid));
}

function mollie_subscribe_invoice_number($invoiceId)
{
    $invoice = Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->first();

    return ($invoice && !empty($invoice->invoicenum)) ? $invoice->invoicenum : (string) $invoiceId;
}

function mollie_subscribe_invoice_status($invoiceId)
{
    return Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->value('status');
}

/**
 * Factuur terug open zetten (na mislukte SEPA-inning of terugboeking).
 */
function mollie_subscribe_reopen_invoice($invoiceId, $reason)
{
    $status = mollie_subscribe_invoice_status($invoiceId);

    if ($status === null || $status === 'Unpaid' || $status === 'Cancelled')
        return;

    $result = function_exists('localAPI') ? localAPI('UpdateInvoice', array('invoiceid' => (int) $invoiceId, 'status' => 'Unpaid')) : array('result' => 'error');

    if (!isset($result['result']) || $result['result'] !== 'success')
        Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->update(array('status' => 'Unpaid'));

    mollie_subscribe_activity('factuur ' . (int) $invoiceId . ' terug op Unpaid (' . $reason . ', was ' . $status . ')');
}

/**
 * SEPA wacht dagen op de bank. Zetten we de factuur op Payment Pending, dan probeert de cron
 * niet elke dag opnieuw en stuurt WS Invordering geen herinnering (die kijkt enkel naar Unpaid).
 */
function mollie_subscribe_mark_pending($invoiceId)
{
    if (mollie_subscribe_invoice_status($invoiceId) === 'Unpaid')
        Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->update(array('status' => 'Payment Pending'));
}

function mollie_subscribe_transaction_exists($transId)
{
    return Capsule::table('tblaccounts')->where('transid', (string) $transId)->exists();
}

function mollie_subscribe_send_failed_mail($gateway, $invoiceId)
{
    if ($invoiceId <= 0 || !function_exists('sendMessage'))
        return;

    sendMessage(mollie_subscribe_is_sepa($gateway) ? 'Direct Debit Payment Failed' : 'Credit Card Payment Failed', (int) $invoiceId);
}

function mollie_subscribe_is_sepa($gateway)
{
    return $gateway === 'molliesubscribesepa_devapp';
}

/* ------------------------------------------------------------------------------------------------
 * Mandaten en WHMCS-betaalmethodes
 * --------------------------------------------------------------------------------------------- */

/**
 * Na een geslaagde eerste betaling: mandaat bewaren en WHMCS-betaalmethode maken (1 keer).
 */
function mollie_subscribe_store_mandate($mollie, $row, $payment)
{
    if ((int) $row->paymethod_created === 1 || empty($payment->mandateId))
        return;

    $mandate = $mollie->mandates->getForId($row->customerid, $payment->mandateId);
    $details = $mandate->details;
    $method = $mandate->method;
    $last4 = null;
    $label = null;
    $expiry = null;

    if ($method === 'creditcard') {
        $last4 = isset($details->cardNumber) ? substr((string) $details->cardNumber, -4) : null;
        $label = isset($details->cardLabel) ? (string) $details->cardLabel : 'Card';
        $expiry = isset($details->cardExpiryDate) ? (string) $details->cardExpiryDate : null;
    } else {
        $account = isset($details->consumerAccount) ? preg_replace('/\s+/', '', (string) $details->consumerAccount) : '';
        $last4 = $account !== '' ? substr($account, -4) : null;
        $label = 'SEPA';
    }

    if (!Capsule::table(MOLLIE_SUBSCRIBE_TABLE_MANDATES)->where('mandateid', $mandate->id)->exists()) {
        Capsule::table(MOLLIE_SUBSCRIBE_TABLE_MANDATES)->insert(array(
            'userid' => (int) $row->userid,
            'gateway' => $row->gateway,
            'mode' => $row->mode,
            'customerid' => $row->customerid,
            'mandateid' => $mandate->id,
            'method' => $method,
            'label' => $label,
            'last4' => $last4,
            'expiry' => $expiry,
            'status' => $mandate->status === 'invalid' ? 'invalid' : 'valid',
            'created' => mollie_subscribe_now(),
            'updated' => mollie_subscribe_now(),
        ));
    }

    $token = mollie_subscribe_token($row->customerid, $mandate->id);
    $lang = mollie_subscribe_lang(mollie_subscribe_client_language($row->userid));

    if ($method === 'creditcard') {
        $mmyy = $expiry ? date('my', strtotime($expiry)) : '';
        $cardTypes = array('Mastercard' => 'MasterCard', 'Visa' => 'Visa', 'American Express' => 'American Express', 'Maestro' => 'Maestro');
        $cardType = isset($cardTypes[$label]) ? $cardTypes[$label] : $label;
        createCardPayMethod((int) $row->userid, $row->gateway, (string) $last4, $mmyy, $cardType, null, null, $token, 'billing', sprintf($lang['subscribeCardDescription'], $label, $last4));
    } else {
        $holder = isset($details->consumerName) ? (string) $details->consumerName : '';
        $bank = isset($details->consumerBic) && $details->consumerBic ? (string) $details->consumerBic : 'SEPA';
        createBankPayMethod((int) $row->userid, $row->gateway, 'Checking', '', (string) $last4, $bank, $holder, $token, 'billing', sprintf($lang['subscribeSepaDescription'], $last4));
    }

    mollie_subscribe_update_row($row->id, array('mandateid' => $mandate->id, 'paymethod_created' => 1));
    mollie_subscribe_make_default($row->userid, $row->gateway);
    mollie_subscribe_activity('betaalmethode bewaard (' . $method . ', ' . $mandate->id . ')', (int) $row->userid);
}

/**
 * De cron int enkel facturen met een inningsgateway als betaalmethode. Na een nieuw mandaat:
 * standaardgateway van de klant en de open facturen op deze gateway zetten.
 */
function mollie_subscribe_make_default($userId, $gateway)
{
    $result = function_exists('localAPI') ? localAPI('UpdateClient', array('clientid' => (int) $userId, 'paymentmethod' => $gateway)) : array('result' => 'error');

    if (!isset($result['result']) || $result['result'] !== 'success')
        Capsule::table('tblclients')->where('id', (int) $userId)->update(array('defaultgateway' => $gateway));

    $count = Capsule::table('tblinvoices')->where('userid', (int) $userId)->where('status', 'Unpaid')->where('paymentmethod', '!=', $gateway)->update(array('paymentmethod' => $gateway));

    if ($count)
        mollie_subscribe_activity($count . ' open facturen op ' . $gateway . ' gezet', (int) $userId);
}

function mollie_subscribe_client_language($userId)
{
    return (string) Capsule::table('tblclients')->where('id', (int) $userId)->value('language');
}

function mollie_subscribe_mandate_row($mandateId)
{
    return Capsule::table(MOLLIE_SUBSCRIBE_TABLE_MANDATES)->where('mandateid', (string) $mandateId)->first();
}

/**
 * Mandaat laten vervallen: in onze tabel op invalid, bij Mollie intrekken en de WHMCS-betaalmethode
 * verwijderen, zodat de klant een nieuwe moet kiezen. Werkt idempotent.
 */
function mollie_subscribe_invalidate_mandate($mollie, $customerId, $mandateId, $reason, $revokeAtMollie = true)
{
    $row = mollie_subscribe_mandate_row($mandateId);

    if ($row && $row->status !== 'valid')
        return;

    if ($row) {
        Capsule::table(MOLLIE_SUBSCRIBE_TABLE_MANDATES)->where('id', $row->id)->update(array(
            'status' => $revokeAtMollie ? 'revoked' : 'invalid',
            'reason' => substr($reason, 0, 250),
            'updated' => mollie_subscribe_now(),
        ));
    }

    if ($revokeAtMollie) {
        try {
            $mollie->mandates->revokeForId($customerId, $mandateId);
        } catch (\Exception $e) {
            // Al ingetrokken of niet meer gekend: niets te doen
        }
    }

    if ($row)
        mollie_subscribe_remove_paymethod((int) $row->userid, $row->gateway, mollie_subscribe_token($customerId, $mandateId));

    mollie_subscribe_activity('mandaat ' . $mandateId . ' vervallen: ' . $reason, $row ? (int) $row->userid : 0);
}

/**
 * WHMCS-betaalmethode met dit token verwijderen (WHMCS 8+ PayMethod-model).
 */
function mollie_subscribe_remove_paymethod($userId, $gateway, $token)
{
    if (!class_exists('\WHMCS\Payment\PayMethod\Model'))
        return false;

    try {
        $payMethods = \WHMCS\Payment\PayMethod\Model::where('userid', (int) $userId)->where('gateway_name', $gateway)->get();

        foreach ($payMethods as $payMethod) {
            $payment = $payMethod->payment;

            if ($payment && method_exists($payment, 'getRemoteToken') && $payment->getRemoteToken() === $token) {
                $payMethod->delete();
                return true;
            }
        }
    } catch (\Exception $e) {
        mollie_subscribe_activity('betaalmethode niet verwijderd: ' . $e->getMessage(), (int) $userId);
    }

    return false;
}

/**
 * _storeremote: bij verwijderen in de klantenzone het mandaat intrekken.
 */
function mollie_subscribe_storeremote($params, $gateway)
{
    if ($params['action'] !== 'delete')
        return array('status' => 'error', 'rawdata' => 'Gebruik remote input om een betaalmethode toe te voegen');

    $token = mollie_subscribe_parse_token($params['gatewayid']);

    if (!$token)
        return array('status' => 'success', 'rawdata' => 'Geen geldig token, niets in te trekken');

    mollie_subscribe_install();
    $mollie = mollie_subscribe_client($params['key']);

    try {
        $mollie->mandates->revokeForId($token['customerId'], $token['mandateId']);
        $result = 'Mandaat ingetrokken';
    } catch (\Mollie\Api\Exceptions\ApiException $e) {
        if ((int) $e->getCode() !== 404 && (int) $e->getCode() !== 410)
            return array('status' => 'error', 'rawdata' => $e->getMessage());

        $result = 'Mandaat was al weg bij Mollie';
    } catch (\Exception $e) {
        return array('status' => 'error', 'rawdata' => $e->getMessage());
    }

    Capsule::table(MOLLIE_SUBSCRIBE_TABLE_MANDATES)->where('mandateid', $token['mandateId'])->update(array(
        'status' => 'revoked',
        'reason' => 'verwijderd in klantenzone',
        'updated' => mollie_subscribe_now(),
    ));

    mollie_subscribe_activity('betaalmethode verwijderd, mandaat ' . $token['mandateId'] . ' ingetrokken');

    return array('status' => 'success', 'rawdata' => $result);
}

/* ------------------------------------------------------------------------------------------------
 * Stap 3: inning door de cron
 * --------------------------------------------------------------------------------------------- */

/**
 * _capture. Geeft success, pending of declined terug zoals WHMCS verwacht.
 */
function mollie_subscribe_capture($params, $gateway)
{
    mollie_subscribe_install();

    $invoiceId = (int) $params['invoiceid'];
    $token = mollie_subscribe_parse_token(isset($params['gatewayid']) ? $params['gatewayid'] : '');
    $lang = mollie_subscribe_lang(isset($params['clientdetails']['language']) ? $params['clientdetails']['language'] : '');

    if (!$token)
        return array('status' => 'declined', 'declinereason' => $lang['subscribeNoMandate'], 'rawdata' => 'Geen geldig remote token');

    $apiKey = $params['key'];
    $mollie = mollie_subscribe_client($apiKey);

    try {
        return mollie_subscribe_lock('invoice-' . $invoiceId, function () use ($params, $gateway, $invoiceId, $token, $apiKey, $mollie, $lang) {
            // 1. Loopt er al een betaling voor deze factuur? Dan geen tweede.
            $existing = Capsule::table(MOLLIE_SUBSCRIBE_TABLE_PAYMENTS)
                ->where('invoiceid', $invoiceId)
                ->whereIn('status', array('creating', 'open', 'pending', 'authorized', 'paid'))
                ->orderBy('id', 'desc')
                ->get();

            foreach ($existing as $row) {
                $result = mollie_subscribe_capture_existing($mollie, $row, $gateway);

                if ($result !== null)
                    return $result;
            }

            // 2. Mandaat nog geldig?
            $mandateRow = mollie_subscribe_mandate_row($token['mandateId']);

            if ($mandateRow && $mandateRow->status !== 'valid')
                return array('status' => 'declined', 'declinereason' => $lang['subscribeNoMandate'], 'rawdata' => 'Mandaat ' . $mandateRow->status . ': ' . $mandateRow->reason);

            try {
                $mandate = $mollie->mandates->getForId($token['customerId'], $token['mandateId']);
            } catch (\Mollie\Api\Exceptions\ApiException $e) {
                if ((int) $e->getCode() === 404 || (int) $e->getCode() === 410) {
                    mollie_subscribe_invalidate_mandate($mollie, $token['customerId'], $token['mandateId'], 'mandaat niet meer gekend bij Mollie', false);
                    return array('status' => 'declined', 'declinereason' => $lang['subscribeNoMandate'], 'rawdata' => $e->getMessage());
                }

                throw $e;
            }

            if ($mandate->status !== 'valid' && $mandate->status !== 'pending') {
                mollie_subscribe_invalidate_mandate($mollie, $token['customerId'], $token['mandateId'], 'mandaat ' . $mandate->status . ' bij Mollie', false);
                return array('status' => 'declined', 'declinereason' => $lang['subscribeNoMandate'], 'rawdata' => 'Mandaat status ' . $mandate->status);
            }

            // 3. Verlopen kaart
            if ($mandate->method === 'creditcard' && isset($mandate->details->cardExpiryDate) && $mandate->details->cardExpiryDate) {
                $expiry = strtotime($mandate->details->cardExpiryDate . ' 23:59:59');

                if ($expiry !== false && $expiry < time()) {
                    mollie_subscribe_invalidate_mandate($mollie, $token['customerId'], $token['mandateId'], 'kaart verlopen op ' . $mandate->details->cardExpiryDate, true);
                    return array('status' => 'declined', 'declinereason' => $lang['subscribeCardExpired'], 'rawdata' => 'Kaart verlopen ' . $mandate->details->cardExpiryDate);
                }
            }

            // 4. Nieuwe recurring betaling, bedrag uit de factuur
            $amount = mollie_subscribe_invoice_balance($invoiceId);

            if ((float) $amount <= 0)
                return array('status' => 'declined', 'declinereason' => 'Niets te innen', 'rawdata' => 'Saldo factuur is 0');

            return mollie_subscribe_create_recurring($mollie, $params, $gateway, $invoiceId, $token, $amount, $apiKey, $lang);
        });
    } catch (\Exception $e) {
        // Netwerk of API: geen betaling bevestigd. De rij staat op creating en wordt bij de volgende
        // poging eerst bij Mollie opgezocht, dus een retry int nooit dubbel.
        return array('status' => 'declined', 'declinereason' => $lang['subscribeTryLater'], 'rawdata' => get_class($e) . ': ' . $e->getMessage());
    }
}

/**
 * Een bestaande betaling voor deze factuur nakijken. Geeft een WHMCS-antwoord terug, of null als
 * er een nieuwe poging mag komen.
 */
function mollie_subscribe_capture_existing($mollie, $row, $gateway)
{
    if ($row->status === 'creating') {
        // Vorige poging brak af tijdens het aanmaken: zoek de betaling bij Mollie op via metadata
        $found = mollie_subscribe_find_payment_for_row($mollie, $row);

        if (!$found) {
            if (strtotime($row->created) < time() - 3600) {
                mollie_subscribe_update_row($row->id, array('status' => 'error', 'note' => 'aanmaken niet bevestigd, niet gevonden bij Mollie'));
                return null;
            }

            return array('status' => 'pending', 'rawdata' => 'Vorige poging (rij ' . $row->id . ') wordt nog bevestigd');
        }

        mollie_subscribe_update_row($row->id, array('paymentid' => $found->id, 'status' => $found->status));
        $row = mollie_subscribe_payment_row($row->id);
    }

    try {
        $payment = $mollie->payments->get($row->paymentid);
    } catch (\Exception $e) {
        return array('status' => 'pending', 'rawdata' => 'Betaling ' . $row->paymentid . ' niet op te halen: ' . $e->getMessage());
    }

    mollie_subscribe_update_row($row->id, array('status' => $payment->status));

    if ($payment->isPaid() && !$payment->hasChargebacks()) {
        if ((int) $row->applied === 1)
            return array('status' => 'declined', 'declinereason' => 'Al geïnd', 'rawdata' => 'Betaling ' . $payment->id . ' staat al op de factuur');

        // Webhook gemist: WHMCS zet de betaling nu op de factuur
        mollie_subscribe_update_row($row->id, array('applied' => 1));

        return array('status' => 'success', 'transid' => $payment->id, 'rawdata' => array('payment' => $payment->id, 'via' => 'bestaande betaling'));
    }

    if ($payment->isOpen() || $payment->isPending() || $payment->isAuthorized()) {
        // Een eerste betaling die nog open staat: de klant zit nu in de checkout. Niet ook nog innen.
        if ($row->sequencetype === 'recurring' && mollie_subscribe_is_sepa($gateway))
            mollie_subscribe_mark_pending($row->invoiceid);

        return array('status' => 'pending', 'transid' => $payment->id, 'rawdata' => array('payment' => $payment->id, 'status' => $payment->status, 'via' => 'loopt al'));
    }

    // failed, canceled, expired of teruggeboekt: nieuwe poging toegelaten
    return null;
}

function mollie_subscribe_find_payment_for_row($mollie, $row)
{
    try {
        $page = $mollie->customerPayments->pageForId($row->customerid, null, 50);

        foreach ($page as $payment) {
            if (isset($payment->metadata->whmcs_row) && (int) $payment->metadata->whmcs_row === (int) $row->id)
                return $payment;
        }
    } catch (\Exception $e) {
        // niets gevonden
    }

    return null;
}

function mollie_subscribe_create_recurring($mollie, $params, $gateway, $invoiceId, $token, $amount, $apiKey, $lang)
{
    $currency = isset($params['currency']) && $params['currency'] ? $params['currency'] : 'EUR';
    $idempotencyKey = 'whmcs-rec-' . $invoiceId . '-' . bin2hex(random_bytes(10));

    $rowId = Capsule::table(MOLLIE_SUBSCRIBE_TABLE_PAYMENTS)->insertGetId(array(
        'userid' => (int) (isset($params['clientdetails']['id']) ? $params['clientdetails']['id'] : $params['clientdetails']['userid']),
        'invoiceid' => $invoiceId,
        'gateway' => $gateway,
        'mode' => mollie_subscribe_mode($apiKey),
        'customerid' => $token['customerId'],
        'mandateid' => $token['mandateId'],
        'sequencetype' => 'recurring',
        'idempotencykey' => $idempotencyKey,
        'amount' => $amount,
        'currency' => $currency,
        'status' => 'creating',
        'created' => mollie_subscribe_now(),
        'updated' => mollie_subscribe_now(),
    ));

    $payload = array(
        'amount' => array('currency' => $currency, 'value' => $amount),
        'customerId' => $token['customerId'],
        'mandateId' => $token['mandateId'],
        'sequenceType' => 'recurring',
        'description' => sprintf($lang['subscribeInvoiceDescription'], mollie_subscribe_invoice_number($invoiceId)),
        'webhookUrl' => mollie_subscribe_systemurl($params['systemurl']) . 'modules/gateways/mollie/subscribe/webhook.php',
        'metadata' => array('whmcs_row' => $rowId, 'whmcs_invoice_id' => $invoiceId),
    );

    $mollie->setIdempotencyKey($idempotencyKey);

    try {
        $payment = $mollie->payments->create($payload);
    } catch (\Mollie\Api\Exceptions\ApiException $e) {
        // 4xx van Mollie: zeker niet aangemaakt
        if ((int) $e->getCode() >= 400 && (int) $e->getCode() < 500) {
            mollie_subscribe_update_row($rowId, array('status' => 'error', 'note' => substr($e->getMessage(), 0, 250)));
            return array('status' => 'declined', 'declinereason' => $lang['subscribeTryLater'], 'rawdata' => $e->getMessage());
        }

        throw $e; // onzeker, rij blijft op creating
    } finally {
        if (method_exists($mollie, 'resetIdempotencyKey'))
            $mollie->resetIdempotencyKey();
    }

    mollie_subscribe_update_row($rowId, array('paymentid' => $payment->id, 'status' => $payment->status));

    $raw = array('payment' => $payment->id, 'status' => $payment->status, 'amount' => $amount, 'mandate' => $token['mandateId']);

    if ($payment->isPaid()) {
        mollie_subscribe_update_row($rowId, array('applied' => 1));
        return array('status' => 'success', 'transid' => $payment->id, 'rawdata' => $raw);
    }

    if ($payment->isFailed() || $payment->isCanceled() || $payment->isExpired()) {
        $row = mollie_subscribe_payment_row($rowId);
        mollie_subscribe_after_failure($mollie, $row, $payment, $params, false);
        return array('status' => 'declined', 'declinereason' => $lang['subscribeFailed'], 'rawdata' => $raw);
    }

    if (mollie_subscribe_is_sepa($gateway))
        mollie_subscribe_mark_pending($invoiceId);

    return array('status' => 'pending', 'transid' => $payment->id, 'rawdata' => $raw);
}

/* ------------------------------------------------------------------------------------------------
 * Stap 2 en 4: webhook en return-pagina
 * --------------------------------------------------------------------------------------------- */

/**
 * Verwerkt de stand van een Mollie-betaling. Idempotent: mag zo vaak als nodig opgeroepen worden
 * (dubbele webhooks, return-pagina, cron).
 *
 * @return string korte uitkomst voor het log
 */
function mollie_subscribe_process_payment($paymentId)
{
    mollie_subscribe_install();

    $row = Capsule::table(MOLLIE_SUBSCRIBE_TABLE_PAYMENTS)->where('paymentid', (string) $paymentId)->first();

    if (!$row)
        return 'unknown';

    return mollie_subscribe_lock('invoice-' . ((int) $row->invoiceid > 0 ? (int) $row->invoiceid : 'row' . $row->id), function () use ($row) {
        $row = mollie_subscribe_payment_row($row->id);
        $gatewayParams = getGatewayVariables($row->gateway);
        $mollie = mollie_subscribe_client($gatewayParams['key']);
        $payment = $mollie->payments->get($row->paymentid);
        $outcome = array();

        mollie_subscribe_update_row($row->id, array('status' => $payment->status));

        if ($payment->isPaid()) {
            if ($row->sequencetype === 'first') {
                try {
                    mollie_subscribe_store_mandate($mollie, $row, $payment);
                    $outcome[] = 'mandaat bewaard';
                } catch (\Exception $e) {
                    mollie_subscribe_log($row->gateway, array('row' => $row->id, 'payment' => $payment->id, 'error' => $e->getMessage()), 'Betaalmethode bewaren mislukt');
                    $outcome[] = 'mandaat NIET bewaard: ' . $e->getMessage();
                }
            }

            $row = mollie_subscribe_payment_row($row->id);

            if ((int) $row->invoiceid > 0)
                $outcome[] = mollie_subscribe_apply_paid($mollie, $row, $payment, $gatewayParams);
            else if ((int) $row->refunded === 0)
                $outcome[] = mollie_subscribe_refund($mollie, $row, $payment, 'bevestiging betaalmethode');

            if ($payment->hasChargebacks())
                $outcome[] = mollie_subscribe_handle_chargebacks($mollie, mollie_subscribe_payment_row($row->id), $payment, $gatewayParams);
        } else if ($payment->isFailed() || $payment->isCanceled() || $payment->isExpired()) {
            $outcome[] = mollie_subscribe_after_failure($mollie, $row, $payment, $gatewayParams, true);
        } else {
            $outcome[] = 'status ' . $payment->status;
        }

        $outcome = implode(', ', array_filter($outcome));
        mollie_subscribe_log($row->gateway, array('row' => $row->id, 'payment' => $payment->id, 'status' => $payment->status, 'invoice' => $row->invoiceid), 'Webhook: ' . $outcome);

        return $outcome;
    });
}

/**
 * Betaalde inning op de factuur zetten, of terugstorten als de factuur intussen anders betaald is.
 */
function mollie_subscribe_apply_paid($mollie, $row, $payment, $gatewayParams)
{
    if ((int) $row->applied === 1 || mollie_subscribe_transaction_exists($payment->id)) {
        if ((int) $row->applied !== 1)
            mollie_subscribe_update_row($row->id, array('applied' => 1));

        return 'al op factuur';
    }

    $status = mollie_subscribe_invoice_status($row->invoiceid);

    if ($status === 'Paid' || $status === 'Cancelled') {
        // Klant betaalde intussen zelf of de factuur werd geannuleerd: niet dubbel boeken
        if ((int) $row->refunded === 0 && (!isset($gatewayParams['refundDoublePayment']) || $gatewayParams['refundDoublePayment'] === 'on'))
            return 'factuur al ' . $status . ', ' . mollie_subscribe_refund($mollie, $row, $payment, 'dubbele betaling factuur ' . mollie_subscribe_invoice_number($row->invoiceid));

        mollie_subscribe_activity('dubbele betaling ' . $payment->id . ' op factuur ' . $row->invoiceid . ' NIET teruggestort, nakijken', (int) $row->userid);
        return 'factuur al ' . $status . ', niet geboekt';
    }

    if ($status === 'Payment Pending')
        Capsule::table('tblinvoices')->where('id', (int) $row->invoiceid)->update(array('status' => 'Unpaid'));

    addInvoicePayment((int) $row->invoiceid, $payment->id, (float) $payment->amount->value, 0, $row->gateway);
    mollie_subscribe_update_row($row->id, array('applied' => 1));

    return 'betaling op factuur ' . $row->invoiceid;
}

function mollie_subscribe_refund($mollie, $row, $payment, $reason)
{
    try {
        $payment->refund(array(
            'amount' => array('currency' => $payment->amount->currency, 'value' => $payment->amount->value),
            'description' => substr('Terugstorting ' . $reason, 0, 140),
        ));
        mollie_subscribe_update_row($row->id, array('refunded' => 1, 'note' => 'teruggestort: ' . $reason));
        mollie_subscribe_activity('teruggestort ' . $payment->amount->value . ' (' . $reason . ', ' . $payment->id . ')', (int) $row->userid);

        return 'teruggestort';
    } catch (\Exception $e) {
        mollie_subscribe_activity('terugstorten mislukt voor ' . $payment->id . ': ' . $e->getMessage(), (int) $row->userid);

        return 'terugstorten mislukt';
    }
}

/**
 * Terugboekingen (chargebacks, tot 8 weken bij SEPA): betaling terugdraaien in WHMCS, factuur
 * terug open, mandaat laten vervallen en de klant mailen.
 */
function mollie_subscribe_handle_chargebacks($mollie, $row, $payment, $gatewayParams)
{
    $done = array_filter(explode(',', (string) $row->chargebacks));
    $new = array();

    foreach ($payment->chargebacks() as $chargeback) {
        if (in_array($chargeback->id, $done, true))
            continue;

        $new[] = $chargeback->id;
    }

    if (!$new)
        return 'terugboeking al verwerkt';

    // Eerst noteren, dan pas de gevolgen: een dubbele webhook doet zo niets meer
    mollie_subscribe_update_row($row->id, array('chargebacks' => implode(',', array_merge($done, $new))));

    $result = array();

    if ((int) $row->invoiceid > 0 && (int) $row->applied === 1) {
        foreach ($new as $chargebackId) {
            try {
                paymentReversed($chargebackId, $payment->id, (int) $row->invoiceid, $row->gateway);
                $result[] = 'betaling teruggedraaid (' . $chargebackId . ')';
            } catch (\Exception $e) {
                $result[] = 'terugdraaien: ' . $e->getMessage();
            }
        }

        mollie_subscribe_reopen_invoice($row->invoiceid, 'terugboeking ' . implode(',', $new));
        mollie_subscribe_send_failed_mail($row->gateway, (int) $row->invoiceid);
        $result[] = 'factuur terug open';
    }

    $mandateId = $row->mandateid ?: (isset($payment->mandateId) ? $payment->mandateId : null);

    if ($mandateId) {
        mollie_subscribe_invalidate_mandate($mollie, $row->customerid, $mandateId, 'terugboeking ' . implode(',', $new), true);
        $result[] = 'mandaat vervallen';
    }

    return implode(', ', $result);
}

/**
 * Mislukte, geannuleerde of verlopen betaling.
 */
function mollie_subscribe_after_failure($mollie, $row, $payment, $gatewayParams, $sendMail)
{
    if ($row->sequencetype === 'first') {
        if ((int) $row->invoiceid > 0 && mollie_subscribe_invoice_status($row->invoiceid) === 'Payment Pending')
            mollie_subscribe_reopen_invoice($row->invoiceid, 'eerste betaling ' . $payment->status);

        return 'eerste betaling ' . $payment->status . ', geen betaalmethode bewaard';
    }

    if ((int) $row->invoiceid <= 0)
        return 'status ' . $payment->status;

    // Al eens verwerkt? (dubbele webhook)
    if (strpos((string) $row->note, 'mislukt verwerkt') === 0)
        return 'mislukking al verwerkt';

    $status = mollie_subscribe_invoice_status($row->invoiceid);

    // Geannuleerd (door ons omdat de factuur anders betaald werd, of door een beheerder in Mollie)
    // of verlopen: geen fout van de klant. Factuur terug open als ze nog openstaat, mandaat blijft.
    if (!$payment->isFailed()) {
        if (strpos((string) $row->note, 'geannuleerd') !== 0)
            mollie_subscribe_update_row($row->id, array('note' => 'mislukt verwerkt (' . $payment->status . ') ' . mollie_subscribe_now()));

        if ($status === 'Payment Pending') {
            mollie_subscribe_reopen_invoice($row->invoiceid, 'inning ' . $payment->status);
            return 'inning ' . $payment->status . ', factuur terug open';
        }

        return 'inning ' . $payment->status;
    }

    mollie_subscribe_update_row($row->id, array('note' => 'mislukt verwerkt ' . mollie_subscribe_now()));

    $result = array();

    if ($status === 'Payment Pending') {
        mollie_subscribe_reopen_invoice($row->invoiceid, 'inning ' . $payment->status);
        $result[] = 'factuur terug open';
    }

    $reason = mollie_subscribe_failure_reason($payment);
    $invalidate = !isset($gatewayParams['invalidateOnFailure']) || $gatewayParams['invalidateOnFailure'] === 'on' || mollie_subscribe_is_hard_failure($payment);

    if ($invalidate && $row->mandateid) {
        mollie_subscribe_invalidate_mandate($mollie, $row->customerid, $row->mandateid, 'inning ' . $payment->status . ($reason ? ' (' . $reason . ')' : ''), true);
        $result[] = 'mandaat vervallen';
    }

    // Bij een synchrone weigering in _capture stuurt WHMCS zelf de mail (failedEmail)
    if ($sendMail && $status !== 'Paid') {
        mollie_subscribe_send_failed_mail($row->gateway, (int) $row->invoiceid);
        $result[] = 'klant gemaild';
    }

    mollie_subscribe_activity('inning factuur ' . $row->invoiceid . ' ' . $payment->status . ($reason ? ' (' . $reason . ')' : ''), (int) $row->userid);

    return implode(', ', $result) ?: 'mislukt';
}

function mollie_subscribe_failure_reason($payment)
{
    $details = isset($payment->details) ? $payment->details : null;

    if ($details && isset($details->bankReasonCode))
        return $details->bankReasonCode . (isset($details->bankReason) ? ' ' . $details->bankReason : '');

    if ($details && isset($details->failureReason))
        return (string) $details->failureReason;

    return '';
}

/**
 * Fouten waarbij opnieuw proberen geen zin heeft: rekening dicht, geen mandaat, kaart verlopen.
 */
function mollie_subscribe_is_hard_failure($payment)
{
    $details = isset($payment->details) ? $payment->details : null;
    $hardBank = array('AC01', 'AC04', 'AC06', 'AG01', 'MD01', 'MD06', 'MD07', 'MS02', 'SL01');
    $hardCard = array('card_expired', 'invalid_card_number', 'card_declined');

    if ($details && isset($details->bankReasonCode) && in_array($details->bankReasonCode, $hardBank, true))
        return true;

    if ($details && isset($details->failureReason) && in_array($details->failureReason, $hardCard, true))
        return true;

    return false;
}

/* ------------------------------------------------------------------------------------------------
 * Terugstorten vanuit WHMCS en beheermelding
 * --------------------------------------------------------------------------------------------- */

function mollie_subscribe_refund_from_whmcs($params)
{
    $mollie = mollie_subscribe_client($params['key']);

    try {
        $payment = $mollie->payments->get($params['transid']);
        $refund = $payment->refund(array(
            'amount' => array('currency' => $params['currency'] ?: 'EUR', 'value' => mollie_subscribe_amount($params['amount'])),
            'description' => 'Terugbetaling factuur ' . mollie_subscribe_invoice_number($params['invoiceid']),
        ));

        return array('status' => 'success', 'transid' => $refund->id, 'rawdata' => array('refund' => $refund->id, 'payment' => $payment->id));
    } catch (\Exception $e) {
        return array('status' => 'error', 'rawdata' => $e->getMessage());
    }
}

function mollie_subscribe_adminstatusmsg($params)
{
    $token = mollie_subscribe_parse_token(isset($params['gatewayid']) ? $params['gatewayid'] : '');

    if (!$token)
        return null;

    mollie_subscribe_install();
    $mandate = mollie_subscribe_mandate_row($token['mandateId']);
    $pending = Capsule::table(MOLLIE_SUBSCRIBE_TABLE_PAYMENTS)->where('invoiceid', (int) $params['id'])->whereIn('status', array('open', 'pending'))->value('paymentid');

    $msg = 'Mollie-mandaat ' . $token['mandateId'] . ' (klant ' . $token['customerId'] . ')';

    if ($mandate)
        $msg .= ', ' . $mandate->method . ' ' . $mandate->label . ' ' . $mandate->last4 . ', status ' . $mandate->status . ($mandate->reason ? ' (' . $mandate->reason . ')' : '');

    if ($pending)
        $msg .= '. Lopende inning: ' . $pending . ', niet opnieuw innen.';

    return array('type' => ($mandate && $mandate->status !== 'valid') ? 'error' : 'info', 'title' => 'Mollie automatisch', 'msg' => $msg);
}

/* ------------------------------------------------------------------------------------------------
 * Eindpunten (start.php, return.php, webhook.php zijn dunne omhulsels hierrond)
 * --------------------------------------------------------------------------------------------- */

function mollie_subscribe_gateways()
{
    return array('molliesubscribecard_devapp', 'molliesubscribesepa_devapp');
}

function mollie_subscribe_client_details($userId)
{
    $client = Capsule::table('tblclients')->where('id', (int) $userId)->first();

    if (!$client)
        return null;

    return array(
        'id' => (int) $client->id,
        'userid' => (int) $client->id,
        'firstname' => $client->firstname,
        'lastname' => $client->lastname,
        'companyname' => $client->companyname,
        'email' => $client->email,
        'countrycode' => $client->country,
        'language' => isset($client->language) ? $client->language : '',
    );
}

/**
 * start.php: formulier uit _remoteinput controleren, eerste betaling maken en de klant
 * (uit de iframe) naar Mollie sturen.
 */
function mollie_subscribe_handle_start(array $post)
{
    $gateway = isset($post['gateway']) ? (string) $post['gateway'] : '';

    if (!in_array($gateway, mollie_subscribe_gateways(), true))
        return array(400, 'Onbekende gateway');

    $gatewayParams = getGatewayVariables($gateway);
    $fields = array();

    foreach (array('gateway', 'client', 'invoice', 'amount', 'currency', 'ts') as $name)
        $fields[$name] = isset($post[$name]) ? (string) $post[$name] : '';

    if (empty($gatewayParams['key']) || !mollie_subscribe_verify($fields, isset($post['sig']) ? $post['sig'] : '', $gatewayParams['key']))
        return array(403, 'Ongeldige handtekening');

    if ((int) $fields['ts'] < time() - 7200)
        return array(403, 'Link verlopen, vernieuw de pagina');

    $client = mollie_subscribe_client_details((int) $fields['client']);

    if (!$client)
        return array(404, 'Klant niet gevonden');

    $lang = mollie_subscribe_lang($client['language']);

    try {
        $result = mollie_subscribe_start_first($gatewayParams, $gateway, $client, (int) $fields['invoice'], $fields['amount'], $fields['currency']);
    } catch (\Exception $e) {
        mollie_subscribe_log($gateway, array('client' => $client['id'], 'invoice' => $fields['invoice'], 'error' => $e->getMessage()), 'Eerste betaling mislukt');

        return array(500, '<p>' . htmlspecialchars($lang['subscribeStartFailed'], ENT_QUOTES) . '</p>');
    }

    $url = htmlspecialchars($result['checkoutUrl'], ENT_QUOTES);
    $json = json_encode($result['checkoutUrl'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    return array(200, '<!doctype html><html><head><meta charset="utf-8"><title>Mollie</title></head><body style="font-family:sans-serif;text-align:center;padding:24px">'
        . '<p>' . htmlspecialchars($lang['subscribeRedirecting'], ENT_QUOTES) . '</p>'
        . '<p><a href="' . $url . '" target="_top">' . htmlspecialchars($lang['subscribeContinue'], ENT_QUOTES) . '</a></p>'
        . '<script>window.top.location.href = ' . $json . ';</script></body></html>', $result);
}

/**
 * return.php: klant komt terug van Mollie. Stand ophalen (zelfde verwerking als de webhook) en
 * doorsturen naar de factuur of naar de betaalmethodes.
 *
 * @return array [status, html of null, redirect-URL of null]
 */
function mollie_subscribe_handle_return(array $get)
{
    mollie_subscribe_install();

    $rowId = isset($get['row']) ? (int) $get['row'] : 0;
    $row = mollie_subscribe_payment_row($rowId);

    if (!$row || !$row->paymentid)
        return array(404, 'Betaling niet gevonden', null);

    $gatewayParams = getGatewayVariables($row->gateway);

    if (!mollie_subscribe_verify(array('row' => $rowId), isset($get['sig']) ? $get['sig'] : '', $gatewayParams['key']))
        return array(403, 'Ongeldige handtekening', null);

    try {
        mollie_subscribe_process_payment($row->paymentid);
    } catch (\Exception $e) {
        mollie_subscribe_log($row->gateway, array('row' => $rowId, 'error' => $e->getMessage()), 'Return: verwerken mislukt');
    }

    $row = mollie_subscribe_payment_row($rowId);
    $systemUrl = mollie_subscribe_systemurl($gatewayParams['systemurl']);
    $lang = mollie_subscribe_lang(mollie_subscribe_client_language($row->userid));
    $ok = in_array($row->status, array('paid'), true);
    $busy = in_array($row->status, array('creating', 'open', 'pending', 'authorized'), true);

    if ($busy) {
        $attempt = isset($get['try']) ? (int) $get['try'] : 0;

        if ($attempt < 15) {
            $next = $systemUrl . 'modules/gateways/mollie/subscribe/return.php?' . http_build_query(array('row' => $rowId, 'sig' => $get['sig'], 'try' => $attempt + 1));

            return array(200, '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="2;url=' . htmlspecialchars($next, ENT_QUOTES) . '"><title>Mollie</title></head>'
                . '<body style="font-family:sans-serif;text-align:center;padding:24px"><p>' . htmlspecialchars($lang['checkPayment'], ENT_QUOTES) . '</p></body></html>', null);
        }
    }

    if ($row->returnto === 'invoice' && (int) $row->invoiceid > 0)
        return array(302, null, $systemUrl . 'viewinvoice.php?id=' . (int) $row->invoiceid . ($ok ? '&paymentsuccess=true' : ($busy ? '&pendingreview=true' : '&paymentfailed=true')));

    return array(302, null, $systemUrl . 'index.php?rp=/account/paymentmethods' . ($ok ? '' : '&mollie=' . ($busy ? 'pending' : 'failed')));
}

/**
 * webhook.php: Mollie post enkel het id, wij halen de stand zelf op.
 *
 * @return array [HTTP-status, tekst]
 */
function mollie_subscribe_handle_webhook(array $post)
{
    $paymentId = isset($post['id']) ? (string) $post['id'] : '';

    if (!preg_match('/^tr_[A-Za-z0-9]+$/', $paymentId))
        return array(400, 'Arg mismatch');

    try {
        $outcome = mollie_subscribe_process_payment($paymentId);
    } catch (\Exception $e) {
        mollie_subscribe_log('molliesubscribe', array('id' => $paymentId, 'error' => $e->getMessage()), 'Webhook: fout');

        return array(500, 'Error');  // Mollie probeert later opnieuw
    }

    if ($outcome === 'unknown') {
        mollie_subscribe_log('molliesubscribe', array('id' => $paymentId), 'Webhook: betaling (nog) niet gekend');

        return array(404, 'Unknown payment'); // kan net aangemaakt zijn, Mollie probeert opnieuw
    }

    return array(200, 'OK');
}

/**
 * Hook InvoicePaid: de factuur is betaald (door de klant zelf, een andere gateway of een beheerder).
 * Loopt er nog een inning die niet op de factuur staat, dan annuleren we ze bij Mollie als dat nog
 * kan. Lukt dat niet en komt ze toch binnen, dan stort mollie_subscribe_apply_paid ze terug.
 */
function mollie_subscribe_on_invoice_paid($invoiceId)
{
    mollie_subscribe_install();

    $rows = Capsule::table(MOLLIE_SUBSCRIBE_TABLE_PAYMENTS)
        ->where('invoiceid', (int) $invoiceId)
        ->where('applied', 0)
        ->whereIn('status', array('open', 'pending'))
        ->whereNotNull('paymentid')
        ->get();

    $result = array();

    foreach ($rows as $row) {
        try {
            $gatewayParams = getGatewayVariables($row->gateway);
            $mollie = mollie_subscribe_client($gatewayParams['key']);
            $payment = $mollie->payments->get($row->paymentid);

            if (!empty($payment->isCancelable)) {
                $mollie->payments->cancel($payment->id);
                mollie_subscribe_update_row($row->id, array('status' => 'canceled', 'note' => 'geannuleerd, factuur anders betaald'));
                mollie_subscribe_activity('inning ' . $payment->id . ' geannuleerd, factuur ' . (int) $invoiceId . ' anders betaald', (int) $row->userid);
                $result[] = $payment->id . ' geannuleerd';
            } else {
                $result[] = $payment->id . ' niet meer te annuleren';
            }
        } catch (\Exception $e) {
            $result[] = $row->paymentid . ': ' . $e->getMessage();
        }
    }

    return $result;
}

/**
 * Dagelijkse nacontrole (hook DailyCronJob), voor het geval een webhook nooit aankwam:
 * lopende betalingen nakijken, en betaalde SEPA-inningen tot 9 weken terug op terugboekingen.
 */
function mollie_subscribe_daily_sweep()
{
    mollie_subscribe_install();

    $since = date('Y-m-d H:i:s', strtotime('-63 days'));
    $rows = Capsule::table(MOLLIE_SUBSCRIBE_TABLE_PAYMENTS)
        ->whereNotNull('paymentid')
        ->where(function ($q) use ($since) {
            $q->whereIn('status', array('open', 'pending', 'authorized'))
                ->orWhere(function ($q) use ($since) {
                    $q->where('status', 'paid')->where('sequencetype', 'recurring')->where('gateway', 'molliesubscribesepa_devapp')->where('created', '>=', $since);
                });
        })
        ->orderBy('id')
        ->limit(500)
        ->pluck('paymentid');

    $result = array();

    foreach ($rows as $paymentId) {
        try {
            $result[$paymentId] = mollie_subscribe_process_payment($paymentId);
        } catch (\Exception $e) {
            $result[$paymentId] = 'fout: ' . $e->getMessage();
        }
    }

    return $result;
}
