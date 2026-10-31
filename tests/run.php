<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Cosmemilton\Sitef\{BridgeTransport, CancellationToken, Catalog, Client, FileJournal, Journal, Sitef, SitefException, Transport};

function ensure(bool $value, string $message = 'Assertion failed'): void { if (!$value) throw new RuntimeException($message); }
function same(mixed $expected, mixed $actual): void { ensure($expected === $actual, 'Values differ: ' . json_encode([$expected, $actual])); }
function fails(string $code, callable $operation): SitefException {
    try { $operation(); } catch (SitefException $error) { same($code, $error->errorCode); return $error; }
    throw new RuntimeException("Expected error $code");
}
function input(array $patch = []): array { return [...['functionId' => 0, 'amount' => '12,34', 'fiscalDocument' => '123', 'fiscalDate' => '20261030', 'fiscalTime' => '120000', 'operator' => 'test', 'additionalParameters' => '{X=1}'], ...$patch]; }
function exchange(int $command, string $buffer = '', int $field = 0, array $patch = []): array { return [...['status' => 10000, 'command' => $command, 'fieldType' => $field, 'minLength' => 0, 'maxLength' => 20, 'buffer' => $buffer], ...$patch]; }
final class MemoryJournal implements Journal {
    public ?array $record = null; public bool $locked = false; public ?string $failOn = null;
    public array $history = [];
    public function acquire(): void { if ($this->locked) throw new RuntimeException('locked'); $this->locked = true; }
    public function read(): ?array { return $this->record; }
    public function write(array $record): void { if ($this->failOn === $record['state']) throw new RuntimeException('disk fixture'); $this->record = $record; $this->history[] = $record; }
    public function release(): void { $this->locked = false; }
}
final class FakeTransport implements Transport {
    public array $calls = []; public bool $closed = false; public int $startStatus = 10000; public bool $finishFails = false; public bool $continueFails = false;
    public int $finishCount = 0; public ?int $finishFailureAt = null; public ?Closure $onCall = null;
    public function __construct(public array $steps = [['status' => 0]]) {}
    public function call(string $method, array $parameters = []): array {
        $this->calls[] = [$method, $parameters];
        if ($this->onCall !== null) ($this->onCall)($method, $parameters);
        if ($method === 'configure') return ['status' => 0];
        if (in_array($method, ['start', 'startBanking', 'readSecureCard', 'readTrack3', 'readPassword', 'readChip'], true)) return ['status' => $this->startStatus];
        if ($method === 'continue') { if ($this->continueFails) throw new SitefException('BRIDGE_EOF', 'fixture', true); return array_shift($this->steps) ?? ['status' => 0]; }
        if ($method === 'finish') { $this->finishCount++; if ($this->finishFails || $this->finishFailureAt === $this->finishCount) throw new SitefException('BRIDGE_TIMEOUT', 'fixture', true); return ['returned' => true]; }
        if ($method === 'pendingCount') return ['status' => 2];
        if ($method === 'validateBarcode') return ['status' => 3, 'documentType' => 1];
        return ['status' => 0];
    }
    public function close(): void { $this->closed = true; }
}
function setup(array $steps = [['status' => 0]], ?callable $interaction = null, ?callable $observer = null, ?MemoryJournal $journal = null, ?array $semantics = null): array {
    $transport = new FakeTransport($steps); $journal ??= new MemoryJournal();
    $semantics ??= ['hooks' => ['storeReceipt' => static fn(): bool => true, 'printReceipt' => static fn(): bool => true]]; // Explicit acknowledgements for own fixtures only.
    $client = Client::connect($transport, ['server' => '127.0.0.1', 'storeId' => '00000000', 'terminalId' => 'AA123456', 'additionalParameters' => '[X=1]'], $journal, $interaction ?? static fn(): array => [], $observer, 20000, 100000, $semantics);
    return [$client, $transport, $journal];
}
$tests = [];
$tests['catalogue is shared and unknown statuses remain numeric'] = static function (): void {
    same(1, Catalog::all()['schemaVersion']); same('input', Catalog::commandInfo(30)['kind']); same('unknown', Catalog::commandInfo(123456)['kind']);
    same('authorizer-declined', Catalog::classifyReturn(987654)['kind']); same(-987654, Catalog::classifyReturn(-987654)['code']);
    same('present', Catalog::classifyReturn(1, 'pinpad-presence')['kind']); same('count', Catalog::classifyReturn(2, 'pending-count')['kind']); same(false, Catalog::functionInfo(0)['businessFlowVerified']);
};
$tests['ordered repeated fields preserve raw base64 and continuation echoes exact data'] = static function (): void {
    [$client, $transport] = setup([exchange(0, 'Ação', 121, ['bufferBase64' => base64_encode("A\xe7\xe3o")]), exchange(0, 'second', 121), exchange(30, 'Collect', 999123), ['status' => 0]], static fn(): array => ['buffer' => '1234']);
    $result = $client->transact(input()); same(['Ação', 'second'], array_column($result['fields'], 'buffer'));
    $continuations = array_values(array_filter($transport->calls, static fn($call): bool => $call[0] === 'continue'));
    same('', $continuations[0][1]['buffer']); same(base64_encode("A\xe7\xe3o"), $continuations[1][1]['bufferBase64']); same('1234', $continuations[3][1]['buffer']);
    same('awaiting-finalization', $client->state()); fails('FINALIZATION_REQUIRED', static fn() => $client->close());
    $client->finalize(false, '{FutureFinalization=1}'); $finish = array_values(array_filter($transport->calls, static fn($call): bool => $call[0] === 'finish'))[0][1];
    same('20261030', $finish['fiscalDate']); same('120000', $finish['fiscalTime']); same('123', $finish['fiscalDocument']); same('{FutureFinalization=1}', $finish['additionalParameters']); $client->close();
};
$tests['callback failure drains with -1 and cannot reuse previous buffer'] = static function (): void {
    [$client, $transport] = setup([exchange(999, 'unknown'), exchange(23), ['status' => -2]], static function (): array { throw new RuntimeException('UI failure'); });
    fails('INTERACTION_FAILED', static fn() => $client->transact(input()));
    $calls = array_values(array_filter($transport->calls, static fn($call): bool => $call[0] === 'continue')); same(-1, $calls[1][1]['continueFlag']); same('', $calls[1][1]['buffer']); same(-1, $calls[2][1]['continueFlag']); same('idle', $client->state()); $client->close();
};
$tests['polling bypasses UI and supports cooperative cancellation'] = static function (): void {
    $ui = 0; $observed = 0;
    [$client, $transport] = setup([exchange(23), ['status' => -15]], static function () use (&$ui): array { $ui++; return []; }, static function ($request, $cancel) use (&$observed): void { $observed++; $cancel->cancel(); });
    $result = $client->transact(input()); same(0, $ui); same(1, $observed); same(-15, $result['status']); same(-1, $transport->calls[3][1]['continueFlag']); $client->close();
};
$tests['unknown positive and negative terminal returns are never discarded'] = static function (): void {
    foreach ([987654, -987654] as $status) { [$client] = setup([['status' => $status]]); $result = $client->transact(input()); same($status, $result['status']); same(false, $result['requiresFinalization']); $client->close(); }
};
$tests['transport failure after start makes journal uncertain and blocks replay'] = static function (): void {
    [$client, $transport, $journal] = setup(); $transport->continueFails = true;
    ensure(fails('TRANSACTION_UNCERTAIN', static fn() => $client->transact(input()))->uncertain); same('uncertain', $journal->record['state']); fails('SESSION_BUSY', static fn() => $client->transact(input()));
    same(2, $client->callNative('pendingCount')['status']); fails('RECOVERY_REQUIRED', static fn() => $client->callNative('flush')); $client->close();
};
$tests['failed finalization persists decision and scope; recovery rejects changes'] = static function (): void {
    [$client, $transport] = setup(); $client->transact(input()); $transport->finishFails = true;
    fails('FINALIZATION_UNCERTAIN', static fn() => $client->finalize(true, '{FutureScope=1}')); $id = $client->recovery()['id'];
    fails('RECOVERY_DECISION_MISMATCH', static fn() => $client->finalizeRecovery(false, $id)); fails('RECOVERY_SCOPE_MISMATCH', static fn() => $client->finalizeRecovery(true, $id, '{FutureScope=2}'));
    $transport->finishFails = false; same('not-reported', $client->finalizeRecovery(true, $id)['remoteConfirmation']); $client->close();
};
$tests['crash journal resumes uncertain; terminal mismatch releases lock'] = static function (): void {
    [$original, $transport, $journal] = setup(); $original->transact(input()); $journal->locked = false;
    [$resumed] = setup(journal: $journal); same('uncertain', $resumed->state()); $resumed->close();
    $journal->record['terminalId'] = 'ZZ123456'; fails('JOURNAL_TERMINAL_MISMATCH', static fn() => setup(journal: $journal)); same(false, $journal->locked);
};
$tests['input validation and cancelled token prevent native start'] = static function (): void {
    [$client, $transport] = setup(); fails('INVALID_FISCAL_ID', static fn() => $client->transact(input(['fiscalDate' => '20260230']))); fails('INVALID_ARGUMENT', static fn() => $client->transact(input(['amount' => 12.34])));
    $cancel = new CancellationToken(); $cancel->cancel(); fails('ABORTED', static fn() => $client->transact(input(), $cancel)); same(1, count($transport->calls)); $client->close();
};
$tests['UTF8 API length does not reject valid twenty-character Windows1252 identifiers'] = static function (): void {
    [$client] = setup(); $result = $client->transact(input(['fiscalDocument' => str_repeat('á', 20), 'operator' => str_repeat('ç', 20)]));
    same(0, $result['status']); $client->finalize(false);
    fails('INVALID_FISCAL_ID', static fn() => $client->transact(input(['operator' => str_repeat('ç', 21)])));
    fails('INVALID_FISCAL_ID', static fn() => $client->transact(input(['fiscalDocument' => str_repeat('á', 21)]))); $client->close();
};
$tests['journal failure before start cannot initiate native operation'] = static function (): void {
    $journal = new MemoryJournal(); $journal->failOn = 'started'; [$client, $transport] = setup(journal: $journal);
    try { $client->transact(input()); throw new LogicException('Expected failure'); } catch (RuntimeException $error) { ensure(!$error instanceof LogicException); }
    same(1, count($transport->calls)); $client->close();
};
$tests['partial-bill navigation and barcode validation retain their native domains'] = static function (): void {
    $client = null;
    [$client, $transport] = setup([exchange(34, 'Value', 601), ['status' => 0]], static function () use (&$client): array { same(3, $client->validateBarcode('1234')['status']); return ['continueFlag' => 2]; });
    $client->transact(input()); same(2, $transport->calls[4][1]['continueFlag']); $client->finalize(false); $client->close();
};
$tests['disk journal is exclusive, atomic, persistent, and excludes collected fields'] = static function (): void {
    $directory = sys_get_temp_dir() . '/sitef-php-' . bin2hex(random_bytes(8)); mkdir($directory);
    $path = $directory . '/terminal.json'; $first = new FileJournal($path); $other = new FileJournal($path); $first->acquire();
    try {
        fails('TERMINAL_LOCKED', static fn() => $other->acquire());
        $record = ['version' => 1, 'id' => 'test-id', 'functionId' => 0, 'storeId' => '00000000', 'terminalId' => 'AA123456', 'fiscalDocument' => '123', 'fiscalDate' => '20261030', 'fiscalTime' => '120000', 'state' => 'started'];
        $first->write($record); $first->write([...$record, 'state' => 'uncertain']); same('uncertain', $first->read()['state']);
        $first->write([...$record, 'buffer' => 'SENSITIVE', 'fields' => [['buffer' => 'SENSITIVE']]]); ensure(!str_contains(file_get_contents($path), 'SENSITIVE'));
        file_put_contents($path, str_repeat('X', 1048577)); fails('JOURNAL_INVALID', static fn() => $first->read()); $first->write($record);
        same([], glob($path . '.*.lock.tmp')); $first->release(); $other->acquire(); same('test-id', $other->read()['id']); $other->release();
    } finally { $first->release(); $other->release(); foreach (glob($directory . '/*') as $file) unlink($file); rmdir($directory); }
};
$tests['IPC authenticates, preserves ID, supports fragmented frames and vendor errors without replay'] = static function (): void {
    $transport = new BridgeTransport(PHP_BINARY, __DIR__, [__DIR__ . '/fake-bridge.php', 'good'], 2);
    try { same(['nested' => ['value' => 'Ação']], $transport->call('echo', ['nested' => ['value' => 'Ação']])['params']); fails('OPTIONAL_SYMBOL_UNAVAILABLE', static fn() => $transport->call('native-error')); same('next', $transport->call('next')['method']); }
    finally { $transport->close(); }
};
$tests['IPC rejects spoofed handshake'] = static function (): void { fails('BRIDGE_AUTH', static fn() => new BridgeTransport(PHP_BINARY, __DIR__, [__DIR__ . '/fake-bridge.php', 'bad-auth'], 2)); };
$tests['IPC rejects malformed, mismatched, oversized and truncated replies permanently'] = static function (): void {
    foreach (['malformed' => 'INVALID_FRAME', 'bom' => 'INVALID_FRAME', 'non-object' => 'INVALID_FRAME', 'wrong-id' => 'INVALID_FRAME', 'oversized' => 'FRAME_TOO_LARGE', 'eof' => 'BRIDGE_EOF'] as $mode => $code) {
        $transport = new BridgeTransport(PHP_BINARY, __DIR__, [__DIR__ . '/fake-bridge.php', $mode], 2, 1024);
        try { fails($code, static fn() => $transport->call('request')); fails('BRIDGE_CLOSED', static fn() => $transport->call('retry')); } finally { $transport->close(); }
    }
};
$tests['IPC timeout is bounded and stderr cannot deadlock a full pipe'] = static function (): void {
    $transport = new BridgeTransport(PHP_BINARY, __DIR__, [__DIR__ . '/fake-bridge.php', 'timeout'], 0.5);
    $start = hrtime(true); fails('BRIDGE_TIMEOUT', static fn() => $transport->call('request')); ensure((hrtime(true) - $start) / 1e9 < 2); $transport->close();
    $transport = new BridgeTransport(PHP_BINARY, __DIR__, [__DIR__ . '/fake-bridge.php', 'stderr-flood'], 2); same('ok', $transport->call('ok')['method']); $transport->close();
};
$tests['production factory refuses Linux before launching a process'] = static function (): void {
    if (PHP_OS_FAMILY !== 'Windows') fails('UNSUPPORTED_RUNTIME', static fn() => Sitef::create([]));
};

require __DIR__ . '/semantic-suite.php';
$failures = 0;
foreach ($tests as $name => $operation) {
    try { $operation(); echo "PASS $name\n"; }
    catch (Throwable $error) { $failures++; fwrite(STDERR, "FAIL $name: " . get_class($error) . ': ' . $error->getMessage() . "\n"); }
}
echo count($tests) . " tests, $failures failures\n";
exit($failures === 0 ? 0 : 1);
