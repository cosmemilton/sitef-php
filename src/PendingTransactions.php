<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

final class PendingTransactions
{
    public static function parse(array $fields): array
    {
        $count = null; $records = []; $current = null;
        $map = [160 => 'fiscalDocument', 161 => 'paymentIndex', 163 => 'fiscalDate', 164 => 'fiscalTime', 211 => 'functionId', 1319 => 'amount'];
        foreach ($fields as $field) {
            $code = $field['fieldType']; $buffer = $field['buffer'];
            if ($code === 210) {
                if (!ctype_digit($buffer) || $count !== null) throw new SitefException('INVALID_PENDING_DATA', 'Contagem210 inválida ou repetida.');
                $count = $buffer; continue;
            }
            if (!isset($map[$code])) continue;
            if ($code === 160) { if ($current !== null) $records[] = self::validate($current); $current = ['fields' => []]; }
            if ($current === null) throw new SitefException('INVALID_PENDING_DATA', 'Campo de pendência antes do cupom160.');
            $key = $map[$code];
            if (array_key_exists($key, $current)) throw new SitefException('INVALID_PENDING_DATA', 'Campo repetido no mesmo bloco de pendência.');
            $current[$key] = $buffer; $current['fields'][] = $field;
        }
        if ($current !== null) $records[] = self::validate($current);
        if ($count === null || (ltrim($count, '0') ?: '0') !== (string)count($records)) throw new SitefException('PENDING_COUNT_MISMATCH', 'Quantidade210 diverge dos blocos recebidos.');
        return ['count' => count($records), 'rawCount' => $count, 'records' => $records, 'transactions' => $records];
    }
    private static function validate(array $record): array
    {
        foreach (['fiscalDocument', 'paymentIndex', 'fiscalDate', 'fiscalTime', 'functionId', 'amount'] as $key) if (!isset($record[$key])) throw new SitefException('INVALID_PENDING_DATA', "Campo$key ausente na pendência.");
        if (!ctype_digit($record['paymentIndex']) || preg_match('/^-?\d+$/D', $record['functionId']) !== 1) throw new SitefException('INVALID_PENDING_DATA', 'IDs da pendência inválidos.');
        Validation::input(['functionId' => 0, 'amount' => '', 'fiscalDocument' => $record['fiscalDocument'], 'fiscalDate' => $record['fiscalDate'], 'fiscalTime' => $record['fiscalTime'], 'operator' => '']);
        // 1319 remains text: its unit is not inferred without the product contract.
        return $record;
    }
}
