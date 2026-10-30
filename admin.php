<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'products.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'app-config.php';

ini_set('session.use_strict_mode', '1');
session_set_cookie_params(array(
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax'
));
session_start();

function admin_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_redirect()
{
    header('Location: admin.php', true, 303);
    exit;
}

function admin_csrf_valid()
{
    return isset($_POST['csrf'], $_SESSION['admin_csrf']) &&
        is_string($_POST['csrf']) &&
        hash_equals($_SESSION['admin_csrf'], $_POST['csrf']);
}

function admin_uploaded_image($file)
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK ||
        !isset($file['tmp_name'], $file['size']) ||
        !is_uploaded_file($file['tmp_name']) ||
        $file['size'] < 1 ||
        $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Each uploaded image must be a valid file under 5 MB.');
    }

    $imageInfo = getimagesize($file['tmp_name']);
    if ($imageInfo === false || !isset($imageInfo['mime'])) {
        throw new RuntimeException('Only JPEG, PNG, and WebP image uploads are allowed.');
    }
    $extensions = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    );
    if (!isset($extensions[$imageInfo['mime']])) {
        throw new RuntimeException('Only JPEG, PNG, and WebP image uploads are allowed.');
    }

    $directory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The image upload folder could not be created.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$imageInfo['mime']];
    if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('The image could not be saved. Check folder permissions.');
    }

    return 'uploads/' . $filename;
}

if (!isset($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$adminPassword = store_config('ADMIN_PASSWORD');
$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_valid()) {
        http_response_code(400);
        $errorMessage = 'This form expired. Refresh the page and try again.';
    } elseif (isset($_POST['logout'])) {
        $_SESSION = array();
        session_destroy();
        admin_redirect();
    } elseif (!isset($_SESSION['admin_authenticated'])) {
        if ($adminPassword === false || $adminPassword === '') {
            http_response_code(503);
            $errorMessage = 'Admin login is not configured. Add ADMIN_PASSWORD to private-config.php.';
        } elseif (isset($_POST['password']) &&
            is_string($_POST['password']) &&
            hash_equals($adminPassword, $_POST['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
            admin_redirect();
        } else {
            $errorMessage = 'The password was not correct.';
        }
    } elseif (isset($_POST['save_products'])) {
        try {
            $catalog = store_load_catalog();
            if (!isset($_POST['prices']) || !is_array($_POST['prices']) ||
                !isset($_POST['image_urls']) || !is_array($_POST['image_urls']) ||
                !isset($_POST['image_alts']) || !is_array($_POST['image_alts'])) {
                throw new RuntimeException('Product form data is incomplete.');
            }

            $prices = store_read_json('product-prices.json');
            $images = store_read_json('product-images.json');
            foreach ($catalog as $id => $product) {
                $priceValue = isset($_POST['prices'][$id]) && is_string($_POST['prices'][$id])
                    ? $_POST['prices'][$id]
                    : '';
                $price = filter_var($priceValue, FILTER_VALIDATE_INT);
                if ($price === false || $price < 1 || $price > 10000000) {
                    throw new RuntimeException('Enter a whole-number price between R 1 and R 10 000 000.');
                }

                $prices[$id]['price'] = $price;
                $uploadedSrc = isset($_FILES['images']['error'][$id])
                    ? admin_uploaded_image(array(
                        'error' => $_FILES['images']['error'][$id],
                        'tmp_name' => $_FILES['images']['tmp_name'][$id],
                        'size' => $_FILES['images']['size'][$id]
                    ))
                    : null;
                $imageSrc = $uploadedSrc !== null
                    ? $uploadedSrc
                    : (isset($_POST['image_urls'][$id]) && is_string($_POST['image_urls'][$id])
                        ? trim($_POST['image_urls'][$id])
                        : '');
                if ($imageSrc !== '' && !store_valid_image_src($imageSrc)) {
                    throw new RuntimeException('Use an HTTPS image URL or upload an image file.');
                }
                if ($imageSrc !== '') {
                    $images[$id]['src'] = $imageSrc;
                }
                $imageAlt = isset($_POST['image_alts'][$id]) && is_string($_POST['image_alts'][$id])
                    ? trim($_POST['image_alts'][$id])
                    : '';
                if ($imageAlt === '' || strlen($imageAlt) > 250) {
                    throw new RuntimeException('Each image needs alt text between 1 and 250 characters.');
                }
                $images[$id]['alt'] = $imageAlt;
            }

            store_write_json('product-images.json', $images);
            store_write_json('product-prices.json', $prices);
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
            $successMessage = 'Product changes saved. The store and checkout now use the updated data.';
        } catch (Throwable $error) {
            error_log('Admin product update failed: ' . $error->getMessage());
            $errorMessage = $error instanceof RuntimeException
                ? $error->getMessage()
                : 'The changes could not be saved. Check the server logs and folder permissions.';
        }
    }
}

$isAuthenticated = isset($_SESSION['admin_authenticated']);
$catalog = array();
if ($isAuthenticated) {
    try {
        $catalog = store_load_catalog();
    } catch (Throwable $error) {
        error_log('Admin catalog load failed: ' . $error->getMessage());
        $errorMessage = 'The product data could not be loaded. Check the server logs.';
    }
}
?>
<!doctype html>
<html lang="en-ZA">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Store admin — Sarah's Boutique</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .admin-page{max-width:1100px;margin:0 auto;padding:clamp(24px,5vw,64px);}
    .admin-head{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:2rem;}
    .admin-head h1{font-family:var(--serif);font-size:clamp(2rem,5vw,3.5rem);font-weight:300;}
    .admin-panel{background:var(--white);border:1px solid var(--rule);padding:clamp(20px,4vw,36px);}
    .admin-login{max-width:480px;margin:10vh auto;}
    .admin-login label,.admin-product label{display:block;font-size:.68rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:.4rem;}
    .admin-login input,.admin-product input[type=number],.admin-product input[type=url],.admin-product input[type=file]{width:100%;padding:.75rem;border:1px solid var(--rule);background:var(--paper);font:inherit;margin-bottom:1rem;}
    .admin-message{padding:.8rem 1rem;margin-bottom:1rem;background:#e8f0df;color:var(--olive);}
    .admin-error{background:#f6e4e3;color:#7a1f2c;}
    .admin-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;margin:1.5rem 0;}
    .admin-product{border:1px solid var(--rule);padding:1rem;min-width:0;}
    .admin-product h2{font-family:var(--serif);font-size:1.2rem;margin:.75rem 0;}
    .admin-product img{width:100%;height:220px;object-fit:cover;background:var(--paper);margin-bottom:.75rem;}
    .admin-hint{font-size:.76rem;color:var(--stone-2);margin:.3rem 0 1rem;}
    .admin-actions{display:flex;align-items:center;gap:1rem;flex-wrap:wrap;}
    @media(max-width:600px){.admin-head{align-items:flex-start;flex-direction:column;}}
  </style>
</head>
<body>
  <main class="admin-page">
    <?php if (!$isAuthenticated): ?>
      <section class="admin-panel admin-login">
        <div class="foot__mark" style="font-size:2.5rem;border:0;padding:0 0 1rem;">Sarah's <em>Boutique</em></div>
        <h1 style="font-family:var(--serif);font-weight:300;margin-bottom:1rem;">Store admin</h1>
        <?php if ($errorMessage !== ''): ?><p class="admin-message admin-error" role="alert"><?= admin_escape($errorMessage) ?></p><?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= admin_escape($_SESSION['admin_csrf']) ?>">
          <label for="password">Admin password</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required>
          <button class="btn btn--primary" type="submit">Sign in</button>
        </form>
      </section>
    <?php else: ?>
      <header class="admin-head">
        <div>
          <p class="eyebrow" style="color:var(--rose);">Sarah's Boutique</p>
          <h1>Store admin</h1>
          <p>Update product prices and gallery images.</p>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= admin_escape($_SESSION['admin_csrf']) ?>">
          <button class="btn" type="submit" name="logout" value="1">Sign out</button>
        </form>
      </header>
      <section class="admin-panel">
        <?php if ($errorMessage !== ''): ?><p class="admin-message admin-error" role="alert"><?= admin_escape($errorMessage) ?></p><?php endif; ?>
        <?php if ($successMessage !== ''): ?><p class="admin-message" role="status"><?= admin_escape($successMessage) ?></p><?php endif; ?>
        <?php if (count($catalog) > 0): ?>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= admin_escape($_SESSION['admin_csrf']) ?>">
            <div class="admin-grid">
              <?php foreach ($catalog as $id => $product): ?>
                <article class="admin-product">
                  <img src="<?= admin_escape($product['src']) ?>" alt="<?= admin_escape($product['alt']) ?>">
                  <h2><?= admin_escape($product['name']) ?></h2>
                  <label for="price-<?= admin_escape($id) ?>">Price (ZAR)</label>
                  <input id="price-<?= admin_escape($id) ?>" name="prices[<?= admin_escape($id) ?>]" type="number" min="1" max="10000000" step="1" value="<?= admin_escape($product['price']) ?>" required>
                  <label for="image-url-<?= admin_escape($id) ?>">Image URL</label>
                  <input id="image-url-<?= admin_escape($id) ?>" name="image_urls[<?= admin_escape($id) ?>]" type="url" value="<?= admin_escape($product['src']) ?>" placeholder="https://...">
                  <label for="image-alt-<?= admin_escape($id) ?>">Image description (alt text)</label>
                  <input id="image-alt-<?= admin_escape($id) ?>" name="image_alts[<?= admin_escape($id) ?>]" type="text" maxlength="250" value="<?= admin_escape($product['alt']) ?>" required>
                  <label for="image-file-<?= admin_escape($id) ?>">Or upload a replacement</label>
                  <input id="image-file-<?= admin_escape($id) ?>" name="images[<?= admin_escape($id) ?>]" type="file" accept="image/jpeg,image/png,image/webp">
                  <p class="admin-hint">JPEG, PNG, or WebP; maximum 5 MB. Uploading replaces the URL above.</p>
                </article>
              <?php endforeach; ?>
            </div>
            <div class="admin-actions">
              <button class="btn btn--primary" type="submit" name="save_products" value="1">Save all changes</button>
              <span class="admin-hint">Updates appear on the storefront and are used by Stripe checkout.</span>
            </div>
          </form>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
