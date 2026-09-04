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
 * Unset process environment keys so a later .env load is not blocked.
 *
 * @param array<int, string> $names
 */
function bakery_clear_env_keys(array $names): void
{
    foreach ($names as $name) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}

/**
 * @param string $envPath Absolute path to .env file
 * @param bool $override When true, replace keys already present in the process
 * @return bool True if file was loaded
 */
function bakery_load_env_file($envPath, $override = false) {
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

        if (!$override) {
            $existing = getenv($name);
            if ($existing !== false && $existing !== '') {
                // Process env wins, but still mirror into $_ENV/$_SERVER so
                // callers that read $_ENV (e.g. setup_local_db.php, CI) see it.
                if (!isset($_ENV[$name])) {
                    $_ENV[$name] = $existing;
                    $_SERVER[$name] = $existing;
                }
                continue;
            }
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
