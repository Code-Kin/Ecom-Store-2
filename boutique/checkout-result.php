<?php

function show_checkout_result($status, $heading, $message)
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!doctype html>
<html lang="en-ZA">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> — Sarah's Boutique</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <main>
    <section class="sec">
      <div class="sec__head">
        <h2><?= htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <p><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>
      <p style="padding: 2rem clamp(24px, 4vw, 56px);">
        <a class="btn btn--primary" href="index.html">Return to the boutique</a>
      </p>
    </section>
  </main>
</body>
</html>
    <?php
    exit;
}

$sessionId = isset($_GET['session_id']) && is_string($_GET['session_id'])
    ? $_GET['session_id']
    : '';
if (!preg_match('/^cs_(test|live)_[A-Za-z0-9_]+$/', $sessionId)) {
    show_checkout_result(400, 'Payment status unavailable', 'We could not verify this checkout session.');
}

$secretKey = getenv('STRIPE_SECRET_KEY');
if ($secretKey === false || $secretKey === '') {
    error_log('Stripe payment verification is missing STRIPE_SECRET_KEY.');
    show_checkout_result(500, 'Payment status unavailable', 'Please contact the boutique to confirm your order.');
}

$curl = curl_init('https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sessionId));
curl_setopt_array($curl, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $secretKey)
));
$responseBody = curl_exec($curl);
$curlError = curl_error($curl);
$httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($responseBody === false || $httpStatus < 200 || $httpStatus >= 300) {
    error_log('Stripe Checkout session verification failed: ' .
        ($responseBody === false ? $curlError : 'HTTP ' . $httpStatus));
    show_checkout_result(502, 'Payment status unavailable', 'Please contact the boutique to confirm your order.');
}

$session = json_decode($responseBody, true);
if (!is_array($session) ||
    !isset($session['payment_status'], $session['mode'], $session['currency']) ||
    $session['payment_status'] !== 'paid' ||
    $session['mode'] !== 'payment' ||
    $session['currency'] !== 'zar') {
    show_checkout_result(402, 'Payment not confirmed', 'No completed payment was found for this checkout session.');
}

show_checkout_result(200, 'Payment complete', 'Thank you for your order. Your payment has been confirmed securely by Stripe.');
