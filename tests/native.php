<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Cosmemilton\Sitef\{Catalog, Sitef, SitefException};

// ONLY the original synthetic C fixture is allowed here. Never point this test at a vendor DLL.
[$script, $bridge, $fixture] = $argv + [null, null, null];
if (!is_string($bridge) || !is_string($fixture) || !str_contains(basename($fixture), 'fixture')) { fwrite(STDERR, "Usage: php tests/native.php <absolute-bridge> <absolute-owned-fixture>\n"); exit(2); }
$directory = dirname(__DIR__) . '/work/abi-' . bin2hex(random_bytes(8));
if (!is_dir(dirname($directory))) mkdir(dirname($directory));
mkdir($directory);
$configuration = ['server' => '127.0.0.1', 'storeId' => '00000000', 'terminalId' => 'AA123456', 'additionalParameters' => '[X=1]'];
$input = ['functionId' => 0, 'amount' => '12,34', 'fiscalDocument' => '123', 'fiscalDate' => '20261030', 'fiscalTime' => '120000', 'operator' => 'test', 'additionalParameters' => '{X=1}'];
$seen = [];
$client = null;
try {
    $client = Sitef::create(['bridgeExecutable' => $bridge, 'bridgeDirectory' => dirname($bridge), 'libraryPath' => $fixture, 'stateDirectory' => $directory, 'testMode' => true, 'timeoutSeconds' => 5,
        'configuration' => $configuration,
        'hooks' => ['storeReceipt' => static fn(): bool => true, 'printReceipt' => static fn(): bool => true], // Own synthetic receipt acknowledgement only.
        'onExchange' => static function ($request) use (&$seen): void { $seen[] = $request; },
        'interaction' => static function ($request): array { if ($request['command'] !== 30 || $request['fieldType'] !== 999123 || $request['minLength'] !== 1 || $request['maxLength'] !== 4) throw new RuntimeException('Unexpected fixture request'); return ['buffer' => '1234']; },
    ]);
    $result = $client->transact($input);
    if ($result['status'] !== 0 || !$result['requiresFinalization'] || $result['fields'][0]['buffer'] !== "Ação\n" || base64_decode($result['fields'][0]['bufferBase64']) !== "A\xe7\xe3o\n") throw new RuntimeException('Native ABI/encoding/echo mismatch');
    if ($client->finalize(false)['remoteConfirmation'] !== 'not-reported') throw new RuntimeException('Void finish semantics mismatch');
    if ($client->callNative('pendingCount', ['fiscalDate' => $input['fiscalDate'], 'fiscalDocument' => $input['fiscalDocument']])['status'] !== 2) throw new RuntimeException('Pending-count domain lost');
    if ($client->validateBarcode('1234')['status'] !== 3) throw new RuntimeException('Barcode return lost');
    if ($client->transact([...$input, 'functionId' => 999], options: ['requiresFinalization' => false])['status'] !== -98765) throw new RuntimeException('Negative native return lost');
    $client->close(); $client = null;
    echo "PASS native TCP/ABI: handshake, environment nonce removed by Rust, configuration, encoding/raw echo, unknown field, interactive response, void finish, auxiliary domains, unknown negative status\n";
} finally {
    if ($client !== null && $client->state() !== 'running') { try { if ($client->state() === 'awaiting-finalization') $client->finalize(false); $client->close(); } catch (Throwable) {} }
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
