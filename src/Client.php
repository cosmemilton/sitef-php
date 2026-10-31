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
    private array $runtimePayments = [];
    private function __construct(private readonly Transport $transport, private readonly array $configuration, private readonly Journal $journal, callable $interaction, ?callable $onExchange, private readonly int $bufferSize, private readonly int $maxSteps, private readonly array $semanticOptions)
    {
        $this->interaction = \Closure::fromCallable($interaction); $this->onExchange = $onExchange === null ? null : \Closure::fromCallable($onExchange);
    }
    public static function connect(Transport $transport, array $configuration, Journal $journal, callable $interaction, ?callable $onExchange = null, int $bufferSize = 20000, int $maxSteps = 100000, array $semanticOptions = []): self
    {
        foreach (['server', 'storeId', 'terminalId'] as $key) Validation::text($configuration[$key] ?? null, $key);
        Validation::text($configuration['additionalParameters'] ?? '', 'additionalParameters');
        if ($configuration['server'] === '' || !preg_match('/^\d{8}$/D', $configuration['storeId']) || !preg_match('/^[A-Za-z0-9]{8}$/D', $configuration['terminalId']) || ($configuration['reserved'] ?? 0) !== 0) throw new SitefException('INVALID_CONFIGURATION', 'Servidor, loja, terminal ou reservado inválido.');
        if ($bufferSize < 20000 || $bufferSize > 1048576 || $maxSteps < 1) throw new SitefException('INVALID_OPTIONS', 'bufferSize ou maxSteps inválido.');
        $client = new self($transport, $configuration, $journal, $interaction, $onExchange, $bufferSize, $maxSteps, $semanticOptions);
        $journal->acquire();
        try {
            $saved = $journal->read(); $client->record = $saved === null ? null : JournalRecord::normalize($saved);
            if ($client->record !== null && ($client->record['storeId'] !== $configuration['storeId'] || $client->record['terminalId'] !== $configuration['terminalId'])) throw new SitefException('JOURNAL_TERMINAL_MISMATCH', 'Journal pertence a outro terminal.');
            $status = Validation::status($transport->call('configure', [...$configuration, 'reserved' => 0, 'additionalParameters' => $configuration['additionalParameters'] ?? '']));
            if ($status !== 0) throw new SitefException('CONFIGURATION_FAILED', "CliSiTef recusou a configuração ($status).", false, $status);
            if ($client->record !== null && $client->pendingPayments() !== []) $client->currentState = 'uncertain';
            return $client;
        } catch (\Throwable $error) { try { $journal->release(); } finally { $transport->close(); } throw $error; }
    }
    public function state(): string { return $this->currentState; }
    public function recovery(): ?array { return $this->record; }
    public function capabilities(): array { return $this->transport->call('capabilities'); }
    public function cancel(): void { $this->cancellation?->cancel(); }
    public function appendPayment(array $input, ?CancellationToken $external = null, array $options = []): array { return $this->transact($input, $external, [...$options, 'appendPayment' => true]); }
    public function runFunction(int $functionId, array $input = [], ?CancellationToken $external = null, array $options = []): array
    {
        if ($this->operation || $this->currentState !== 'idle') throw new SitefException('SESSION_BUSY', 'Rotina não financeira exige sessão ociosa; pendências usam queryPendingTransactions.');
        $override = $options['finalizationPolicy'] ?? (isset($options['requiresFinalization']) ? ($options['requiresFinalization'] ? 'financial' : 'nonfinancial') : null);
        $function = Semantics::function($functionId, $override);
        if ($function['finalizationPolicy'] !== 'nonfinancial') throw new SitefException('FINANCIAL_OPERATION_REQUIRED', 'Função financeira exige transact com identidade fiscal completa.');
        $input = [...['amount' => '', 'fiscalDocument' => '', 'fiscalDate' => '', 'fiscalTime' => '', 'operator' => '', 'additionalParameters' => ''], ...$input, 'functionId' => $functionId];
        foreach (['amount', 'fiscalDocument', 'fiscalDate', 'fiscalTime', 'operator', 'additionalParameters'] as $key) Validation::text($input[$key], $key);
        if ($external?->isCancelled()) throw new SitefException('ABORTED', 'Cancelado antes do início.');
        $this->operation = true; $this->cancellation = new CancellationToken();
        try {
            $result = $this->cycle($input, $function, $external, $options);
            if ($result['interactionFailure'] !== null) throw new SitefException('INTERACTION_FAILED', 'Interação da rotina falhou.', false, $result['status'], $result['interactionFailure']);
            unset($result['interactionFailure']); return [...$result, 'requiresFinalization' => false, 'confirmationAllowed' => !$result['semantics']['confirmationBlocked']];
        } finally { $this->operation = false; $this->cancellation = null; }
    }

    public function transact(array $input, ?CancellationToken $external = null, array $options = []): array
    {
        if ($this->operation || !in_array($this->currentState, ['idle', 'awaiting-finalization'], true)) throw new SitefException('SESSION_BUSY', "Sessão em {$this->currentState}.");
        Validation::input($input);
        if ($external?->isCancelled()) throw new SitefException('ABORTED', 'Cancelado antes do início.');
        $override = $options['finalizationPolicy'] ?? (array_key_exists('requiresFinalization', $options) ? ($options['requiresFinalization'] === true ? 'financial' : ($options['requiresFinalization'] === false ? 'nonfinancial' : 'invalid')) : null);
        $method = $options['nativeStart']['method'] ?? 'start';
        if (!in_array($method, ['start', 'startBanking', 'readSecureCard', 'readTrack3', 'readPassword', 'readChip'], true)) throw new SitefException('INVALID_ARGUMENT', 'Entrypoint inválido.');
        $effectiveFunction = $method === 'startBanking' ? 310 : ($method === 'start' ? $input['functionId'] : ($method === 'readChip' ? 431 : 430));
        $function = Semantics::function($effectiveFunction, $override);
        if ($method === 'startBanking') $function = [...$function, 'domain' => 'banking', 'receiptPolicy' => 'immediate'];
        $financial = $function['finalizationPolicy'] === 'financial'; $append = $options['appendPayment'] ?? false;
        if (!is_bool($append)) throw new SitefException('INVALID_ARGUMENT', 'appendPayment deve ser boolean.');
        if ($this->currentState === 'awaiting-finalization' && (!$append || !$financial)) throw new SitefException('APPEND_REQUIRED', 'Há pagamentos pendentes; use appendPayment ou finalize o grupo.');
        if ($append && ($this->currentState !== 'awaiting-finalization' || !$financial)) throw new SitefException('NO_PENDING_GROUP', 'appendPayment exige grupo financeiro pendente.');
        if ($append) foreach ($this->pendingPayments() as $payment) if ($payment['fiscalDate'] === $input['fiscalDate'] && $payment['fiscalDocument'] !== $input['fiscalDocument']) throw new SitefException('FISCAL_GROUP_MISMATCH', 'Outro cupom na mesma data exige decisão do grupo atual.');
        $id = bin2hex(random_bytes(16));
        if ($financial) {
            $payment = ['id' => $id, 'functionId' => $input['functionId'], 'entryPoint' => $method, 'fiscalDocument' => $input['fiscalDocument'], 'fiscalDate' => $input['fiscalDate'], 'fiscalTime' => $input['fiscalTime'], 'state' => 'started', 'receipts' => []];
            if (!$append) $this->record = ['version' => 2, 'id' => $id, 'storeId' => $this->configuration['storeId'], 'terminalId' => $this->configuration['terminalId'], 'fiscalDocument' => $input['fiscalDocument'], 'fiscalDate' => $input['fiscalDate'], 'fiscalTime' => $input['fiscalTime'], 'functionId' => $input['functionId'], 'state' => 'started', 'updatedAt' => gmdate(DATE_ATOM), 'payments' => []];
            $this->record['payments'][] = $payment;
        }
        $this->operation = true; $this->cancellation = new CancellationToken(); $started = false;
        try {
            if ($financial) $this->save('started'); // Before every first/append native start.
            $this->currentState = 'running'; $started = true;
            $result = $this->cycle($input, $function, $external, [...$options, 'paymentId' => $id], $financial ? function (array $summary) use ($id, $input): void { $this->updatePaymentMetadata($id, $summary); $this->runtimePayments[$id] = ['input' => $input, 'summary' => $summary]; } : null);
            $status = $result['status']; $summary = $result['semantics'];
            if ($financial) {
                $this->updatePaymentMetadata($id, $summary);
                $index = $this->paymentPosition($id); $this->record['payments'][$index]['status'] = $status;
                $this->record['payments'][$index]['state'] = $status === 0 ? 'awaiting-finalization' : 'ended';
                if ($result['interactionFailure'] !== null || $summary['confirmationBlocked']) { $this->record['payments'][$index]['confirmationBlocked'] = true; $this->record['payments'][$index]['confirmationAllowed'] = false; }
                else $this->record['payments'][$index]['confirmationAllowed'] = true;
                $this->runtimePayments[$id] = ['input' => $input, 'summary' => $summary, 'hooks' => [...($this->semanticOptions['hooks'] ?? []), ...($options['hooks'] ?? [])]];
                $this->currentState = $this->pendingPayments() === [] ? 'idle' : 'awaiting-finalization';
                $this->save($this->currentState === 'idle' ? 'ended' : 'awaiting-finalization');
                if ($status !== 0) unset($this->runtimePayments[$id]);
            } else $this->currentState = 'idle';
            if ($result['interactionFailure'] !== null) throw new SitefException('INTERACTION_FAILED', "Interação falhou; ciclo terminou com retorno$status. Estado financeiro preservado.", false, $status, $result['interactionFailure']);
            unset($result['interactionFailure']);
            return [...$result, 'transactionId' => $id, 'groupId' => $financial ? $this->record['id'] : null, 'requiresFinalization' => $financial && $status === 0, 'confirmationAllowed' => !$summary['confirmationBlocked']];
        } catch (\Throwable $error) {
            if ($started && !($error instanceof SitefException && $error->errorCode === 'INTERACTION_FAILED')) {
                $this->currentState = 'uncertain';
                if ($financial) { $this->record['payments'][$this->paymentPosition($id)]['state'] = 'uncertain'; try { $this->save('uncertain'); } catch (\Throwable) {} }
                throw new SitefException('TRANSACTION_UNCERTAIN', 'Operação incerta; não repetir, reconciliar pendências.', true, null, $error);
            }
            if (!$started) $this->currentState = $append ? 'awaiting-finalization' : 'idle';
            throw $error;
        } finally { $this->cancellation = null; $this->operation = false; }
    }

    private function cycle(array $input, array $function, ?CancellationToken $external, array $options, ?callable $metadata = null): array
    {
        $semantic = [...$this->semanticOptions, ...$options];
        if (isset($options['hooks'])) $semantic['hooks'] = [...($this->semanticOptions['hooks'] ?? []), ...$options['hooks']];
        if (in_array($options['nativeStart']['method'] ?? '', ['readSecureCard', 'readChip', 'readTrack3', 'readPassword'], true)) $semantic['schemaContext'] = 'secure-card-read';
        $processor = new FieldProcessor($input, $function, $semantic);
        $rawFields = []; $failure = null; $method = $options['nativeStart']['method'] ?? 'start';
        $parameters = $options['nativeStart']['params'] ?? [...$input, 'additionalParameters' => $input['additionalParameters'] ?? ''];
        if (!is_array($parameters)) throw new SitefException('INVALID_ARGUMENT', 'Parâmetros nativos inválidos.');
        if ($method === 'startBanking') $parameters = [...$parameters, 'fiscalDocument' => $input['fiscalDocument'], 'fiscalDate' => $input['fiscalDate'], 'fiscalTime' => $input['fiscalTime'], 'operator' => $input['operator']];
        $status = Validation::status($this->transport->call($method, $parameters)); $response = []; $steps = 0;
        try {
            while ($status === 10000) {
                if (++$steps > $this->maxSteps) throw new SitefException('LOOP_LIMIT', 'Limite de passos atingido.', true);
                if ($external?->isCancelled()) $this->cancellation->cancel();
                $cancelled = $this->cancellation->isCancelled() || $failure !== null;
                $parameters = ['buffer' => $cancelled ? '' : ($response['buffer'] ?? ''), 'continueFlag' => $cancelled ? -1 : ($response['continueFlag'] ?? 0), 'bufferSize' => $this->bufferSize];
                if (!$cancelled && isset($response['bufferBase64'])) $parameters['bufferBase64'] = $response['bufferBase64'];
                $exchange = $this->transport->call('continue', $parameters); $status = Validation::status($exchange);
                if ($status !== 10000) break;
                foreach (['command', 'fieldType', 'minLength', 'maxLength'] as $key) if (!is_int($exchange[$key] ?? null)) throw new SitefException('INVALID_NATIVE_RESULT', 'Continuação nativa malformada.', true);
                if (!is_string($exchange['buffer'] ?? null)) throw new SitefException('INVALID_NATIVE_RESULT', 'Buffer nativo malformado.', true);
                $request = [...$exchange, 'kind' => Catalog::commandInfo($exchange['command'])['kind']]; $response = [];
                if ($exchange['command'] === 0) {
                    $field = ['fieldType' => $exchange['fieldType'], 'buffer' => $exchange['buffer']];
                    foreach (['bufferBase64', 'decodingError'] as $key) if (array_key_exists($key, $exchange)) $field[$key] = $exchange[$key];
                    $rawFields[] = $field; $response = ['buffer' => $exchange['buffer']]; if (isset($exchange['bufferBase64'])) $response['bufferBase64'] = $exchange['bufferBase64'];
                }
                if ($cancelled) continue;
                try {
                    $request = $processor->observe($request, $this->cancellation);
                    if ($metadata !== null) $metadata($processor->snapshot());
                    if ($this->onExchange !== null) ($this->onExchange)($request, $this->cancellation);
                    if ($external?->isCancelled()) $this->cancellation->cancel();
                    if ($this->cancellation->isCancelled()) continue;
                    if ($exchange['command'] === 29 && $processor->hasApplicationData()) $response = $processor->applicationData($request, $this->cancellation);
                    elseif ($exchange['command'] !== 0 && $exchange['command'] !== 23) $response = ($this->interaction)($request, $this->cancellation);
                    if (!is_array($response) || (isset($response['continueFlag']) && !in_array($response['continueFlag'], [-1, 0, 1, 2, 10000], true))) throw new SitefException('INVALID_RESPONSE', 'Callback retornou controle inválido.');
                    Validation::text($response['buffer'] ?? '', 'buffer');
                    if ($exchange['command'] !== 0 && $exchange['command'] !== 23) $response = $processor->validateResponse($request, $response, $this->cancellation);
                    if ($exchange['command'] !== 0 && array_key_exists('bufferBase64', $response)) {
                        $raw = is_string($response['bufferBase64']) ? base64_decode($response['bufferBase64'], true) : false;
                        if ($raw === false || $raw !== Validation::encode($response['buffer'] ?? '', $semantic['nativeEncoding'] ?? 'windows-1252')) throw new SitefException('INVALID_BINARY_RESPONSE', 'Base64 da coleta deve coincidir com o texto validado na codificação nativa.');
                    }
                    if (($response['continueFlag'] ?? 0) === -1) $this->cancellation->cancel();
                } catch (\Throwable $error) { $failure = $error; $this->cancellation->cancel(); }
            }
            try { $summary = $processor->finish($status, $this->cancellation); }
            catch (\Throwable $error) { $failure ??= $error; $summary = [...$processor->snapshot(), 'confirmationBlocked' => true]; }
            if ($failure !== null) $summary['confirmationBlocked'] = true;
            return ['status' => $status, 'fields' => $rawFields, 'assessment' => $summary, 'semantics' => $summary, 'interactionFailure' => $failure];
        } finally { $processor->cleanup($this->cancellation); }
    }

    public function queryPendingTransactions(?array $fiscal = null, ?CancellationToken $external = null): array
    {
        if ($this->operation || !in_array($this->currentState, ['idle', 'awaiting-finalization', 'uncertain'], true)) throw new SitefException('SESSION_BUSY', 'Consulta indisponível.');
        $input = ['functionId' => $fiscal === null ? 130 : 131, 'amount' => '', 'fiscalDocument' => $fiscal['fiscalDocument'] ?? '', 'fiscalDate' => $fiscal['fiscalDate'] ?? '', 'fiscalTime' => '000000', 'operator' => '', 'additionalParameters' => ''];
        if ($fiscal !== null) Validation::input($input);
        if ($external?->isCancelled()) throw new SitefException('ABORTED', 'Consulta cancelada antes de iniciar.');
        $previous = $this->currentState; $this->operation = true; $this->cancellation = new CancellationToken();
        try {
            $result = $this->cycle($input, Semantics::function($input['functionId']), $external, []);
            if ($result['interactionFailure'] !== null) throw new SitefException('QUERY_FAILED', 'Interação da consulta falhou.', false, $result['status'], $result['interactionFailure']);
            $parsed = $result['status'] === 0 ? PendingTransactions::parse($result['fields']) : ['count' => null, 'rawCount' => null, 'records' => [], 'transactions' => []];
            return ['status' => $result['status'], ...$parsed, 'fields' => $result['fields']];
        } finally { $this->operation = false; $this->cancellation = null; $this->currentState = $previous; }
    }

    public function finalize(bool $confirm, ?string $additionalParameters = null): array
    {
        $this->assertFinalizable(false); $this->rejectSelectors($additionalParameters);
        return $this->finishPlans($confirm, $this->plans($this->pendingPayments(), $additionalParameters ?? ''), false);
    }
    public function finalizePayment(bool $confirm, string $paymentId, ?string $additionalParameters = null): array
    {
        $this->assertFinalizable(false); $this->rejectSelectors($additionalParameters); $payment = $this->pendingPayment($paymentId);
        if (!isset($payment['paymentIndex'])) throw new SitefException('PAYMENT_INDEX_REQUIRED', 'Finalização individual exige161.');
        $matching = array_filter($this->pendingPayments(), static fn($other) => $other['fiscalDocument'] === $payment['fiscalDocument'] && $other['fiscalDate'] === $payment['fiscalDate'] && ($other['paymentIndex'] ?? null) === $payment['paymentIndex']);
        if (count($matching) !== 1) throw new SitefException('PAYMENT_INDEX_AMBIGUOUS', 'Índice161 duplicado no cupom/data.');
        return $this->finishPlans($confirm, $this->plans([$payment], ($additionalParameters ?? '') . '{NumeroPagamentoCupom=' . $payment['paymentIndex'] . '}'), false);
    }
    public function finalizeNetwork(bool $confirm, string $network, ?string $additionalParameters = null): array
    {
        $this->assertFinalizable(false); $this->rejectSelectors($additionalParameters);
        if (!ctype_digit($network)) throw new SitefException('INVALID_NETWORK', 'Rede deve ser texto numérico.');
        $payments = $this->pendingPayments();
        foreach ($payments as $payment) if (!isset($payment['network'])) throw new SitefException('NETWORK_RESOLUTION_REQUIRED', 'Rede de confirmação exige configuração131/158 ou resolvedor explícito.');
        $selected = array_values(array_filter($payments, static fn($payment) => (ltrim($payment['network'], '0') ?: '0') === (ltrim($network, '0') ?: '0')));
        if ($selected === []) throw new SitefException('NETWORK_NOT_PENDING', 'Nenhum pagamento pendente dessa rede.');
        return $this->finishPlans($confirm, $this->plans($selected, ($additionalParameters ?? '') . '{RedeConfirmacao=' . $network . '}'), false);
    }
    public function finalizeWithPayments(bool $confirm, string $paymentId, ?array $payments = null, ?string $expectedTotal = null, ?string $additionalParameters = null): array
    {
        $this->assertFinalizable(false); $this->rejectSelectors($additionalParameters); $target = $this->pendingPayment($paymentId);
        if (!isset($target['paymentIndex'])) throw new SitefException('PAYMENT_INDEX_REQUIRED', 'NFPAG exige161.');
        $sameIndex = array_filter($this->pendingPayments(), static fn($other) => $other['fiscalDocument'] === $target['fiscalDocument'] && $other['fiscalDate'] === $target['fiscalDate'] && ($other['paymentIndex'] ?? null) === $target['paymentIndex']);
        if (count($sameIndex) !== 1) throw new SitefException('PAYMENT_INDEX_AMBIGUOUS', 'NFPAG exige161 único na partição fiscal.');
        $this->operation = true;
        try {
        $runtime = $this->runtimePayments[$paymentId] ?? null;
        $requirements = $runtime['summary']['paymentData'] ?? null;
        $hooks = [...($this->semanticOptions['hooks'] ?? []), ...($runtime['hooks'] ?? [])];
        if ($expectedTotal === null) throw new SitefException('NFPAG_TOTAL_REQUIRED', 'NFPAG exige total explícito; o SDK não presume valor inicial ou escala após crash.');
        if ($payments === null) {
            if (!isset($hooks['paymentData'])) throw new SitefException('NFPAG_METHODS_REQUIRED', 'Forneça métodos explícitos ou hook paymentData.');
            $provided = $hooks['paymentData']($requirements, ['payment' => $target, 'expectedTotal' => $expectedTotal]);
            if (!is_array($provided) || !array_is_list($provided)) throw new SitefException('INVALID_NFPAG_PROVIDER', 'paymentData deve devolver lista de métodos.');
            $payments = $provided;
        }
        if ($requirements === null) throw new SitefException('NFPAG_RECOVERY_DATA_REQUIRED', 'Requisitos NFPAG não estão disponíveis; não reconstruir do nada após crash.');
        $total = $expectedTotal;
        $extra = ($additionalParameters ?? '') . PaymentData::build($target['paymentIndex'], $requirements, $payments, $total);
        // NumeroPagamentoNFPAG identifies attachment target, but FFI confirms the entire document/date.
        $selected = array_values(array_filter($this->pendingPayments(), static fn($payment) => $payment['fiscalDocument'] === $target['fiscalDocument'] && $payment['fiscalDate'] === $target['fiscalDate']));
        $payload = ['id' => $this->record['id'] . ':nfpag:' . bin2hex(random_bytes(12)), 'hash' => hash('sha256', $extra)];
        if (!isset($hooks['storeFinalizationPayload']) || $hooks['storeFinalizationPayload']([...$payload, 'parameters' => $extra], ['payment' => $target]) !== true) throw new SitefException('FINALIZATION_PAYLOAD_STORAGE_REQUIRED', 'NFPAG exige armazenamento externo confirmado antes da FFI.');
        $plans = $this->plans($selected, ($additionalParameters ?? '') . '{NumeroPagamentoNFPAG=' . $target['paymentIndex'] . '}');
        foreach ($plans as &$plan) { $plan['payload'] = $payload; $plan['wireParameters'] = $extra; } unset($plan);
        return $this->finishPlans($confirm, $plans, false);
        } finally { $this->operation = false; }
    }
    public function finalizeRecovery(bool $confirm, string $expectedTransactionId, ?string $additionalParameters = null): array
    {
        $this->assertFinalizable(true);
        if ($this->record['id'] !== $expectedTransactionId) throw new SitefException('INVALID_RECOVERY', 'ID do grupo não corresponde ao journal.');
        $pending = $this->pendingPayments(); $decided = array_values(array_filter($pending, static fn($payment) => isset($payment['decision'])));
        if ($decided !== []) {
            $plans = [];
            foreach ($decided as $payment) {
                if ($payment['decision'] !== ($confirm ? 1 : 0)) throw new SitefException('RECOVERY_DECISION_MISMATCH', 'Decisão persistida diverge.');
                $stored = $payment['finalizationParameters'] ?? '';
                if ($additionalParameters !== null && $additionalParameters !== $stored) throw new SitefException('RECOVERY_SCOPE_MISMATCH', 'Escopo persistido diverge.');
                $key = $payment['fiscalDocument'] . "\0" . $payment['fiscalDate'] . "\0" . $stored;
                if (!isset($plans[$key])) { $plans[$key] = ['payments' => [], 'parameters' => $stored]; if (isset($payment['finalizationPayload'])) $plans[$key]['payload'] = $payment['finalizationPayload']; } $plans[$key]['payments'][] = $payment;
            }
            return $this->finishPlans($confirm, array_values($plans), true);
        }
        $this->rejectSelectors($additionalParameters);
        return $this->finishPlans($confirm, $this->plans($pending, $additionalParameters ?? ''), true);
    }

    private function finishPlans(bool $confirm, array $plans, bool $recovery): array
    {
        if ($plans === []) throw new SitefException('INVALID_STATE', 'Não há pagamentos para essa decisão.');
        foreach ($plans as $plan) foreach ($plan['payments'] as $payment) {
            if ($confirm && ($payment['confirmationBlocked'] ?? false) && !($payment['legacyReceiptStateUnknown'] ?? false)) throw new SitefException('CONFIRMATION_BLOCKED', 'Validação de negócio impede confirmar; desfazer continua disponível.');
            if (isset($payment['decision']) && $payment['decision'] !== ($confirm ? 1 : 0)) throw new SitefException('RECOVERY_DECISION_MISMATCH', 'Decisão persistida diverge.');
            if (isset($payment['finalizationParameters']) && $payment['finalizationParameters'] !== $plan['parameters']) throw new SitefException('RECOVERY_SCOPE_MISMATCH', 'Escopo de decisão persistido diverge.');
        }
        $this->operation = true; $finalized = []; $intentPersisted = false;
        try {
            foreach ($plans as &$plan) {
                if (!isset($plan['payload'])) continue;
                if (!isset($plan['wireParameters'])) {
                    $loader = $this->semanticOptions['hooks']['loadFinalizationPayload'] ?? null;
                    if ($loader === null) throw new SitefException('FINALIZATION_PAYLOAD_LOAD_REQUIRED', 'Recuperação NFPAG exige loadFinalizationPayload.');
                    $plan['wireParameters'] = $loader($plan['payload'], ['payments' => $plan['payments'], 'recovery' => $recovery]);
                }
                if (!is_string($plan['wireParameters']) || !hash_equals($plan['payload']['hash'], hash('sha256', $plan['wireParameters']))) throw new SitefException('FINALIZATION_PAYLOAD_HASH_MISMATCH', 'Payload NFPAG recuperado diverge do persistido.');
                if (self::safePayloadScope($plan['wireParameters']) !== $plan['parameters']) throw new SitefException('RECOVERY_SCOPE_MISMATCH', 'Seletor NFPAG recuperado diverge do plano persistido.');
            } unset($plan);
            if ($confirm) foreach ($plans as $plan) foreach ($plan['payments'] as $payment) $this->prepareReceipts($payment, $recovery);
            // Persist the complete multi-date intent before the first void FFI call.
            foreach ($plans as $plan) {
                foreach ($plan['payments'] as $payment) {
                    $index = $this->paymentPosition($payment['id']);
                    $this->record['payments'][$index] = [...$this->record['payments'][$index], 'state' => 'finalizing', 'decision' => $confirm ? 1 : 0, 'finalizationParameters' => $plan['parameters']];
                    if (isset($plan['payload'])) $this->record['payments'][$index]['finalizationPayload'] = $plan['payload'];
                }
            }
            $intentPersisted = true; $this->save('finalizing');
            foreach ($plans as $plan) {
                $first = $plan['payments'][0];
                $reply = $this->transport->call('finish', ['confirm' => $confirm ? 1 : 0, 'fiscalDocument' => $first['fiscalDocument'], 'fiscalDate' => $first['fiscalDate'], 'fiscalTime' => $first['fiscalTime'], 'additionalParameters' => $plan['wireParameters'] ?? $plan['parameters']]);
                if (($reply['returned'] ?? null) !== true) throw new SitefException('INVALID_NATIVE_RESULT', 'Finalização sem retorno local.', true);
                foreach ($plan['payments'] as $payment) $this->record['payments'][$this->paymentPosition($payment['id'])]['state'] = 'finalized-local';
                $hasPlan = array_filter($this->record['payments'], static fn($payment) => $payment['state'] === 'finalizing') !== [];
                try { $this->save($hasPlan ? 'finalizing' : ($this->pendingPayments() === [] ? 'finalized-local' : 'awaiting-finalization')); }
                catch (\Throwable $error) {
                    // A local return without a durable acknowledgment remains uncertain.
                    foreach ($plan['payments'] as $payment) $this->record['payments'][$this->paymentPosition($payment['id'])]['state'] = 'uncertain';
                    throw $error;
                }
                foreach ($plan['payments'] as $payment) { $finalized[] = $payment['id']; unset($this->runtimePayments[$payment['id']]); }
            }
            $this->currentState = $this->pendingPayments() === [] ? 'idle' : 'awaiting-finalization';
            return ['returned' => true, 'remoteConfirmation' => 'not-reported', 'finalizedPaymentIds' => $finalized, 'remainingPaymentIds' => array_column($this->pendingPayments(), 'id')];
        } catch (\Throwable $error) {
            if (!$intentPersisted) throw $error;
            foreach ($this->record['payments'] as &$payment) if ($payment['state'] === 'finalizing') $payment['state'] = 'uncertain'; unset($payment);
            $this->currentState = 'uncertain'; try { $this->save('uncertain'); } catch (\Throwable) {}
            throw new SitefException('FINALIZATION_UNCERTAIN', 'Falha ao finalizar; decisões/escopos conservados, não repetir automaticamente.', true, null, $error);
        } finally { $this->operation = false; }
    }
    private function prepareReceipts(array $payment, bool $recovery): void
    {
        $hooks = [...($this->semanticOptions['hooks'] ?? []), ...($this->runtimePayments[$payment['id']]['hooks'] ?? [])]; $index = $this->paymentPosition($payment['id']);
        if ($payment['legacyReceiptStateUnknown'] ?? false) {
            if (!isset($hooks['attestLegacyObligations']) || $hooks['attestLegacyObligations']($payment, ['recovery' => $recovery]) !== true) throw new SitefException('LEGACY_RECEIPT_MIGRATION_REQUIRED', 'V1 exige attestLegacyObligations para recibos, assinatura e validação de negócio.');
            $this->record['payments'][$index]['legacyReceiptStateUnknown'] = false;
            $this->record['payments'][$index]['confirmationAllowed'] = true;
            $this->record['payments'][$index]['confirmationBlocked'] = false;
            $this->save($this->record['state']); $payment = $this->record['payments'][$index];
        }
        foreach ($payment['receipts'] ?? [] as $position => $metadata) {
            $receipt = null;
            foreach ($this->runtimePayments[$payment['id']]['summary']['receipts'] ?? [] as $candidate) if ($candidate['id'] === $metadata['id']) $receipt = $candidate;
            if ((!$metadata['stored'] || !$metadata['printed']) && $receipt === null) {
                if (!isset($hooks['loadReceipt'])) throw new SitefException('RECEIPT_LOAD_REQUIRED', 'Recuperação exige loadReceipt por ID/hash antes de confirmar.');
                $loaded = $hooks['loadReceipt']($metadata, ['payment' => $payment, 'recovery' => $recovery]);
                if (!is_array($loaded)) throw new SitefException('INVALID_RECEIPT', 'loadReceipt deve devolver texto e/ou bytes.');
                $body = $loaded['body'] ?? $loaded['text'] ?? '';
                if (!is_string($body)) throw new SitefException('INVALID_RECEIPT', 'Corpo de comprovante recuperado inválido.');
                $receipt = [...$metadata, 'body' => $body, 'text' => $body];
                if (isset($loaded['bufferBase64'])) $receipt['bufferBase64'] = $loaded['bufferBase64'];
            }
            if ($receipt !== null && !hash_equals($metadata['hash'], self::receiptHash($receipt))) throw new SitefException('RECEIPT_HASH_MISMATCH', 'Comprovante recuperado difere do original.');
            if (!$metadata['stored']) {
                if (!isset($hooks['storeReceipt']) || $hooks['storeReceipt']($receipt, ['payment' => $payment]) !== true) throw new SitefException('RECEIPT_STORAGE_REQUIRED', 'Persistência do comprovante não foi reconhecida.');
                $this->record['payments'][$index]['receipts'][$position]['stored'] = true;
            }
            if (!$metadata['printed']) {
                if (!isset($hooks['printReceipt']) || $hooks['printReceipt']($receipt, ['payment' => $payment, 'recovery' => $recovery]) !== true) throw new SitefException('RECEIPT_PRINT_REQUIRED', 'Impressão do comprovante não foi reconhecida.');
                $this->record['payments'][$index]['receipts'][$position]['printed'] = true;
            }
            $this->save($this->record['state']);
        }
        if (($payment['signatureRequired'] ?? false) && !($payment['signatureCaptured'] ?? false)) {
            if (!isset($hooks['captureSignature']) || $hooks['captureSignature'](['payment' => $payment, 'paperRequired' => true, 'recovery' => $recovery], ['paymentId' => $payment['id'], 'payment' => $payment, 'recovery' => $recovery]) !== true) throw new SitefException('SIGNATURE_REQUIRED', 'Assinatura em papel deve ser obtida após a impressão e antes de confirmar.');
            $this->record['payments'][$index]['signatureCaptured'] = true; $this->save($this->record['state']);
        }
    }
    public function acknowledgeReceiptStored(string $receiptId): void { $this->acknowledgeReceipt($receiptId, 'stored'); }
    public function acknowledgeReceiptPrinted(string $receiptId): void { $this->acknowledgeReceipt($receiptId, 'printed'); }
    public function acknowledgeLegacyReceiptState(string $paymentId, array $receiptMetadata): void
    {
        if ($this->operation || !in_array($this->currentState, ['awaiting-finalization', 'uncertain'], true)) throw new SitefException('INVALID_STATE', 'Reconhecimento exige decisão pendente.');
        $position = $this->paymentPosition($paymentId);
        if (!($this->record['payments'][$position]['legacyReceiptStateUnknown'] ?? false)) throw new SitefException('NOT_LEGACY_RECEIPT_STATE', 'Pagamento já possui estado de recibos conhecido.');
        $this->operation = true;
        try {
        $attest = $this->semanticOptions['hooks']['attestLegacyObligations'] ?? null;
        if ($attest === null || $attest($this->record['payments'][$position], ['receiptMetadata' => $receiptMetadata, 'migration' => true]) !== true) throw new SitefException('LEGACY_RECEIPT_MIGRATION_REQUIRED', 'Reconhecimento de recibos exige attestLegacyObligations de todas as obrigações legadas.');
        $candidate = $this->record; $candidate['payments'][$position]['receipts'] = $receiptMetadata; $candidate['payments'][$position]['legacyReceiptStateUnknown'] = false;
        $candidate['payments'][$position]['confirmationAllowed'] = true; $candidate['payments'][$position]['confirmationBlocked'] = false;
        $this->record = JournalRecord::normalize($candidate); $this->save($this->record['state']);
        } finally { $this->operation = false; }
    }
    private function acknowledgeReceipt(string $receiptId, string $property): void
    {
        if ($this->operation || !in_array($this->currentState, ['awaiting-finalization', 'uncertain'], true)) throw new SitefException('INVALID_STATE', 'Não há decisão de comprovante pendente.');
        foreach ($this->record['payments'] as $p => $payment) foreach ($payment['receipts'] ?? [] as $r => $receipt) if ($receipt['id'] === $receiptId) { $this->record['payments'][$p]['receipts'][$r][$property] = true; $this->save($this->record['state']); return; }
        throw new SitefException('RECEIPT_NOT_FOUND', 'ID de comprovante desconhecido.');
    }
    private static function receiptHash(array $receipt): string
    {
        if (isset($receipt['bufferBase64'])) { $bytes = base64_decode($receipt['bufferBase64'], true); if ($bytes === false) throw new SitefException('INVALID_RECEIPT', 'Bytes de comprovante inválidos.'); }
        else $bytes = $receipt['text'] ?? $receipt['body'] ?? '';
        if (!is_string($bytes)) throw new SitefException('INVALID_RECEIPT', 'Texto de comprovante inválido.');
        return hash('sha256', $bytes);
    }
    private function updatePaymentMetadata(string $id, array $summary): void
    {
        $index = $this->paymentPosition($id); $before = $this->record['payments'][$index]; $payment = $before;
        foreach (['paymentIndex', 'network'] as $key) if (($summary['context'][$key] ?? null) !== null) $payment[$key] = $summary['context'][$key];
        if ($summary['context']['signatureRequired'] ?? false) $payment['signatureRequired'] = true;
        if ($summary['confirmationBlocked']) $payment['confirmationBlocked'] = true;
        $payment['receipts'] = [];
        foreach ($summary['receipts'] as $receipt) $payment['receipts'][] = ['id' => $receipt['id'], 'fieldType' => $receipt['fieldType'], 'type' => $receipt['receiptType'], 'timing' => $receipt['timing'], 'hash' => self::receiptHash($receipt), 'printed' => $receipt['printed'], 'stored' => $receipt['stored']];
        if ($before !== $payment) { $this->record['payments'][$index] = $payment; $this->save($this->record['state']); }
    }
    private function pendingPayments(): array { return array_values(array_filter($this->record['payments'] ?? [], static fn($payment) => !in_array($payment['state'], ['ended', 'finalized-local'], true))); }
    private function pendingPayment(string $id): array { foreach ($this->pendingPayments() as $payment) if ($payment['id'] === $id) return $payment; throw new SitefException('PAYMENT_NOT_PENDING', 'Pagamento não está pendente.'); }
    private function paymentPosition(string $id): int { foreach ($this->record['payments'] as $index => $payment) if ($payment['id'] === $id) return $index; throw new SitefException('PAYMENT_NOT_FOUND', 'Pagamento não encontrado.'); }
    private function plans(array $payments, string $parameters): array
    {
        $plans = []; foreach ($payments as $payment) { $key = $payment['fiscalDocument'] . "\0" . $payment['fiscalDate']; if (!isset($plans[$key])) $plans[$key] = ['payments' => [], 'parameters' => $parameters]; $plans[$key]['payments'][] = $payment; } return array_values($plans);
    }
    private function assertFinalizable(bool $recovery): void { if ($this->operation || $this->currentState !== ($recovery ? 'uncertain' : 'awaiting-finalization')) throw new SitefException($recovery ? 'INVALID_RECOVERY' : 'INVALID_STATE', 'Estado incompatível com a decisão.'); }
    private function rejectSelectors(?string $parameters): void
    {
        Validation::text($parameters ?? '', 'additionalParameters');
        if (preg_match('/\b(?:NumeroPagamentoCupom|RedeConfirmacao|NumeroPagamentoNFPAG|NFPAG)\s*=/i', $parameters ?? '')) throw new SitefException('TYPED_FINALIZATION_REQUIRED', 'Seletores/NFPAG exigem API tipada, nunca finalize genérico.');
    }
    private static function safePayloadScope(string $parameters): string
    {
        Validation::text($parameters, 'finalization payload');
        $scope = preg_replace('/\{NFPAG=[^{}]*\}/i', '', $parameters, -1, $count);
        if ($count !== 1 || $scope === null || preg_match('/\bNFPAG\s*=/i', $scope)) throw new SitefException('RECOVERY_SCOPE_MISMATCH', 'Payload NFPAG não corresponde ao formato do plano persistido.');
        return $scope;
    }
    private function save(string $state): void { $this->record = JournalRecord::normalize([...$this->record, 'state' => $state, 'updatedAt' => gmdate(DATE_ATOM)]); $this->journal->write($this->record); }
    public function callNative(string $method, array $parameters = []): array
    {
        if ($this->operation || !in_array($this->currentState, ['idle', 'uncertain'], true)) throw new SitefException('SESSION_BUSY', 'Rotina auxiliar indisponível.');
        if ($this->currentState === 'uncertain' && !in_array($method, ['pendingCount', 'version', 'capabilities'], true)) throw new SitefException('RECOVERY_REQUIRED', 'Somente consultas de recuperação permitidas.');
        if (in_array($method, ['load', 'configure', 'configureEx', 'start', 'continue', 'finish', 'startBanking', 'readSecureCard', 'readTrack3', 'readPassword', 'readChip', 'readChipEx'], true)) throw new SitefException('RESERVED_METHOD', 'Use a operação de sessão.');
        $this->operation = true; try { return $this->transport->call($method, $parameters); } finally { $this->operation = false; }
    }
    public function validateBarcode(string $data): array { Validation::text($data, 'data'); if (!in_array($this->currentState, ['idle', 'running'], true)) throw new SitefException('INVALID_STATE', 'Validação indisponível.'); return $this->transport->call('validateBarcode', ['data' => $data]); }
    public function close(): void
    {
        if ($this->operation || $this->currentState === 'running') throw new SitefException('SESSION_BUSY', 'Cancele e aguarde o loop antes de fechar.');
        if ($this->currentState === 'awaiting-finalization') throw new SitefException('FINALIZATION_REQUIRED', 'Decida todos os pagamentos antes de fechar.');
        try { $this->transport->close(); $this->journal->release(); $this->currentState = 'closed'; }
        finally { $this->runtimePayments = []; }
    }
}
