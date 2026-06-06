<?php

ini_set('error_log', 'error_log');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../panels.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();
$textbotlang = languagechange(__DIR__ . '/../text.json');

header('Content-Type: application/json; charset=utf-8');

function tetraminator_json_response($statusCode, array $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tetraminator_load_payment_texts()
{
    $records = select('textbot', '*', null, null, 'fetchAll');
    $texts = [
        'textafterpay' => '',
        'textaftertext' => '',
        'textmanual' => '',
        'textselectlocation' => '',
        'text_wgdashboard' => '',
        'textafterpayibsng' => '',
    ];

    if (is_array($records)) {
        foreach ($records as $row) {
            $key = $row['id_text'] ?? null;
            if ($key !== null && array_key_exists($key, $texts)) {
                $texts[$key] = $row['text'];
            }
        }
    }

    return $texts;
}

$invoiceId = trim((string) ($_GET['invoice_id'] ?? $_GET['id_order'] ?? ''));
if ($invoiceId === '') {
    tetraminator_json_response(400, [
        'success' => false,
        'error' => 'Missing invoice_id.',
    ]);
}

$paymentReport = select('Payment_report', '*', 'id_order', $invoiceId, 'select');
if (!is_array($paymentReport)) {
    tetraminator_json_response(404, [
        'success' => false,
        'error' => 'Payment report not found.',
    ]);
}

if (($paymentReport['Payment_Method'] ?? '') !== 'Tetraminator') {
    tetraminator_json_response(400, [
        'success' => false,
        'error' => 'Payment method mismatch.',
    ]);
}

if (($paymentReport['payment_Status'] ?? '') === 'paid') {
    tetraminator_json_response(200, [
        'success' => true,
        'already_paid' => true,
    ]);
}

if (($paymentReport['payment_Status'] ?? '') === 'expire') {
    tetraminator_json_response(409, [
        'success' => false,
        'error' => 'Payment is expired.',
    ]);
}

$storedMetadata = json_decode((string) ($paymentReport['dec_not_confirmed'] ?? ''), true);
if (!is_array($storedMetadata)) {
    $storedMetadata = [];
}

$callbackToken = trim((string) ($_GET['token'] ?? ''));
$storedToken = trim((string) ($storedMetadata['callback_token'] ?? ''));
if ($storedToken !== '') {
    if ($callbackToken === '' || !hash_equals($storedToken, $callbackToken)) {
        tetraminator_json_response(403, [
            'success' => false,
            'error' => 'Invalid callback token.',
        ]);
    }
} else {
    $expectedToken = createTetraminatorCallbackToken($invoiceId);
    if ($expectedToken === '' || $callbackToken === '' || !hash_equals($expectedToken, $callbackToken)) {
        tetraminator_json_response(403, [
            'success' => false,
            'error' => 'Invalid callback token.',
        ]);
    }
}

$datatextbot = tetraminator_load_payment_texts();
$setting = select('setting', '*');
$paymentreports = select('topicid', 'idreport', 'report', 'paymentreport', 'select')['idreport'] ?? null;

$storedMetadata['gateway'] = 'tetraminator';
$storedMetadata['callback_query'] = $_GET;
$storedMetadata['callback_at'] = date('Y/m/d H:i:s');
update('Payment_report', 'dec_not_confirmed', json_encode($storedMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id_order', $paymentReport['id_order']);

DirectPayment($paymentReport['id_order'], '../images.jpg');

$pricecashback = select('PaySetting', 'ValuePay', 'NamePay', 'chashbacktetraminator', 'select')['ValuePay'] ?? '0';
$balanceUser = select('user', '*', 'id', $paymentReport['id_user'], 'select');
if (is_array($balanceUser) && $pricecashback !== '0') {
    $cashbackAmount = ($paymentReport['price'] * $pricecashback) / 100;
    $newBalance = intval($balanceUser['Balance']) + $cashbackAmount;
    update('user', 'Balance', $newBalance, 'id', $balanceUser['id']);
    $cashbackText = sprintf($textbotlang['users']['Discount']['gift-deposit'] ?? 'Cashback: %s', $cashbackAmount);
    sendmessage($balanceUser['id'], $cashbackText, null, 'HTML');
}

if (is_array($setting) && !empty($setting['Channel_Report']) && is_array($balanceUser)) {
    $priceFormatted = number_format((int) $paymentReport['price']);
    $payId = $storedMetadata['pay_id'] ?? '';
    $reportText = "Tetraminator payment completed\n"
        . "User ID: {$balanceUser['id']}\n"
        . "Username: @{$balanceUser['username']}\n"
        . "Amount: {$priceFormatted} Toman\n"
        . "Pay ID: {$payId}\n"
        . "Method: Tetraminator";

    $telegramPayload = [
        'chat_id' => $setting['Channel_Report'],
        'text' => $reportText,
        'parse_mode' => 'HTML',
    ];
    if (!empty($paymentreports)) {
        $telegramPayload['message_thread_id'] = $paymentreports;
    }

    telegram('sendmessage', $telegramPayload);
}

update('Payment_report', 'payment_Status', 'paid', 'id_order', $paymentReport['id_order']);

tetraminator_json_response(200, [
    'success' => true,
]);
