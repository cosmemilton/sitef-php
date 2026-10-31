<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

/** Shared, versioned field/function definitions. Raw codes remain open to extensions. */
final class Semantics
{
    private static array $documents = [];
    public static function document(string $name): array
    {
        if (!in_array($name, ['fields', 'functions'], true)) throw new SitefException('INVALID_SCHEMA', 'Documento semântico inválido.');
        if (!isset(self::$documents[$name])) {
            $bytes = @file_get_contents(dirname(__DIR__) . '/protocol/' . $name . '.json');
            if ($bytes === false) throw new SitefException('SEMANTIC_SCHEMA_MISSING', "Definições semânticas $name ausentes.");
            $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (($document['schemaVersion'] ?? null) !== 1 || !is_array($document['entries'] ?? null)) throw new SitefException('SEMANTIC_SCHEMA_INVALID', 'Schema semântico incompatível.');
            self::$documents[$name] = $document;
        }
        return self::$documents[$name];
    }
    public static function function(int $code, ?string $override = null): array
    {
        $definition = self::document('functions')['entries'][$code] ?? ['name' => "Unknown function $code", 'domain' => 'unknown', 'finalizationPolicy' => 'explicit', 'receiptPolicy' => 'immediate'];
        if ($override !== null) {
            if (!in_array($override, ['financial', 'nonfinancial'], true)) throw new SitefException('INVALID_POLICY', 'Política deve ser financial ou nonfinancial.');
            if (($definition['finalizationPolicy'] ?? 'explicit') !== 'explicit' && $definition['finalizationPolicy'] !== $override) throw new SitefException('FINALIZATION_POLICY_MISMATCH', 'Política explícita contradiz uma função conhecida.');
            $definition['finalizationPolicy'] = $override;
        }
        if (($definition['finalizationPolicy'] ?? 'explicit') === 'explicit') throw new SitefException('FUNCTION_POLICY_REQUIRED', "Função $code exige política explícita de finalização.");
        return ['code' => $code, ...$definition];
    }
    public static function field(int $code, string $context = 'transaction'): array
    {
        $document = self::document('fields');
        $entry = $document['entries'][$code] ?? null;
        $best = null;
        foreach ($document['ranges'] ?? [] as $range) {
            if ($code < $range['from'] || $code > $range['to']) continue;
            $contexts = $range['contexts'] ?? [];
            if ($contexts !== [] && !in_array($context, $contexts, true)) continue;
            if ($best === null || $contexts !== []) $best = $range;
        }
        if ($context === 'secure-card-read' && $best !== null && in_array($context, $best['contexts'] ?? [], true)) $entry = $best;
        elseif ($entry === null) $entry = $best;
        if ($entry !== null && ($entry['contexts'] ?? []) !== [] && !in_array($context, $entry['contexts'], true)) $entry = null;
        return $entry === null ? ['code' => $code, 'known' => false, 'name' => "Unknown field $code", 'dataType' => 'text'] : ['code' => $code, 'known' => true, ...$entry];
    }
}
