<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

final class Sitef
{
    /** @param array<string,mixed> $options */
    public static function create(array $options): Client
    {
        $testMode = $options['testMode'] ?? false;
        if (!is_bool($testMode)) throw new SitefException('INVALID_ARGUMENT', 'testMode deve ser boolean e é exclusivo para fixtures próprias.');
        if (!$testMode && (PHP_OS_FAMILY !== 'Windows' || PHP_INT_SIZE !== 8)) throw new SitefException('UNSUPPORTED_RUNTIME', 'Produção requer PHP Windows x64. Linux é permitido apenas com testMode para fixture sintética.');
        foreach (['bridgeExecutable', 'bridgeDirectory', 'libraryPath', 'stateDirectory'] as $key) {
            Validation::text($options[$key] ?? null, $key);
            if (!Validation::absolute($options[$key])) throw new SitefException('INVALID_PATH', "$key precisa ser absoluto.");
        }
        if (!is_array($options['configuration'] ?? null) || !is_callable($options['interaction'] ?? null) || (isset($options['onExchange']) && !is_callable($options['onExchange']))) throw new SitefException('INVALID_OPTIONS', 'configuration, interaction ou onExchange inválido.');
        $encoding = $options['nativeEncoding'] ?? 'windows-1252';
        if (!in_array($encoding, ['windows-1252', 'utf-8', 'ascii'], true)) throw new SitefException('INVALID_OPTIONS', 'Codificação nativa inválida.');
        $transport = new BridgeTransport($options['bridgeExecutable'], $options['bridgeDirectory'], $options['bridgeArguments'] ?? [], (float)($options['timeoutSeconds'] ?? 120), $options['maxFrameBytes'] ?? 1048576);
        try {
            $parameters = ['libraryPath' => $options['libraryPath'], 'nativeEncoding' => $encoding, 'testMode' => $testMode];
            if (isset($options['workingDirectory'])) { Validation::text($options['workingDirectory'], 'workingDirectory'); $parameters['workingDirectory'] = $options['workingDirectory']; }
            $capabilities = $transport->call('load', $parameters);
            if (($capabilities['protocolVersion'] ?? null) !== 1) throw new SitefException('PROTOCOL_MISMATCH', 'Versão incompatível da ponte.');
            if (!$testMode && (($capabilities['platform'] ?? null) !== 'windows' || ($capabilities['architecture'] ?? null) !== 'x86_64' || ($capabilities['fieldTypeBytes'] ?? null) !== 4)) throw new SitefException('UNSUPPORTED_BRIDGE', 'Produção requer ponte Windows x86_64 com long de32bits.');
            $configuration = $options['configuration'];
            $terminalKey = hash('sha256', ($configuration['storeId'] ?? '') . "\0" . ($configuration['terminalId'] ?? ''));
            $journal = new FileJournal(rtrim($options['stateDirectory'], '\\/') . DIRECTORY_SEPARATOR . $terminalKey . '.journal.json');
            return Client::connect($transport, $configuration, $journal, $options['interaction'], $options['onExchange'] ?? null, $options['bufferSize'] ?? 20000, $options['maxSteps'] ?? 100000);
        } catch (\Throwable $error) { $transport->close(); throw $error; }
    }
}
