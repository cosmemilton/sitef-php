<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use Cosmemilton\Sitef\{Client, Journal, Transport};

// In-memory demonstration only: no DLL, hardware, network, receipt or real payment.
$transport = new class implements Transport {
    private array $steps = [
        ['status' => 10000, 'command' => 1, 'fieldType' => 0, 'minLength' => 0, 'maxLength' => 0, 'buffer' => 'Demonstração em memória'],
        ['status' => 10000, 'command' => 0, 'fieldType' => 999123, 'minLength' => 0, 'maxLength' => 0, 'buffer' => 'Campo de demonstração'],
        ['status' => 0],
    ];
    public function call(string $method, array $parameters = []): array {
        return match ($method) { 'configure' => ['status' => 0], 'start' => ['status' => 10000], 'continue' => array_shift($this->steps), 'finish' => ['returned' => true], default => throw new LogicException('Método fora da demonstração') };
    }
    public function close(): void {}
};
$journal = new class implements Journal {
    private ?array $record = null;
    public function acquire(): void {}
    public function read(): ?array { return $this->record; }
    public function write(array $record): void { $this->record = $record; }
    public function release(): void {}
};
$client = Client::connect($transport, ['server' => 'fixture', 'storeId' => '00000000', 'terminalId' => 'AA123456'], $journal,
    static function (array $request): array { if ($request['command'] !== 1) throw new LogicException('Implemente o comando solicitado.'); echo $request['buffer'] . "\n"; return []; });
$result = $client->transact(['functionId' => 0, 'amount' => '10,00', 'fiscalDocument' => '123', 'fiscalDate' => '20261030', 'fiscalTime' => '120000', 'operator' => 'demo']);
echo json_encode(['status' => $result['status'], 'requiresFinalization' => $result['requiresFinalization']], JSON_THROW_ON_ERROR) . "\n";
$client->finalize(false); // Own in-memory stub; no financial finalization.
$client->close();
