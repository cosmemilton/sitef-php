<?php
declare(strict_types=1);
use Cosmemilton\Sitef\{CancellationToken, Client, ExactDecimal, FieldProcessor, JournalRecord, PaymentData, ProductData, Semantics, SitefException};

function processor(array $options = [], int $functionId = 0): FieldProcessor { return new FieldProcessor(input(['functionId' => $functionId]), Semantics::function($functionId), $options); }
function finishCalls(FakeTransport $transport): array { return array_values(array_filter($transport->calls, static fn($call): bool => $call[0] === 'finish')); }
function pendingFields(string $count = '1'): array { return [exchange(0, $count, 210), exchange(0, '123', 160), exchange(0, '0', 161), exchange(0, '20261030', 163), exchange(0, '120000', 164), exchange(0, '0', 211), exchange(0, '1234', 1319), ['status' => 0]]; }

$tests['semantic schemas drive known fields and strict function policy before FFI'] = static function (): void {
    ensure(count(Semantics::document('fields')['entries']) >= 536); same(131, count(Semantics::document('functions')['entries']));
    [$client, $transport, $journal] = setup();
    fails('FINALIZATION_POLICY_MISMATCH', static fn() => $client->transact(input(), options: ['requiresFinalization' => false]));
    fails('FUNCTION_POLICY_REQUIRED', static fn() => $client->transact(input(['functionId' => 999999])));
    same(1, count($transport->calls)); same([], $journal->history);
    $result = $client->runFunction(111); same(false, $result['requiresFinalization']); same([], $journal->history);
    same('', $transport->calls[1][1]['fiscalDocument']); $client->close();
};
$tests['exact decimal arithmetic exceeds machine integer precision without float'] = static function (): void {
    $large = ExactDecimal::parse('90071992547409931234567890,1234');
    same('90071992547409931234567890,1235', ExactDecimal::add($large, ExactDecimal::parse('0,0001'))['raw']);
    same('-0,01', ExactDecimal::subtract(ExactDecimal::parse('10,00'), ExactDecimal::parse('10,01'))['raw']);
    same(0, ExactDecimal::compare(ExactDecimal::parse('001,2300'), ExactDecimal::parse('1,23')));
};
$tests['2355 and 2470 apply to one collection; quantities retain product precision'] = static function (): void {
    $p = processor(); $cancel = new CancellationToken();
    $p->observe(exchange(0, 'N', 2355), $cancel); $numeric = $p->observe(exchange(30, 'Number', 99999), $cancel);
    fails('INVALID_FIELD_FORMAT', static fn() => $p->validateResponse($numeric, ['buffer' => 'ABC'], $cancel));
    same('123', $p->validateResponse($numeric, ['buffer' => '123'], $cancel)['buffer']);
    $p->observe(exchange(0, '4', 2470), $cancel); $decimal = $p->observe(exchange(30, 'Decimal', 99999), $cancel);
    same('1,2345', $p->validateResponse($decimal, ['buffer' => '1,2345'], $cancel)['buffer']);
    fails('INVALID_DECIMAL_PRECISION', static fn() => $p->validateResponse($decimal, ['buffer' => '1,23456'], $cancel));
    $ordinary = $p->observe(exchange(30, 'Text', 99999), $cancel); same('ABC', $p->validateResponse($ordinary, ['buffer' => 'ABC'], $cancel)['buffer']);
    $p->observe(exchange(0, '3', 1126), $cancel); $p->observe(exchange(0, '4', 1121), $cancel);
    $quantity = $p->observe(exchange(30, 'Quantity', 1113), $cancel); same(3, $quantity['collection']['decimalPlaces']); same('0,001', $p->validateResponse($quantity, ['buffer' => '0,001'], $cancel)['buffer']);
    same(4, $p->snapshot()['context']['unitPriceDecimals']);
};
$tests['Mc requires explicit confirmation and AN accepts encodable product text'] = static function (): void {
    $p = processor(); $c = new CancellationToken(); $p->observe(exchange(0, 'Mc', 2355), $c);
    $menu = $p->observe(exchange(21, '8:Credit;2:Debit;'), $c);
    fails('CONFIRMATION_REQUIRED', static fn() => $p->validateResponse($menu, ['buffer' => '8'], $c));
    same('8', $p->validateResponse($menu, ['buffer' => '8', 'confirmed' => true], $c)['buffer']);
    $p->observe(exchange(0, 'AN', 2355), $c); $text = $p->observe(exchange(30, 'Text', 99999), $c);
    same('Ação #1', $p->validateResponse($text, ['buffer' => 'Ação #1'], $c)['buffer']);
    fails('UNENCODABLE_INPUT', static fn() => $p->validateResponse($text, ['buffer' => '🔒'], $c));
};
$tests['typed dates, enums, contextual collisions and scale ambiguity are explicit'] = static function (): void {
    $p = processor(); $c = new CancellationToken(); fails('INVALID_DATE_FIELD', static fn() => $p->observe(exchange(0, '31022026', 506), $c));
    same('PRODUCT', $p->observe(exchange(0, 'PRODUCT', 2053), $c)['value']); same('datetime', Semantics::field(2055)['dataType']);
    same(true, $p->observe(exchange(0, '1234', 137), $c)['value']['requiresMonetaryFormat']);
    $secure = new FieldProcessor(input(['functionId' => 430]), Semantics::function(430)); fails('FIELD_RESOLVER_REQUIRED', static fn() => $secure->observe(exchange(0, 'HASH', 2055), $c));
    $resolved = new FieldProcessor(input(['functionId' => 430]), Semantics::function(430), ['hooks' => ['resolveField' => static fn($data) => ['protected' => $data['buffer']]]]);
    same('HASH', $resolved->observe(exchange(0, 'HASH', 2055), $c)['value']['protected']);
    $money = processor(['monetaryFormats' => [137 => 'minor-units', 138 => 'decimal-comma']]); $money->observe(exchange(0, '-5', 137), $c); $money->observe(exchange(0, '12,39', 138), $c);
    same('change-due', $money->snapshot()['balances']['settlement']); same('0,05', $money->snapshot()['balances']['change']['raw']);
};
$tests['immediate receipts block native continuation until print and store succeed'] = static function (): void {
    $events = []; $semantics = ['hooks' => ['printReceipt' => static function () use (&$events): bool { $events[] = 'print'; return true; }, 'storeReceipt' => static function () use (&$events): bool { $events[] = 'store'; return true; }]];
    [$client, $transport] = setup([exchange(0, 'receipt', 121), ['status' => 0]], semantics: $semantics);
    $transport->onCall = static function ($method) use (&$events): void { if ($method === 'continue') $events[] = 'continue'; };
    $client->transact(input(['functionId' => 310])); same(['continue', 'print', 'store', 'continue'], $events); $client->finalize(false); $client->close();
    [$missing, $t] = setup([exchange(0, 'receipt', 121), ['status' => -15]], semantics: ['hooks' => []]);
    fails('INTERACTION_FAILED', static fn() => $missing->transact(input(['functionId' => 310]))); same(-1, $t->calls[3][1]['continueFlag']); $missing->close();
};
$tests['deferred receipt print precedes paper signature and financial confirmation'] = static function (): void {
    $events = []; $hooks = ['storeReceipt' => static function () use (&$events): bool { $events[] = 'store'; return true; }, 'printReceipt' => static function () use (&$events): bool { $events[] = 'print'; return true; }, 'captureSignature' => static function ($data) use (&$events): bool { same(true, $data['paperRequired']); $events[] = 'signature'; return true; }];
    [$client, $t, $j] = setup([exchange(0, 'PRIVATE RECEIPT', 121), exchange(0, '', 5074), ['status' => 0]], semantics: ['hooks' => $hooks]);
    $client->transact(input()); same(['store'], $events); ensure(!str_contains(json_encode($j->record), 'PRIVATE'));
    $t->onCall = static function ($method) use (&$events): void { if ($method === 'finish') $events[] = 'finish'; };
    $client->finalize(true); same(['store', 'print', 'signature', 'finish'], $events); same(true, $j->record['payments'][0]['signatureCaptured']); $client->close();
};
$tests['explicit receipt return requires storage and printing acknowledgements before confirm'] = static function (): void {
    [$client, $t] = setup([exchange(0, 'receipt', 121), ['status' => 0]], semantics: ['receiptStoragePolicy' => 'explicit-return']);
    $result = $client->transact(input()); $id = $result['semantics']['receipts'][0]['id'];
    fails('RECEIPT_STORAGE_REQUIRED', static fn() => $client->finalize(true)); same([], finishCalls($t));
    $client->acknowledgeReceiptStored($id); $client->acknowledgeReceiptPrinted($id); $client->finalize(true); $client->close();
};
$tests['receipt recovery loads and verifies original bytes; false finalization remains available'] = static function (): void {
    [$original, $t, $journal] = setup([exchange(0, 'Ação', 121, ['bufferBase64' => base64_encode("A\xe7\xe3o")]), ['status' => 0]]);
    $original->transact(input()); $journal->locked = false; $id = $journal->record['id'];
    [$resumed, $transport] = setup(journal: $journal, semantics: ['hooks' => ['loadReceipt' => static fn() => ['text' => 'CORRUPTED'], 'printReceipt' => static fn(): bool => true]]);
    fails('RECEIPT_HASH_MISMATCH', static fn() => $resumed->finalizeRecovery(true, $id)); same('uncertain', $resumed->state()); same([], finishCalls($transport)); $resumed->finalizeRecovery(false, $id); $resumed->close();
};
$tests['resource lock validates unlock hook and cleanup releases after cancellation'] = static function (): void {
    $locks = 0; $unlocks = 0;
    $p = processor(['hooks' => ['lockResources' => static function () use (&$locks): bool { $locks++; return true; }]]);
    fails('REQUIRED_HOOK', static fn() => $p->observe(exchange(0, '', 5042), new CancellationToken())); same(0, $locks);
    [$client] = setup([exchange(0, '', 5042), exchange(999), ['status' => -15]], static function (): array { throw new RuntimeException(); }, semantics: ['hooks' => ['lockResources' => static function () use (&$locks): bool { $locks++; return true; }, 'unlockResources' => static function () use (&$unlocks): bool { $unlocks++; return true; }]]);
    fails('INTERACTION_FAILED', static fn() => $client->transact(input())); same(1, $locks); same(1, $unlocks); $client->close();
};
$tests['PBM and post-loop validation never turn known approval into a false decline'] = static function (): void {
    [$client, $t, $j] = setup([exchange(0, '7', 4039), ['status' => 0]]);
    $result = $client->transact(input()); same(0, $result['status']); same(false, $result['confirmationAllowed']); same('awaiting-finalization', $client->state());
    fails('CONFIRMATION_BLOCKED', static fn() => $client->finalize(true)); same([], finishCalls($t)); same(true, $j->record['payments'][0]['confirmationBlocked']); $client->finalize(false); $client->close();
    [$failure] = setup([['status' => 0]], semantics: ['hooks' => ['validateTransaction' => static function (): bool { throw new RuntimeException('guard'); }]]);
    fails('INTERACTION_FAILED', static fn() => $failure->transact(input())); same('awaiting-finalization', $failure->state()); $failure->finalize(false); $failure->close();
};
$tests['multiple payments retain prior approvals and partition at fiscal date rollover'] = static function (): void {
    [$client, $t, $j] = setup([exchange(0, '0', 161), ['status' => 0]]); $first = $client->transact(input());
    fails('APPEND_REQUIRED', static fn() => $client->transact(input()));
    fails('FISCAL_GROUP_MISMATCH', static fn() => $client->appendPayment(input(['fiscalDocument' => '124'])));
    $t->steps = [['status' => -2]]; $failed = $client->appendPayment(input()); same(-2, $failed['status']); same('awaiting-finalization', $client->state());
    $t->steps = [exchange(0, '0', 161), ['status' => 0]]; $third = $client->appendPayment(input(['fiscalDocument' => '124', 'fiscalDate' => '20261031', 'fiscalTime' => '000001']));
    same($first['groupId'], $third['groupId']); same(3, count($j->record['payments']));
    $t->onCall = static function ($method) use ($j): void { if ($method === 'finish') foreach ($j->record['payments'] as $payment) if ($payment['state'] !== 'ended' && $payment['state'] !== 'finalized-local') { same('finalizing', $payment['state']); same(1, $payment['decision']); } };
    $client->finalize(true); $calls = finishCalls($t); same(2, count($calls)); same('20261030', $calls[0][1]['fiscalDate']); same('120000', $calls[0][1]['fiscalTime']); same('20261031', $calls[1][1]['fiscalDate']); same('000001', $calls[1][1]['fiscalTime']); $client->close();
};
$tests['second-partition failure resumes only persisted remaining intent and query is read-only'] = static function (): void {
    [$client, $t, $j] = setup(); $first = $client->transact(input()); $client->appendPayment(input(['fiscalDocument' => '124', 'fiscalDate' => '20261031']));
    $t->finishFailureAt = 2; fails('FINALIZATION_UNCERTAIN', static fn() => $client->finalize(true));
    same('finalized-local', $j->record['payments'][0]['state']); same('uncertain', $j->record['payments'][1]['state']);
    $before = serialize($j->record); $writes = count($j->history); $t->steps = pendingFields(); $pending = $client->queryPendingTransactions(); same(1, $pending['count']); same('1', $pending['rawCount']); same('123', $pending['records'][0]['fiscalDocument']); same($before, serialize($j->record)); same($writes, count($j->history)); same('uncertain', $client->state());
    fails('RECOVERY_DECISION_MISMATCH', static fn() => $client->finalizeRecovery(false, $first['groupId']));
    $client->finalizeRecovery(true, $first['groupId']); same(3, count(finishCalls($t))); same('20261031', finishCalls($t)[2][1]['fiscalDate']); $client->close();
};
$tests['typed individual/network finalization cannot accidentally mark siblings finalized'] = static function (): void {
    [$client, $t, $j] = setup([exchange(0, '0', 161), exchange(0, '5', 131), ['status' => 0]], semantics: ['confirmationNetworkField' => 131]);
    $a = $client->transact(input()); $t->steps = [exchange(0, '1', 161), exchange(0, '7', 131), ['status' => 0]]; $b = $client->appendPayment(input());
    fails('TYPED_FINALIZATION_REQUIRED', static fn() => $client->finalize(true, '{NumeroPagamentoCupom=0}'));
    $client->finalizePayment(true, $a['transactionId']); same('{NumeroPagamentoCupom=0}', finishCalls($t)[0][1]['additionalParameters']); same('awaiting-finalization', $j->record['payments'][1]['state']);
    $client->finalizeNetwork(false, '7'); same('{RedeConfirmacao=7}', finishCalls($t)[1][1]['additionalParameters']); $client->close();
};
$tests['confirmation network ambiguity blocks selective finish while group rollback works'] = static function (): void {
    [$client, $t] = setup([exchange(0, '5', 131), exchange(0, '6', 158), ['status' => 0]]); $client->transact(input());
    fails('NETWORK_RESOLUTION_REQUIRED', static fn() => $client->finalizeNetwork(true, '6')); same([], finishCalls($t)); $client->finalize(false); $client->close();
};
$tests['NFPAG validates totals, cheque origin and network13 without emitting constraints'] = static function (): void {
    $requirements = ['maximum' => '2', 'methods' => [['type' => '00', 'requiredData' => [], 'allowedNetworks' => []], ['type' => '02', 'requiredData' => ['03'], 'allowedNetworks' => ['5', '6']]]];
    same('{NumeroPagamentoNFPAG=0}{NFPAG=00:234;02:1000:03:5;}', PaymentData::build('0', $requirements, [['type' => '00', 'amount' => '2,34'], ['type' => '02', 'amount' => '10,00', 'data' => ['03' => '5']]], '12,34'));
    fails('NFPAG_NETWORK_NOT_ALLOWED', static fn() => PaymentData::build('0', $requirements, [['type' => '02', 'amount' => '12,34', 'data' => ['03' => '7']]], '12,34'));
    fails('NFPAG_TOTAL_MISMATCH', static fn() => PaymentData::build('0', $requirements, [['type' => '00', 'amount' => '12,33']], '12,34'));
};
$tests['NFPAG body is externally stored and hash-checked on recovery without journal PII'] = static function (): void {
    $payloads = []; $hooks = ['storeFinalizationPayload' => static function ($payload) use (&$payloads): bool { $payloads[$payload['id']] = $payload['parameters']; return true; }, 'loadFinalizationPayload' => static function ($meta) use (&$payloads): string { return $payloads[$meta['id']]; }];
    [$client, $t, $j] = setup([exchange(0, '0', 161), exchange(0, '1', 730), exchange(0, '00', 731), ['status' => 0]], semantics: ['hooks' => $hooks]);
    $payment = $client->transact(input()); $t->finishFails = true;
    fails('FINALIZATION_UNCERTAIN', static fn() => $client->finalizeWithPayments(true, $payment['transactionId'], [['type' => '00', 'amount' => '12,34']], expectedTotal: '12,34'));
    ensure(!str_contains(json_encode($j->record), '{NFPAG=')); ensure(isset($j->record['payments'][0]['finalizationPayload']['hash']));
    $payloadId = array_key_first($payloads); $original = $payloads[$payloadId]; $payloads[$payloadId] = 'CORRUPTED'; $t->finishFails = false;
    fails('FINALIZATION_PAYLOAD_HASH_MISMATCH', static fn() => $client->finalizeRecovery(true, $payment['groupId'])); same('uncertain', $client->state()); same(1, count(finishCalls($t)));
    $payloads[$payloadId] = $original; $client->finalizeRecovery(true, $payment['groupId']); same('{NumeroPagamentoNFPAG=0}{NFPAG=00:1234;}', finishCalls($t)[1][1]['additionalParameters']); $client->close();
};
$tests['product and PIX builders preserve exact text and reject delimiter collisions'] = static function (): void {
    $item = ['Id' => '001', 'd' => 'Ação', 'Q' => '10', 'VU' => '100', 'T' => 'S', 'TQ' => '3'];
    $wire = ProductData::buildProducts([$item]); same('Id=001,d=Ação,Q=10,VU=100,T=S,TQ=3<#>', $wire); same('100', ProductData::parseProducts($wire)[0]['unitPrice']['unscaled']);
    fails('DUPLICATE_PRODUCT', static fn() => ProductData::buildProducts([$item, $item])); fails('PRODUCT_DELIMITER', static fn() => ProductData::buildProducts([[...$item, 'd' => 'A,B']]));
    same('Nome<{|}>Valor', ProductData::buildPix([['name' => 'Nome', 'value' => 'Valor']])); fails('PIX_DELIMITER', static fn() => ProductData::buildPix([['name' => 'N', 'value' => 'A<{#}>B']]));
};
$tests['v1 migration preserves identity/decision and rejects legacy NFPAG body safely'] = static function (): void {
    $old = ['version' => 1, 'id' => 'legacy', 'storeId' => '00000000', 'terminalId' => 'AA123456', 'functionId' => 0, 'fiscalDocument' => '123', 'fiscalDate' => '20261030', 'fiscalTime' => '120000', 'state' => 'uncertain', 'decision' => 1, 'finalizationParameters' => '{Scope=1}'];
    $v2 = JournalRecord::normalize($old); same(2, $v2['version']); same('legacy', $v2['payments'][0]['id']); same(1, $v2['payments'][0]['decision']); same('{Scope=1}', $v2['payments'][0]['finalizationParameters']);
    fails('LEGACY_PAYLOAD_MIGRATION_REQUIRED', static fn() => JournalRecord::normalize([...$old, 'finalizationParameters' => '{NFPAG=01:100:02:PRIVATE;}']));
    same(true, $v2['payments'][0]['legacyReceiptStateUnknown']);
    $journal = new MemoryJournal(); $journal->record = $old; [$client, $t] = setup(journal: $journal);
    fails('LEGACY_RECEIPT_MIGRATION_REQUIRED', static fn() => $client->finalizeRecovery(true, 'legacy')); same([], finishCalls($t));
    $client->close();
    $cancelJournal = new MemoryJournal(); $cancelJournal->record = array_diff_key($old, array_flip(['decision', 'finalizationParameters']));
    [$cancelLegacy] = setup(journal: $cancelJournal); $cancelLegacy->finalizeRecovery(false, 'legacy'); $cancelLegacy->close();
    $approved = new MemoryJournal(); $approved->record = $old;
    [$attested] = setup(journal: $approved, semantics: ['hooks' => ['attestLegacyObligations' => static fn(): bool => true]]);
    $attested->finalizeRecovery(true, 'legacy'); $attested->close();
};

$tests['PBM price obligations preserve medicine scope and cannot override proven violations'] = static function (): void {
    $steps = [exchange(0, '1', 1011), exchange(0, '0', 1042), exchange(0, '1000', 1043), exchange(0, '999', 1044), ['status' => 0]];
    [$client, $transport] = setup($steps, semantics: ['monetaryFormats' => [1043 => 'minor-units', 1044 => 'minor-units'], 'hooks' => ['validateTransaction' => static fn(): bool => true]]);
    $result = $client->transact(input()); same('violated', $result['assessment']['productConstraints'][0]['state']); same('1', $result['assessment']['productConstraints'][0]['scope']['medicineIndex']); same(false, $result['confirmationAllowed']);
    fails('CONFIRMATION_BLOCKED', static fn() => $client->finalize(true)); same([], finishCalls($transport)); $client->finalize(false); $client->close();
    [$unresolved] = setup([exchange(0, '0', 1042), exchange(0, '1000', 1043), exchange(0, '1000', 1044), ['status' => 0]], semantics: ['hooks' => ['validateTransaction' => static fn(): bool => true]]);
    same('unresolved', $unresolved->transact(input())['assessment']['productConstraints'][0]['state']); $unresolved->finalize(false); $unresolved->close();
};
$tests['product limits reject clear excess and leave shorter-code interpretation explicit'] = static function (): void {
    $items = [['Id' => '001', 'd' => 'A', 'Q' => '1', 'VU' => '100', 'T' => 'S', 'TQ' => '3'], ['Id' => '002', 'd' => 'B', 'Q' => '1', 'VU' => '100', 'T' => 'S', 'TQ' => '3']];
    [$client] = setup([exchange(0, '1', 1122), exchange(0, '4', 1123), exchange(0, ProductData::buildProducts($items), 1323), ['status' => 0]], semantics: ['hooks' => ['validateTransaction' => static fn(): bool => true]]);
    $result = $client->transact(input()); same('violated', $result['assessment']['productConstraints'][0]['state']); same('unresolved', $result['assessment']['productConstraints'][1]['state']); same(false, $result['confirmationAllowed']); $client->finalize(false); $client->close();
};
$tests['application data provider and generic callback both supply command29 without UI assumptions'] = static function (): void {
    $seen = 0;
    [$generic] = setup([exchange(29, 'Application data', 2601), ['status' => 0]], static function ($request) use (&$seen): array { same('system-input', $request['kind']); $seen++; return ['buffer' => 'LOCAL']; });
    $generic->transact(input()); same(1, $seen); $generic->finalize(false); $generic->close();
    [$provider] = setup([exchange(29, 'Application data', 2601), ['status' => 0]], static function (): array { throw new RuntimeException('Must not invoke UI'); }, semantics: ['hooks' => ['resolveApplicationData' => static fn(): string => 'SYSTEM']]);
    $provider->transact(input()); $provider->finalize(false); $provider->close();
};
$tests['5010 is notification; carnet fallback requires explicit acceptance'] = static function (): void {
    $p = processor(); $c = new CancellationToken(); same(5010, $p->observe(exchange(0, '', 5010), $c)['fieldType']);
    [$rejected] = setup(); $r = $rejected->transact(input(['functionId' => 16])); same(false, $r['confirmationAllowed']); $rejected->finalize(false); $rejected->close();
    [$accepted] = setup(semantics: ['hooks' => ['acceptFallback' => static fn(): bool => true]]); same(true, $accepted->transact(input(['functionId' => 16]))['confirmationAllowed']); $accepted->finalize(false); $accepted->close();
};
$tests['recovery of a selective plan preserves undecided sibling payments'] = static function (): void {
    [$client, $t, $j] = setup([exchange(0, '5', 131), ['status' => 0]], semantics: ['confirmationNetworkField' => 131]); $first = $client->transact(input());
    $t->steps = [exchange(0, '7', 131), ['status' => 0]]; $client->appendPayment(input()); $t->finishFails = true;
    fails('FINALIZATION_UNCERTAIN', static fn() => $client->finalizeNetwork(true, '5')); same('awaiting-finalization', $j->record['payments'][1]['state']); ensure(!isset($j->record['payments'][1]['decision']));
    $t->finishFails = false; $client->finalizeRecovery(true, $first['groupId']); same('awaiting-finalization', $client->state()); same('{RedeConfirmacao=5}', finishCalls($t)[1][1]['additionalParameters']); same('awaiting-finalization', $j->record['payments'][1]['state']); $client->finalize(false); $client->close();
};
$tests['paymentData provider runs only for omitted methods; explicit totals and reentrancy are guarded'] = static function (): void {
    $calls = 0; $client = null; $hooks = ['paymentData' => static function ($requirements, $context) use (&$calls, &$client): array { $calls++; same('12,34', $context['expectedTotal']); fails('SESSION_BUSY', static fn() => $client->appendPayment(input())); return [['type' => '00', 'amount' => '12,34']]; }, 'storeFinalizationPayload' => static fn(): bool => true];
    [$client, $t] = setup([exchange(0, '0', 161), exchange(0, '1', 730), exchange(0, '00', 731), ['status' => 0]], semantics: ['hooks' => $hooks]); $payment = $client->transact(input());
    fails('NFPAG_TOTAL_REQUIRED', static fn() => $client->finalizeWithPayments(true, $payment['transactionId'])); same(0, $calls); same([], finishCalls($t));
    $client->finalizeWithPayments(true, $payment['transactionId'], expectedTotal: '12,34'); same(1, $calls); $client->close();
    [$explicit] = setup([exchange(0, '0', 161), exchange(0, '1', 730), exchange(0, '00', 731), ['status' => 0]], semantics: ['hooks' => ['paymentData' => static function (): array { throw new RuntimeException('Do not replace explicit methods'); }, 'storeFinalizationPayload' => static fn(): bool => true]]);
    $p = $explicit->transact(input()); $explicit->finalizeWithPayments(true, $p['transactionId'], [['type' => '00', 'amount' => '12,34']], expectedTotal: '12,34'); $explicit->close();
};
$tests['Corban document occurrences preserve repeated indices in sequence'] = static function (): void {
    $p = processor(); $c = new CancellationToken(); $p->observe(exchange(0, '0', 607), $c); $p->observe(exchange(0, '31102026', 600), $c); $p->observe(exchange(0, '0', 607), $c); $p->observe(exchange(0, '01112026', 600), $c);
    $bills = $p->snapshot()['bills']; same(2, count($bills)); same('31102026', $bills[0]['fields'][0]['buffer']); same('01112026', $bills[1]['fields'][0]['buffer']);
};
$tests['precollected responses require confirmation for every command and bytes cannot bypass semantic validation'] = static function (): void {
    [$unknown] = setup([exchange(999, 'custom'), ['status' => -15]], static fn(): array => ['buffer' => 'X', 'continueFlag' => 10000]);
    $error = fails('INTERACTION_FAILED', static fn() => $unknown->transact(input())); same('CONFIRMATION_REQUIRED', $error->getPrevious()->errorCode); $unknown->close();
    [$binary, $t] = setup([exchange(30, 'Document type', 501), ['status' => -15]], static fn(): array => ['buffer' => '0', 'bufferBase64' => base64_encode('1')]);
    $error = fails('INTERACTION_FAILED', static fn() => $binary->transact(input())); same('INVALID_BINARY_RESPONSE', $error->getPrevious()->errorCode); same(-1, $t->calls[3][1]['continueFlag']); $binary->close();
    [$valid] = setup([exchange(30, 'text', 99999), ['status' => 0]], static fn(): array => ['buffer' => 'Ação', 'bufferBase64' => base64_encode("A\xe7\xe3o")]); $valid->transact(input()); $valid->finalize(false); $valid->close();
};
$tests['native readers override nominal financial product policy and retain secure field context'] = static function (): void {
    $resolved = [];
    [$client, $t, $j] = setup([exchange(0, 'HASH', 2053), ['status' => 0]], semantics: ['hooks' => ['resolveField' => static function ($data) use (&$resolved): array { $resolved[] = $data; return ['protected' => $data['buffer']]; }]]);
    fails('FINALIZATION_POLICY_MISMATCH', static fn() => $client->transact(input(), options: ['nativeStart' => ['method' => 'readSecureCard', 'params' => ['message' => 'READ']], 'requiresFinalization' => true])); same(1, count($t->calls));
    $result = $client->transact(input(['functionId' => 16]), options: ['nativeStart' => ['method' => 'readPassword', 'params' => ['key' => 'OWN FIXTURE']]]);
    same(false, $result['requiresFinalization']); same(true, $result['confirmationAllowed']); same([], $j->history); same('secure-card-read', $resolved[0]['context']['schemaContext']); same('legacy_card_hash', $resolved[0]['definition']['name']); $client->close();
};
$tests['finish with failed local durable acknowledgment leaves only that partition uncertain'] = static function (): void {
    [$client, $t, $j] = setup(); $first = $client->transact(input()); $client->appendPayment(input(['fiscalDocument' => '124', 'fiscalDate' => '20261031']));
    $t->onCall = static function ($method) use ($t, $j): void { if ($method === 'finish' && $t->finishCount === 1) $j->failOn = 'finalized-local'; };
    fails('FINALIZATION_UNCERTAIN', static fn() => $client->finalize(false));
    same('finalized-local', $j->record['payments'][0]['state']); same('uncertain', $j->record['payments'][1]['state']); same('uncertain', $client->state()); same(2, count(finishCalls($t)));
    $j->failOn = null; $t->onCall = null; $client->finalizeRecovery(false, $first['groupId']); same(3, count(finishCalls($t))); same('124', finishCalls($t)[2][1]['fiscalDocument']); $client->close();
};
$tests['NFPAG recovery binds authenticated payload to its persisted safe selector'] = static function (): void {
    $payloads = []; $hooks = ['storeFinalizationPayload' => static function ($payload) use (&$payloads): bool { $payloads[$payload['id']] = $payload['parameters']; return true; }, 'loadFinalizationPayload' => static function ($meta) use (&$payloads): string { return $payloads[$meta['id']]; }];
    [$client, $t, $j] = setup([exchange(0, '0', 161), exchange(0, '1', 730), exchange(0, '00', 731), ['status' => 0]], semantics: ['hooks' => $hooks]);
    $payment = $client->transact(input()); $t->finishFails = true;
    fails('FINALIZATION_UNCERTAIN', static fn() => $client->finalizeWithPayments(true, $payment['transactionId'], [['type' => '00', 'amount' => '12,34']], '12,34')); $client->close();
    $payloadId = array_key_first($payloads); $payloads[$payloadId] = str_replace('NumeroPagamentoNFPAG=0', 'NumeroPagamentoNFPAG=9', $payloads[$payloadId]);
    $j->record['payments'][0]['finalizationPayload']['hash'] = hash('sha256', $payloads[$payloadId]);
    [$recovered, $newTransport] = setup(journal: $j, semantics: ['hooks' => $hooks]);
    fails('RECOVERY_SCOPE_MISMATCH', static fn() => $recovered->finalizeRecovery(true, $payment['groupId'])); same([], finishCalls($newTransport)); same('uncertain', $recovered->state()); $recovered->close();
};
$tests['terminal decisions release sensitive per-payment runtime data'] = static function (): void {
    $runtime = new ReflectionProperty(Client::class, 'runtimePayments');
    [$client, $t] = setup(); $client->transact(input()); same(1, count($runtime->getValue($client))); $client->finalize(false); same([], $runtime->getValue($client));
    $t->steps = [['status' => -15]]; $client->transact(input()); same([], $runtime->getValue($client));
    $t->steps = [['status' => 0]]; $payment = $client->transact(input()); $t->finishFails = true; fails('FINALIZATION_UNCERTAIN', static fn() => $client->finalize(false)); ensure($runtime->getValue($client) !== []); $client->close(); same([], $runtime->getValue($client));
};
