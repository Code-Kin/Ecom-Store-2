<?php

function webhook_respond($status, $message)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array('received' => $status >= 200 && $status < 300, 'message' => $message));
    exit;
}

function stripe_api_get($url, $secretKey)
{
    $curl = curl_init($url);
    curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $secretKey)
    ));
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($body === false || $status < 200 || $status >= 300) {
        error_log('Stripe webhook API request failed: ' .
            ($body === false ? $error : 'HTTP ' . $status));
        webhook_respond(502, 'Stripe data could not be retrieved.');
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        error_log('Stripe webhook API returned invalid JSON.');
        webhook_respond(502, 'Stripe data could not be retrieved.');
    }

    return $data;
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    webhook_respond(405, 'POST only.');
}

$webhookSecret = getenv('STRIPE_WEBHOOK_SECRET');
$stripeSecretKey = getenv('STRIPE_SECRET_KEY');
if ($webhookSecret === false || $webhookSecret === '' ||
    $stripeSecretKey === false || $stripeSecretKey === '') {
    error_log('Stripe webhook is missing STRIPE_WEBHOOK_SECRET or STRIPE_SECRET_KEY.');
    webhook_respond(500, 'Webhook is not configured.');
}

$payload = file_get_contents('php://input');
$signatureHeader = isset($_SERVER['HTTP_STRIPE_SIGNATURE'])
    ? $_SERVER['HTTP_STRIPE_SIGNATURE']
    : '';
$timestamp = null;
$signatures = array();

foreach (explode(',', $signatureHeader) as $part) {
    $pair = explode('=', trim($part), 2);
    if (count($pair) !== 2) {
        continue;
    }
    if ($pair[0] === 't' && ctype_digit($pair[1])) {
        $timestamp = (int) $pair[1];
    } elseif ($pair[0] === 'v1') {
        $signatures[] = $pair[1];
    }
}

if ($timestamp === null || abs(time() - $timestamp) > 300 || count($signatures) === 0) {
    webhook_respond(400, 'Invalid or expired Stripe signature.');
}

$expectedSignature = hash_hmac('sha256', $timestamp . '.' . $payload, $webhookSecret);
$validSignature = false;
foreach ($signatures as $signature) {
    if (hash_equals($expectedSignature, $signature)) {
        $validSignature = true;
        break;
    }
}
if (!$validSignature) {
    webhook_respond(400, 'Invalid Stripe signature.');
}

$event = json_decode($payload, true);
if (!is_array($event) ||
    !isset($event['id'], $event['type'], $event['data']['object']) ||
    !is_string($event['id']) ||
    !is_string($event['type']) ||
    !is_array($event['data']['object'])) {
    webhook_respond(400, 'Invalid Stripe event.');
}

$handledEvents = array(
    'checkout.session.completed',
    'checkout.session.async_payment_succeeded',
    'checkout.session.async_payment_failed'
);
if (!in_array($event['type'], $handledEvents, true)) {
    webhook_respond(200, 'Event ignored.');
}

$session = $event['data']['object'];
if (!isset($session['id'], $session['mode'], $session['currency'], $session['metadata']['store']) ||
    !is_string($session['id']) ||
    !preg_match('/^cs_(test|live)_[A-Za-z0-9_]+$/', $session['id']) ||
    $session['mode'] !== 'payment' ||
    $session['currency'] !== 'zar' ||
    $session['metadata']['store'] !== "Sarah's Boutique") {
    webhook_respond(200, "Event is not a Sarah's Boutique payment.");
}

if (!isset($session['amount_total']) || !is_int($session['amount_total']) || $session['amount_total'] < 0) {
    webhook_respond(400, 'Checkout session has an invalid total.');
}

if ($event['type'] === 'checkout.session.async_payment_succeeded' &&
    (!isset($session['payment_status']) || $session['payment_status'] !== 'paid')) {
    webhook_respond(400, 'Successful payment event is not marked paid.');
}

if ($event['type'] === 'checkout.session.completed' &&
    (!isset($session['payment_status']) ||
        !in_array($session['payment_status'], array('paid', 'unpaid'), true))) {
    webhook_respond(400, 'Checkout session has an invalid payment status.');
}

$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPassword = getenv('DB_PASSWORD');
$dbPort = getenv('DB_PORT');
if ($dbHost === false || $dbHost === '' ||
    $dbName === false || $dbName === '' ||
    $dbUser === false || $dbUser === '' ||
    $dbPassword === false) {
    error_log('Stripe webhook is missing one or more database environment variables.');
    webhook_respond(500, 'Order storage is not configured.');
}
if ($dbPort === false || $dbPort === '') {
    $dbPort = '3306';
}

$lineItems = stripe_api_get(
    'https://api.stripe.com/v1/checkout/sessions/' .
        rawurlencode($session['id']) . '/line_items?limit=100',
    $stripeSecretKey
);
if (!isset($lineItems['data']) || !is_array($lineItems['data'])) {
    error_log('Stripe returned checkout line items in an unexpected format.');
    webhook_respond(502, 'Order items could not be retrieved.');
}

$itemsJson = json_encode($lineItems['data'], JSON_UNESCAPED_UNICODE);
$customerDetails = isset($session['customer_details']) && is_array($session['customer_details'])
    ? $session['customer_details']
    : array();
$shippingDetails = isset($session['shipping_details']) && is_array($session['shipping_details'])
    ? $session['shipping_details']
    : array();
$customerJson = json_encode($customerDetails, JSON_UNESCAPED_UNICODE);
$shippingJson = json_encode($shippingDetails, JSON_UNESCAPED_UNICODE);
if ($itemsJson === false || $customerJson === false || $shippingJson === false) {
    error_log('Order details could not be encoded for MySQL storage.');
    webhook_respond(500, 'Order details could not be stored.');
}

$status = 'pending';
if ($event['type'] === 'checkout.session.async_payment_failed') {
    $status = 'failed';
} elseif (
    $event['type'] === 'checkout.session.async_payment_succeeded' ||
    $session['payment_status'] === 'paid'
) {
    $status = 'paid';
}

try {
    $pdo = new PDO(
        'mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4',
        $dbUser,
        $dbPassword,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            stripe_session_id VARCHAR(191) NOT NULL,
            stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            currency CHAR(3) NOT NULL,
            amount_total BIGINT UNSIGNED NOT NULL,
            customer_email VARCHAR(254) DEFAULT NULL,
            customer_details_json LONGTEXT NOT NULL,
            shipping_details_json LONGTEXT NOT NULL,
            line_items_json LONGTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            paid_at TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_orders_stripe_session_id (stripe_session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS stripe_webhook_events (
            event_id VARCHAR(191) NOT NULL,
            event_type VARCHAR(100) NOT NULL,
            processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->beginTransaction();
    $eventStatement = $pdo->prepare(
        'INSERT IGNORE INTO stripe_webhook_events (event_id, event_type) VALUES (:event_id, :event_type)'
    );
    $eventStatement->execute(array(
        'event_id' => $event['id'],
        'event_type' => $event['type']
    ));

    if ($eventStatement->rowCount() === 0) {
        $pdo->commit();
        webhook_respond(200, 'Event already processed.');
    }

    $paymentIntent = isset($session['payment_intent']) && is_string($session['payment_intent'])
        ? $session['payment_intent']
        : null;
    $customerEmail = isset($customerDetails['email']) && is_string($customerDetails['email'])
        ? $customerDetails['email']
        : null;
    $orderStatement = $pdo->prepare(
        'INSERT INTO orders (
            stripe_session_id, stripe_payment_intent_id, status, currency, amount_total,
            customer_email, customer_details_json, shipping_details_json, line_items_json, paid_at
        ) VALUES (
            :session_id, :payment_intent_id, :status, :currency, :amount_total,
            :customer_email, :customer_details, :shipping_details, :line_items,
            IF(:paid_status = \'paid\', CURRENT_TIMESTAMP, NULL)
        )
        ON DUPLICATE KEY UPDATE
            stripe_payment_intent_id = VALUES(stripe_payment_intent_id),
            status = IF(
                status = \'paid\' OR (status = \'failed\' AND VALUES(status) = \'pending\'),
                status,
                VALUES(status)
            ),
            currency = VALUES(currency),
            amount_total = VALUES(amount_total),
            customer_email = VALUES(customer_email),
            customer_details_json = VALUES(customer_details_json),
            shipping_details_json = VALUES(shipping_details_json),
            line_items_json = VALUES(line_items_json),
            paid_at = IF(
                status = \'paid\' OR VALUES(status) = \'paid\',
                COALESCE(paid_at, CURRENT_TIMESTAMP),
                paid_at
            )'
    );
    $orderStatement->execute(array(
        'session_id' => $session['id'],
        'payment_intent_id' => $paymentIntent,
        'status' => $status,
        'currency' => $session['currency'],
        'amount_total' => $session['amount_total'],
        'customer_email' => $customerEmail,
        'customer_details' => $customerJson,
        'shipping_details' => $shippingJson,
        'line_items' => $itemsJson,
        'paid_status' => $status
    ));
    $pdo->commit();
} catch (PDOException $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Stripe webhook order storage failed: ' . $error->getMessage());
    webhook_respond(500, 'Order could not be stored.');
}

webhook_respond(200, 'Order stored.');
