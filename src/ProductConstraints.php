<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

/** Evaluate only comparisons whose units, scope and inputs are actually known. */
final class ProductConstraints
{
    public static function assess(array $fields, string $encoding = 'windows-1252'): array
    {
        $medicines = []; $limits = []; $lists = [];
        foreach ($fields as $field) {
            $code = $field['fieldType'];
            if (in_array($code, [1014, 1016, 1042, 1043, 1044], true)) {
                $key = $field['context']['medicineIndex'] ?? '@unscoped'; $medicines[$key][$code] = $field;
            }
            if (in_array($code, [1122, 1123], true)) $limits[$code] = $field['buffer'];
            if (in_array($code, [1115, 1323], true)) $lists[] = $field;
        }
        $constraints = [];
        foreach ($medicines as $index => $medicine) {
            $raw = []; foreach ($medicine as $code => $field) $raw[$code] = $field['buffer'];
            if (isset($medicine[1014]) || isset($medicine[1016])) {
                $ceiling = ['kind' => 'pbm-consumer-ceiling', 'scope' => $index === '@unscoped' ? null : ['medicineIndex' => (string)$index], 'fieldTypes' => [1014, 1016], 'raw' => $raw, 'state' => 'unresolved', 'reason' => 'Índice ou escala do preço máximo/praticado não determinados.'];
                $maximum = $medicine[1014]['value'] ?? null; $practiced = $medicine[1016]['value'] ?? null;
                if ($index !== '@unscoped' && is_array($maximum) && is_array($practiced) && isset($maximum['unscaled'], $practiced['unscaled'])) {
                    $valid = ExactDecimal::compare($practiced, $maximum) <= 0; $ceiling['state'] = $valid ? 'satisfied' : 'violated'; $ceiling['reason'] = $valid ? 'Preço não excede o máximo ao consumidor.' : 'Preço praticado excede o máximo ao consumidor.';
                }
                $constraints[] = $ceiling;
            }
            if (!isset($medicine[1042]) && !isset($medicine[1043]) && !isset($medicine[1044])) continue;
            $entry = ['kind' => 'pbm-price', 'scope' => $index === '@unscoped' ? null : ['medicineIndex' => (string)$index], 'fieldTypes' => [1042, 1043, 1044], 'raw' => $raw, 'state' => 'unresolved', 'reason' => 'Índice, condição ou escalas de preço não determinados.'];
            $reference = $medicine[1043]['value'] ?? null; $actual = $medicine[1044]['value'] ?? null;
            if ($index !== '@unscoped' && isset($medicine[1042]) && is_array($reference) && is_array($actual) && isset($reference['unscaled'], $actual['unscaled']) && in_array($medicine[1042]['buffer'], ['0', '1'], true)) {
                $comparison = ExactDecimal::compare($actual, $reference);
                if ($medicine[1042]['buffer'] === '1' && $comparison > 0) { $entry['state'] = 'unresolved'; $entry['reason'] = 'Permissão de preço inferior não define o tratamento de preço superior ao Funcional Card.'; }
                else { $valid = $medicine[1042]['buffer'] === '0' ? $comparison === 0 : true; $entry['state'] = $valid ? 'satisfied' : 'violated'; $entry['reason'] = $valid ? 'Preço atende à condição PBM documentada.' : 'Preço praticado contraria a condição PBM documentada.'; }
            }
            $constraints[] = $entry;
        }
        foreach ($limits as $code => $limit) {
            $entry = ['kind' => $code === 1122 ? 'maximum-products' : 'product-code-length', 'fieldTypes' => [$code, 1115, 1323], 'raw' => [$code => $limit], 'state' => 'unresolved', 'reason' => 'Limite zero, lista ou unidade de comparação não determinados.'];
            if (!ctype_digit($limit) || ltrim($limit, '0') === '' || $lists === []) { $constraints[] = $entry; continue; }
            $maximum = strlen(ltrim($limit, '0')) > 7 ? PHP_INT_MAX : (int)$limit;
            $states = [];
            foreach ($lists as $list) {
                $items = $list['value']; if (is_array($items) && isset($items['items'])) $items = $items['items'];
                if (!is_array($items) || !array_is_list($items)) { $states[] = 'unresolved'; continue; }
                if ($code === 1122) { $states[] = count($items) <= $maximum ? 'satisfied' : 'violated'; continue; }
                $lengths = [];
                foreach ($items as $item) {
                    $identifier = $item['Id'] ?? $item['productCode'] ?? null;
                    if (!is_string($identifier)) { $lengths[] = 'unresolved'; continue; }
                    $length = strlen(Validation::encode($identifier, $encoding));
                    $lengths[] = $length > $maximum ? 'violated' : ($length === $maximum ? 'satisfied' : 'unresolved');
                }
                $states[] = in_array('violated', $lengths, true) ? 'violated' : (in_array('unresolved', $lengths, true) || $lengths === [] ? 'unresolved' : 'satisfied');
            }
            $entry['state'] = in_array('violated', $states, true) ? 'violated' : (in_array('unresolved', $states, true) ? 'unresolved' : 'satisfied');
            $entry['reason'] = $entry['state'] === 'violated' ? 'Limite de produto inequivocamente excedido.' : ($entry['state'] === 'satisfied' ? 'Dados completos atendem ao limite.' : 'Tamanho menor pode significar limite máximo ou tamanho exato; exige aplicação.');
            $constraints[] = $entry;
        }
        return $constraints;
    }
}
