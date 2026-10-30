<?php

function respond($status, $heading, $message)
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
        <a class="btn btn--primary" href="index.html#visit">Return to the boutique</a>
      </p>
    </section>
  </main>
</body>
</html>
    <?php
    exit;
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, 'Use the signup form', 'Submit your email using the newsletter form.');
}

$email = isset($_POST['email']) && is_string($_POST['email'])
    ? trim($_POST['email'])
    : '';

if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    respond(422, 'Check your email address', 'Enter a valid email address and try again.');
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
    error_log('Newsletter subscription is missing one or more database environment variables.');
    respond(500, 'Signup is temporarily unavailable', 'Please try again later or contact the boutique.');
}

if ($dbPort === false || $dbPort === '') {
    $dbPort = '3306';
}

try {
    $pdo = new PDO(
        'mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4',
        $dbUser,
        $dbPassword,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS newsletter_subscribers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(254) NOT NULL,
            subscribed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_newsletter_subscribers_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $statement = $pdo->prepare(
        'INSERT IGNORE INTO newsletter_subscribers (email) VALUES (:email)'
    );
    $statement->execute(array('email' => $email));
} catch (PDOException $error) {
    error_log('Newsletter subscription failed: ' . $error->getMessage());
    respond(500, 'Signup is temporarily unavailable', 'Please try again later or contact the boutique.');
}

respond(200, 'Thanks for joining', 'You are on the list for boutique updates.');
