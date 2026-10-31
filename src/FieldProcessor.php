<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

/** Stateful interpretation for a single native cycle; business hooks never default to success. */
final class FieldProcessor
{
    private array $context;
    private array $fields = [];
    private array $receipts = [];
    private array $bills = [];
    private ?int $billPosition = null;
    private array $nfpag = ['maximum' => null, 'methods' => []];
    private array $diagnostics = [];
    private array $balances = [];
    private array $hooks;
    private array $monetaryFormats;
    private ?int $confirmationNetworkField;
    private string $encoding;
    private string $paymentId;
    private array $guards = [];
    private array $chequePayees = [];
    private array $validationIssues = [];
    private ?array $formatHint = null;
    private ?string $currentPaymentMethod = null;
    private bool $resourcesLocked = false;
    private bool $confirmationBlocked = false;
    public function __construct(private readonly array $input, private readonly array $function, array $options = [])
    {
        $this->hooks = $options['hooks'] ?? [];
        $this->encoding = $options['nativeEncoding'] ?? 'windows-1252';
        $this->paymentId = $options['paymentId'] ?? bin2hex(random_bytes(16));
        $this->monetaryFormats = $options['monetaryFormats'] ?? [];
        $this->confirmationNetworkField = $options['confirmationNetworkField'] ?? null;
        if ($this->confirmationNetworkField !== null && !in_array($this->confirmationNetworkField, [131, 158], true)) throw new SitefException('INVALID_NETWORK_FIELD', 'confirmationNetworkField deve ser131ou158.');
        $aliases = ['deviceResourceLock' => 'lockResources', 'lockTerminal' => 'lockResources', 'deviceResourceUnlock' => 'unlockResources', 'unlockTerminal' => 'unlockResources', 'collectSignature' => 'captureSignature', 'systemData' => 'resolveApplicationData', 'applicationData' => 'resolveApplicationData'];
        foreach ($aliases as $alias => $name) if (isset($this->hooks[$alias]) && !isset($this->hooks[$name])) $this->hooks[$name] = $this->hooks[$alias];
        foreach ($this->hooks as $hook) if (!is_callable($hook)) throw new SitefException('INVALID_HOOK', 'Hook semântico inválido.');
        $storage = $options['receiptStoragePolicy'] ?? 'persist';
        if (!in_array($storage, ['persist', 'explicit-return'], true)) throw new SitefException('INVALID_RECEIPT_POLICY', 'Escolha persist ou explicit-return.');
        $this->context = ['domain' => $function['domain'] ?? 'unknown', 'effectiveFunctionId' => $function['code'] ?? $input['functionId'], 'schemaContext' => $options['schemaContext'] ?? (($function['domain'] ?? '') === 'secure-card-read' ? 'secure-card-read' : 'transaction'), 'receiptTiming' => $function['receiptPolicy'] ?? 'immediate', 'receiptStoragePolicy' => $storage, 'receiptType' => null, 'paymentIndex' => null, 'network' => null, 'acquirer' => null, 'billIndex' => null, 'decimalPlaces' => 2, 'precisionAnnounced' => false, 'unitPriceDecimals' => null, 'quantityDecimals' => null, 'sensitiveSpan' => false, 'productSpan' => false, 'usesCheque' => ($function['code'] ?? $input['functionId']) === 1 ? true : null];
    }
    public function observe(array $request, CancellationToken $cancel): array
    {
        $field = $request['fieldType']; $buffer = $request['buffer']; $command = $request['command'];
        $definition = Semantics::field($field, $this->context['schemaContext']);
        if ($command === 0) {
            switch ($field) {
                case 123: $this->context['receiptType'] = $buffer; if (in_array($buffer, ['04', '05'], true)) $this->context['receiptTiming'] = 'immediate'; break;
                case 161: $this->context['paymentIndex'] = $this->integer($buffer); break;
                case 158: $this->context['authorizerNetwork'] = $this->integer($buffer); break;
                case 131: $this->context['acquirer'] = $this->integer($buffer); break;
                case 607: $this->context['billIndex'] = $this->integer($buffer); $this->bills[] = ['index' => $buffer, 'fields' => []]; $this->billPosition = count($this->bills) - 1; break;
                case 2355: $this->formatHint = $this->parseHint($buffer); break;
                case 2470: $this->context['decimalPlaces'] = $this->precision($buffer); $this->context['precisionAnnounced'] = true; break;
                case 1121: $this->context['unitPriceDecimals'] = $this->precision($buffer); break;
                case 1126: $this->context['quantityDecimals'] = $this->precision($buffer); break;
                case 5057: $this->context['sensitiveSpan'] = true; break;
                case 5058: $this->context['sensitiveSpan'] = false; break;
                case 5062: $this->context['productSpan'] = true; break;
                case 5063: $this->context['productSpan'] = false; break;
                case 5501: $this->context['domain'] = 'banking'; $this->context['receiptTiming'] = 'immediate'; break;
                case 730: $this->nfpag['maximum'] = $this->integer($buffer); break;
                case 731:
                    $this->currentPaymentMethod = $buffer;
                    $this->nfpag['methods'][] = ['type' => $buffer, 'requiredData' => [], 'allowedNetworks' => []]; break;
                case 732:
                    if ($this->currentPaymentMethod === null) throw new SitefException('INVALID_NFPAG_SEQUENCE', 'Campo732 sem forma731 anterior.');
                    $last = count($this->nfpag['methods']) - 1;
                    if (preg_match('/^13\((\d+(?:,\d+)*)\)$/D', $buffer, $networks) === 1) $this->nfpag['methods'][$last]['allowedNetworks'] = explode(',', $networks[1]);
                    else $this->nfpag['methods'][$last]['requiredData'][] = $buffer;
                    break;
                case 100: case 110: case 608:
                    if (preg_match('/^\d{4}$/D', $buffer)) $this->context['usesCheque'] = substr($buffer, 0, 2) === '00';
                    break;
                case 613: $this->context['usesCheque'] = true; break;
                case 606: $this->chequePayees[] = $request; break;
                case 1011: $this->context['medicineIndex'] = $this->integer($buffer); break;
            }
            if ($field === 5074 && ($definition['action']['phase'] ?? '') === 'beforeFinalize') $this->context['signatureRequired'] = true;
            if (in_array($field, [5042, 5043, 5010, 5074], true) && isset($definition['action']['requiredHook']) && ($definition['action']['phase'] ?? '') !== 'beforeFinalize') {
                $hook = $field === 5042 ? 'lockResources' : ($field === 5043 ? 'unlockResources' : 'captureSignature');
                if ($field === 5042 && !isset($this->hooks['unlockResources'])) throw new SitefException('REQUIRED_HOOK', 'lockResources exige também unlockResources para limpeza.');
                $this->required($hook, ['request' => $request, 'context' => $this->context, 'paperRequired' => $field === 5074], $cancel);
                if ($field === 5042) $this->resourcesLocked = true;
                if ($field === 5043) $this->resourcesLocked = false;
            }
            $typed = $this->typed($buffer, $definition, $request, $cancel);
            $record = [...$request, 'semantic' => $definition, 'value' => $typed, 'context' => $this->context];
            if (($definition['action']['phase'] ?? '') === 'beforeFinalize' && ($definition['action']['requiredHook'] ?? '') === 'validateTransaction' || in_array($field, [1042, 1043, 1044, 1122, 1123, 137, 138], true)) $this->guards[] = $record;
            $this->fields[] = $record;
            if ($this->billPosition !== null && in_array($field, [600, 601, 602, 603, 604, 605, 606, 624], true)) $this->bills[$this->billPosition]['fields'][] = $record;
            if (in_array($field, [137, 138], true)) $this->balances[$field === 137 ? 'remainingToPay' : 'received'] = is_array($typed) ? $typed : ['raw' => $buffer, 'requiresMonetaryFormat' => true];
            if ($field === 4039 && $buffer !== '0') { $this->confirmationBlocked = true; $this->validationIssues[] = ['code' => 'PBM_BUSINESS_ERROR', 'fieldType' => 4039]; }
            if (in_array($field, [4100, 5049, 5084, 4039], true)) {
                $diagnostic = ['fieldType' => $field, 'buffer' => $buffer, 'businessError' => $field === 4039 && $buffer !== '0']; $this->diagnostics[] = $diagnostic;
                if (($definition['action']['requiredHook'] ?? null) === 'onDiagnostic' && !isset($this->hooks['onDiagnostic'])) throw new SitefException('REQUIRED_HOOK', 'Diagnóstico exige onDiagnostic.');
                if (isset($this->hooks['onDiagnostic'])) ($this->hooks['onDiagnostic'])($diagnostic, $this->hookContext($cancel));
            }
            if (in_array($field, [121, 122], true)) $this->receipt($request, $cancel);
            elseif (($definition['action']['requiredHook'] ?? '') === 'printReceipt' && ($definition['action']['phase'] ?? '') === 'applicationDefined') $this->required('printReceipt', ['fieldType' => $field, 'kind' => $definition['action']['kind'], 'text' => $buffer, 'context' => $this->context], $cancel);
            return [...$request, 'semantic' => $definition, 'value' => $typed, 'protocolContext' => $this->context];
        }
        $collection = ['format' => $this->formatHint, 'decimalPlaces' => $this->collectionPrecision($definition, $field), 'decimal' => $command === 34 || ($this->formatHint['kind'] ?? '') === 'V' || ($definition['dataType'] ?? '') === 'decimal' || $this->context['precisionAnnounced'] && in_array($command, [30, 34], true), 'allowCancelCurrentBill' => in_array($field, [600, 601, 602, 603, 604], true) && in_array($command, [29, 30, 34], true)];
        if ($command === 31) $this->context['usesCheque'] = true;
        if ($this->context['precisionAnnounced'] && in_array($command, [30, 34], true)) { $this->context['precisionAnnounced'] = false; $this->context['decimalPlaces'] = 2; }
        if (in_array($command, [20, 21, 29, 30, 31, 34, 35, 41, 42], true)) $this->formatHint = null;
        if (($definition['resolverRequired'] ?? false) && ($definition['action']['phase'] ?? '') !== 'beforeArithmetic' && !($definition['format']['arithmeticResolverRequired'] ?? false) && !in_array($field, [109, 137, 138, 1319], true)) {
            if (!isset($this->hooks['resolveField'])) throw new SitefException('FIELD_RESOLVER_REQUIRED', "Campo $field exige resolvedor do produto/contexto.");
            $resolved = ($this->hooks['resolveField'])(['request' => $request, 'definition' => $definition, 'context' => $this->context, 'collection' => $collection], $this->hookContext($cancel));
            if (!is_array($resolved)) throw new SitefException('INVALID_FIELD_RESOLUTION', 'Resolvedor de coleta deve devolver metadados explícitos.');
            $collection = [...$collection, ...$resolved];
        }
        if (in_array($collection['format']['kind'] ?? '', ['S', 'M', 'Mc'], true) && !in_array($command, [20, 21, 42], true) && !isset($collection['resolvedFormat'])) throw new SitefException('FIELD_RESOLVER_REQUIRED', 'Formato S/M/Mc fora do comando correspondente exige resolveField.');
        return [...$request, 'semantic' => $definition, 'collection' => $collection, 'protocolContext' => $this->context];
    }
    public function applicationData(array $request, CancellationToken $cancel): array
    {
        if (!isset($this->hooks['resolveApplicationData'])) throw new SitefException('APPLICATION_DATA_REQUIRED', 'Comando29 exige resolveApplicationData, sem perguntar ao operador.');
        $response = ($this->hooks['resolveApplicationData'])($request, $this->hookContext($cancel));
        if (is_array($response) && array_is_list($response) && $request['fieldType'] === 1323) return ['buffer' => ProductData::buildProducts($response, $this->encoding)];
        if (is_array($response) && array_is_list($response) && $request['fieldType'] === 4156) return ['buffer' => ProductData::buildPix($response, $this->encoding)];
        return is_string($response) ? ['buffer' => $response] : $response;
    }
    public function hasApplicationData(): bool { return isset($this->hooks['resolveApplicationData']); }
    public function validateResponse(array $request, array $response, CancellationToken $cancel): array
    {
        $flag = $response['continueFlag'] ?? 0;
        if ($flag === 10000 && ($response['confirmed'] ?? false) !== true) throw new SitefException('CONFIRMATION_REQUIRED', 'Coleta prévia exige confirmação humana explícita.');
        if ($flag === 2 && !($request['collection']['allowCancelCurrentBill'] ?? false)) throw new SitefException('INVALID_PARTIAL_CANCEL', 'Continua2 só é válido no valor/vencimento da conta atual.');
        if (in_array($flag, [-1, 1, 2], true)) return $response;
        $value = $response['buffer'] ?? '';
        if (!is_string($value)) throw new SitefException('INVALID_RESPONSE', 'Resposta deve ser texto.');
        $command = $request['command']; $definition = $request['semantic'] ?? [];
        if ($command === 20 && !in_array($value, ['0', '1'], true)) throw new SitefException('INVALID_CONFIRMATION', 'Confirmação exige0ou1.');
        if (in_array($command, [21, 42], true)) {
            $menu = $request['buffer']; if ($command === 42) $menu = substr($menu, strpos($menu, '|') + 1);
            $ids = array_map(static fn($item) => explode(':', $item, 2)[0], array_filter(explode(';', $menu), static fn($item) => $item !== ''));
            if (!in_array($value, $ids, true)) throw new SitefException('INVALID_MENU_SELECTION', 'Índice de menu não disponibilizado.');
            if (($request['collection']['format']['kind'] ?? '') === 'Mc') {
                if (($response['confirmed'] ?? false) !== true) throw new SitefException('CONFIRMATION_REQUIRED', 'MenuMc exige confirmação humana explícita.');
            }
        }
        if (in_array($command, [29, 30, 31, 34, 35, 41], true)) {
            $data = $value;
            if (in_array($command, [31, 35], true)) {
                if (preg_match('/^[012]:(.*)$/sD', $value, $match) !== 1) throw new SitefException('INVALID_COLLECTION', 'Coleta cheque/barcode exige prefixo da origem.');
                if ($command === 35 && $value === '2:') return $response;
                if ($command === 35 && str_starts_with($value, '2:')) throw new SitefException('INVALID_COLLECTION', 'Cancelamento da coleta35 não leva dados.');
                $data = $match[1];
            }
            $format = $request['collection']['format'] ?? null;
            $length = strlen(Validation::encode($data, $this->encoding));
            if ($length < $request['minLength'] || $length > $request['maxLength']) throw new SitefException('INVALID_LENGTH', 'Coleta fora do tamanho nativo solicitado.');
            if ($format !== null) $this->validateFormat($data, $format);
            if ($request['collection']['decimal'] ?? false) {
                $decimal = ExactDecimal::parse($data);
                if ($decimal['scale'] > ($request['collection']['decimalPlaces'] ?? 2)) throw new SitefException('INVALID_DECIMAL_PRECISION', 'Casas decimais acima da precisão anunciada.');
            }
            if (in_array($definition['dataType'] ?? '', ['integer', 'date', 'time', 'datetime', 'enum', 'decimal'], true)) $this->typed($data, $definition, $request, $cancel, true);
            if (($definition['dataType'] ?? '') === 'structured') $this->typed(in_array($command, [31, 35], true) ? $value : $data, $definition, $request, $cancel, true);
        }
        return $response;
    }
    public function finish(int $status, CancellationToken $cancel): array
    {
        $acquirer = $this->context['acquirer']; $network = $this->context['authorizerNetwork'] ?? null;
        if ($this->confirmationNetworkField !== null) $this->context['network'] = $this->confirmationNetworkField === 131 ? $acquirer : $network;
        elseif ($acquirer !== null && $network !== null && $acquirer === $network) $this->context['network'] = $network;
        elseif (isset($this->hooks['resolveConfirmationNetwork'])) {
            $resolved = ($this->hooks['resolveConfirmationNetwork'])(['acquirer' => $acquirer, 'authorizerNetwork' => $network, 'context' => $this->context], $this->hookContext($cancel));
            if ($resolved !== null) $this->context['network'] = $this->integer($resolved);
        }
        $summary = $this->snapshot();
        if ($status === 0) {
            foreach ($summary['productConstraints'] as $constraint) if ($constraint['state'] === 'violated') { $this->confirmationBlocked = true; $this->validationIssues[] = ['code' => 'PRODUCT_CONSTRAINT_VIOLATED', 'kind' => $constraint['kind'], 'fieldTypes' => $constraint['fieldTypes']]; }
            if ($this->chequePayees !== [] && $this->context['usesCheque'] === true) foreach ($this->chequePayees as $request) $this->required('printReceipt', ['fieldType' => 606, 'kind' => 'payee-for-cheque-print', 'text' => $request['buffer'], 'context' => $this->context], $cancel);
            if ($this->chequePayees !== [] && $this->context['usesCheque'] === null && !isset($this->hooks['validateTransaction'])) $this->confirmationBlocked = true;
            if (in_array($this->context['effectiveFunctionId'], [16, 17], true)) {
                $expected = $this->context['effectiveFunctionId'] === 16 ? 2361 : 2363; $matched = false;
                foreach ($this->fields as $field) if ($field['fieldType'] === $expected && $field['buffer'] === '1') $matched = true;
                if (!$matched && (!isset($this->hooks['acceptFallback']) || ($this->hooks['acceptFallback'])(['expectedField' => $expected, 'summary' => $summary], $this->hookContext($cancel)) !== true)) { $this->confirmationBlocked = true; $this->validationIssues[] = ['code' => 'FALLBACK_NOT_ACCEPTED', 'requiredHook' => 'acceptFallback', 'expectedField' => $expected]; }
            }
            if (($this->guards !== [] || ($this->function['requiredHook'] ?? '') === 'validateTransaction') && !isset($this->hooks['validateTransaction'])) { $this->confirmationBlocked = true; $this->validationIssues[] = ['code' => 'TRANSACTION_VALIDATION_REQUIRED', 'requiredHook' => 'validateTransaction', 'fields' => array_column($this->guards, 'fieldType')]; }
            if (($this->context['signatureRequired'] ?? false) && ($this->function['finalizationPolicy'] ?? '') === 'nonfinancial') $this->required('captureSignature', ['paperRequired' => true, 'summary' => $summary], $cancel);
            if (isset($this->hooks['validateTransaction'])) {
                $accepted = ($this->hooks['validateTransaction'])($summary, $this->hookContext($cancel));
                if ($accepted !== true) { $this->confirmationBlocked = true; $this->validationIssues[] = ['code' => 'TRANSACTION_VALIDATION_REJECTED']; }
            }
        }
        $this->cleanup($cancel);
        return $this->snapshot();
    }
    public function cleanup(CancellationToken $cancel): void
    {
        if ($this->resourcesLocked) { $this->required('unlockResources', ['context' => $this->context, 'cleanup' => true], $cancel); $this->resourcesLocked = false; }
    }
    public function snapshot(): array
    {
        $balances = $this->balances;
        if (isset($balances['remainingToPay']['unscaled'])) {
            $comparison = ExactDecimal::compare($balances['remainingToPay'], ExactDecimal::parse('0'));
            $balances['settlement'] = $comparison > 0 ? 'underpaid' : ($comparison < 0 ? 'change-due' : 'settled');
            if ($comparison < 0) $balances['change'] = ExactDecimal::subtract(ExactDecimal::parse('0'), $balances['remainingToPay']);
        }
        elseif (isset($balances['remainingToPay'])) $balances['settlement'] = 'unresolved';
        if (isset($balances['received']['unscaled']) && $this->input['amount'] !== '') $balances['receivedDifference'] = ExactDecimal::subtract($balances['received'], ExactDecimal::parse($this->input['amount']));
        return ['fields' => $this->fields, 'productConstraints' => ProductConstraints::assess($this->fields, $this->encoding), 'receipts' => $this->receipts, 'bills' => array_values($this->bills), 'paymentData' => $this->nfpag, 'balances' => $balances, 'diagnostics' => $this->diagnostics, 'validationIssues' => $this->validationIssues, 'context' => $this->context, 'confirmationBlocked' => $this->confirmationBlocked];
    }
    private function receipt(array $request, CancellationToken $cancel): void
    {
        $receipt = ['id' => $this->paymentId . ':' . $request['fieldType'] . ':' . count($this->receipts), 'paymentId' => $this->paymentId, 'fieldType' => $request['fieldType'], 'text' => $request['buffer'], 'body' => $request['buffer'], 'receiptType' => $this->context['receiptType'], 'paymentIndex' => $this->context['paymentIndex'], 'billIndex' => $this->context['billIndex'], 'timing' => $this->context['receiptTiming'], 'stored' => false, 'printed' => false];
        if (isset($request['bufferBase64'])) $receipt['bufferBase64'] = $request['bufferBase64'];
        if ($receipt['timing'] === 'immediate') { $this->required('printReceipt', $receipt, $cancel); $receipt['printed'] = true; }
        if ($this->context['receiptStoragePolicy'] === 'persist') { $this->required('storeReceipt', $receipt, $cancel); $receipt['stored'] = true; }
        elseif (isset($this->hooks['storeReceipt'])) { $this->required('storeReceipt', $receipt, $cancel); $receipt['stored'] = true; }
        $this->receipts[] = $receipt;
    }
    private function required(string $name, array $payload, CancellationToken $cancel): void
    {
        if (!isset($this->hooks[$name])) throw new SitefException('REQUIRED_HOOK', "Hook obrigatório ausente: $name.");
        if (($this->hooks[$name])($payload, $this->hookContext($cancel)) !== true) throw new SitefException('HOOK_REJECTED', "Hook $name não confirmou sucesso.");
    }
    private function hookContext(CancellationToken $cancel): array { return ['paymentId' => $this->paymentId, 'cancellation' => $cancel, 'protocolContext' => $this->context]; }
    private function typed(string $buffer, array $definition, array $request, CancellationToken $cancel, bool $collecting = false): mixed
    {
        if (!$collecting && in_array($request['fieldType'], [2355, 2470, 1121, 1126, 730, 731, 732], true)) return $buffer;
        if (!$collecting && in_array($request['fieldType'], [109, 137, 138, 1319], true)) {
            $format = $this->monetaryFormats[$request['fieldType']] ?? null;
            if ($format === 'decimal-comma') { if (str_contains($buffer, '.')) throw new SitefException('INVALID_DECIMAL', 'Formato monetário configurado exige vírgula.'); return ExactDecimal::parse($buffer); }
            if ($format === 'minor-units') return ExactDecimal::parse($buffer, 2);
            if (isset($this->hooks['resolveField'])) {
                $resolved = ($this->hooks['resolveField'])(['request' => $request, 'definition' => $definition, 'context' => $this->context, 'buffer' => $buffer], $this->hookContext($cancel));
                if (!is_array($resolved) || !is_string($resolved['unscaled'] ?? null) || !is_int($resolved['scale'] ?? null)) throw new SitefException('MONETARY_FORMAT_REQUIRED', 'Resolvedor monetário deve devolver unscaled/scale.');
                return ExactDecimal::parse($resolved['unscaled'], $resolved['scale']);
            }
            return ['raw' => $buffer, 'requiresMonetaryFormat' => true];
        }
        if (($definition['resolverRequired'] ?? false) && (($definition['action']['phase'] ?? '') === 'beforeArithmetic' || ($definition['format']['arithmeticResolverRequired'] ?? false))) {
            if ($collecting) return ExactDecimal::parse($buffer);
            $format = $this->monetaryFormats[$request['fieldType']] ?? null;
            if ($format === 'decimal-comma') return ExactDecimal::parse($buffer);
            if ($format === 'minor-units') return ExactDecimal::parse($buffer, 2);
            if (!isset($this->hooks['resolveField'])) return ['raw' => $buffer, 'requiresArithmeticResolver' => true];
            $resolved = ($this->hooks['resolveField'])(['request' => $request, 'definition' => $definition, 'buffer' => $buffer, 'context' => $this->context], $this->hookContext($cancel));
            if (!is_array($resolved) || !is_string($resolved['unscaled'] ?? null) || !is_int($resolved['scale'] ?? null)) throw new SitefException('MONETARY_FORMAT_REQUIRED', 'Resolvedor aritmético exige unscaled/scale.');
            return ExactDecimal::parse($resolved['unscaled'], $resolved['scale']);
        }
        if (($definition['resolverRequired'] ?? false)) {
            if (!isset($this->hooks['resolveField'])) throw new SitefException('FIELD_RESOLVER_REQUIRED', "Campo {$request['fieldType']} exige resolvedor específico.");
            return ($this->hooks['resolveField'])(['request' => $request, 'definition' => $definition, 'context' => $this->context, 'buffer' => $buffer, 'collecting' => $collecting], $this->hookContext($cancel));
        }
        if ($buffer === '' && !$collecting) return ['raw' => '', 'empty' => true];
        $format = $definition['format'] ?? []; $format = is_array($format) ? $format : ['kind' => $format];
        if (($format['kind'] ?? '') === 'nonnegative-integer' && !ctype_digit($buffer)) throw new SitefException('INVALID_INTEGER_FIELD', 'Campo exige inteiro não negativo.');
        if (($definition['dataType'] ?? '') === 'structured') return $this->structured($buffer, $format, $request, $cancel);
        return match ($definition['dataType'] ?? 'text') {
            'integer' => $this->integer($buffer),
            'decimal' => $this->decimal($buffer, $definition),
            'date', 'time', 'datetime' => $this->date($buffer, $format['pattern'] ?? '', $definition['dataType']),
            'enum' => $this->enumeration($buffer, $definition),
            default => $buffer,
        };
    }
    private function integer(string $value): string
    {
        if (preg_match('/^[+-]?\d+$/D', $value) !== 1) throw new SitefException('INVALID_INTEGER_FIELD', 'Campo numérico inválido.');
        return $value;
    }
    private function decimal(string $value, array $definition): array
    {
        $format = $definition['format'] ?? []; $format = is_array($format) ? $format : ['kind' => $format];
        $scale = ($format['kind'] ?? '') === 'minor-units' ? ($format['scale'] ?? 2) : null;
        if (($format['kind'] ?? '') === 'decimal-comma' && (str_contains($value, '.') || isset($format['scale']) && preg_match('/^[+-]?\d+,\d{' . $format['scale'] . '}$/D', $value) !== 1)) throw new SitefException('INVALID_DECIMAL_FORMAT', 'Formato monetário documentado exige vírgula e precisão exata.');
        return ExactDecimal::parse($value, $scale);
    }
    private function structured(string $value, array $format, array $request, CancellationToken $cancel): mixed
    {
        switch ($format['kind'] ?? '') {
            case 'confirmation-data':
                $parts = explode(';', $value, 3);
                if (count($parts) === 2) throw new SitefException('INVALID_CONFIRMATION_DATA', 'Confirmação estendida incompleta.');
                return ['raw' => $value, 'serviceZ' => $parts[0], 'sitefIndex' => $parts[1] ?? null, 'sitefAddress' => $parts[2] ?? null];
            case 'payment-mode':
                if (!preg_match('/^\d{4}$/D', $value)) throw new SitefException('INVALID_PAYMENT_MODE', 'Modalidade deve conter grupo e variante de2dígitos.');
                return ['raw' => $value, 'group' => substr($value, 0, 2), 'variant' => substr($value, 2, 2)];
            case 'cheque-command31':
                if (!preg_match('/^([012]):(.+)$/sD', $value, $match) || $match[1] === '0' && !preg_match('/^\d{29}$/D', $match[2])) throw new SitefException('INVALID_CHEQUE_FIELD', 'Cheque31 inválido.');
                return ['raw' => $value, 'origin' => $match[1], 'data' => $match[2]];
            case 'cheque-without-check-digits':
                if (!preg_match('/^\d{26}$/D', $value)) throw new SitefException('INVALID_CHEQUE_FIELD', 'Cheque613 exige26dígitos semDVs.');
                $parts = []; $offset = 0; foreach ($format['parts'] as $part) { $parts[$part['name']] = substr($value, $offset, $part['length']); $offset += $part['length']; }
                return ['raw' => $value, ...$parts];
            case 'barcode-origin':
                if (!preg_match('/^([01]):(.+)$/sD', $value, $match)) throw new SitefException('INVALID_BARCODE_FIELD', 'Código pago exige origem0/1.');
                return ['raw' => $value, 'origin' => $match[1], 'data' => $match[2]];
            case 'nonfuel-product-list': return ProductData::parseProducts($value, $this->encoding);
            case 'pix-additional-fields': return ProductData::parsePix($value, $this->encoding);
            case 'native-communication-diagnostic': return ['raw' => $value];
            default:
                if (isset($this->hooks['resolveField'])) return ($this->hooks['resolveField'])(['request' => $request, 'definition' => $format, 'context' => $this->context, 'buffer' => $value], $this->hookContext($cancel));
                throw new SitefException('FIELD_RESOLVER_REQUIRED', 'Estrutura não possui parser documentado; forneça resolveField.');
        }
    }
    private function precision(string $value): int
    {
        if (preg_match('/^\d+$/D', $value) !== 1 || strlen(ltrim($value, '0')) > 7 || (int)$value > 1048576) throw new SitefException('INVALID_DECIMAL_PLACES', 'Precisão decimal inválida.');
        return (int)$value;
    }
    private function parseHint(string $value): array
    {
        if (preg_match('/^V(\d+)$/D', $value, $match) === 1) return ['kind' => 'V', 'decimalPlaces' => $this->precision($match[1])];
        if (!in_array($value, ['A', 'AN', 'LN', 'N', 'S', 'M', 'Mc'], true)) throw new SitefException('UNKNOWN_FORMAT_HINT', 'Dica2355 desconhecida exige resolveField.');
        return ['kind' => $value];
    }
    private function collectionPrecision(array $definition, int $field): int
    {
        if (($this->formatHint['kind'] ?? '') === 'V') return $this->formatHint['decimalPlaces'];
        if ($this->context['precisionAnnounced']) return $this->context['decimalPlaces'];
        $label = ($definition['name'] ?? '') . ' ' . ($definition['description'] ?? '');
        if (($field === 1113 || $this->context['productSpan'] && preg_match('/quantidade|quantity|litros/iu', $label)) && $this->context['quantityDecimals'] !== null) return $this->context['quantityDecimals'];
        if ($this->context['productSpan'] && preg_match('/preço|preco|unit.price|unitário/iu', $label) && $this->context['unitPriceDecimals'] !== null) return $this->context['unitPriceDecimals'];
        return $this->context['decimalPlaces'];
    }
    private function validateFormat(string $value, array $hint): void
    {
        $kind = $hint['kind'];
        $valid = match ($kind) { 'A' => preg_match('/^\p{L}+$/uD', $value), 'LN' => preg_match('/^[A-Za-z0-9]+$/D', $value), 'N' => preg_match('/^\d+$/D', $value), default => 1 };
        if ($valid !== 1) throw new SitefException('INVALID_FIELD_FORMAT', 'Resposta incompatível com a dica2355.');
    }
    private function enumeration(string $value, array $definition): string
    {
        $choices = $definition['choices'] ?? null;
        if (is_array($choices) && !array_key_exists($value, $choices) && !in_array($value, $choices, true)) throw new SitefException('INVALID_ENUM_FIELD', 'Valor fora dos valores documentados.');
        return $value;
    }
    private function date(string $value, string $format, string $type): array
    {
        $format = strtoupper($format); $format = str_replace(['YYYY', 'YY'], ['AAAA', 'AA'], $format);
        $dateValue = $value;
        if ($type === 'datetime') { if (!preg_match('/^\d{14}$/D', $value)) throw new SitefException('INVALID_DATE_FIELD', 'Data/hora inválida.'); $this->date(substr($value, 8), 'HHMMSS', 'time'); $dateValue = substr($value, 0, 8); $format = 'AAAAMMDD'; }
        if ($type === 'time') { if (!preg_match('/^\d{6}$/D', $value) || (int)substr($value, 0, 2) > 23 || (int)substr($value, 2, 2) > 59 || (int)substr($value, 4, 2) > 59) throw new SitefException('INVALID_DATE_FIELD', 'Hora inválida.'); return ['raw' => $value, 'format' => $format]; }
        $date = match ($format) { 'DDMMAAAA' => [substr($dateValue, 4, 4), substr($dateValue, 2, 2), substr($dateValue, 0, 2), 8], 'AAAAMMDD' => [substr($dateValue, 0, 4), substr($dateValue, 4, 2), substr($dateValue, 6, 2), 8], 'DDMMAA' => ['20' . substr($dateValue, 4, 2), substr($dateValue, 2, 2), substr($dateValue, 0, 2), 6], 'MMAAAA' => [substr($dateValue, 2, 4), substr($dateValue, 0, 2), '01', 6], 'MMAA' => ['20' . substr($dateValue, 2, 2), substr($dateValue, 0, 2), '01', 4], 'DDMM' => ['2000', substr($dateValue, 2, 2), substr($dateValue, 0, 2), 4], default => null };
        if ($date === null) throw new SitefException('FIELD_RESOLVER_REQUIRED', 'Formato de data não documentado exige resolveField.');
        if (!ctype_digit($dateValue) || strlen($dateValue) !== $date[3] || !checkdate((int)$date[1], (int)$date[2], (int)$date[0])) throw new SitefException('INVALID_DATE_FIELD', 'Data de campo inválida.');
        return ['raw' => $value, 'format' => $format];
    }
}
