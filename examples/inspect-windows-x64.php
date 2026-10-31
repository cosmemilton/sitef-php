<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use Cosmemilton\Sitef\BridgeTransport;

// Load/capabilities only. Does not configure a terminal or start/finish any transaction.
if (PHP_OS_FAMILY !== 'Windows' || PHP_INT_SIZE !== 8) throw new RuntimeException('Este smoke requer PHP Windows x64.');
[$script, $bridge, $library] = $argv + [null, null, null];
if (!is_string($bridge) || !is_string($library)) throw new InvalidArgumentException('Uso: php examples/inspect-windows-x64.php <ponte.exe absoluta> <DLL absoluta do integrador>');
$transport = new BridgeTransport($bridge, dirname($bridge), timeoutSeconds: 120);
try {
    $capabilities = $transport->call('load', ['libraryPath' => $library, 'workingDirectory' => dirname($library), 'nativeEncoding' => 'windows-1252', 'testMode' => false]);
    if (($capabilities['protocolVersion'] ?? null) !== 1 || ($capabilities['platform'] ?? null) !== 'windows' || ($capabilities['architecture'] ?? null) !== 'x86_64' || ($capabilities['fieldTypeBytes'] ?? null) !== 4) throw new RuntimeException('Ponte incompatível com Windows x64.');
    echo json_encode(array_intersect_key($capabilities, array_flip(['protocolVersion', 'bridgeVersion', 'platform', 'architecture', 'fieldTypeBytes', 'nativeEncoding', 'functions'])), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
} finally { $transport->close(); }
