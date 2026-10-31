<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

/** Metadata only. Receipt storage and application business data need separate persistence. */
final class FileJournal implements Journal
{
    private mixed $lease = null;
    public function __construct(private readonly string $path)
    {
        if (!Validation::absolute($path)) throw new SitefException('INVALID_PATH', 'Journal precisa de caminho absoluto e persistente.');
    }
    public function acquire(): void
    {
        if (is_resource($this->lease)) throw new SitefException('TERMINAL_LOCKED', 'Journal já está em uso.');
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) throw new SitefException('JOURNAL_IO', 'Não foi possível criar diretório do journal.');
        $lease = @fopen($this->path . '.lock', 'x+b');
        if ($lease === false) throw new SitefException('TERMINAL_LOCKED', 'Terminal bloqueado. Um lock após falha exige reconciliação; não o remova automaticamente.');
        $this->lease = $lease;
        try { self::writeAll($lease, json_encode(['pid' => getmypid(), 'createdAt' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR)); self::sync($lease); }
        catch (\Throwable $error) { $this->release(); throw $error; }
    }
    public function read(): ?array
    {
        if (!file_exists($this->path)) return null;
        $bytes = @file_get_contents($this->path, false, null, 0, 1048577);
        try {
            if ($bytes === false || strlen($bytes) > 1048576) throw new \RuntimeException();
            $record = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            self::validateRecord($record);
            return $record;
        } catch (\Throwable $error) { throw new SitefException('JOURNAL_INVALID', 'Journal inválido; reconciliação necessária.', false, null, $error); }
    }
    public function write(array $record): void
    {
        if (!is_resource($this->lease)) throw new SitefException('JOURNAL_UNLOCKED', 'Journal sem exclusividade.');
        $keys = ['version', 'id', 'storeId', 'terminalId', 'functionId', 'entryPoint', 'fiscalDocument', 'fiscalDate', 'fiscalTime', 'state', 'decision', 'finalizationParameters', 'status', 'updatedAt'];
        $record = array_intersect_key($record, array_flip($keys));
        self::validateRecord($record);
        $encoded = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 1048576) throw new SitefException('JOURNAL_INVALID', 'Metadados excedem limite do journal.');
        $temporary = $this->path . '.' . bin2hex(random_bytes(12)) . '.lock.tmp';
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) throw new SitefException('JOURNAL_IO', 'Não foi possível criar escrita atômica.');
        try {
            @chmod($temporary, 0600);
            self::writeAll($handle, $encoded);
            self::sync($handle);
            fclose($handle); $handle = null;
            // Same-directory rename; never unlink the old journal first.
            if (!@rename($temporary, $this->path)) throw new SitefException('JOURNAL_IO', 'Não foi possível substituir journal atomicamente.');
            if (PHP_OS_FAMILY !== 'Windows') {
                $directory = @fopen(dirname($this->path), 'r');
                if ($directory === false) throw new SitefException('JOURNAL_IO', 'Não foi possível abrir diretório para sincronização.');
                try { if (!fsync($directory)) throw new SitefException('JOURNAL_IO', 'Falha ao sincronizar diretório do journal.'); }
                finally { fclose($directory); }
            }
        } finally { if (is_resource($handle)) fclose($handle); if (file_exists($temporary)) @unlink($temporary); }
    }
    public function release(): void
    {
        if (!is_resource($this->lease)) return;
        fclose($this->lease); $this->lease = null;
        if (!@unlink($this->path . '.lock')) throw new SitefException('JOURNAL_IO', 'Não foi possível liberar lock do terminal.');
    }
    private static function writeAll(mixed $stream, string $data): void
    {
        for ($offset = 0; $offset < strlen($data); $offset += $written) {
            $written = fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) throw new SitefException('JOURNAL_IO', 'Falha ao gravar journal.');
        }
    }
    private static function sync(mixed $stream): void
    {
        if (!fflush($stream) || !fsync($stream)) throw new SitefException('JOURNAL_IO', 'Falha ao sincronizar journal.');
    }
    private static function validateRecord(mixed $record): void
    {
        $states = ['started', 'awaiting-finalization', 'finalizing', 'finalized-local', 'ended', 'uncertain'];
        if (!is_array($record) || ($record['version'] ?? null) !== 1 || !is_string($record['id'] ?? null) || $record['id'] === '' || strlen($record['id']) > 128 || !in_array($record['state'] ?? null, $states, true) || !is_int($record['functionId'] ?? null)) throw new SitefException('JOURNAL_INVALID', 'Metadata do journal inválida.');
        if (!is_string($record['storeId'] ?? null) || !is_string($record['terminalId'] ?? null) || preg_match('/^\d{8}$/D', $record['storeId']) !== 1 || preg_match('/^[A-Za-z0-9]{8}$/D', $record['terminalId']) !== 1) throw new SitefException('JOURNAL_INVALID', 'Terminal do journal inválido.');
        try { Validation::input(['functionId' => $record['functionId'], 'amount' => '', 'fiscalDocument' => $record['fiscalDocument'] ?? null, 'fiscalDate' => $record['fiscalDate'] ?? null, 'fiscalTime' => $record['fiscalTime'] ?? null, 'operator' => '']); }
        catch (\Throwable $error) { throw new SitefException('JOURNAL_INVALID', 'IDs fiscais do journal inválidos.', false, null, $error); }
        if (isset($record['decision']) && !in_array($record['decision'], [0, 1], true)) throw new SitefException('JOURNAL_INVALID', 'Decisão do journal inválida.');
        if (isset($record['finalizationParameters'])) Validation::text($record['finalizationParameters'], 'finalizationParameters');
        if (isset($record['status']) && !is_int($record['status'])) throw new SitefException('JOURNAL_INVALID', 'Status do journal inválido.');
        foreach (['entryPoint', 'updatedAt'] as $key) if (isset($record[$key]) && !is_string($record[$key])) throw new SitefException('JOURNAL_INVALID', 'Tipo de metadado do journal inválido.');
    }
}
