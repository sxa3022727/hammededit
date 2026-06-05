<?php

ini_set('error_log', 'error_log');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();
$textbotlang = languagechange(__DIR__ . '/../text.json');

header('Content-Type: application/json; charset=utf-8');

function starbot_json_response($statusCode, array $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function starbot_load_payment_texts()
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
$signature = $_SERVER['HTTP_X_STARBOT_SIGNATURE'] ?? '';
$webhookSecret = trim((string) getPaySettingValue('starbot_webhook_secret', ''));

if ($webhookSecret === '' || $webhookSecret === '0') {
    error_log('StarBot webhook secret is not configured.');
    starbot_json_response(500, [
        'success' => false,
        'error' => 'Webhook secret is not configured.',
    ]);
}

$expectedSignature = hash_hmac('sha256', (string) $rawBody, $webhookSecret);
if ($signature === '' || !hash_equals($expectedSignature, $signature)) {
    error_log('Invalid StarBot webhook signature.');
    starbot_json_response(403, [
        'success' => false,
        'error' => 'Invalid signature.',
    ]);
}

$payload = json_decode((string) $rawBody, true);
if (!is_array($payload)) {
    starbot_json_response(400, [
        'success' => false,
        'error' => 'Invalid JSON payload.',
    ]);
}

$status = (string) ($payload['status'] ?? '');
$event = (string) ($payload['event'] ?? '');
if ($event !== 'invoice.completed' || $status !== 'completed') {
    starbot_json_response(200, [
        'success' => true,
        'ignored' => true,
    ]);
}

$orderId = trim((string) ($payload['metadata'] ?? ''));
if ($orderId === '') {
    starbot_json_response(400, [
        'success' => false,
        'error' => 'Missing metadata order id.',
    ]);
}

$paymentReport = select('Payment_report', '*', 'id_order', $orderId, 'select');
if (!is_array($paymentReport)) {
    starbot_json_response(404, [
        'success' => false,
        'error' => 'Payment report not found.',
    ]);
}

if (($paymentReport['Payment_Method'] ?? '') !== 'Star Telegram') {
    starbot_json_response(400, [
        'success' => false,
        'error' => 'Payment method mismatch.',
    ]);
}

if (($paymentReport['payment_Status'] ?? '') === 'paid') {
    starbot_json_response(200, [
        'success' => true,
        'already_paid' => true,
    ]);
}

$storedMetadata = json_decode((string) ($paymentReport['dec_not_confirmed'] ?? ''), true);
if (!is_array($storedMetadata)) {
    $storedMetadata = [];
}

$storedToken = (string) ($storedMetadata['invoice_token'] ?? '');
$payloadToken = (string) ($payload['invoice_token'] ?? '');
if ($storedToken !== '' && $payloadToken !== '' && !hash_equals($storedToken, $payloadToken)) {
    starbot_json_response(409, [
        'success' => false,
        'error' => 'Invoice token mismatch.',
    ]);
}

$storedStars = isset($storedMetadata['stars_count']) ? (int) $storedMetadata['stars_count'] : null;
$payloadStars = isset($payload['stars_count']) ? (int) $payload['stars_count'] : null;
if ($storedStars !== null && $payloadStars !== null && $storedStars !== $payloadStars) {
    starbot_json_response(409, [
        'success' => false,
        'error' => 'Stars count mismatch.',
    ]);
}

$paidToman = $payload['total_toman'] ?? null;
if ($paidToman !== null && is_numeric($paidToman) && (int) $paidToman < (int) $paymentReport['price']) {
    update('Payment_report', 'dec_not_confirmed', json_encode([
        'gateway' => 'starbot',
        'error' => 'paid amount is lower than invoice price',
        'webhook' => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id_order', $paymentReport['id_order']);

    starbot_json_response(409, [
        'success' => false,
        'error' => 'Paid amount is lower than invoice price.',
    ]);
}

$datatextbot = starbot_load_payment_texts();
$setting = select('setting', '*');
$paymentreports = select('topicid', 'idreport', 'report', 'paymentreport', 'select')['idreport'] ?? null;

update('Payment_report', 'dec_not_confirmed', json_encode([
    'gateway' => 'starbot',
    'webhook' => $payload,
    'verified_at' => date('Y/m/d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id_order', $paymentReport['id_order']);

DirectPayment($paymentReport['id_order'], '../images.jpg');

$pricecashback = select('PaySetting', 'ValuePay', 'NamePay', 'chashbackstar', 'select')['ValuePay'] ?? '0';
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
    $stars = $payload['stars_count'] ?? ($storedMetadata['stars_count'] ?? '');
    $orderCode = $payload['order_code'] ?? ($storedMetadata['order_code'] ?? '');
    $reportText = "StarBot payment completed\n"
        . "User ID: {$balanceUser['id']}\n"
        . "Username: @{$balanceUser['username']}\n"
        . "Amount: {$priceFormatted} Toman\n"
        . "Stars: {$stars}\n"
        . "Order: {$orderCode}\n"
        . "Method: Star Telegram";

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

starbot_json_response(200, [
    'success' => true,
]);
