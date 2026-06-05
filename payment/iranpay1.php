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

function tetrapay_json_response($statusCode, array $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tetrapay_load_payment_texts()
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

$rawBody = file_get_contents('php://input');
$payload = json_decode((string) $rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

if (!is_array($payload) || empty($payload)) {
    tetrapay_json_response(400, [
        'success' => false,
        'error' => 'Invalid TetraPay callback payload.',
    ]);
}

$status = (string) ($payload['status'] ?? '');
if ($status !== '100') {
    tetrapay_json_response(200, [
        'success' => true,
        'ignored' => true,
    ]);
}

$hashId = trim((string) ($payload['hashid'] ?? $payload['Hash_id'] ?? $payload['hash_id'] ?? ''));
$authority = trim((string) ($payload['authority'] ?? $payload['Authority'] ?? ''));

if ($hashId === '' || $authority === '') {
    tetrapay_json_response(400, [
        'success' => false,
        'error' => 'Missing hashid or authority.',
    ]);
}

$paymentReport = select('Payment_report', '*', 'id_order', $hashId, 'select');
if (!is_array($paymentReport)) {
    tetrapay_json_response(404, [
        'success' => false,
        'error' => 'Payment report not found.',
    ]);
}

if (($paymentReport['Payment_Method'] ?? '') !== 'Currency Rial 1') {
    tetrapay_json_response(400, [
        'success' => false,
        'error' => 'Payment method mismatch.',
    ]);
}

if (($paymentReport['payment_Status'] ?? '') === 'paid') {
    tetrapay_json_response(200, [
        'success' => true,
        'already_paid' => true,
    ]);
}

$storedMetadata = json_decode((string) ($paymentReport['dec_not_confirmed'] ?? ''), true);
if (!is_array($storedMetadata)) {
    $storedMetadata = [
        'authority' => (string) ($paymentReport['dec_not_confirmed'] ?? ''),
    ];
}

$storedAuthority = trim((string) ($storedMetadata['authority'] ?? ''));
if ($storedAuthority !== '' && !hash_equals($storedAuthority, $authority)) {
    tetrapay_json_response(409, [
        'success' => false,
        'error' => 'Authority mismatch.',
    ]);
}

$verifyResponse = verifyTetraPay($authority, $hashId);
if ((string) ($verifyResponse['status'] ?? '') !== '100') {
    $storedMetadata['last_callback_verify'] = [
        'at' => date('Y/m/d H:i:s'),
        'error' => 'verify failed',
        'callback' => $payload,
        'verify_response' => $verifyResponse,
    ];
    update('Payment_report', 'dec_not_confirmed', json_encode($storedMetadata + [
        'gateway' => 'tetrapay',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id_order', $paymentReport['id_order']);

    tetrapay_json_response(409, [
        'success' => false,
        'error' => 'TetraPay verify failed.',
        'verify_response' => $verifyResponse,
    ]);
}

$verifiedAuthority = trim((string) ($verifyResponse['authority'] ?? $verifyResponse['Authority'] ?? ''));
if ($verifiedAuthority !== '' && !hash_equals($verifiedAuthority, $authority)) {
    tetrapay_json_response(409, [
        'success' => false,
        'error' => 'Verified authority mismatch.',
    ]);
}

$verifiedHashId = trim((string) ($verifyResponse['hash_id'] ?? $verifyResponse['Hash_id'] ?? $verifyResponse['hashid'] ?? ''));
if ($verifiedHashId !== '' && !hash_equals($verifiedHashId, $hashId)) {
    tetrapay_json_response(409, [
        'success' => false,
        'error' => 'Verified hash id mismatch.',
    ]);
}

$datatextbot = tetrapay_load_payment_texts();
$setting = select('setting', '*');
$paymentreports = select('topicid', 'idreport', 'report', 'paymentreport', 'select')['idreport'] ?? null;

$storedMetadata['gateway'] = 'tetrapay';
$storedMetadata['authority'] = $authority;
$storedMetadata['callback'] = $payload;
$storedMetadata['verify_response'] = $verifyResponse;
$storedMetadata['verified_at'] = date('Y/m/d H:i:s');
update('Payment_report', 'dec_not_confirmed', json_encode($storedMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id_order', $paymentReport['id_order']);

DirectPayment($paymentReport['id_order'], '../images.jpg');

$pricecashback = select('PaySetting', 'ValuePay', 'NamePay', 'chashbackiranpay1', 'select')['ValuePay'] ?? '0';
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
    $reportText = "TetraPay payment completed\n"
        . "User ID: {$balanceUser['id']}\n"
        . "Username: @{$balanceUser['username']}\n"
        . "Amount: {$priceFormatted} Toman\n"
        . "Authority: {$authority}\n"
        . "Method: Currency Rial 1";

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

tetrapay_json_response(200, [
    'success' => true,
]);
