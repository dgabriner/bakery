<?php
/**
 * Minimal .env loader (no Composer dependency).
 * Loads KEY=VALUE pairs into putenv, $_ENV, and $_SERVER.
 * Does not override variables already set in the process environment.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * @param string $envPath Absolute path to .env file
 * @return bool True if file was loaded
 */
function bakery_load_env_file($envPath) {
    if (!is_readable($envPath)) {
        return false;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return false;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if ($name === '') {
            continue;
        }

        // Strip optional surrounding quotes
        if (
            strlen($value) >= 2 &&
            (($value[0] === '"' && substr($value, -1) === '"') ||
             ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $existing = getenv($name);
        if ($existing !== false && $existing !== '') {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    return true;
}

/**
 * Read a required configuration value from the environment.
 *
 * @param string $name
 * @param string|null $default Use null to require the variable
 * @return string
 */
function bakery_env($name, $default = null) {
    $value = $_ENV[$name] ?? getenv($name);
    if ($value === false || $value === null || $value === '') {
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException(
            "Missing required environment variable: {$name}. " .
            "Copy bakery/.env.example to bakery/.env for local development, " .
            "or set Apache/panel environment variables for production."
        );
    }
    return (string) $value;
}
