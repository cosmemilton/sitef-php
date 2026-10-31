<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

final class Client
{
    private string $currentState = 'idle';
    private ?array $record = null;
    private bool $operation = false;
    private ?CancellationToken $cancellation = null;
    private \Closure $interaction;
    private ?\Closure $onExchange;
    private function __construct(private readonly Transport $transport, private readonly array $configuration, private readonly Journal $journal, callable $interaction, ?callable $onExchange, private readonly int $bufferSize, private readonly int $maxSteps)
    {
        $this->interaction = \Closure::fromCallable($interaction);
        $this->onExchange = $onExchange === null ? null : \Closure::fromCallable($onExchange);
    }
    public static function connect(Transport $transport, array $configuration, Journal $journal, callable $interaction, ?callable $onExchange = null, int $bufferSize = 20000, int $maxSteps = 100000): self
    {
        foreach (['server', 'storeId', 'terminalId'] as $key) Validation::text($configuration[$key] ?? null, $key);
        Validation::text($configuration['additionalParameters'] ?? '', 'additionalParameters');
        if ($configuration['server'] === '' || preg_match('/^\d{8}$/D', $configuration['storeId']) !== 1 || preg_match('/^[A-Za-z0-9]{8}$/D', $configuration['terminalId']) !== 1 || ($configuration['reserved'] ?? 0) !== 0) throw new SitefException('INVALID_CONFIGURATION', 'Servidor obrigatório, loja com8dígitos, terminal com8caracteres alfanuméricos e reservado0.');
        if ($bufferSize < 20000 || $bufferSize > 1048576 || $maxSteps < 1) throw new SitefException('INVALID_OPTIONS', 'bufferSize ou maxSteps inválido.');
        $client = new self($transport, $configuration, $journal, $interaction, $onExchange, $bufferSize, $maxSteps);
        $journal->acquire();
        try {
            $client->record = $journal->read();
            if ($client->record !== null && (($client->record['storeId'] ?? null) !== $configuration['storeId'] || ($client->record['terminalId'] ?? null) !== $configuration['terminalId'])) throw new SitefException('JOURNAL_TERMINAL_MISMATCH', 'Journal pertence a outro terminal.');
            $status = Validation::status($transport->call('configure', [...$configuration, 'reserved' => 0, 'additionalParameters' => $configuration['additionalParameters'] ?? '']));
            if ($status !== 0) throw new SitefException('CONFIGURATION_FAILED', "CliSiTef recusou a configuração ($status).", false, $status);
            if ($client->record !== null && !in_array($client->record['state'], ['ended', 'finalized-local'], true)) $client->currentState = 'uncertain';
            return $client;
        } catch (\Throwable $error) { try { $journal->release(); } finally { $transport->close(); } throw $error; }
    }
    public function state(): string { return $this->currentState; }
    public function recovery(): ?array { return $this->record; }
    public function capabilities(): array { return $this->transport->call('capabilities'); }
    public function cancel(): void { $this->cancellation?->cancel(); }

    /**
     * Callback(request,CancellationToken): array{buffer?:string,bufferBase64?:string,continueFlag?:int}.
     * Synchronous PHP callbacks must be bounded; polling callbacks must return immediately.
     */
    public function transact(array $input, ?CancellationToken $external = null, array $options = []): array
    {
        if ($this->currentState !== 'idle' || $this->operation) throw new SitefException('SESSION_BUSY', "Sessão em {$this->currentState}.");
        Validation::input($input);
        if ($external?->isCancelled()) throw new SitefException('ABORTED', 'Cancelado antes do início.');
        $method = $options['nativeStart']['method'] ?? 'start';
        if (!in_array($method, ['start', 'startBanking', 'readSecureCard', 'readTrack3', 'readPassword', 'readChip'], true)) throw new SitefException('INVALID_ARGUMENT', 'Entrypoint de início inválido.');
        if (isset($options['requiresFinalization']) && !is_bool($options['requiresFinalization'])) throw new SitefException('INVALID_ARGUMENT', 'requiresFinalization deve ser boolean.');
        $this->operation = true; $this->cancellation = new CancellationToken();
        $this->record = ['version' => 1, 'id' => bin2hex(random_bytes(16)), 'storeId' => $this->configuration['storeId'], 'terminalId' => $this->configuration['terminalId'], 'functionId' => $input['functionId'], 'entryPoint' => $method, 'fiscalDocument' => $input['fiscalDocument'], 'fiscalDate' => $input['fiscalDate'], 'fiscalTime' => $input['fiscalTime'], 'state' => 'started', 'updatedAt' => gmdate(DATE_ATOM)];
        $fields = []; $started = false; $interactionFailure = null;
        try {
            $this->save('started');
            $this->currentState = 'running';
            $started = true;
            $parameters = $options['nativeStart']['params'] ?? [...$input, 'additionalParameters' => $input['additionalParameters'] ?? ''];
            if (!is_array($parameters)) throw new SitefException('INVALID_ARGUMENT', 'Parâmetros nativos inválidos.');
            if ($method === 'startBanking') $parameters = [...$parameters, 'fiscalDocument' => $input['fiscalDocument'], 'fiscalDate' => $input['fiscalDate'], 'fiscalTime' => $input['fiscalTime'], 'operator' => $input['operator']];
            $status = Validation::status($this->transport->call($method, $parameters));
            $response = []; $steps = 0;
            while ($status === 10000) {
                if (++$steps > $this->maxSteps) throw new SitefException('LOOP_LIMIT', 'Limite de passos atingido; reconciliar antes de prosseguir.', true);
                if ($external?->isCancelled()) $this->cancellation->cancel();
                $cancelled = $this->cancellation->isCancelled() || $interactionFailure !== null;
                $parameters = ['buffer' => $cancelled ? '' : ($response['buffer'] ?? ''), 'continueFlag' => $cancelled ? -1 : ($response['continueFlag'] ?? 0), 'bufferSize' => $this->bufferSize];
                if (!$cancelled && isset($response['bufferBase64'])) $parameters['bufferBase64'] = $response['bufferBase64'];
                $exchange = $this->transport->call('continue', $parameters);
                $status = Validation::status($exchange);
                if ($status !== 10000) break;
                foreach (['command', 'fieldType', 'minLength', 'maxLength'] as $key) if (!is_int($exchange[$key] ?? null)) throw new SitefException('INVALID_NATIVE_RESULT', 'Continuação nativa malformada.', true);
                if (!is_string($exchange['buffer'] ?? null)) throw new SitefException('INVALID_NATIVE_RESULT', 'Buffer nativo malformado.', true);
                if (isset($exchange['bufferBase64']) && !is_string($exchange['bufferBase64'])) throw new SitefException('INVALID_NATIVE_RESULT', 'Buffer binário malformado.', true);
                $request = [...$exchange, 'kind' => Catalog::commandInfo($exchange['command'])['kind']];
                $response = [];
                if ($exchange['command'] === 0) {
                    $field = ['fieldType' => $exchange['fieldType'], 'buffer' => $exchange['buffer']];
                    foreach (['bufferBase64', 'decodingError'] as $key) if (array_key_exists($key, $exchange)) $field[$key] = $exchange[$key];
                    $fields[] = $field;
                    $response = ['buffer' => $exchange['buffer']];
                    if (isset($exchange['bufferBase64'])) $response['bufferBase64'] = $exchange['bufferBase64'];
                }
                if ($cancelled) continue;
                try {
                    // PHP has no implicit event loop. This callback must not block command23.
                    if ($this->onExchange !== null) ($this->onExchange)($request, $this->cancellation);
                    if ($external?->isCancelled()) $this->cancellation->cancel();
                    if ($this->cancellation->isCancelled()) continue;
                    if ($exchange['command'] !== 0 && $exchange['command'] !== 23) $response = ($this->interaction)($request, $this->cancellation);
                    if (!is_array($response) || (isset($response['continueFlag']) && !in_array($response['continueFlag'], [-1, 0, 1, 2, 10000], true))) throw new SitefException('INVALID_RESPONSE', 'Callback retornou controle inválido.');
                    Validation::text($response['buffer'] ?? '', 'buffer');
                    if (isset($response['bufferBase64']) && (!is_string($response['bufferBase64']) || base64_decode($response['bufferBase64'], true) === false)) throw new SitefException('INVALID_RESPONSE', 'Callback retornou base64 inválido.');
                    if (($response['continueFlag'] ?? 0) === -1) $this->cancellation->cancel();
                } catch (\Throwable $error) { $interactionFailure = $error; $this->cancellation->cancel(); }
            }
            $requiresFinalization = $status === 0 && ($options['requiresFinalization'] ?? in_array($method, ['start', 'startBanking'], true));
            $this->currentState = $requiresFinalization ? 'awaiting-finalization' : 'idle';
            $this->save($requiresFinalization ? 'awaiting-finalization' : 'ended', ['status' => $status]);
            if ($interactionFailure !== null) throw new SitefException('INTERACTION_FAILED', "Interação falhou; ciclo terminou com retorno $status. Consulte state antes de finalizar.", false, $status, $interactionFailure);
            return ['status' => $status, 'transactionId' => $this->record['id'], 'requiresFinalization' => $requiresFinalization, 'fields' => $fields];
        } catch (\Throwable $error) {
            if ($started && !($error instanceof SitefException && $error->errorCode === 'INTERACTION_FAILED')) {
                $this->currentState = 'uncertain';
                try { $this->save('uncertain'); } catch (\Throwable) {}
                throw new SitefException('TRANSACTION_UNCERTAIN', 'Não é possível determinar o resultado. Não repetir; reconciliar pendências.', true, null, $error);
            }
            throw $error;
        } finally { $this->cancellation = null; $this->operation = false; }
    }

    public function finalize(bool $confirm, ?string $additionalParameters = null): array
    {
        if ($this->currentState !== 'awaiting-finalization' || $this->operation) throw new SitefException('INVALID_STATE', 'Não há transação aguardando decisão.');
        return $this->finish($confirm, $additionalParameters);
    }
    public function finalizeRecovery(bool $confirm, string $expectedTransactionId, ?string $additionalParameters = null): array
    {
        if ($this->currentState !== 'uncertain' || $this->operation || ($this->record['id'] ?? null) !== $expectedTransactionId) throw new SitefException('INVALID_RECOVERY', 'Reconciliação exige estado incerto e id correspondente ao journal.');
        if (isset($this->record['decision']) && $this->record['decision'] !== ($confirm ? 1 : 0)) throw new SitefException('RECOVERY_DECISION_MISMATCH', 'A decisão persistida diverge.');
        if ($additionalParameters !== null && isset($this->record['finalizationParameters']) && $additionalParameters !== $this->record['finalizationParameters']) throw new SitefException('RECOVERY_SCOPE_MISMATCH', 'Escopo de finalização diverge do persistido.');
        return $this->finish($confirm, $additionalParameters ?? ($this->record['finalizationParameters'] ?? null));
    }
    private function finish(bool $confirm, ?string $additionalParameters): array
    {
        Validation::text($additionalParameters ?? '', 'additionalParameters');
        $this->operation = true;
        try {
            $this->save('finalizing', ['decision' => $confirm ? 1 : 0, 'finalizationParameters' => $additionalParameters ?? '']);
            $record = $this->record;
            $parameters = ['confirm' => $confirm ? 1 : 0, 'fiscalDocument' => $record['fiscalDocument'], 'fiscalDate' => $record['fiscalDate'], 'fiscalTime' => $record['fiscalTime']];
            if ($additionalParameters !== null) $parameters['additionalParameters'] = $additionalParameters;
            $result = $this->transport->call('finish', $parameters);
            if (($result['returned'] ?? null) !== true) throw new SitefException('INVALID_NATIVE_RESULT', 'Finalização não retornou localmente.', true);
            $this->save('finalized-local'); $this->currentState = 'idle';
            return ['returned' => true, 'remoteConfirmation' => 'not-reported'];
        } catch (\Throwable $error) {
            $this->currentState = 'uncertain'; try { $this->save('uncertain'); } catch (\Throwable) {}
            throw new SitefException('FINALIZATION_UNCERTAIN', 'Falha ao finalizar; reconciliar antes de prosseguir.', true, null, $error);
        } finally { $this->operation = false; }
    }
    public function callNative(string $method, array $parameters = []): array
    {
        if ($this->operation || !in_array($this->currentState, ['idle', 'uncertain'], true)) throw new SitefException('SESSION_BUSY', 'Rotina auxiliar indisponível durante ciclo/decisão.');
        if ($this->currentState === 'uncertain' && !in_array($method, ['pendingCount', 'version', 'capabilities'], true)) throw new SitefException('RECOVERY_REQUIRED', 'Somente consultas de recuperação permitidas em estado incerto.');
        if (in_array($method, ['load', 'configure', 'configureEx', 'start', 'continue', 'finish', 'startBanking', 'readSecureCard', 'readTrack3', 'readPassword', 'readChip', 'readChipEx'], true)) throw new SitefException('RESERVED_METHOD', 'Use operações da sessão para esta rotina.');
        $this->operation = true;
        try { return $this->transport->call($method, $parameters); }
        finally { $this->operation = false; }
    }
    public function validateBarcode(string $data): array
    {
        Validation::text($data, 'data');
        if (!in_array($this->currentState, ['idle', 'running'], true)) throw new SitefException('INVALID_STATE', 'Validação indisponível neste estado.');
        return $this->transport->call('validateBarcode', ['data' => $data]);
    }
    private function save(string $state, array $patch = []): void
    {
        $this->record = [...$this->record, ...$patch, 'state' => $state, 'updatedAt' => gmdate(DATE_ATOM)];
        $this->journal->write($this->record);
    }
    public function close(): void
    {
        if ($this->operation || $this->currentState === 'running') throw new SitefException('SESSION_BUSY', 'Cancele cooperativamente e aguarde o ciclo antes de fechar.');
        if ($this->currentState === 'awaiting-finalization') throw new SitefException('FINALIZATION_REQUIRED', 'Decida confirmar/desfazer antes de fechar.');
        $this->transport->close(); $this->journal->release(); $this->currentState = 'closed';
    }
}
