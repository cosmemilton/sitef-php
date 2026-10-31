<?php
declare(strict_types=1);
// Optional dependency-free loader; Composer PSR-4 autoloading is supported as well.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Cosmemilton\\Sitef\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $relative) !== 1) return;
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) require $path;
});
