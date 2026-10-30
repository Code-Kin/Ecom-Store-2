<?php

if (!function_exists('store_load_env_file')) {
    function store_env_candidates()
    {
        $base = __DIR__;
        return array(
            $base . DIRECTORY_SEPARATOR . '.env',
            $base . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . '.env',
            $base . DIRECTORY_SEPARATOR . 'env' . DIRECTORY_SEPARATOR . '.env',
        );
    }

    function store_load_env_file($path = null)
    {
        $candidate = $path;
        if ($candidate === null || $candidate === '') {
            foreach (store_env_candidates() as $filepath) {
                if (is_readable($filepath)) {
                    $candidate = $filepath;
                    break;
                }
            }
        }

        if ($candidate === null || $candidate === '' || !is_readable($candidate)) {
            return;
        }

        $lines = @file($candidate, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || preg_match('/^(?:#|;)/', $trimmed)) {
                continue;
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));
            if ($key === '') {
                continue;
            }

            $quoteLength = strlen($value);
            if ($quoteLength >= 2 && (($value[0] === '"' && $value[$quoteLength - 1] === '"') || ($value[0] === "'" && $value[$quoteLength - 1] === "'"))) {
                $value = substr($value, 1, -1);
            }

            $value = str_replace(array('\\n', '\\r', '\\t'), array("\n", "\r", "\t"), $value);

            $_ENV[$key] = $value;
            if (!isset($_SERVER[$key])) {
                $_SERVER[$key] = $value;
            }
            putenv($key . '=' . $value);
        }
    }

    function env_value($key, $default = null)
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER)) {
            return $_SERVER[$key];
        }
        return $default;
    }

    store_load_env_file();
}
