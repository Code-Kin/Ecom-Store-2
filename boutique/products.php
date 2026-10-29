<?php

function store_read_json($filename)
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . $filename;
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read ' . $filename . '.');
    }

    try {
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Invalid JSON in ' . $filename . '.', 0, $error);
    }

    if (!is_array($data)) {
        throw new RuntimeException('Invalid data in ' . $filename . '.');
    }
    return $data;
}

function store_load_catalog()
{
    $prices = store_read_json('product-prices.json');
    $images = store_read_json('product-images.json');
    if (count($prices) === 0 || count($prices) !== count($images)) {
        throw new RuntimeException('Product prices and images do not match.');
    }

    $catalog = array();
    foreach ($prices as $id => $product) {
        if (!is_string($id) ||
            !preg_match('/^[a-z0-9-]+$/', $id) ||
            !is_array($product) ||
            !isset($product['name'], $product['price']) ||
            !is_string($product['name']) ||
            $product['name'] === '' ||
            !is_int($product['price']) ||
            $product['price'] < 1 ||
            !isset($images[$id]['src'], $images[$id]['alt']) ||
            !is_string($images[$id]['src']) ||
            !store_valid_image_src($images[$id]['src']) ||
            !is_string($images[$id]['alt']) ||
            $images[$id]['alt'] === '') {
            throw new RuntimeException('Invalid product data for ' . (string) $id . '.');
        }

        $catalog[$id] = array(
            'id' => $id,
            'name' => $product['name'],
            'price' => $product['price'],
            'src' => $images[$id]['src'],
            'alt' => $images[$id]['alt']
        );
    }

    foreach ($images as $id => $image) {
        if (!isset($prices[$id])) {
            throw new RuntimeException('Image entry has no matching product price.');
        }
    }
    return $catalog;
}

function store_write_json($filename, $data)
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $path = __DIR__ . DIRECTORY_SEPARATOR . $filename;
    $temporaryPath = tempnam(__DIR__, 'catalog-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Unable to prepare product data for saving.');
    }

    if (file_put_contents($temporaryPath, $json . PHP_EOL, LOCK_EX) === false) {
        @unlink($temporaryPath);
        throw new RuntimeException('Unable to write product data.');
    }
    if (!rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Unable to replace product data.');
    }
}

function store_valid_image_src($src)
{
    if (!is_string($src) || strlen($src) > 2048) {
        return false;
    }
    if (preg_match('/^uploads\/[A-Za-z0-9._-]+\.(?:jpg|jpeg|png|webp)$/i', $src)) {
        return strpos($src, '..') === false;
    }
    return filter_var($src, FILTER_VALIDATE_URL) !== false &&
        parse_url($src, PHP_URL_SCHEME) === 'https';
}

if (isset($_SERVER['SCRIPT_FILENAME']) &&
    realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    try {
        echo json_encode(array_values(store_load_catalog()), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        error_log('Product catalog could not be served: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(array('error' => 'The product catalogue is temporarily unavailable.'));
    }
}
