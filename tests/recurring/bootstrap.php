<?php
/**
 * Nagebootste WHMCS voor de Mollie Recurring-gateways: SQLite via illuminate/database plus stubs
 * voor de WHMCS-functies die de gateway gebruikt. Gedrag van de stubs volgt de WHMCS-docs
 * (developer-docs payment-gateways, sample-remote-input-gateway, sample-remote-bank-gateway-module).
 */

namespace WHMCS\Database {
    require_once __DIR__ . '/vendor/autoload.php';
    class Capsule extends \Illuminate\Database\Capsule\Manager {}
}

namespace WHMCS\Payment\PayMethod {
    use WHMCS\Database\Capsule;

    /** Minimale nabootsing van het PayMethod-model van WHMCS 8+ */
    class Model
    {
        public $row;
        public $payment;
        private static $wheres = array();

        public static function where($col, $val)
        {
            $q = new ModelQuery();
            return $q->where($col, $val);
        }

        public function __construct($row)
        {
            $this->row = $row;
            $this->payment = new RemotePayment($row->token);
        }

        public function delete()
        {
            Capsule::table('tblpaymethods')->where('id', $this->row->id)->update(array('deleted_at' => date('Y-m-d H:i:s')));
            return true;
        }
    }

    class ModelQuery
    {
        private $q;
        public function __construct() { $this->q = Capsule::table('tblpaymethods')->whereNull('deleted_at'); }
        public function where($col, $val) { $this->q->where($col, $val); return $this; }
        public function get() { return array_map(function ($r) { return new Model($r); }, $this->q->get()->all()); }
    }

    class RemotePayment
    {
        private $token;
        public function __construct($token) { $this->token = $token; }
        public function getRemoteToken() { return $this->token; }
    }
}

namespace {
    use WHMCS\Database\Capsule;

    if (!defined('WHMCS'))
        define('WHMCS', true);

    $capsule = new Capsule();
    $capsule->addConnection(array('driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''));
    $capsule->setAsGlobal();

    $GLOBALS['MAILS'] = array();
    $GLOBALS['HOOKS'] = array();
    $GLOBALS['GATEWAYLOG'] = array();

    function test_schema()
    {
        $s = Capsule::schema();
        foreach (array('tblclients', 'tblinvoices', 'tblaccounts', 'tblpaymethods', 'tblactivitylog', 'tblgatewaylog', 'tblpaymentgateways', 'gateway_mollie_customers', 'gateway_mollie_mandates', 'gateway_mollie_recurring') as $t)
            $s->dropIfExists($t);

        $s->create('tblclients', function ($t) { $t->increments('id'); foreach (array('firstname', 'lastname', 'companyname', 'email', 'country', 'language', 'defaultgateway') as $c) $t->string($c)->default(''); });
        $s->create('tblinvoices', function ($t) { $t->increments('id'); $t->integer('userid'); $t->string('invoicenum')->default(''); $t->date('duedate')->nullable(); $t->dateTime('datepaid')->nullable(); $t->decimal('total', 10, 2); $t->string('status'); $t->string('paymentmethod')->default(''); });
        $s->create('tblaccounts', function ($t) { $t->increments('id'); $t->integer('userid')->default(0); $t->integer('invoiceid'); $t->string('gateway')->default(''); $t->string('transid'); $t->decimal('amountin', 10, 2)->default(0); $t->decimal('amountout', 10, 2)->default(0); $t->string('description')->default(''); });
        $s->create('tblpaymethods', function ($t) { $t->increments('id'); $t->integer('userid'); $t->string('gateway_name'); $t->string('type'); $t->string('token'); $t->string('description')->default(''); $t->string('last4')->default(''); $t->string('expiry')->default(''); $t->string('cardtype')->default(''); $t->dateTime('deleted_at')->nullable(); });
        $s->create('tblactivitylog', function ($t) { $t->increments('id'); $t->text('description'); $t->integer('userid')->default(0); });
        $s->create('tblgatewaylog', function ($t) { $t->increments('id'); $t->string('gateway'); $t->text('data'); $t->string('result'); });
        $s->create('tblpaymentgateways', function ($t) { $t->string('gateway'); $t->string('setting'); $t->text('value')->nullable(); });

        foreach (array('molliesubscribecard_devapp' => 'creditcard', 'molliesubscribesepa_devapp' => 'bancontact,ideal,belfius,kbc') as $gw => $methods) {
            foreach (array('type' => $gw === 'molliesubscribesepa_devapp' ? 'Bank' : 'CC', 'key' => 'test_fakefakefakefakefakefakefakefake', 'firstMethods' => $methods, 'verifyAmount' => '0.01', 'invalidateOnFailure' => 'on', 'refundDoublePayment' => 'on') as $k => $v)
                Capsule::table('tblpaymentgateways')->insert(array('gateway' => $gw, 'setting' => $k, 'value' => $v));
        }

        $GLOBALS['MAILS'] = array();
        $GLOBALS['GATEWAYLOG'] = array();
        unset($GLOBALS['MOLLIE_SUBSCRIBE_INSTALLED']);
    }

    /* ---------- WHMCS-functies ---------- */

    function getGatewayVariables($gateway)
    {
        $params = array('paymentmethod' => $gateway, 'name' => $gateway, 'systemurl' => 'https://test.webscout.eu/');
        foreach (Capsule::table('tblpaymentgateways')->where('gateway', $gateway)->get() as $r)
            $params[$r->setting] = $r->value;
        return $params;
    }

    function logTransaction($gateway, $data, $result)
    {
        $GLOBALS['GATEWAYLOG'][] = array($gateway, $result, $data);
        Capsule::table('tblgatewaylog')->insert(array('gateway' => (string) $gateway, 'data' => json_encode($data), 'result' => (string) $result));
    }

    function logActivity($message, $userId = 0)
    {
        Capsule::table('tblactivitylog')->insert(array('description' => $message, 'userid' => (int) $userId));
    }

    function sendMessage($template, $relId)
    {
        $GLOBALS['MAILS'][] = array($template, (int) $relId);
        return true;
    }

    function add_hook($name, $prio, $fn) { $GLOBALS['HOOKS'][$name][] = $fn; }
    function run_hook($name, $vars) { foreach ($GLOBALS['HOOKS'][$name] ?? array() as $fn) $fn($vars); }

    /** Zoals WHMCS: betaling boeken, bij volledig saldo de factuur op Paid en hook InvoicePaid */
    function addInvoicePayment($invoiceId, $transId, $amount, $fee, $gateway)
    {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        Capsule::table('tblaccounts')->insert(array('userid' => $invoice->userid, 'invoiceid' => $invoiceId, 'gateway' => $gateway, 'transid' => (string) $transId, 'amountin' => $amount ?: $invoice->total, 'description' => 'Invoice Payment'));
        $paid = Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin') - Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountout');
        if ($paid >= $invoice->total - 0.001 && $invoice->status !== 'Paid') {
            Capsule::table('tblinvoices')->where('id', $invoiceId)->update(array('status' => 'Paid', 'datepaid' => date('Y-m-d H:i:s')));
            run_hook('InvoicePaid', array('invoiceid' => $invoiceId));
        }
        return true;
    }

    /** Zoals WHMCS (docs reversals): uitzondering bij onbekende of al teruggedraaide betaling */
    function paymentReversed($reverseTransId, $originalTransId, $invoiceId = 0, $gateway = null)
    {
        $orig = Capsule::table('tblaccounts')->where('transid', $originalTransId)->get();
        if (count($orig) === 0)
            throw new \Exception('Original Transaction Not Found');
        if (count($orig) > 1)
            throw new \Exception('Multiple Original Transaction ID matches found');
        if (Capsule::table('tblaccounts')->where('transid', $reverseTransId)->exists())
            throw new \Exception('Transaction Already Reversed');
        $o = $orig[0];
        Capsule::table('tblaccounts')->insert(array('userid' => $o->userid, 'invoiceid' => $o->invoiceid, 'gateway' => $o->gateway, 'transid' => $reverseTransId, 'amountout' => $o->amountin, 'description' => 'Payment Reversal'));
        // Slechtste geval: WHMCS-instelling zet de factuur op Collections
        Capsule::table('tblinvoices')->where('id', $o->invoiceid)->update(array('status' => 'Collections'));
    }

    function localAPI($cmd, $params = array(), $admin = '')
    {
        if ($cmd === 'UpdateClient') {
            Capsule::table('tblclients')->where('id', $params['clientid'])->update(array('defaultgateway' => $params['paymentmethod']));
            return array('result' => 'success', 'clientid' => $params['clientid']);
        }
        if ($cmd === 'UpdateInvoice') {
            Capsule::table('tblinvoices')->where('id', $params['invoiceid'])->update(array('status' => $params['status']));
            return array('result' => 'success', 'invoiceid' => $params['invoiceid']);
        }
        return array('result' => 'error', 'message' => 'unknown ' . $cmd);
    }

    /** Zoals WHMCS 7.9+: uitzondering als de gateway niet actief is */
    function test_assert_gateway($gateway)
    {
        if (!Capsule::table('tblpaymentgateways')->where('gateway', $gateway)->exists())
            throw new \Exception('Module Not Activated');
    }

    function createCardPayMethod($clientId, $gateway, $cardNumber, $expiry, $cardType, $start = null, $issue = null, $remoteToken = '', $billingContactId = 'billing', $description = '')
    {
        test_assert_gateway($gateway);
        if (Capsule::table('tblpaymentgateways')->where('gateway', $gateway)->where('setting', 'type')->value('value') === 'Bank')
            throw new \InvalidArgumentException('Invalid Workflow Type for PayMethod');
        Capsule::table('tblpaymethods')->insert(array('userid' => $clientId, 'gateway_name' => $gateway, 'type' => 'RemoteCreditCard', 'token' => $remoteToken, 'description' => $description, 'last4' => $cardNumber, 'expiry' => $expiry, 'cardtype' => (string) $cardType));
        return true;
    }

    function createBankPayMethod($clientId, $gateway, $accountType, $routing, $accountNumber, $bankName, $holder, $remoteToken = '', $billingContactId = 'billing', $description = '')
    {
        test_assert_gateway($gateway);
        if (Capsule::table('tblpaymentgateways')->where('gateway', $gateway)->where('setting', 'type')->value('value') !== 'Bank')
            throw new \InvalidArgumentException('Invalid Workflow Type for PayMethod');
        Capsule::table('tblpaymethods')->insert(array('userid' => $clientId, 'gateway_name' => $gateway, 'type' => 'RemoteBankAccount', 'token' => $remoteToken, 'description' => $description, 'last4' => $accountNumber, 'cardtype' => $bankName));
        return true;
    }

    /* ---------- WHMCS-cron nabootsen ---------- */

    /**
     * Zoals de cron-taak "Process Credit Card Charges": enkel Unpaid facturen, met een bewaarde
     * betaalmethode. success boekt de betaling, declined stuurt de failedEmail.
     */
    function test_cron_capture($invoiceId)
    {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if ($invoice->status !== 'Unpaid')
            return array('status' => 'skipped', 'why' => 'factuur ' . $invoice->status);
        if (!in_array($invoice->paymentmethod, array('molliesubscribecard_devapp', 'molliesubscribesepa_devapp'), true))
            return array('status' => 'skipped', 'why' => 'factuur heeft geen inningsgateway (' . $invoice->paymentmethod . ')');

        $pm = Capsule::table('tblpaymethods')->where('userid', $invoice->userid)->whereNull('deleted_at')->orderBy('id', 'desc')->first();
        if (!$pm)
            return array('status' => 'skipped', 'why' => 'geen betaalmethode');

        $gateway = $pm->gateway_name;
        $client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();
        $params = array_merge(getGatewayVariables($gateway), array(
            'invoiceid' => $invoiceId, 'amount' => $invoice->total, 'currency' => 'EUR', 'gatewayid' => $pm->token, 'description' => 'Webscout - Factuur ' . $invoice->invoicenum,
            'clientdetails' => array('id' => $client->id, 'userid' => $client->id, 'firstname' => $client->firstname, 'lastname' => $client->lastname, 'email' => $client->email, 'countrycode' => $client->country, 'language' => $client->language),
        ));

        $fn = $gateway . '_capture';
        $result = $fn($params);

        if ($result['status'] === 'success')
            addInvoicePayment($invoiceId, $result['transid'], 0, 0, $gateway);
        else if ($result['status'] === 'declined')
            sendMessage($gateway === 'molliesubscribesepa_devapp' ? 'Direct Debit Payment Failed' : 'Credit Card Payment Failed', $invoiceId);

        return $result;
    }
}
