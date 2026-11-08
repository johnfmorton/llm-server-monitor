<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'LogWatch\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('LogWatch\\'))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Optional: MaxMind's reader, vendored next to the collector.
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../maxmind-db-reader.phar'] as $f) {
    if (is_file($f)) {
        require_once $f;
        break;
    }
}

date_default_timezone_set('UTC');
