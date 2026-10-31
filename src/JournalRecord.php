<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

/** Journal v2 metadata allowlist and lossless v1 migration. No buffers, PAN or receipts. */
final class JournalRecord
{
    private const PAYMENT_KEYS = ['id', 'functionId', 'entryPoint', 'fiscalDocument', 'fiscalDate', 'fiscalTime', 'state', 'decision', 'status', 'paymentIndex', 'network', 'finalizationParameters', 'finalizationPayload', 'confirmationAllowed', 'confirmationBlocked', 'receipts', 'signatureRequired', 'signatureCaptured', 'legacyReceiptStateUnknown'];
    private const GROUP_KEYS = ['id', 'version', 'storeId', 'terminalId', 'fiscalDocument', 'fiscalDate', 'fiscalTime', 'functionId', 'entryPoint', 'state', 'updatedAt', 'payments'];
    private const STATES = ['started', 'awaiting-finalization', 'finalizing', 'finalized-local', 'ended', 'uncertain'];
    public static function normalize(array $record): array
    {
        if (is_string($record['finalizationParameters'] ?? null) && preg_match('/\bNFPAG\s*=/i', $record['finalizationParameters'])) throw new SitefException('LEGACY_PAYLOAD_MIGRATION_REQUIRED', 'Journal legado contém corpo NFPAG; migre para armazenamento externo por hash antes de usar.');
        if (($record['version'] ?? null) === 1) {
            $payment = [...array_intersect_key($record, array_flip(self::PAYMENT_KEYS)), 'legacyReceiptStateUnknown' => true, 'confirmationAllowed' => false];
            $record = [...$record, 'version' => 2, 'payments' => [$payment]];
        }
        if (($record['version'] ?? null) !== 2 || !is_array($record['payments'] ?? null) || !array_is_list($record['payments']) || $record['payments'] === []) throw new SitefException('JOURNAL_INVALID', 'Journal v2 exige pagamentos ordenados.');
        $record = array_intersect_key($record, array_flip(self::GROUP_KEYS));
        if (!is_string($record['storeId'] ?? null) || !is_string($record['terminalId'] ?? null) || preg_match('/^\d{8}$/D', $record['storeId']) !== 1 || preg_match('/^[A-Za-z0-9]{8}$/D', $record['terminalId']) !== 1) throw new SitefException('JOURNAL_INVALID', 'Terminal do journal inválido.');
        self::validateMetadata($record);
        $ids = [];
        foreach ($record['payments'] as $index => $payment) {
            if (!is_array($payment)) throw new SitefException('JOURNAL_INVALID', 'Pagamento de journal inválido.');
            $payment = array_intersect_key($payment, array_flip(self::PAYMENT_KEYS)); self::validateMetadata($payment);
            if (isset($payment['finalizationParameters']) && preg_match('/\bNFPAG\s*=/i', $payment['finalizationParameters'])) throw new SitefException('LEGACY_PAYLOAD_MIGRATION_REQUIRED', 'Corpo NFPAG legado exige migração externa explícita.');
            if (isset($payment['finalizationPayload'])) {
                $payload = $payment['finalizationPayload'];
                if (!is_array($payload) || !is_string($payload['id'] ?? null) || $payload['id'] === '' || !is_string($payload['hash'] ?? null) || !preg_match('/^[0-9a-f]{64}$/D', $payload['hash'])) throw new SitefException('JOURNAL_INVALID', 'Referência de payload inválida.');
                $payment['finalizationPayload'] = ['id' => $payload['id'], 'hash' => $payload['hash']];
            }
            if (isset($ids[$payment['id']])) throw new SitefException('JOURNAL_INVALID', 'Pagamento duplicado no journal.');
            $ids[$payment['id']] = true;
            foreach (['paymentIndex', 'network'] as $key) if (isset($payment[$key]) && (!is_string($payment[$key]) || preg_match('/^\d+$/D', $payment[$key]) !== 1)) throw new SitefException('JOURNAL_INVALID', 'Seletor de journal inválido.');
            if (isset($payment['confirmationBlocked']) && !is_bool($payment['confirmationBlocked'])) throw new SitefException('JOURNAL_INVALID', 'Guarda de confirmação inválida.');
            foreach (['signatureRequired', 'signatureCaptured', 'legacyReceiptStateUnknown', 'confirmationAllowed'] as $key) if (isset($payment[$key]) && !is_bool($payment[$key])) throw new SitefException('JOURNAL_INVALID', 'Guarda de assinatura/comprovante inválida.');
            if (($payment['confirmationBlocked'] ?? false) || ($payment['confirmationAllowed'] ?? true) === false && !($payment['legacyReceiptStateUnknown'] ?? false)) { $payment['confirmationBlocked'] = true; $payment['confirmationAllowed'] = false; }
            $payment['receipts'] = array_map(static function ($receipt): array {
                if (!is_array($receipt)) throw new SitefException('JOURNAL_INVALID', 'Comprovante do journal inválido.');
                $receipt = array_intersect_key($receipt, array_flip(['id', 'fieldType', 'type', 'timing', 'hash', 'printed', 'stored']));
                if (!is_string($receipt['id'] ?? null) || !in_array($receipt['fieldType'] ?? null, [121, 122], true) || !in_array($receipt['timing'] ?? null, ['immediate', 'deferred'], true) || !is_string($receipt['hash'] ?? null) || !preg_match('/^[0-9a-f]{64}$/D', $receipt['hash']) || !is_bool($receipt['printed'] ?? null) || !is_bool($receipt['stored'] ?? null) || isset($receipt['type']) && !is_string($receipt['type'])) throw new SitefException('JOURNAL_INVALID', 'Metadados do comprovante inválidos.');
                return $receipt;
            }, $payment['receipts'] ?? []);
            $record['payments'][$index] = $payment;
        }
        $first = $record['payments'][0];
        foreach (['id', 'fiscalDocument', 'fiscalDate', 'fiscalTime', 'functionId'] as $key) if ($record[$key] !== $first[$key]) throw new SitefException('JOURNAL_INVALID', 'Identidade do grupo diverge do primeiro pagamento.');
        if (isset($record['updatedAt']) && !is_string($record['updatedAt'])) throw new SitefException('JOURNAL_INVALID', 'Timestamp de journal inválido.');
        return $record;
    }
    private static function validateMetadata(array $record): void
    {
        if (!is_string($record['id'] ?? null) || $record['id'] === '' || strlen($record['id']) > 128 || !in_array($record['state'] ?? null, self::STATES, true) || !is_int($record['functionId'] ?? null)) throw new SitefException('JOURNAL_INVALID', 'Metadados do journal inválidos.');
        try { Validation::input(['functionId' => $record['functionId'], 'amount' => '', 'fiscalDocument' => $record['fiscalDocument'] ?? null, 'fiscalDate' => $record['fiscalDate'] ?? null, 'fiscalTime' => $record['fiscalTime'] ?? null, 'operator' => '']); }
        catch (\Throwable $error) { throw new SitefException('JOURNAL_INVALID', 'Identidade fiscal de journal inválida.', false, null, $error); }
        if (isset($record['decision']) && !in_array($record['decision'], [0, 1], true)) throw new SitefException('JOURNAL_INVALID', 'Decisão de journal inválida.');
        if (isset($record['finalizationParameters'])) Validation::text($record['finalizationParameters'], 'finalizationParameters');
        if (isset($record['status']) && !is_int($record['status'])) throw new SitefException('JOURNAL_INVALID', 'Status de journal inválido.');
        if (isset($record['entryPoint']) && !is_string($record['entryPoint'])) throw new SitefException('JOURNAL_INVALID', 'Entrypoint de journal inválido.');
    }
}
