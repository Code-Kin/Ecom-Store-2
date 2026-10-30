<?php

function store_config($name)
{
    static $fileConfig = null;
    if ($fileConfig === null) {
        $fileConfig = require __DIR__ . DIRECTORY_SEPARATOR . 'private-config.php';
        if (!is_array($fileConfig)) {
            throw new RuntimeException('The private configuration file must return an array.');
        }
    }

    if (isset($fileConfig[$name]) && is_string($fileConfig[$name]) && $fileConfig[$name] !== '') {
        return $fileConfig[$name];
    }

    return getenv($name);
}
