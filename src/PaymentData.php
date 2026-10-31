<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

/** NFPAG is attachment data for the entire document/date, never a selective finalization. */
final class PaymentData
{
    public static function build(string $paymentIndex, array $requirements, array $payments, string $expectedTotal): string
    {
        if (!ctype_digit($paymentIndex) || !array_is_list($payments) || $payments === []) throw new SitefException('INVALID_NFPAG', 'Índice e formas de pagamento são obrigatórios.');
        $maximum = $requirements['maximum'] ?? null;
        if (!is_string($maximum) || !ctype_digit($maximum)) throw new SitefException('NFPAG_REQUIREMENTS_MISSING', 'Limite730 não foi recebido.');
        if (ltrim($maximum, '0') !== '' && strlen(ltrim($maximum, '0')) < 7 && count($payments) > (int)$maximum) throw new SitefException('NFPAG_LIMIT', 'Quantidade de formas excede730.');
        $methods = [];
        foreach ($requirements['methods'] ?? [] as $method) {
            if (isset($methods[$method['type']]) && $methods[$method['type']] !== $method) throw new SitefException('NFPAG_REQUIREMENTS_AMBIGUOUS', 'Forma731 repetida com requisitos diferentes exige provider explícito.');
            $methods[$method['type']] = $method;
        }
        $total = ExactDecimal::parse('0'); $records = [];
        foreach ($payments as $payment) {
            $type = $payment['type'] ?? null; $amount = $payment['amount'] ?? null; $data = $payment['data'] ?? [];
            if (!is_string($type) || !isset($methods[$type]) || !is_string($amount) || !is_array($data)) throw new SitefException('INVALID_NFPAG', 'Forma de pagamento não habilitada ou dados inválidos.');
            $decimal = ExactDecimal::parse($amount);
            if ($decimal['scale'] > 2 || ExactDecimal::compare($decimal, ExactDecimal::parse('0')) < 0) throw new SitefException('INVALID_NFPAG_AMOUNT', 'ValorN exige valor não negativo com até2casas.');
            $total = ExactDecimal::add($total, $decimal);
            $minorUnits = $decimal['unscaled'] . str_repeat('0', 2 - $decimal['scale']);
            $required = $methods[$type]['requiredData'] ?? [];
            foreach ($required as $id) {
                if (in_array($id, ['00', '05', '13'], true) || str_starts_with($id, '13(')) continue;
                if (!array_key_exists($id, $data)) throw new SitefException('NFPAG_DATA_REQUIRED', "Dado NFPAG$id obrigatório para$type.");
            }
            $parts = [];
            foreach ($data as $id => $value) {
                $id = (string)$id;
                if (strlen($id) === 1) $id = '0' . $id;
                if (!in_array($id, $required, true) || in_array($id, ['00', '05', '13'], true)) throw new SitefException('INVALID_NFPAG_DATA', 'Dado NFPAG não solicitado, reservado ou constraint13 não transmissível.');
                Validation::text($value, 'NFPAG data');
                if ($id === '10') $value = explode(';', $value, 2)[0]; // Only the documented extended confirmation suffix is removed.
                if ($value === '' || preg_match('/[:;{}\-]/', $value)) throw new SitefException('NFPAG_DELIMITER', 'Dado NFPAG contém delimitador sem escape documentado.');
                if ($id === '01' && !in_array($value, ['0', '1', '2'], true)) throw new SitefException('INVALID_NFPAG_CHEQUE', 'OrigemNFPAG01:0CMC7lido,1primeira linha,2CMC7digitado.');
                if ($id === '03' && !ctype_digit($value)) throw new SitefException('INVALID_NFPAG_NETWORK', 'Rede03 deve ser numérica, conservando zeros à esquerda.');
                if ($id === '08' && (!preg_match('/^\d{8}$/D', $value) || !checkdate((int)substr($value, 2, 2), (int)substr($value, 0, 2), (int)substr($value, 4, 4)))) throw new SitefException('INVALID_NFPAG_DATE', 'DataHost08 exigeDDMMAAAA.');
                $rule = Semantics::document('fields')['rules']['nfPag']['requirements'][$id] ?? [];
                if (isset($rule['choices']) && !array_key_exists($value, $rule['choices'])) throw new SitefException('INVALID_NFPAG_DATA', 'Dado NFPAG fora das opções documentadas.');
                if ($id === '02' && ($data['01'] ?? $data[1] ?? null) === '1' && preg_match('/^\d{29}$/D', $value) !== 1) throw new SitefException('INVALID_NFPAG_CHEQUE', 'Primeira linha deve incluir29dígitos comDVs.');
                if ($id === '03' && ($methods[$type]['allowedNetworks'] ?? []) !== []) {
                    $networks = array_map(static fn($network) => ltrim($network, '0') ?: '0', $methods[$type]['allowedNetworks']);
                    if (!in_array(ltrim($value, '0') ?: '0', $networks, true)) throw new SitefException('NFPAG_NETWORK_NOT_ALLOWED', 'Rede03 fora da restrição13.');
                }
                $parts[] = "$id:$value";
            }
            $records[] = "$type:$minorUnits" . ($parts === [] ? '' : ':' . implode('-', $parts));
        }
        if (ExactDecimal::compare($total, ExactDecimal::parse($expectedTotal)) !== 0) throw new SitefException('NFPAG_TOTAL_MISMATCH', 'Soma das formas não corresponde ao total da transação.');
        return '{NumeroPagamentoNFPAG=' . $paymentIndex . '}{NFPAG=' . implode(';', $records) . ';}';
    }
}
