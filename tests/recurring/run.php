<?php
/**
 * Testlijst Mollie Recurring. Draaien: tests/recurring/run.sh
 * Elk geval begint met een lege database en een lege nagebootste Mollie.
 */

use WHMCS\Database\Capsule;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeMollie.php';

$root = __DIR__ . '/root/modules/gateways';
require $root . '/molliesubscribecard_devapp.php';
require $root . '/molliesubscribesepa_devapp.php';
require $root . '/mollie/subscribe/hooks.php';

const CARD = 'molliesubscribecard_devapp';
const SEPA = 'molliesubscribesepa_devapp';

$results = array();
$current = null;

function check($cond, $what)
{
    global $results, $current;
    $results[$current]['checks'][] = array((bool) $cond, $what);
    if (!$cond)
        $results[$current]['ok'] = false;
}

function scenario($name, $fn)
{
    global $results, $current;
    $current = $name;
    $results[$name] = array('ok' => true, 'checks' => array(), 'error' => null);
    test_schema();
    $GLOBALS['MOLLIE_SUBSCRIBE_HTTP_ADAPTER'] = $fake = new FakeMollie();
    try {
        $fn($fake);
    } catch (\Throwable $e) {
        $results[$name]['ok'] = false;
        $results[$name]['error'] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }
}

/* ---------- hulpjes ---------- */

function client($lang = 'dutch', $country = 'BE')
{
    return Capsule::table('tblclients')->insertGetId(array('firstname' => 'Test', 'lastname' => 'Klant', 'companyname' => 'Testbedrijf BV', 'email' => 'test@webscout.be', 'country' => $country, 'language' => $lang));
}

function invoice($userId, $total = '121.00', $status = 'Unpaid')
{
    static $n = 0;
    $n++;
    // zoals WHMCS: nieuwe factuur krijgt de standaardgateway van de klant
    $gateway = Capsule::table('tblclients')->where('id', $userId)->value('defaultgateway') ?: 'molliecheckout_devapp';
    return Capsule::table('tblinvoices')->insertGetId(array('userid' => $userId, 'invoicenum' => 'TEST-' . $n, 'total' => $total, 'status' => $status, 'duedate' => date('Y-m-d'), 'paymentmethod' => $gateway));
}

function inv($id) { return Capsule::table('tblinvoices')->where('id', $id)->first(); }
function paymethods($userId) { return Capsule::table('tblpaymethods')->where('userid', $userId)->whereNull('deleted_at')->get()->all(); }
function bookings($invoiceId) { return Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->get()->all(); }
function mails($template = null) { return array_values(array_filter($GLOBALS['MAILS'], function ($m) use ($template) { return $template === null || $m[0] === $template; })); }

function remote_form($gateway, $userId, $invoiceId = 0, $amount = '0.00')
{
    $client = mollie_subscribe_client_details($userId);
    $params = array_merge(getGatewayVariables($gateway), array('clientdetails' => $client, 'invoiceid' => $invoiceId, 'amount' => $amount, 'currency' => 'EUR'));
    $fn = $gateway . '_remoteinput';
    $html = $fn($params);
    preg_match_all('/name="([^"]+)" value="([^"]*)"/', $html, $m, PREG_SET_ORDER);
    $post = array();
    foreach ($m as $f)
        $post[$f[1]] = html_entity_decode($f[2], ENT_QUOTES);
    $post['go'] = '1'; // stap 2: de klant klikte in het iframe op Ga verder naar Mollie
    return array($html, $post);
}

/** Klant voegt een betaalmethode toe (of betaalt een factuur) en rondt de checkout af */
function add_method($fake, $gateway, $userId, $invoiceId = 0, $mollieMethod = null, $details = array())
{
    list(, $post) = remote_form($gateway, $userId, $invoiceId, $invoiceId ? inv($invoiceId)->total : '0.00');
    $start = mollie_subscribe_handle_start($post);
    if ($start[0] !== 200)
        throw new \RuntimeException('start: ' . $start[0] . ' ' . $start[1]);
    $row = mollie_subscribe_payment_row($start[2]['rowId']);
    $mandateId = $fake->completeCheckout($row->paymentid, $mollieMethod ?: ($gateway === CARD ? 'creditcard' : 'bancontact'), $details);
    $hook = mollie_subscribe_handle_webhook(array('id' => $row->paymentid));
    return array('row' => mollie_subscribe_payment_row($row->id), 'mandate' => $mandateId, 'webhook' => $hook, 'start' => $start);
}

function last_recurring_payment($fake)
{
    $ids = array_keys(array_filter($fake->payments, function ($p) { return $p['sequenceType'] === 'recurring'; }));
    return end($ids);
}

function recurring_count($fake)
{
    return count(array_filter($fake->payments, function ($p) { return $p['sequenceType'] === 'recurring'; }));
}

/* ================================================================================================
 * Gevallen
 * ============================================================================================= */

scenario('01 Locale blijft zoals in c252558 (Belgische IBAN)', function ($fake) {
    check(mollie_locale('dutch', 'BE') === 'nl_BE', 'dutch + BE = nl_BE');
    check(mollie_locale('dutch', 'NL') === 'nl_NL', 'dutch + NL = nl_NL');
    check(mollie_locale('english', 'US') === 'en_US', 'english + US = en_US');
    check(mollie_locale('english', 'FR') === 'en_BE', 'english + FR valt terug op en_BE');
    check(mollie_locale('french', 'US') === 'fr_BE', 'french + US valt terug op fr_BE');
    check(mollie_locale('klingon', '') === 'en_BE', 'onbekende taal = en_BE');
});

scenario('02 Kaart toevoegen zonder factuur: mandaat, betaalmethode, 0,01 terug', function ($fake) {
    $uid = client('dutch', 'BE');
    $r = add_method($fake, CARD, $uid);
    $first = $fake->payments[$r['row']->paymentid];
    check($first['sequenceType'] === 'first', 'eerste betaling met sequenceType=first');
    check($first['amount']['value'] === '0.01', 'bedrag 0.01');
    check($first['allowedMethods'] === 'creditcard', 'methode creditcard');
    check($first['locale'] === 'nl_BE', 'locale nl_BE');
    check(strpos($first['redirectUrl'], 'subscribe/return.php?row=') !== false && strpos($first['webhookUrl'], 'subscribe/webhook.php') !== false, 'redirect- en webhook-URL');
    check(strpos($r['start'][1], 'window.top.location.href') !== false, 'start.php breekt uit de iframe naar Mollie');
    check($r['webhook'][0] === 200, 'webhook 200');
    $pms = paymethods($uid);
    check(count($pms) === 1 && $pms[0]->type === 'RemoteCreditCard', '1 kaart-betaalmethode in WHMCS');
    check($pms[0]->token === $first['customerId'] . ':' . $r['mandate'], 'remote token = customer:mandaat');
    check($pms[0]->last4 === '6787' && $pms[0]->cardtype === 'MasterCard' && strlen($pms[0]->expiry) === 4, 'laatste 4, type MasterCard, vervaldatum MMYY');
    check(count($fake->refunds[$r['row']->paymentid] ?? array()) === 1, 'bevestigingsbedrag 1 keer teruggestort');
    // dubbele webhook
    mollie_subscribe_handle_webhook(array('id' => $r['row']->paymentid));
    mollie_subscribe_handle_webhook(array('id' => $r['row']->paymentid));
    check(count(paymethods($uid)) === 1, 'dubbele webhooks: nog steeds 1 betaalmethode');
    check(count($fake->refunds[$r['row']->paymentid]) === 1, 'dubbele webhooks: niet opnieuw teruggestort');
    check(count($fake->customers) === 1, '1 Mollie-customer');
    // tweede kaart: zelfde customer
    add_method($fake, CARD, $uid);
    check(count($fake->customers) === 1, 'tweede betaalmethode hergebruikt de Mollie-customer');
});

scenario('03 SEPA via Bancontact tijdens betaling factuur: factuur betaald, bankbetaalmethode', function ($fake) {
    $uid = client('english', 'NL');
    $iid = invoice($uid, '99.22');
    list(, $post) = remote_form(SEPA, $uid, $iid, '99.22');
    $r = add_method($fake, SEPA, $uid, $iid, 'bancontact');
    $first = $fake->payments[$r['row']->paymentid];
    check($first['amount']['value'] === '99.22', 'eerste betaling = saldo factuur');
    check($first['allowedMethods'] === array('bancontact', 'ideal'), 'methodes bancontact, ideal');
    check($first['locale'] === 'en_NL', 'locale en_NL');
    check(inv($iid)->status === 'Paid', 'factuur Paid');
    check(count(bookings($iid)) === 1 && bookings($iid)[0]->transid === $r['row']->paymentid, '1 boeking met Mollie-id');
    $pms = paymethods($uid);
    check(count($pms) === 1 && $pms[0]->type === 'RemoteBankAccount' && $pms[0]->last4 === '7034', 'bankbetaalmethode, rekening eindigend op 7034');
    check(empty($fake->refunds), 'niets teruggestort');
    mollie_subscribe_handle_webhook(array('id' => $r['row']->paymentid));
    check(count(bookings($iid)) === 1, 'dubbele webhook: geen tweede boeking');
});

scenario('04 Geknoeid formulier en verlopen link worden geweigerd', function ($fake) {
    $uid = client();
    $iid = invoice($uid, '50.00');
    list(, $post) = remote_form(SEPA, $uid, $iid, '50.00');
    $bad = $post;
    $bad['amount'] = '0.01';
    check(mollie_subscribe_handle_start($bad)[0] === 403, 'ander bedrag: 403');
    $bad = $post;
    $bad['client'] = (string) client();
    check(mollie_subscribe_handle_start($bad)[0] === 403, 'andere klant: 403');
    $bad = $post;
    $bad['ts'] = (string) (time() - 8000);
    $bad['sig'] = mollie_subscribe_sign(array_intersect_key($bad, array_flip(array('gateway', 'client', 'invoice', 'amount', 'currency', 'ts'))), getGatewayVariables(SEPA)['key']);
    check(mollie_subscribe_handle_start($bad)[0] === 403, 'link ouder dan 2 uur: 403');
    $bad = $post;
    $bad['gateway'] = 'banktransfer';
    check(mollie_subscribe_handle_start($bad)[0] === 400, 'onbekende gateway: 400');
    check(count($fake->payments) === 0, 'geen enkele betaling bij Mollie');
    // factuur van iemand anders
    $other = client();
    $otherInv = invoice($other, '10.00');
    list(, $post2) = remote_form(SEPA, $uid, $otherInv, '10.00');
    check(mollie_subscribe_handle_start($post2)[0] === 500, 'factuur van andere klant: geweigerd');
    check(count($fake->payments) === 0, 'nog altijd geen betaling');
});

scenario('05 Klant breekt checkout af: geen betaalmethode, factuur blijft open', function ($fake) {
    $uid = client();
    $iid = invoice($uid);
    list(, $post) = remote_form(SEPA, $uid, $iid);
    $start = mollie_subscribe_handle_start($post);
    $row = mollie_subscribe_payment_row($start[2]['rowId']);
    $fake->abandonCheckout($row->paymentid, 'canceled');
    mollie_subscribe_handle_webhook(array('id' => $row->paymentid));
    check(count(paymethods($uid)) === 0, 'geen betaalmethode');
    check(inv($iid)->status === 'Unpaid', 'factuur Unpaid');
    $ret = mollie_subscribe_handle_return(array('row' => $row->id, 'sig' => mollie_subscribe_sign(array('row' => $row->id), getGatewayVariables(SEPA)['key'])));
    check($ret[0] === 302 && strpos($ret[2], 'paymentfailed=true') !== false, 'return-pagina: terug naar factuur met paymentfailed');
});

scenario('06 Return-pagina: wachten, daarna door naar factuur of betaalmethodes', function ($fake) {
    $uid = client();
    list(, $post) = remote_form(CARD, $uid);
    $start = mollie_subscribe_handle_start($post);
    $row = mollie_subscribe_payment_row($start[2]['rowId']);
    $sig = mollie_subscribe_sign(array('row' => $row->id), getGatewayVariables(CARD)['key']);
    $ret = mollie_subscribe_handle_return(array('row' => $row->id, 'sig' => $sig));
    check($ret[0] === 200 && strpos($ret[1], 'refresh') !== false, 'nog open: wachtpagina die herlaadt');
    check(mollie_subscribe_handle_return(array('row' => $row->id, 'sig' => 'x'))[0] === 403, 'foute handtekening: 403');
    $fake->completeCheckout($row->paymentid, 'creditcard');
    $ret = mollie_subscribe_handle_return(array('row' => $row->id, 'sig' => $sig));
    check($ret[0] === 302 && strpos($ret[2], 'rp=/account/paymentmethods') !== false, 'betaald: door naar betaalmethodes');
    check(count(paymethods($uid)) === 1, 'return-pagina bewaart de betaalmethode ook zonder webhook');
    mollie_subscribe_handle_webhook(array('id' => $row->paymentid));
    check(count(paymethods($uid)) === 1, 'webhook daarna: geen tweede betaalmethode');
});

scenario('07 Cron int met kaart: meteen betaald, late webhook boekt niet dubbel', function ($fake) {
    $uid = client();
    add_method($fake, CARD, $uid);
    $iid = invoice($uid, '60.50');
    $res = test_cron_capture($iid);
    check($res['status'] === 'success', 'capture success');
    check(inv($iid)->status === 'Paid', 'factuur Paid');
    $pid = last_recurring_payment($fake);
    $pay = $fake->payments[$pid];
    check($pay['sequenceType'] === 'recurring' && $pay['amount']['value'] === '60.50' && $pay['mandateId'] !== null, 'recurring betaling op het mandaat, juiste bedrag');
    mollie_subscribe_handle_webhook(array('id' => $pid));
    mollie_subscribe_handle_webhook(array('id' => $pid));
    check(count(bookings($iid)) === 1, 'webhooks na capture: 1 boeking');
    $res2 = test_cron_capture($iid);
    check($res2['status'] === 'skipped', 'cron slaat betaalde factuur over');
    check(recurring_count($fake) === 1, '1 inning bij Mollie');
});

scenario('08 Cron int met SEPA: pending, retries innen niet dubbel, webhook paid zet betaald', function ($fake) {
    $uid = client();
    add_method($fake, SEPA, $uid);
    $iid = invoice($uid, '45.00');
    $res = test_cron_capture($iid);
    check($res['status'] === 'pending', 'capture pending');
    check(inv($iid)->status === 'Payment Pending', 'factuur Payment Pending, niet Paid');
    check(count(bookings($iid)) === 0, 'nog geen boeking');
    check(test_cron_capture($iid)['status'] === 'skipped', 'cron de dag nadien slaat Payment Pending over');
    // ook als WHMCS de factuur toch Unpaid laat en opnieuw capture oproept
    Capsule::table('tblinvoices')->where('id', $iid)->update(array('status' => 'Unpaid'));
    $res3 = test_cron_capture($iid);
    check($res3['status'] === 'pending', 'retry op Unpaid: weer pending');
    check(recurring_count($fake) === 1, 'retry maakte geen tweede inning');
    $pid = last_recurring_payment($fake);
    $fake->settle($pid);
    mollie_subscribe_handle_webhook(array('id' => $pid));
    check(inv($iid)->status === 'Paid', 'na webhook paid: factuur Paid');
    mollie_subscribe_handle_webhook(array('id' => $pid));
    check(count(bookings($iid)) === 1, 'dubbele webhook: 1 boeking');
    check(count(mails('Direct Debit Payment Failed')) === 0, 'geen mislukt-mail');
});

scenario('09 SEPA-inning mislukt (AM04): factuur terug open, nieuwe betaalmethode nodig', function ($fake) {
    $uid = client();
    $r = add_method($fake, SEPA, $uid);
    $iid = invoice($uid, '45.00');
    test_cron_capture($iid);
    $pid = last_recurring_payment($fake);
    $fake->fail($pid, 'AM04', 'Insufficient funds');
    mollie_subscribe_handle_webhook(array('id' => $pid));
    check(inv($iid)->status === 'Unpaid', 'factuur terug Unpaid (WS Invordering neemt over)');
    check(count(paymethods($uid)) === 0, 'betaalmethode verwijderd');
    check(!empty($fake->mandates[$r['mandate']]['_deleted']), 'mandaat ingetrokken bij Mollie');
    check(count(mails('Direct Debit Payment Failed')) === 1, 'klant krijgt Direct Debit Payment Failed');
    mollie_subscribe_handle_webhook(array('id' => $pid));
    check(count(mails('Direct Debit Payment Failed')) === 1, 'dubbele webhook: geen tweede mail');
    check(test_cron_capture($iid)['status'] === 'skipped', 'cron int niet meer zonder nieuwe betaalmethode');
    // klant kiest nieuwe methode en betaalt
    add_method($fake, SEPA, $uid, $iid);
    check(inv($iid)->status === 'Paid', 'na nieuwe betaalmethode: factuur Paid');
});

scenario('10 Mislukte inning met optie uit: mandaat blijft, cron probeert opnieuw', function ($fake) {
    Capsule::table('tblpaymentgateways')->where('gateway', SEPA)->where('setting', 'invalidateOnFailure')->update(array('value' => ''));
    $uid = client();
    add_method($fake, SEPA, $uid);
    $iid = invoice($uid, '45.00');
    test_cron_capture($iid);
    $pid = last_recurring_payment($fake);
    $fake->fail($pid, 'AM04', 'Insufficient funds');
    mollie_subscribe_handle_webhook(array('id' => $pid));
    check(inv($iid)->status === 'Unpaid' && count(paymethods($uid)) === 1, 'factuur Unpaid, betaalmethode blijft');
    check(test_cron_capture($iid)['status'] === 'pending' && recurring_count($fake) === 2, 'nieuwe poging maakt 1 nieuwe inning');
    // harde fout (rekening dicht) laat het mandaat toch vervallen
    $pid2 = last_recurring_payment($fake);
    $fake->fail($pid2, 'AC04', 'Account closed');
    mollie_subscribe_handle_webhook(array('id' => $pid2));
    check(count(paymethods($uid)) === 0, 'AC04 (rekening dicht): betaalmethode vervalt toch');
});

scenario('11 Terugboeking na betaling: betaling teruggedraaid, factuur Unpaid, mandaat weg', function ($fake) {
    $uid = client();
    $r = add_method($fake, SEPA, $uid);
    $iid = invoice($uid, '45.00');
    test_cron_capture($iid);
    $pid = last_recurring_payment($fake);
    $fake->settle($pid);
    mollie_subscribe_handle_webhook(array('id' => $pid));
    check(inv($iid)->status === 'Paid', 'eerst Paid');
    $chb = $fake->chargeback($pid);
    mollie_subscribe_handle_webhook(array('id' => $pid));
    $b = bookings($iid);
    check(count($b) === 2 && $b[1]->transid === $chb && (float) $b[1]->amountout === 45.0, 'terugboeking geboekt via paymentReversed');
    check(inv($iid)->status === 'Unpaid', 'factuur Unpaid (niet Collections)');
    check(count(paymethods($uid)) === 0 && !empty($fake->mandates[$r['mandate']]['_deleted']), 'betaalmethode en mandaat weg');
    check(count(mails('Direct Debit Payment Failed')) === 1, 'klant gemaild');
    mollie_subscribe_handle_webhook(array('id' => $pid));
    check(count(bookings($iid)) === 2 && count(mails('Direct Debit Payment Failed')) === 1, 'dubbele webhook: niets extra');
    check(test_cron_capture($iid)['status'] === 'skipped', 'cron int niet opnieuw op het oude mandaat');
});

scenario('12 Terugboeking pas gezien door de dagelijkse nacontrole', function ($fake) {
    $uid = client();
    add_method($fake, SEPA, $uid);
    $iid = invoice($uid, '45.00');
    test_cron_capture($iid);
    $pid = last_recurring_payment($fake);
    $fake->settle($pid);
    $sweep1 = mollie_subscribe_daily_sweep();
    check(inv($iid)->status === 'Paid', 'gemiste webhook paid: nacontrole zet factuur Paid');
    $fake->chargeback($pid);
    mollie_subscribe_daily_sweep();
    check(inv($iid)->status === 'Unpaid', 'gemiste webhook terugboeking: nacontrole zet factuur Unpaid');
    mollie_subscribe_daily_sweep();
    check(count(bookings($iid)) === 2, 'nacontrole opnieuw: niets dubbel');
});

scenario('13 Mandaat ingetrokken bij de bank: geen inning, nieuwe betaalmethode nodig', function ($fake) {
    $uid = client();
    $r = add_method($fake, SEPA, $uid);
    $fake->revokeAtBank($r['mandate']);
    $iid = invoice($uid, '45.00');
    $res = test_cron_capture($iid);
    check($res['status'] === 'declined', 'capture declined');
    check(recurring_count($fake) === 0, 'geen inning aangemaakt');
    check(count(paymethods($uid)) === 0, 'betaalmethode verwijderd');
    check(inv($iid)->status === 'Unpaid', 'factuur Unpaid');
    check(count(mails('Direct Debit Payment Failed')) === 1, 'klant krijgt de mislukt-mail (WHMCS failedEmail)');
});

scenario('14 Verlopen kaart: geen inning, mandaat ingetrokken, nieuwe betaalmethode nodig', function ($fake) {
    $uid = client();
    $r = add_method($fake, CARD, $uid);
    $fake->expireCard($r['mandate']);
    $iid = invoice($uid, '20.00');
    $res = test_cron_capture($iid);
    check($res['status'] === 'declined' && strpos($res['declinereason'], 'vervallen') !== false, 'capture declined met reden kaart vervallen');
    check(recurring_count($fake) === 0, 'geen inning aangemaakt');
    check(count(paymethods($uid)) === 0 && !empty($fake->mandates[$r['mandate']]['_deleted']), 'betaalmethode en mandaat weg');
    check(count(mails('Credit Card Payment Failed')) === 1, 'klant krijgt Credit Card Payment Failed');
});

scenario('15 Klant betaalt zelf terwijl SEPA loopt: inning geannuleerd', function ($fake) {
    $uid = client();
    add_method($fake, SEPA, $uid);
    $iid = invoice($uid, '45.00');
    test_cron_capture($iid);
    $pid = last_recurring_payment($fake);
    addInvoicePayment($iid, 'OVERSCHRIJVING-1', 45.00, 0, 'banktransfer'); // vuurt InvoicePaid
    check($fake->payments[$pid]['status'] === 'canceled', 'lopende inning geannuleerd bij Mollie');
    mollie_subscribe_handle_webhook(array('id' => $pid));
    check(count(bookings($iid)) === 1 && inv($iid)->status === 'Paid', 'factuur 1 keer betaald');
    check(count(paymethods($uid)) === 1, 'betaalmethode blijft (geen mislukking van de klant)');
    check(count(mails('Direct Debit Payment Failed')) === 0, 'geen mislukt-mail');
});

scenario('16 Klant betaalt zelf, inning niet meer te annuleren en komt binnen: teruggestort', function ($fake) {
    $uid = client();
    add_method($fake, SEPA, $uid);
    $iid = invoice($uid, '45.00');
    test_cron_capture($iid);
    $pid = last_recurring_payment($fake);
    $fake->payments[$pid]['isCancelable'] = false;
    addInvoicePayment($iid, 'OVERSCHRIJVING-2', 45.00, 0, 'banktransfer');
    $fake->settle($pid);
    mollie_subscribe_handle_webhook(array('id' => $pid));
    check(count(bookings($iid)) === 1, 'geen tweede boeking');
    check(count($fake->refunds[$pid] ?? array()) === 1 && $fake->refunds[$pid][0]['amount']['value'] === '45.00', 'inning volledig teruggestort');
    mollie_subscribe_handle_webhook(array('id' => $pid));
    check(count($fake->refunds[$pid]) === 1, 'dubbele webhook: 1 terugstorting');
});

scenario('17 Time-out na aanmaken bij Mollie: volgende poging int niet dubbel', function ($fake) {
    $uid = client();
    add_method($fake, SEPA, $uid);
    $iid = invoice($uid, '45.00');
    $fake->failAfterCreate = true;
    $res = test_cron_capture($iid);
    check(in_array($res['status'], array('declined', 'pending'), true), 'eerste poging geeft geen success (' . $res['status'] . ')');
    check(recurring_count($fake) === 1, '1 inning bij Mollie (time-out na aanmaken)');
    Capsule::table('tblinvoices')->where('id', $iid)->update(array('status' => 'Unpaid'));
    $res2 = test_cron_capture($iid);
    check($res2['status'] === 'pending', 'tweede poging vindt de bestaande inning terug');
    check(recurring_count($fake) === 1, 'nog altijd 1 inning');
});

scenario('18 Mollie onbereikbaar tijdens webhook: 500 zodat Mollie opnieuw probeert', function ($fake) {
    $uid = client();
    add_method($fake, SEPA, $uid);
    $iid = invoice($uid, '45.00');
    test_cron_capture($iid);
    $pid = last_recurring_payment($fake);
    $fake->settle($pid);
    $fake->failNextGet = true;
    check(mollie_subscribe_handle_webhook(array('id' => $pid))[0] === 500, 'eerste webhook 500');
    check(inv($iid)->status === 'Payment Pending', 'factuur nog Payment Pending');
    check(mollie_subscribe_handle_webhook(array('id' => $pid))[0] === 200 && inv($iid)->status === 'Paid', 'tweede webhook 200 en Paid');
    check(mollie_subscribe_handle_webhook(array('id' => 'tr_onbekend'))[0] === 404, 'onbekend id: 404 (Mollie probeert later opnieuw)');
    check(mollie_subscribe_handle_webhook(array('id' => '1; DROP'))[0] === 400, 'ongeldig id: 400');
});

scenario('19 Betaalmethode verwijderen in klantenzone trekt mandaat in', function ($fake) {
    $uid = client();
    $r = add_method($fake, CARD, $uid);
    $pm = paymethods($uid)[0];
    $res = molliesubscribecard_devapp_storeremote(array_merge(getGatewayVariables(CARD), array('action' => 'delete', 'gatewayid' => $pm->token)));
    check($res['status'] === 'success', 'storeremote delete: success');
    check(!empty($fake->mandates[$r['mandate']]['_deleted']), 'mandaat ingetrokken bij Mollie');
    check(Capsule::table('gateway_mollie_mandates')->where('mandateid', $r['mandate'])->value('status') === 'revoked', 'mandaat op revoked in onze tabel');
    $res2 = molliesubscribecard_devapp_storeremote(array_merge(getGatewayVariables(CARD), array('action' => 'delete', 'gatewayid' => $pm->token)));
    check($res2['status'] === 'success', 'nog eens verwijderen (al weg bij Mollie): success');
    $iid = invoice($uid, '10.00');
    $res3 = molliesubscribecard_devapp_capture(array_merge(getGatewayVariables(CARD), array('invoiceid' => $iid, 'amount' => '10.00', 'currency' => 'EUR', 'gatewayid' => $pm->token, 'clientdetails' => mollie_subscribe_client_details($uid))));
    check($res3['status'] === 'declined' && recurring_count($fake) === 0, 'capture op ingetrokken mandaat: declined, geen inning');
});

scenario('20 Ongeldig token en terugbetalen vanuit WHMCS', function ($fake) {
    $uid = client();
    $iid = invoice($uid, '10.00');
    $res = molliesubscribecard_devapp_capture(array_merge(getGatewayVariables(CARD), array('invoiceid' => $iid, 'amount' => '10.00', 'currency' => 'EUR', 'gatewayid' => 'onzin', 'clientdetails' => mollie_subscribe_client_details($uid))));
    check($res['status'] === 'declined', 'ongeldig token: declined');
    add_method($fake, CARD, $uid);
    test_cron_capture($iid);
    $pid = last_recurring_payment($fake);
    $ref = molliesubscribecard_devapp_refund(array_merge(getGatewayVariables(CARD), array('transid' => $pid, 'amount' => '4.00', 'currency' => 'EUR', 'invoiceid' => $iid)));
    check($ref['status'] === 'success' && $fake->refunds[$pid][0]['amount']['value'] === '4.00', 'gedeeltelijke terugbetaling bij Mollie');
});

scenario('21 Kaart en SEPA gebruiken het juiste WHMCS-type betaalmethode', function ($fake) {
    $uid = client();
    add_method($fake, CARD, $uid);
    add_method($fake, SEPA, $uid);
    $types = array_map(function ($p) { return $p->gateway_name . '=' . $p->type; }, paymethods($uid));
    check($types === array(CARD . '=RemoteCreditCard', SEPA . '=RemoteBankAccount'), 'kaart via createCardPayMethod, SEPA via createBankPayMethod');
    check(Capsule::table('gateway_mollie_mandates')->count() === 2, '2 mandaten bewaard');
});

scenario('22 Teksten NL en EN volledig', function ($fake) {
    $nl = mollie_subscribe_lang('dutch');
    $en = mollie_subscribe_lang('english');
    $keys = array_filter(array_keys($nl), function ($k) { return strpos($k, 'subscribe') === 0; });
    check(count($keys) >= 12, count($keys) . ' teksten voor automatisch betalen');
    check(!array_diff($keys, array_keys($en)), 'elke NL-tekst bestaat ook in het Engels');
    check(mollie_subscribe_lang('klingon') === $en, 'onbekende taal valt terug op Engels');
    $all = implode(' ', array_merge(array_values($nl), array_values($en)));
    check(strpos($all, "\u{2014}") === false && strpos($all, "\u{2013}") === false, 'geen gedachtestreepjes');
});

scenario('23 Kaartinning meteen geweigerd: declined, 1 mail, nieuwe betaalmethode nodig', function ($fake) {
    $uid = client();
    $r = add_method($fake, CARD, $uid);
    $iid = invoice($uid, '20.00');
    $fake->nextRecurring['creditcard'] = 'failed';
    $fake->nextRecurringDetails = array('failureReason' => 'insufficient_funds');
    $res = test_cron_capture($iid);
    check($res['status'] === 'declined', 'capture declined');
    check(inv($iid)->status === 'Unpaid' && count(bookings($iid)) === 0, 'factuur Unpaid, geen boeking');
    check(count(paymethods($uid)) === 0 && !empty($fake->mandates[$r['mandate']]['_deleted']), 'betaalmethode en mandaat weg (optie staat aan)');
    $pid = last_recurring_payment($fake);
    mollie_subscribe_handle_webhook(array('id' => $pid));
    check(count(mails('Credit Card Payment Failed')) === 1, '1 mislukt-mail (WHMCS), webhook stuurt er geen tweede');
});

scenario('24 Cron tijdens open checkout voor dezelfde factuur: wacht, int niet', function ($fake) {
    $uid = client();
    add_method($fake, SEPA, $uid);
    $iid = invoice($uid, '45.00');
    list(, $post) = remote_form(SEPA, $uid, $iid);
    $start = mollie_subscribe_handle_start($post); // klant zit nu in de checkout
    $res = test_cron_capture($iid);
    check($res['status'] === 'pending' && recurring_count($fake) === 0, 'capture pending, geen inning');
    check(inv($iid)->status === 'Unpaid', 'factuur blijft Unpaid (niet Payment Pending)');
    $row = mollie_subscribe_payment_row($start[2]['rowId']);
    $fake->abandonCheckout($row->paymentid, 'expired');
    mollie_subscribe_handle_webhook(array('id' => $row->paymentid));
    $res2 = test_cron_capture($iid);
    check($res2['status'] === 'pending' && recurring_count($fake) === 1, 'na verlopen checkout: gewone inning');
});

scenario('25 Na een nieuw mandaat: standaardgateway en open facturen op automatisch', function ($fake) {
    $uid = client();
    $old = invoice($uid, '30.00'); // nog op Mollie Checkout
    check(test_cron_capture($old)['status'] === 'skipped', 'voor het mandaat: cron laat de factuur met Mollie Checkout liggen');
    add_method($fake, SEPA, $uid);
    check(Capsule::table('tblclients')->where('id', $uid)->value('defaultgateway') === SEPA, 'standaardgateway klant = domiciliëring');
    check(inv($old)->paymentmethod === SEPA, 'open factuur omgezet naar domiciliëring');
    check(test_cron_capture($old)['status'] === 'pending', 'cron int de oude open factuur');
    $new = invoice($uid, '30.00');
    check(inv($new)->paymentmethod === SEPA, 'nieuwe factuur krijgt meteen domiciliëring');
});

scenario('26 Lege of foute instelling methodes eerste betaling: kaart enkel creditcard, domiciliëring zonder kaart', function ($fake) {
    check(mollie_subscribe_first_methods(CARD, '') === array('creditcard'), 'kaart, leeg: creditcard');
    check(mollie_subscribe_first_methods(CARD, 'ideal,bancontact') === array('creditcard'), 'kaart, iDEAL ingevuld: toch creditcard');
    check(mollie_subscribe_first_methods(SEPA, '') === array('bancontact', 'ideal'), 'domiciliëring, leeg: bancontact, ideal');
    check(mollie_subscribe_first_methods(SEPA, ' Bancontact , creditcard,kbc') === array('bancontact', 'kbc'), 'domiciliëring: creditcard eruit, rest blijft');
    check(mollie_subscribe_first_methods(SEPA, 'creditcard') === array('bancontact', 'ideal'), 'domiciliëring, enkel creditcard: terug naar standaard');
    Capsule::table('tblpaymentgateways')->where('setting', 'firstMethods')->delete();
    $uid = client('dutch', 'BE');
    $r = add_method($fake, CARD, $uid);
    check($fake->payments[$r['row']->paymentid]['allowedMethods'] === 'creditcard', 'zonder opgeslagen instelling: eerste betaling kaart met method creditcard');
});

scenario('27 Iframe laden maakt nog niets bij Mollie: eerst kiezen, dan klikken', function ($fake) {
    $uid = client('dutch', 'BE');
    list(, $post) = remote_form(SEPA, $uid);
    unset($post['go']);
    $before = count($fake->payments);
    $step1 = mollie_subscribe_handle_start($post);
    check($step1[0] === 200, 'automatisch versturen door WHMCS: 200');
    check(count($fake->payments) === $before, 'nog geen betaling bij Mollie');
    check(strpos($step1[1], 'window.top') === false, 'stuurt het venster niet weg');
    check(strpos($step1[1], 'target="_top"') !== false && strpos($step1[1], 'name="go" value="1"') !== false, 'knop naar stap 2 in het hoofdvenster');
    preg_match_all('/name="([^"]+)" value="([^"]*)"/', $step1[1], $m, PREG_SET_ORDER);
    $post2 = array();
    foreach ($m as $f) $post2[$f[1]] = html_entity_decode($f[2], ENT_QUOTES);
    $step2 = mollie_subscribe_handle_start($post2);
    check($step2[0] === 200 && strpos($step2[1], 'window.top.location.href') !== false && count($fake->payments) === $before + 1, 'na de klik: 1 betaling en door naar Mollie');
    $post2['amount'] = '99.00';
    check(mollie_subscribe_handle_start($post2)[0] === 403, 'geknoeid na stap 1: 403');
});

scenario('28 Bevestiging domiciliëring minstens 0,02 (minimum Bancontact), kaart 0,01', function ($fake) {
    check(mollie_subscribe_verify_amount(SEPA, '0.01') === '0.02', 'domiciliëring 0.01 wordt 0.02');
    check(mollie_subscribe_verify_amount(SEPA, '') === '0.02', 'domiciliëring leeg wordt 0.02');
    check(mollie_subscribe_verify_amount(SEPA, '0.05') === '0.05', 'domiciliëring 0.05 blijft');
    check(mollie_subscribe_verify_amount(CARD, '') === '0.01', 'kaart leeg wordt 0.01');
    $uid = client('dutch', 'BE');
    $r = add_method($fake, SEPA, $uid);
    check($fake->payments[$r['row']->paymentid]['amount']['value'] === '0.02', 'eerste betaling domiciliëring zonder factuur: 0.02');
    check(count($fake->refunds[$r['row']->paymentid] ?? array()) === 1, '0.02 teruggestort');
});

scenario('29 Factuur op domiciliëring betalen zonder bewaarde rekening: eigen knop naar Mollie op de betaalpagina', function ($fake) {
    $uid = client('dutch', 'BE');
    $iid = invoice($uid, '24.20');
    Capsule::table('tblinvoices')->where('id', $iid)->update(array('paymentmethod' => SEPA));
    $html = mollie_subscribe_invoice_pay_output('/invoice/' . $iid . '/pay', $uid);
    check(strpos($html, 'frmPayment') !== false && strpos($html, 'wsMollieSepaForm') !== false && strpos($html, '.bank-details') !== false, 'verbergt de rekeningvelden van WHMCS');
    check(mollie_subscribe_invoice_pay_output('/invoice/' . $iid . '/pay', $uid + 1) === '', 'andere klant: niets');
    check(mollie_subscribe_invoice_pay_output('/invoice/' . $iid, $uid) === '', 'andere pagina: niets');
    preg_match_all('/name="([^"]+)" value="([^"]*)"/', $html, $m, PREG_SET_ORDER);
    $post = array();
    foreach ($m as $f) $post[$f[1]] = html_entity_decode($f[2], ENT_QUOTES);
    check($post['go'] === '1' && $post['amount'] === '24.20' && (int) $post['invoice'] === $iid, 'formulier: saldo van de factuur, meteen stap 2');
    $r = mollie_subscribe_handle_start($post);
    check($r[0] === 200 && strpos($r[1], 'window.top.location.href') !== false, 'start maakt de betaling en stuurt door');
    $row = mollie_subscribe_payment_row($r[2]['rowId']);
    $fake->completeCheckout($row->paymentid, 'bancontact', array());
    mollie_subscribe_handle_webhook(array('id' => $row->paymentid));
    check(inv($iid)->status === 'Paid', 'factuur Paid');
    $pms = paymethods($uid);
    check(count($pms) === 1 && $pms[0]->type === 'RemoteBankAccount', 'domiciliëring bewaard');
    Capsule::table('tblinvoices')->where('id', $iid)->update(array('status' => 'Paid'));
    check(mollie_subscribe_invoice_pay_output('/invoice/' . $iid . '/pay', $uid) === '', 'betaalde factuur: niets');
});

/* ---------- uitkomst ---------- */

$pass = 0;
foreach ($results as $name => $r) {
    echo ($r['ok'] ? 'OK  ' : 'FOUT') . '  ' . $name . PHP_EOL;
    foreach ($r['checks'] as $c)
        if (!$c[0] || getenv('VERBOSE'))
            echo '        ' . ($c[0] ? '+' : '-') . ' ' . $c[1] . PHP_EOL;
    if ($r['error'])
        echo '        ! ' . $r['error'] . PHP_EOL;
    if ($r['ok'])
        $pass++;
}
echo PHP_EOL . $pass . '/' . count($results) . ' gevallen OK' . PHP_EOL;

if (getenv('JSON_OUT'))
    file_put_contents(getenv('JSON_OUT'), json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

exit($pass === count($results) ? 0 : 1);
