<?php
/**
 * Nagebootste Mollie-API (v2) met geheugen, achter de echte mollie-api-php SDK.
 * Bootst customers, payments (first en recurring), mandates, refunds, chargebacks en
 * idempotency keys na, plus knoppen om de buitenwereld te simuleren (klant rondt checkout af,
 * bank keurt SEPA goed of af, terugboeking, mandaat ingetrokken bij de bank, netwerkfout).
 */

use Mollie\Api\Contracts\HttpAdapterContract;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Response;
use Mollie\Api\Traits\HasDefaultFactories;

class FakeMollie implements HttpAdapterContract
{
    use HasDefaultFactories;

    public $customers = array();
    public $payments = array();
    public $mandates = array();   // mandateId => array
    public $refunds = array();    // paymentId => [refund...]
    public $chargebacks = array(); // paymentId => [chargeback...]
    public $idempotency = array(); // key => paymentId
    public $requests = array();

    /** Status van de volgende recurring betaling: per methode */
    public $nextRecurring = array('creditcard' => 'paid', 'directdebit' => 'pending');
    public $nextRecurringDetails = null;
    /** Volgende POST /payments: betaling aanmaken en dan een netwerkfout gooien */
    public $failAfterCreate = false;
    /** Volgende GET /payments/{id}: netwerkfout */
    public $failNextGet = false;

    private $seq = 1000;

    public function version(): ?string
    {
        return 'fake';
    }

    private function id($prefix)
    {
        return $prefix . '_' . ($this->seq++) . 'x';
    }

    public function count($method, $pathPrefix)
    {
        $n = 0;
        foreach ($this->requests as $r)
            if ($r[0] === $method && strpos($r[1], $pathPrefix) === 0)
                $n++;
        return $n;
    }

    public function sendRequest(PendingRequest $pendingRequest): Response
    {
        $method = $pendingRequest->method();
        $url = $pendingRequest->url();
        $path = preg_replace('#^https://api\.mollie\.com/v2/#', '', parse_url($url, PHP_URL_SCHEME) ? $url : 'https://api.mollie.com/v2/' . ltrim($url, '/'));
        $path = strtok($path, '?');
        $body = $pendingRequest->payload() ? $pendingRequest->payload()->all() : array();
        $idem = $pendingRequest->headers()->get('Idempotency-Key');
        $this->requests[] = array($method, $path, $body);

        list($status, $json) = $this->route($method, $path, $body, $idem);

        $psr = $this->factories()->responseFactory->createResponse($status);
        $psr = $psr->withHeader('Content-Type', 'application/hal+json')
            ->withBody($this->factories()->streamFactory->createStream($json === null ? '' : json_encode($json)));

        $response = new Response($psr, $pendingRequest->createPsrRequest(), $pendingRequest);

        if ($method === 'POST' && $path === 'payments' && $this->failAfterCreate) {
            $this->failAfterCreate = false;
            throw new \Mollie\Api\Exceptions\RetryableNetworkRequestException($pendingRequest, 'Gesimuleerde time-out na aanmaken');
        }

        return $response;
    }

    private function error($status, $detail)
    {
        return array($status, array('status' => $status, 'title' => 'Error', 'detail' => $detail, '_links' => new \stdClass()));
    }

    private function route($method, $path, $body, $idem)
    {
        $p = explode('/', $path);

        if ($method === 'POST' && $path === 'customers') {
            $id = $this->id('cst');
            $this->customers[$id] = array('resource' => 'customer', 'id' => $id, 'mode' => 'test', 'name' => $body['name'], 'email' => $body['email'], 'locale' => $body['locale'] ?? null, 'metadata' => $body['metadata'] ?? null, '_links' => new \stdClass());
            return array(201, $this->customers[$id]);
        }

        if ($method === 'POST' && $path === 'payments')
            return $this->createPayment($body, $idem);

        if ($method === 'GET' && $p[0] === 'payments' && count($p) === 2) {
            if ($this->failNextGet) {
                $this->failNextGet = false;
                return array(503, array('status' => 503, 'title' => 'Service Unavailable', 'detail' => 'down'));
            }
            return isset($this->payments[$p[1]]) ? array(200, $this->paymentJson($p[1])) : $this->error(404, 'No payment exists with token ' . $p[1]);
        }

        if ($method === 'DELETE' && $p[0] === 'payments' && count($p) === 2) {
            $pay = &$this->payments[$p[1]];
            if (empty($pay['isCancelable']))
                return $this->error(422, 'The payment cannot be canceled');
            $pay['status'] = 'canceled';
            $pay['isCancelable'] = false;
            return array(200, $this->paymentJson($p[1]));
        }

        if ($method === 'POST' && $p[0] === 'payments' && ($p[2] ?? '') === 'refunds') {
            $id = $this->id('re');
            $refund = array('resource' => 'refund', 'id' => $id, 'amount' => $body['amount'], 'status' => 'pending', 'paymentId' => $p[1], 'description' => $body['description'] ?? '', '_links' => new \stdClass());
            $this->refunds[$p[1]][] = $refund;
            return array(201, $refund);
        }

        if ($method === 'GET' && $p[0] === 'payments' && ($p[2] ?? '') === 'chargebacks') {
            $list = $this->chargebacks[$p[1]] ?? array();
            return array(200, array('count' => count($list), '_embedded' => array('chargebacks' => $list), '_links' => array('next' => null, 'previous' => null)));
        }

        if ($p[0] === 'customers' && ($p[2] ?? '') === 'mandates' && isset($p[3])) {
            $m = $this->mandates[$p[3]] ?? null;
            if (!$m || $m['customerId'] !== $p[1] || !empty($m['_deleted']))
                return $this->error(404, 'No mandate exists with token ' . $p[3]);
            if ($method === 'DELETE') {
                $this->mandates[$p[3]]['_deleted'] = true;
                return array(204, null);
            }
            return array(200, $this->mandateJson($p[3]));
        }

        if ($method === 'GET' && $p[0] === 'customers' && ($p[2] ?? '') === 'payments') {
            $list = array();
            foreach ($this->payments as $id => $pay)
                if ($pay['customerId'] === $p[1])
                    $list[] = $this->paymentJson($id);
            return array(200, array('count' => count($list), '_embedded' => array('payments' => array_reverse($list)), '_links' => array('next' => null, 'previous' => null)));
        }

        return $this->error(404, 'Fake: geen route voor ' . $method . ' ' . $path);
    }

    private function createPayment($body, $idem)
    {
        if ($idem && isset($this->idempotency[$idem]))
            return array(201, $this->paymentJson($this->idempotency[$idem]));

        $seq = $body['sequenceType'] ?? 'oneoff';
        $customerId = $body['customerId'] ?? null;

        if ($customerId && !isset($this->customers[$customerId]))
            return $this->error(422, 'customer unknown');

        $id = $this->id('tr');
        $pay = array(
            'resource' => 'payment', 'id' => $id, 'mode' => 'test',
            'amount' => $body['amount'], 'description' => $body['description'],
            'sequenceType' => $seq, 'customerId' => $customerId, 'mandateId' => $body['mandateId'] ?? null,
            'metadata' => $body['metadata'] ?? null, 'locale' => $body['locale'] ?? null,
            'redirectUrl' => $body['redirectUrl'] ?? null, 'webhookUrl' => $body['webhookUrl'] ?? null,
            'method' => is_array($body['method'] ?? null) ? null : ($body['method'] ?? null),
            'allowedMethods' => $body['method'] ?? null,
            'status' => 'open', 'isCancelable' => false, 'paidAt' => null, 'details' => null,
            'createdAt' => date('c'),
        );

        if ($seq === 'recurring') {
            $mandate = $this->mandates[$body['mandateId']] ?? null;
            if (!$mandate || $mandate['customerId'] !== $customerId || !empty($mandate['_deleted']))
                return $this->error(422, 'The mandate is invalid');
            if ($mandate['status'] !== 'valid')
                return $this->error(422, 'The mandate is not valid');

            $pay['method'] = $mandate['method'];
            $status = $this->nextRecurring[$mandate['method']];
            $pay['status'] = $status;
            if ($status === 'paid')
                $pay['paidAt'] = date('c');
            if ($status === 'pending' && $mandate['method'] === 'directdebit')
                $pay['isCancelable'] = true;
            if ($status === 'failed')
                $pay['details'] = $this->nextRecurringDetails;
            $this->nextRecurringDetails = null;
        }

        $this->payments[$id] = $pay;

        if ($idem)
            $this->idempotency[$idem] = $id;

        return array(201, $this->paymentJson($id));
    }

    public function paymentJson($id)
    {
        $pay = $this->payments[$id];
        $out = $pay;
        unset($out['allowedMethods']);
        $links = array('self' => array('href' => 'https://api.mollie.com/v2/payments/' . $id, 'type' => 'application/hal+json'));

        if ($pay['status'] === 'open' && $pay['sequenceType'] === 'first')
            $links['checkout'] = array('href' => 'https://www.mollie.com/checkout/test-mode?id=' . $id, 'type' => 'text/html');

        if (!empty($this->refunds[$id]))
            $links['refunds'] = array('href' => 'https://api.mollie.com/v2/payments/' . $id . '/refunds', 'type' => 'application/hal+json');

        if (!empty($this->chargebacks[$id])) {
            $links['chargebacks'] = array('href' => 'https://api.mollie.com/v2/payments/' . $id . '/chargebacks', 'type' => 'application/hal+json');
            $total = 0;
            foreach ($this->chargebacks[$id] as $c)
                $total += (float) $c['amount']['value'];
            $out['amountChargedBack'] = array('value' => number_format($total, 2, '.', ''), 'currency' => 'EUR');
        }

        $out['_links'] = $links;

        return $out;
    }

    public function mandateJson($id)
    {
        $m = $this->mandates[$id];
        unset($m['_deleted']);
        $m['_links'] = new \stdClass();

        return $m;
    }

    /* ---------- Buitenwereld simuleren ---------- */

    /** Klant rondt de eerste betaling af in de checkout */
    public function completeCheckout($paymentId, $method, $details = array())
    {
        $pay = &$this->payments[$paymentId];
        $pay['method'] = $method;
        $pay['status'] = 'paid';
        $pay['paidAt'] = date('c');

        $mandateMethod = $method === 'creditcard' ? 'creditcard' : 'directdebit';
        $mandateId = $this->id('mdt');
        $mandateDetails = $mandateMethod === 'creditcard'
            ? array_merge(array('cardHolder' => 'T. Tester', 'cardNumber' => '6787', 'cardLabel' => 'Mastercard', 'cardFingerprint' => 'fp', 'cardExpiryDate' => date('Y-m-t', strtotime('+2 years'))), $details)
            : array_merge(array('consumerName' => 'T. Tester', 'consumerAccount' => 'BE68539007547034', 'consumerBic' => 'GEBABEBB'), $details);

        $this->mandates[$mandateId] = array('resource' => 'mandate', 'id' => $mandateId, 'mode' => 'test', 'status' => 'valid', 'method' => $mandateMethod, 'details' => $mandateDetails, 'customerId' => $pay['customerId'], 'signatureDate' => date('Y-m-d'), 'mandateReference' => null, 'createdAt' => date('c'));
        $pay['mandateId'] = $mandateId;

        return $mandateId;
    }

    public function abandonCheckout($paymentId, $status = 'canceled')
    {
        $this->payments[$paymentId]['status'] = $status;
    }

    /** Bank keurt de SEPA-inning goed */
    public function settle($paymentId)
    {
        $pay = &$this->payments[$paymentId];
        $pay['status'] = 'paid';
        $pay['paidAt'] = date('c');
        $pay['isCancelable'] = false;
    }

    /** Bank weigert de SEPA-inning */
    public function fail($paymentId, $code = 'AM04', $reason = 'Insufficient funds')
    {
        $pay = &$this->payments[$paymentId];
        $pay['status'] = 'failed';
        $pay['isCancelable'] = false;
        $pay['details'] = array('bankReasonCode' => $code, 'bankReason' => $reason);
    }

    /** Terugboeking door de klant via de bank */
    public function chargeback($paymentId)
    {
        $id = $this->id('chb');
        $this->chargebacks[$paymentId][] = array('resource' => 'chargeback', 'id' => $id, 'amount' => $this->payments[$paymentId]['amount'], 'paymentId' => $paymentId, 'createdAt' => date('c'), 'reason' => array('code' => 'MD06', 'description' => 'Refund request by customer'), '_links' => new \stdClass());

        return $id;
    }

    /** Klant trekt het mandaat in bij de eigen bank */
    public function revokeAtBank($mandateId)
    {
        $this->mandates[$mandateId]['status'] = 'invalid';
    }

    public function expireCard($mandateId)
    {
        $this->mandates[$mandateId]['details']['cardExpiryDate'] = date('Y-m-t', strtotime('-2 months'));
    }
}
