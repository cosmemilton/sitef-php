<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

/** Decimal arithmetic on strings. No float, machine integer or locale conversion. */
final class ExactDecimal
{
    public static function parse(string $value, ?int $impliedScale = null): array
    {
        if ($impliedScale !== null) {
            if ($impliedScale < 0 || $impliedScale > 20000 || preg_match('/^[+-]?\d+$/D', $value) !== 1) throw new SitefException('INVALID_DECIMAL', 'Valor decimal implícito inválido.');
            $negative = str_starts_with($value, '-'); $digits = ltrim(ltrim($value, '+-'), '0'); $digits = $digits === '' ? '0' : $digits;
            return ['raw' => $value, 'unscaled' => ($negative && $digits !== '0' ? '-' : '') . $digits, 'scale' => $impliedScale];
        }
        if (preg_match('/^([+-]?)(\d+)(?:[,.](\d+))?$/D', $value, $match) !== 1) throw new SitefException('INVALID_DECIMAL', 'Valor decimal inválido.');
        $fraction = $match[3] ?? ''; $digits = ltrim($match[2] . $fraction, '0'); $digits = $digits === '' ? '0' : $digits;
        return ['raw' => $value, 'unscaled' => ($match[1] === '-' && $digits !== '0' ? '-' : '') . $digits, 'scale' => strlen($fraction)];
    }
    public static function compare(array $left, array $right): int
    {
        [$a, $b] = self::align($left, $right);
        $an = str_starts_with($a, '-'); $bn = str_starts_with($b, '-');
        if ($an !== $bn) return $an ? -1 : 1;
        $comparison = self::unsignedCompare(ltrim($a, '-'), ltrim($b, '-'));
        return $an ? -$comparison : $comparison;
    }
    public static function subtract(array $left, array $right): array
    {
        [$a, $b, $scale] = self::align($left, $right);
        $an = str_starts_with($a, '-'); $bn = str_starts_with($b, '-'); $a = ltrim($a, '-'); $b = ltrim($b, '-');
        if ($an !== $bn) { $digits = self::unsignedAdd($a, $b); $negative = $an; }
        elseif (self::unsignedCompare($a, $b) >= 0) { $digits = self::unsignedSubtract($a, $b); $negative = $an; }
        else { $digits = self::unsignedSubtract($b, $a); $negative = !$an; }
        $coefficient = ($negative && $digits !== '0' ? '-' : '') . $digits;
        return ['raw' => self::format($coefficient, $scale), 'unscaled' => $coefficient, 'scale' => $scale];
    }
    public static function add(array $left, array $right): array
    {
        $opposite = $right;
        $opposite['unscaled'] = str_starts_with($right['unscaled'], '-') ? substr($right['unscaled'], 1) : ($right['unscaled'] === '0' ? '0' : '-' . $right['unscaled']);
        return self::subtract($left, $opposite);
    }
    public static function format(string $coefficient, int $scale): string
    {
        $negative = str_starts_with($coefficient, '-'); $digits = ltrim($coefficient, '-');
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $text = $scale === 0 ? $digits : substr($digits, 0, -$scale) . ',' . substr($digits, -$scale);
        return ($negative ? '-' : '') . $text;
    }
    private static function align(array $left, array $right): array
    {
        $scale = max($left['scale'], $right['scale']);
        $a = $left['unscaled'] . str_repeat('0', $scale - $left['scale']); $b = $right['unscaled'] . str_repeat('0', $scale - $right['scale']);
        return [$a, $b, $scale];
    }
    private static function unsignedCompare(string $a, string $b): int
    {
        $a = ltrim($a, '0') ?: '0'; $b = ltrim($b, '0') ?: '0';
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
    }
    private static function unsignedAdd(string $a, string $b): string
    {
        $result = ''; $carry = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
            $sum = ($i >= 0 ? ord($a[$i]) - 48 : 0) + ($j >= 0 ? ord($b[$j]) - 48 : 0) + $carry;
            $result = (string)($sum % 10) . $result; $carry = intdiv($sum, 10);
        }
        return ltrim($result, '0') ?: '0';
    }
    private static function unsignedSubtract(string $a, string $b): string
    {
        $result = ''; $borrow = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0; $i--, $j--) {
            $digit = ord($a[$i]) - 48 - $borrow - ($j >= 0 ? ord($b[$j]) - 48 : 0);
            $borrow = $digit < 0 ? 1 : 0; if ($digit < 0) $digit += 10; $result = (string)$digit . $result;
        }
        return ltrim($result, '0') ?: '0';
    }
}
