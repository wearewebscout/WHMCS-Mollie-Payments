<?php

require_once __DIR__ . '/vendor/autoload.php';

// Webscout: versie van onze fork, bij elke wijziging ophogen
if (!defined('MOLLIE_WS_VERSION'))
    define('MOLLIE_WS_VERSION', '1.2.0');

function mollie_config()
{
    return array(
        'key' => array(
            'FriendlyName' => 'API key',
            'Type' => 'text',
            'Size' => '35',
            'Description' => 'Your channels API key.'
        )
    );
}

function mollie_link($params, $method = Mollie_API_Object_Method::IDEAL)
{
    global $whmcs;

    /**
     *
     *    Setting requirements and includes
     *
     */
    if (substr($params['returnurl'], 0, 1) == '/')
        $params['returnurl'] = $params['systemurl'] . $params['returnurl'];

    // Webscout: WHMCS bewaart de sessietaal als 'Language' (hoofdletter), de actieve taal staat in \Lang
    if (empty($params['language']) && class_exists('\Lang'))
        $params['language'] = \Lang::getName();

    if (empty($params['language']))
        $params['language'] = ((isset($_SESSION['Language'])) ? $_SESSION['Language'] : ((isset($_SESSION['language'])) ? $_SESSION['language'] : $whmcs->get_config('Language')));

    $params['language'] = strtolower(basename((string) $params['language']));

    if (empty($params['language']))
        $params['language'] = 'english';

    if (!file_exists(__DIR__ . '/lang/' . $params['language'] . '.php'))
        $params['language'] = 'english';

    /* @var array $_GATEWAYLANG */
    require __DIR__ . '/lang/' . $params['language'] . '.php';

    // Webscout: locale meegeven aan Mollie, zie mollie_locale()
    $mollieLocale = mollie_locale($params['language'], isset($params['clientdetails']['countrycode']) ? $params['clientdetails']['countrycode'] : '');

    $tableCheckQuery = full_query('SHOW TABLES LIKE \'gateway_mollie\'');

    if (mysql_num_rows($tableCheckQuery) != 1) {
        full_query('CREATE TABLE IF NOT EXISTS `gateway_mollie` (`id` int(11) NOT NULL AUTO_INCREMENT, `paymentid` varchar(64), `amount` double NOT NULL, `currencyid` int(11) NOT NULL, `ip` varchar(50) NOT NULL, `userid` int(11) NOT NULL, `invoiceid` int(11) NOT NULL, `status` ENUM(\'open\',\'paid\',\'closed\') NOT NULL DEFAULT \'open\', `method` VARCHAR(25) NOT NULL,  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `paymentid` (`paymentid`)) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;');
    }

    $paymentIdQuery = full_query("SHOW COLUMNS FROM `gateway_mollie` WHERE `Field` = 'paymentid' AND `Type` LIKE '%64%'");

    if (mysql_num_rows($paymentIdQuery) == 0) {
        full_query("ALTER TABLE `gateway_mollie` CHANGE `paymentid` `paymentid` VARCHAR(64);");
    }

    $mollie = new \Mollie\Api\MollieApiClient();
    $mollie->setApiKey($params['key']);

    /**
     *
     *    Check if good state to open transaction.
     *
     */
    if (isset($_GET['check_payment']) && ctype_digit($_GET['check_payment'])) {
        $transactionQuery = select_query('gateway_mollie', '', array('id' => $_GET['check_payment']), null, null, 1);

        if (mysql_num_rows($transactionQuery) != 1) {
            return '<p>' . $_GATEWAYLANG['errorTransactionNotFound'] . '</p>';
        }

        $transaction = mysql_fetch_assoc($transactionQuery);

        if ($transaction['status'] == 'paid') {
            header('location: ' . $params['returnurl'] . '&paymentsuccess=true');
            exit();
        } else if ($transaction['status'] == 'closed') {
            header('location: ' . $params['returnurl'] . '&paymentfailed=true');
            exit();
        } else {
            return '<br/><img src="' . $params['systemurl'] . 'modules/gateways/mollie/ajax_loader.gif" /><br/>' . $_GATEWAYLANG['checkPayment'] . ' <script> window.onload = function(){ setTimeout("location.reload(true);", 2000); } </script>';
        }
    } else {
        if (isset($_POST['start']) || isset($_POST['issuer']) || (isset($_GET['a']) && $_GET['a'] == 'complete') || (isset($_GET['action']) && ($_GET['action'] == 'addfunds' || $_GET['action'] == 'masspay') && isset($_POST['paymentmethod']) && $_POST['paymentmethod'] == 'mollie' . $method)) {

            $transactionCurrency = select_query('tblcurrencies', '', array('code' => $params['currency']), null, null, 1);
            $transactionCurrency = mysql_fetch_assoc($transactionCurrency);

            // Webscout: bij overschrijving een open betaling hergebruiken, de gegevens staan dan al op de factuur
            if ($method == \Mollie\Api\Types\PaymentMethod::BANKTRANSFER && mollie_banktransfer_open_payment($mollie, $params) !== null) {
                header('Location: ' . $params['returnurl']);
                exit();
            }

            $transactionId = insert_query('gateway_mollie', array(
                'amount' => $params['amount'],
                'currencyid' => $transactionCurrency['id'],
                'ip' => $_SERVER['REMOTE_ADDR'],
                'userid' => $params['clientdetails']['userid'],
                'invoiceid' => $params['invoiceid'],
                'method' => $method
            ));

            $paymentData = array(
                'amount' => [
                    'value' => $params['amount'],
                    'currency' => $params['currency'],
                ],
                'method' => $method,
                'description' => $params['description'],
                'locale' => $mollieLocale,
                'redirectUrl' => $params['returnurl'] . '&check_payment=' . $transactionId,
                'webhookUrl' => $params['systemurl'] . '/modules/gateways/mollie/callback.php',
                'metadata' => array(
                    'invoice_id' => $params['invoiceid'],
                ),
                'dueDate' => (($method == \Mollie\Api\Types\PaymentMethod::BANKTRANSFER) ? date('Y-m-d', strtotime('+100 days')) : NULL),
            );

            // Webscout: e-mailadres van de klant meegeven bij overschrijving (ook via Mollie Checkout),
            // dan vraagt Mollie het niet meer op de betaalpagina en mailt Mollie zelf de overschrijvingsgegevens.
            $clientEmail = isset($params['clientdetails']['email']) ? trim((string) $params['clientdetails']['email']) : '';
            if (($method === null || $method == \Mollie\Api\Types\PaymentMethod::BANKTRANSFER) && filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
                $paymentData['billingEmail'] = $clientEmail;
            }

            $payment = $mollie->payments->create($paymentData);

            update_query('gateway_mollie', array('paymentid' => $payment->id), array('id' => $transactionId));

            // Webscout: bij overschrijving geen Mollie-pagina (met bevestigingsstap), de factuur toont de gegevens zelf
            if ($method == \Mollie\Api\Types\PaymentMethod::BANKTRANSFER) {
                header('Location: ' . $params['returnurl']);
                exit();
            }

            header('Location: ' . $payment->getCheckoutUrl());
            exit();
        } else {
            if ($method == \Mollie\Api\Types\PaymentMethod::BANKTRANSFER) {
                $openPayment = mollie_banktransfer_open_payment($mollie, $params);
                if ($openPayment !== null) {
                    return mollie_banktransfer_details_html($openPayment, $params, $_GATEWAYLANG);
                }
            }

            $return = '<form action="viewinvoice.php?id=' . $params['invoiceid'] . '" method="POST">';

            $return .= '<input type="submit" name="start" value="' . $_GATEWAYLANG['payWith' . ucfirst($method)] . '" /></form>';

            return $return;
        }
    }
}

/**
 * Webscout: open overschrijving voor deze factuur bij Mollie, met hetzelfde bedrag en dezelfde munt.
 *
 * @return \Mollie\Api\Resources\Payment|null
 */
function mollie_banktransfer_open_payment($mollie, $params)
{
    $currency = mysql_fetch_assoc(select_query('tblcurrencies', 'id', array('code' => $params['currency']), null, null, 1));
    $query = select_query('gateway_mollie', '', array(
        'invoiceid' => $params['invoiceid'],
        'method' => \Mollie\Api\Types\PaymentMethod::BANKTRANSFER,
        'status' => 'open',
    ), 'id', 'DESC', 5);

    while ($row = mysql_fetch_assoc($query)) {
        if (empty($row['paymentid']) || abs((float) $row['amount'] - (float) $params['amount']) > 0.004)
            continue;
        if ($currency && (int) $row['currencyid'] !== (int) $currency['id'])
            continue;

        try {
            $payment = $mollie->payments->get($row['paymentid']);
        } catch (\Exception $e) {
            continue;
        }

        if ($payment->status === 'open' && !empty($payment->details->bankAccount) && !empty($payment->details->transferReference))
            return $payment;
    }

    return null;
}

/**
 * Webscout: overschrijvingsgegevens van Mollie op de factuurpagina, met kopieerknoppen.
 */
function mollie_banktransfer_details_html($payment, $params, $lang)
{
    $e = function ($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };
    $iban = trim(chunk_split(preg_replace('/\s+/', '', (string) $payment->details->bankAccount), 4, ' '));
    $commaLanguages = array('dutch', 'french', 'german', 'spanish', 'italian', 'portuguese-pt');
    $value = (float) $payment->amount->value;
    $amount = in_array($params['language'], $commaLanguages, true) ? number_format($value, 2, ',', '.') : number_format($value, 2, '.', ',');
    $amount = ($payment->amount->currency === 'EUR' ? '€ ' : $payment->amount->currency . ' ') . $amount;

    $rows = array(
        array($lang['btBeneficiary'], $payment->details->bankName, true),
        array($lang['btIban'], $iban, true),
        array($lang['btBic'], $payment->details->bankBic, true),
        array($lang['btAmount'], $amount, false),
        array($lang['btReference'], $payment->details->transferReference, true),
    );

    $html = '<div class="mollie-banktransfer" style="text-align:left;max-width:480px;margin:0 auto">';
    $html .= '<p>' . $e($lang['btIntro']) . '</p>';
    $html .= '<table class="table table-sm" style="width:100%"><tbody>';
    foreach ($rows as $row) {
        if ($row[1] === null || $row[1] === '')
            continue;
        $html .= '<tr><th style="font-weight:normal;white-space:nowrap;padding-right:12px">' . $e($row[0]) . '</th>'
            . '<td><strong>' . $e($row[1]) . '</strong></td><td style="text-align:right">';
        if ($row[2])
            $html .= '<button type="button" class="btn btn-sm btn-link mollie-bt-copy" style="padding:0" data-copy="' . $e($row[0] === $lang['btIban'] ? str_replace(' ', '', $row[1]) : $row[1]) . '">' . $e($lang['btCopy']) . '</button>';
        $html .= '</td></tr>';
    }
    $html .= '</tbody></table>';
    $html .= '<p><small>' . $e($lang['btNote']) . '</small></p></div>';
    $html .= '<script>document.querySelectorAll(".mollie-bt-copy").forEach(function(b){b.addEventListener("click",function(){var t=b.textContent;if(navigator.clipboard){navigator.clipboard.writeText(b.getAttribute("data-copy")).then(function(){b.textContent=' . json_encode($lang['btCopied']) . ';setTimeout(function(){b.textContent=t;},1500);});}});});</script>';

    return $html;
}

/**
 * Webscout: locale voor Mollie, taal van de klant plus regio. De regio bepaalt bij
 * overschrijving de IBAN (BE, NL of DE), dus land van de klant en anders standaard België.
 *
 * @param string $language WHMCS-taalnaam (dutch, english, ...)
 * @param string $countryCode landcode van de klant (BE, NL, ...)
 * @return string|null
 */
function mollie_locale($language, $countryCode)
{
    $mollieLocales = array('ca_ES', 'cs_CZ', 'da_DK', 'de_AT', 'de_CH', 'de_DE', 'de_LU', 'el_GR', 'en_BE', 'en_GB', 'en_NL', 'en_US', 'es_ES', 'fi_FI', 'fr_BE', 'fr_FR', 'fr_LU', 'hu_HU', 'is_IS', 'it_IT', 'lt_LT', 'lv_LV', 'nb_NO', 'nl_BE', 'nl_NL', 'pl_PL', 'pt_PT', 'sk_SK', 'sl_SI', 'sv_SE', 'tr_TR');
    $languageCodes = array('dutch' => 'nl', 'english' => 'en', 'french' => 'fr', 'german' => 'de', 'spanish' => 'es', 'italian' => 'it', 'portuguese-pt' => 'pt');
    $language = strtolower((string) $language);
    $languageCode = isset($languageCodes[$language]) ? $languageCodes[$language] : 'en';
    $clientCountry = strtoupper((string) $countryCode);

    foreach (array($languageCode . '_' . $clientCountry, $languageCode . '_BE') as $candidate) {
        if (in_array($candidate, $mollieLocales, true)) {
            return $candidate;
        }
    }

    return null;
}
