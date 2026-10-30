<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'products.php';

function checkout_error($status, $message)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array('error' => $message));
    exit;
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    checkout_error(405, 'Use the cart checkout button to start payment.');
}

$secretKey = getenv('STRIPE_SECRET_KEY');
$publishableKey = getenv('STRIPE_PUBLISHABLE_KEY');
$siteUrl = getenv('SITE_URL');
if ($secretKey === false || $secretKey === '' ||
    $publishableKey === false || $publishableKey === '' ||
    $siteUrl === false || $siteUrl === '') {
    error_log('Stripe checkout is missing STRIPE_SECRET_KEY, STRIPE_PUBLISHABLE_KEY, or SITE_URL.');
    checkout_error(500, 'Checkout is temporarily unavailable. Please try again later.');
}

$siteUrl = rtrim($siteUrl, '/');
$siteParts = parse_url($siteUrl);
if ($siteParts === false ||
    filter_var($siteUrl, FILTER_VALIDATE_URL) === false ||
    !isset($siteParts['scheme'], $siteParts['host']) ||
    ($siteParts['scheme'] !== 'https' &&
        !($siteParts['scheme'] === 'http' &&
            in_array($siteParts['host'], array('localhost', '127.0.0.1'), true)))) {
    error_log('SITE_URL must be an HTTPS URL (HTTP is allowed for localhost only).');
    checkout_error(500, 'Checkout is temporarily unavailable. Please try again later.');
}

$requestBody = file_get_contents('php://input');
$request = json_decode($requestBody, true);
if (!is_array($request) || !isset($request['items']) || !is_array($request['items'])) {
    checkout_error(400, 'Your cart could not be read. Please refresh and try again.');
}

$catalog = array();
try {
    $catalog = store_load_catalog();
} catch (Throwable $error) {
    error_log('Stripe checkout could not load the product catalog: ' . $error->getMessage());
    checkout_error(500, 'Checkout is temporarily unavailable. Please try again later.');
}
$items = array();
$quantityTotal = 0;

if (count($request['items']) < 1 || count($request['items']) > count($catalog)) {
    checkout_error(422, 'Your cart is empty or invalid.');
}

foreach ($request['items'] as $item) {
    if (!is_array($item) ||
        !isset($item['id'], $item['qty']) ||
        !is_string($item['id']) ||
        !is_int($item['qty']) ||
        $item['qty'] < 1 ||
        $item['qty'] > 50 ||
        !isset($catalog[$item['id']])) {
        checkout_error(422, 'Your cart contains an invalid item or quantity.');
    }

    $items[$item['id']] = isset($items[$item['id']])
        ? $items[$item['id']] + $item['qty']
        : $item['qty'];
    $quantityTotal += $item['qty'];
    if ($items[$item['id']] > 50 || $quantityTotal > 50) {
        checkout_error(422, 'The quantity in your cart exceeds the checkout limit.');
    }
}

$form = array(
    'mode' => 'payment',
    'ui_mode' => 'form',
    'return_url' => $siteUrl . '/checkout-result.php?session_id={CHECKOUT_SESSION_ID}',
    'billing_address_collection' => 'required',
    'phone_number_collection[enabled]' => 'false',
    'automatic_tax[enabled]' => 'false',
    'submit_type' => 'auto',
    'shipping_address_collection[allowed_countries][0]' => 'ZA',
    'integration_identifier' => 'custom_embedded_web_0001'
);

$lineIndex = 0;
foreach ($items as $id => $quantity) {
    $form['line_items[' . $lineIndex . '][price_data][currency]'] = 'zar';
    $form['line_items[' . $lineIndex . '][price_data][unit_amount]'] = $catalog[$id]['price'] * 100;
    $form['line_items[' . $lineIndex . '][price_data][product_data][name]'] = $catalog[$id]['name'];
    $form['line_items[' . $lineIndex . '][quantity]'] = $quantity;
    $lineIndex++;
}

$curl = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($curl, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($form),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => array(
        'Authorization: Bearer ' . $secretKey,
        'Stripe-Version: 2026-03-25.dahlia',
        'Content-Type: application/x-www-form-urlencoded'
    )
));
$responseBody = curl_exec($curl);
$curlError = curl_error($curl);
$httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($responseBody === false || $httpStatus < 200 || $httpStatus >= 300) {
    $failure = $responseBody === false ? $curlError : 'HTTP ' . $httpStatus;
    if ($responseBody !== false) {
        $errorResponse = json_decode($responseBody, true);
        if (isset($errorResponse['error']) && is_array($errorResponse['error'])) {
            $stripeError = $errorResponse['error'];
            $details = array();
            foreach (array('type', 'code', 'param', 'message') as $field) {
                if (isset($stripeError[$field]) && is_string($stripeError[$field])) {
                    $details[] = $field . ': ' . substr($stripeError[$field], 0, 300);
                }
            }
            if (count($details) > 0) {
                $failure .= ' (' . implode('; ', $details) . ')';
            }
        }
    }
    error_log('Stripe Checkout session request failed: ' . $failure);
    checkout_error(502, 'We could not start Stripe checkout. Please try again.');
}

$session = json_decode($responseBody, true);
if (!is_array($session) ||
    !isset($session['client_secret']) ||
    !is_string($session['client_secret'])) {
    error_log('Stripe Checkout returned a response without a client secret.');
    checkout_error(502, 'We could not start Stripe checkout. Please try again.');
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(array(
    'client_secret' => $session['client_secret'],
    'publishable_key' => $publishableKey
));
