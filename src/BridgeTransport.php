<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

/** Authenticated loopback IPC avoids unsupported nonblocking proc_open pipes on Windows. */
final class BridgeTransport implements Transport
{
    private mixed $process = null;
    private mixed $socket = null;
    private bool $dead = false;
    private bool $busy = false;
    private int $nextId = 1;
    private string $input = '';

    public function __construct(
        string $executable,
        string $cwd,
        array $arguments = [],
        private readonly float $timeoutSeconds = 120.0,
        private readonly int $maxFrameBytes = 1048576,
    ) {
        if (!Validation::absolute($executable) || !Validation::absolute($cwd)) throw new SitefException('INVALID_PATH', 'executable e cwd precisam ser absolutos.');
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $maxFrameBytes < 1024) throw new SitefException('INVALID_OPTIONS', 'Limites IPC inválidos.');
        foreach ($arguments as $argument) Validation::text($argument, 'argument');
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if ($listener === false) throw new SitefException('BRIDGE_LISTEN', 'Não foi possível criar canal IPC loopback.');
        $token = bin2hex(random_bytes(32));
        $endpoint = stream_socket_get_name($listener, false);
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment['SITEF_BRIDGE_TOKEN'] = $token;
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']];
        try {
            $pipes = [];
            $this->process = @proc_open([$executable, ...$arguments, '--connect', $endpoint, '--token-env', 'SITEF_BRIDGE_TOKEN'], $descriptors, $pipes, $cwd, $environment, ['bypass_shell' => true, 'create_new_console' => false, 'suppress_errors' => true]);
            unset($environment['SITEF_BRIDGE_TOKEN']);
            if (!is_resource($this->process)) throw new SitefException('BRIDGE_SPAWN', 'Não foi possível iniciar a ponte nativa.');
            stream_set_blocking($listener, false);
            $deadline = self::now() + $timeoutSeconds;
            while (true) {
                $read = [$listener]; $write = []; $except = [];
                $this->select($read, $write, $except, $deadline);
                if ($read !== []) {
                    $this->socket = @stream_socket_accept($listener, 0);
                    if (is_resource($this->socket)) break;
                }
                $this->assertRunning();
            }
            stream_set_blocking($this->socket, false);
            $handshake = $this->readFrame($deadline);
            if (($handshake['protocolVersion'] ?? null) !== 1 || ($handshake['transport'] ?? null) !== 'tcp-loopback' || !is_string($handshake['token'] ?? null) || !hash_equals($token, $handshake['token'])) throw new SitefException('BRIDGE_AUTH', 'Handshake IPC inválido.');
            if ($this->input !== '') throw new SitefException('INVALID_FRAME', 'Dados inesperados após handshake IPC.');
        } catch (\Throwable $error) {
            $this->shutdown(true);
            throw $error instanceof SitefException ? $error : new SitefException('BRIDGE_START', 'Falha ao iniciar canal IPC.', true, null, $error);
        } finally { fclose($listener); unset($token, $environment); }
    }

    public function call(string $method, array $parameters = []): array
    {
        if ($this->dead) throw new SitefException('BRIDGE_CLOSED', 'Ponte encerrada; não repetir operação financeira.', true);
        if ($this->busy) throw new SitefException('BRIDGE_BUSY', 'Uma chamada IPC já está em andamento.');
        Validation::text($method, 'method');
        $id = $this->nextId++;
        $frame = json_encode(['id' => $id, 'method' => $method, 'params' => (object)$parameters], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (strlen($frame) > $this->maxFrameBytes) throw new SitefException('FRAME_TOO_LARGE', 'Requisição excede limite IPC.');
        $this->busy = true;
        try {
            $deadline = self::now() + $this->timeoutSeconds;
            for ($offset = 0; $offset < strlen($frame);) {
                $read = []; $write = [$this->socket]; $except = [];
                $this->select($read, $write, $except, $deadline);
                if ($write !== []) {
                    $written = @fwrite($this->socket, substr($frame, $offset, 8192));
                    if ($written === false) throw new SitefException('BRIDGE_PIPE', 'Falha ao enviar requisição IPC.', true);
                    $offset += $written;
                }
            }
            $reply = $this->readFrame($deadline);
            if ($this->input !== '' || ($reply['id'] ?? null) !== $id || array_key_exists('result', $reply) === array_key_exists('error', $reply)) throw new SitefException('INVALID_FRAME', 'Resposta IPC não corresponde à chamada.', true);
            if (array_key_exists('error', $reply)) {
                $error = $reply['error'];
                if (!is_array($error) || !is_string($error['code'] ?? null) || !is_string($error['message'] ?? null)) throw new SitefException('INVALID_FRAME', 'Erro IPC malformado.', true);
                throw new SitefException($error['code'], $error['message']);
            }
            if (!is_array($reply['result'])) throw new SitefException('INVALID_FRAME', 'Resultado IPC deve ser objeto.', true);
            return $reply['result'];
        } catch (\Throwable $error) {
            // Vendor/bridge errors are replies. Transport failures invalidate this process permanently.
            if (!($error instanceof SitefException) || $error->uncertain) $this->shutdown(true);
            throw $error;
        } finally { $this->busy = false; }
    }

    private function readFrame(float $deadline): array
    {
        while (($boundary = strpos($this->input, "\n")) === false) {
            if (strlen($this->input) > $this->maxFrameBytes) throw new SitefException('FRAME_TOO_LARGE', 'Resposta excede limite IPC.', true);
            $read = [$this->socket]; $write = []; $except = [];
            $this->select($read, $write, $except, $deadline);
            if ($read !== []) {
                $chunk = @fread($this->socket, 8192);
                if ($chunk === false) throw new SitefException('BRIDGE_PIPE', 'Falha ao ler canal IPC.', true);
                if ($chunk === '' && feof($this->socket)) throw new SitefException('BRIDGE_EOF', 'Ponte encerrou sem resposta completa.', true);
                $this->input .= $chunk;
            }
            // Read socket before observing process exit so a final complete reply is not discarded.
            if (strpos($this->input, "\n") === false && feof($this->socket)) throw new SitefException('BRIDGE_EOF', 'Ponte encerrou sem resposta completa.', true);
        }
        if ($boundary > $this->maxFrameBytes) throw new SitefException('FRAME_TOO_LARGE', 'Resposta excede limite IPC.', true);
        $raw = substr($this->input, 0, $boundary); $this->input = substr($this->input, $boundary + 1);
        try {
            $object = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass || (property_exists($object, 'result') && !$object->result instanceof \stdClass) || (property_exists($object, 'error') && !$object->error instanceof \stdClass)) throw new \RuntimeException('Invalid frame shape');
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        catch (\Throwable $error) { throw new SitefException('INVALID_FRAME', 'Resposta JSON inválida.', true, null, $error); }
        if (!is_array($value)) throw new SitefException('INVALID_FRAME', 'Frame IPC deve ser objeto.', true);
        return $value;
    }
    private function select(array &$read, array &$write, array &$except, float $deadline): void
    {
        $remaining = $deadline - self::now();
        if ($remaining <= 0) throw new SitefException('BRIDGE_TIMEOUT', 'Tempo limite IPC; resultado pode ser incerto.', true);
        $seconds = min($remaining, 0.05);
        if (@stream_select($read, $write, $except, 0, max(1, (int)($seconds * 1000000))) === false) throw new SitefException('BRIDGE_SELECT', 'Falha no canal IPC.', true);
    }
    private function assertRunning(): void
    {
        if (!is_resource($this->process) || !proc_get_status($this->process)['running']) throw new SitefException('BRIDGE_EXIT', 'Processo nativo encerrou.', true);
    }
    private static function now(): float { return hrtime(true) / 1000000000; }
    public function close(): void { $this->shutdown(false); }
    private function shutdown(bool $force): void
    {
        $this->dead = true;
        if (is_resource($this->socket)) { @fclose($this->socket); $this->socket = null; }
        if (!is_resource($this->process)) return;
        if ($force) @proc_terminate($this->process);
        $deadline = self::now() + ($force ? 0.5 : 5.0);
        while (proc_get_status($this->process)['running'] && self::now() < $deadline) usleep(1000);
        $forcedAfterGrace = false;
        if (proc_get_status($this->process)['running']) {
            $forcedAfterGrace = !$force;
            @proc_terminate($this->process, 9);
            $deadline = self::now() + 0.5;
            while (proc_get_status($this->process)['running'] && self::now() < $deadline) usleep(1000);
        }
        if (!proc_get_status($this->process)['running']) { @proc_close($this->process); $this->process = null; }
        elseif (!$force) throw new SitefException('BRIDGE_CLOSE_TIMEOUT', 'A ponte não encerrou no prazo; reconciliação necessária.', true);
        if ($forcedAfterGrace) throw new SitefException('BRIDGE_CLOSE_TIMEOUT', 'A ponte exigiu encerramento forçado; lock mantido e reconciliação necessária.', true);
    }
}
