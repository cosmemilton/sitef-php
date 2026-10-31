<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

final class ProductData
{
    private const KEYS = ['Id', 'd', 'Q', 'VU', 'T', 'TQ'];
    public static function buildProducts(array $items, string $encoding = 'windows-1252'): string
    {
        $records = []; $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) throw new SitefException('INVALID_PRODUCT_DATA', 'Produto deve ser objeto.');
            foreach (self::KEYS as $key) if (!is_string($item[$key] ?? null)) throw new SitefException('INVALID_PRODUCT_DATA', "Campo$key obrigatório como texto.");
            if (isset($seen[$item['Id']])) throw new SitefException('DUPLICATE_PRODUCT', 'Agregue códigos de produto repetidos explicitamente.');
            $seen[$item['Id']] = true;
            foreach (['Id' => 17, 'd' => 30, 'Q' => 9, 'VU' => 12] as $key => $maximum) if ($item[$key] === '' || strlen(Validation::encode($item[$key], $encoding)) > $maximum) throw new SitefException('INVALID_PRODUCT_LENGTH', "Campo$key excede limite do produto.");
            if (!ctype_digit($item['Q']) || !ctype_digit($item['VU']) || !in_array($item['T'], ['E', 'S'], true) || !in_array($item['TQ'], ['1', '2', '3'], true)) throw new SitefException('INVALID_PRODUCT_DATA', 'Quantidade/preço/tipo/unidade inválidos.');
            $parts = [];
            foreach (self::KEYS as $key) { if (strpbrk($item[$key], ',=') !== false || str_contains($item[$key], '<#>')) throw new SitefException('PRODUCT_DELIMITER', 'Produto contém delimitador sem escape documentado.'); $parts[] = "$key={$item[$key]}"; }
            $records[] = implode(',', $parts);
        }
        return $records === [] ? '' : implode('<#>', $records) . '<#>';
    }
    public static function parseProducts(string $buffer, string $encoding = 'windows-1252'): array
    {
        if ($buffer === '') return [];
        if (!str_ends_with($buffer, '<#>')) throw new SitefException('INVALID_PRODUCT_DATA', 'Lista de produtos exige<#>final.');
        $items = [];
        foreach (explode('<#>', substr($buffer, 0, -3)) as $row) {
            $item = [];
            foreach (explode(',', $row) as $part) { $pair = explode('=', $part, 2); if (count($pair) !== 2 || isset($item[$pair[0]])) throw new SitefException('INVALID_PRODUCT_DATA', 'Registro de produto inválido.'); $item[$pair[0]] = $pair[1]; }
            if (array_keys($item) !== self::KEYS) throw new SitefException('INVALID_PRODUCT_DATA', 'Ordem ou campos do produto inválidos.'); $items[] = $item;
        }
        self::buildProducts($items, $encoding);
        return array_map(static fn($item) => [...$item, 'unitPrice' => ExactDecimal::parse($item['VU'], 2)], $items);
    }
    public static function buildPix(array $items, string $encoding = 'windows-1252'): string
    {
        if (count($items) > 50) throw new SitefException('PIX_DATA_LIMIT', 'PIX aceita até50pares adicionais.');
        $records = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) || !is_string($item['value'] ?? null)) throw new SitefException('INVALID_PIX_DATA', 'ParPIX exige name/value.');
            if ($item['name'] === '' || strlen(Validation::encode($item['name'], $encoding)) > 50 || strlen(Validation::encode($item['value'], $encoding)) > 200) throw new SitefException('PIX_DATA_LIMIT', 'ParPIX excede limites nativos.');
            foreach ([$item['name'], $item['value']] as $value) if (str_contains($value, '<{|}>') || str_contains($value, '<{#}>')) throw new SitefException('PIX_DELIMITER', 'ParPIX contém delimitador sem escape documentado.');
            $records[] = $item['name'] . '<{|}>' . $item['value'];
        }
        return implode('<{#}>', $records);
    }
    public static function parsePix(string $buffer, string $encoding = 'windows-1252'): array
    {
        if ($buffer === '') return [];
        $records = [];
        foreach (explode('<{#}>', $buffer) as $row) { $pair = explode('<{|}>', $row); if (count($pair) !== 2) throw new SitefException('INVALID_PIX_DATA', 'ParPIX inválido.'); $records[] = ['name' => $pair[0], 'value' => $pair[1]]; }
        self::buildPix($records, $encoding); return $records;
    }
}
