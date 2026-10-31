<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

final class Validation
{
    public static function text(mixed $value, string $name): string
    {
        if (!is_string($value) || str_contains($value, "\0")) throw new SitefException('INVALID_ARGUMENT', "$name deve ser texto sem NUL.");
        return $value;
    }
    public static function absolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~D', $path) === 1 || str_starts_with($path, '\\\\');
    }
    public static function encode(string $value, string $encoding = 'windows-1252'): string
    {
        self::text($value, 'value');
        if (preg_match('//u', $value) !== 1) throw new SitefException('INVALID_UTF8', 'Texto da aplicação deve usarUTF8.');
        if ($encoding === 'utf-8') return $value;
        $target = $encoding === 'windows-1252' ? 'Windows-1252' : ($encoding === 'ascii' ? 'ASCII' : null);
        if ($target === null) throw new SitefException('INVALID_ENCODING', 'Codificação não suportada.');
        $bytes = @iconv('UTF-8', $target, $value);
        if ($bytes === false || @iconv($target, 'UTF-8', $bytes) !== $value) throw new SitefException('UNENCODABLE_INPUT', 'Texto não representável na codificação nativa, sem conversão com perda.');
        return $bytes;
    }
    public static function status(array $result): int
    {
        if (!isset($result['status']) || !is_int($result['status'])) throw new SitefException('INVALID_NATIVE_RESULT', 'Retorno nativo sem status inteiro.', true);
        return $result['status'];
    }
    public static function input(array $input): void
    {
        $function = $input['functionId'] ?? null;
        if (!is_int($function) || $function < -2147483648 || $function > 2147483647) throw new SitefException('INVALID_ARGUMENT', 'functionId deve ser int32.');
        foreach (['amount', 'fiscalDocument', 'fiscalDate', 'fiscalTime', 'operator'] as $key) self::text($input[$key] ?? null, $key);
        self::text($input['additionalParameters'] ?? '', 'additionalParameters');
        if ($input['amount'] !== '' && preg_match('/^\d+,\d{2}$/D', $input['amount']) !== 1) throw new SitefException('INVALID_AMOUNT', 'amount deve usar duas casas (10,00) ou vazio quando o valor será coletado.');
        $documentCharacters = preg_match_all('/./us', $input['fiscalDocument']);
        $operatorCharacters = preg_match_all('/./us', $input['operator']);
        if ($input['fiscalDocument'] === '' || $documentCharacters === false || $operatorCharacters === false || $documentCharacters > 20 || $operatorCharacters > 20 || preg_match('/^\d{8}$/D', $input['fiscalDate']) !== 1 || preg_match('/^\d{6}$/D', $input['fiscalTime']) !== 1) throw new SitefException('INVALID_FISCAL_ID', 'IDs fiscais e operador inválidos. Limites na codificação nativa são verificados pela ponte.');
        $date = $input['fiscalDate']; $time = $input['fiscalTime'];
        if (!checkdate((int)substr($date, 4, 2), (int)substr($date, 6, 2), (int)substr($date, 0, 4)) || (int)substr($time, 0, 2) > 23 || (int)substr($time, 2, 2) > 59 || (int)substr($time, 4, 2) > 59) throw new SitefException('INVALID_FISCAL_ID', 'Data/hora fiscal inválida.');
    }
}
