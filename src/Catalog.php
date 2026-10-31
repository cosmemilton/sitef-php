<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

/** Shared documentary catalogue. Entries do not assert business-flow implementation. */
final class Catalog
{
    private static ?array $data = null;
    public static function all(): array
    {
        if (self::$data === null) {
            $path = dirname(__DIR__) . '/protocol/catalog.json';
            $bytes = @file_get_contents($path);
            if ($bytes === false) throw new SitefException('CATALOG_MISSING', 'Catálogo compartilhado ausente.');
            self::$data = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if ((self::$data['schemaVersion'] ?? null) !== 1) throw new SitefException('CATALOG_SCHEMA', 'Versão de catálogo incompatível.');
        }
        return self::$data;
    }
    public static function commandInfo(int $code): array
    {
        return self::all()['commands'][$code] ?? ['kind' => 'unknown', 'blocking' => true, 'description' => "Comando não catalogado ($code); exige implementação explícita"];
    }
    public static function functionInfo(int $code): array
    {
        $name = self::all()['functionIds'][$code] ?? null;
        return ['code' => $code, 'known' => $name !== null, 'description' => $name ?? "Função não catalogada ($code)", 'businessFlowVerified' => false];
    }
    public static function classifyReturn(int $code, string $context = 'transaction'): array
    {
        $catalog = self::all();
        $result = static fn(string $kind, string $message, bool $known = true): array => ['code' => $code, 'known' => $known, 'kind' => $kind, 'message' => $message];
        if ($context === 'configuration') return $result($code === 0 ? 'success' : 'error', $catalog['configurationReturns'][$code] ?? "Configuração: retorno desconhecido ($code)", isset($catalog['configurationReturns'][$code]));
        if ($context === 'pending-count' && $code >= 0) return $result('count', "$code transações pendentes");
        if ($context === 'pinpad-presence') return $result($code === 1 ? 'present' : ($code === 0 ? 'absent' : 'error'), $code === 1 ? 'Pinpad operacional' : ($code === 0 ? 'Nenhum pinpad operacional' : "Erro pinpad ($code)"), in_array($code, [-1, 0, 1], true));
        if ($context === 'pinpad-confirmation') return $result($code === 1 ? 'confirmed' : ($code === 0 ? 'cancelled' : 'error'), $code === 1 ? 'Confirmado no pinpad' : ($code === 0 ? 'Cancelado no pinpad' : "Erro pinpad ($code)"), in_array($code, [0, 1], true));
        if ($context === 'barcode-validation') return $result($code === 0 ? 'success' : ($code >= -1 && $code <= 5 ? 'invalid-data' : 'error'), $code === 0 ? 'Código válido até o ponto informado' : "Código inválido ou retorno da validação ($code)", $code >= -1 && $code <= 5);
        if ($context === 'secure-read') return $result($code === 0 ? 'success' : 'error', $catalog['secureReadReturns'][$code] ?? "Leitura segura: retorno desconhecido ($code)", isset($catalog['secureReadReturns'][$code]));
        if ($context === 'pinpad-key') {
            $keys = [0 => 'OK/ENTER', 4 => 'F1', 5 => 'F2', 6 => 'F3', 7 => 'F4', 8 => 'LIMPA', 13 => 'CANCELA'];
            return $result(isset($keys[$code]) ? 'key' : 'error', $keys[$code] ?? "Erro tecla pinpad ($code)", isset($keys[$code]));
        }
        if ($context === 'zero-status') return $result($code === 0 ? 'success' : 'error', $code === 0 ? 'Função concluída' : "Falha nativa ($code)", $code === 0);
        if (!in_array($context, ['transaction', 'secure-transaction', 'pending-count'], true)) throw new SitefException('INVALID_RETURN_CONTEXT', 'Contexto de retorno desconhecido.');
        if ($context === 'secure-transaction' && $code === 12) return $result('error', $catalog['secureReadReturns'][12]);
        if ($code === 0) return $result('success', $catalog['transactionReturns'][0]);
        if ($code === 10000) return $result('continue', $catalog['transactionReturns'][10000]);
        if ($code > 0) return $result('authorizer-declined', "Recusa do autorizador ($code)", false);
        return $result(in_array($code, [-2, -6, -15], true) ? 'cancelled' : (in_array($code, [-7, -42], true) ? 'reserved' : 'error'), $catalog['transactionReturns'][$code] ?? "Falha interna não catalogada ($code)", isset($catalog['transactionReturns'][$code]));
    }
}
