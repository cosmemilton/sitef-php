<?php
declare(strict_types=1);
// Original local IPC fixture, never loads a DLL or performs payments.
$mode = $argv[1] ?? 'good';
$position = array_search('--connect', $argv, true);
if (!is_int($position) || !isset($argv[$position + 1])) exit(2);
$socket = stream_socket_client('tcp://' . $argv[$position + 1], $errno, $error, 2);
if ($socket === false) exit(3);
$token = getenv('SITEF_BRIDGE_TOKEN'); putenv('SITEF_BRIDGE_TOKEN');
fwrite($socket, json_encode(['protocolVersion' => 1, 'transport' => 'tcp-loopback', 'token' => $mode === 'bad-auth' ? str_repeat('0', 64) : $token]) . "\n");
unset($token);
while (($line = fgets($socket)) !== false) {
    $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $id = $request['id'];
    if ($mode === 'timeout') { usleep(2000000); continue; }
    if ($mode === 'eof') { fclose($socket); exit(0); }
    if ($mode === 'malformed') { fwrite($socket, "invalid-json\n"); continue; }
    if ($mode === 'bom') { fwrite($socket, "\xef\xbb\xbf" . json_encode(['id' => $id, 'result' => (object)[]]) . "\n"); continue; }
    if ($mode === 'non-object') { fwrite($socket, json_encode(['id' => $id, 'result' => [1, 2]]) . "\n"); continue; }
    if ($mode === 'wrong-id') { fwrite($socket, json_encode(['id' => $id + 1, 'result' => (object)[]]) . "\n"); continue; }
    if ($mode === 'oversized') { fwrite($socket, str_repeat('X', 12000) . "\n"); continue; }
    if ($request['method'] === 'native-error') $reply = ['id' => $id, 'error' => ['code' => 'OPTIONAL_SYMBOL_UNAVAILABLE', 'message' => 'Unavailable fixture method']];
    else $reply = ['id' => $id, 'result' => ['method' => $request['method'], 'params' => $request['params']]];
    if ($mode === 'stderr-flood') for ($i = 0; $i < 100; $i++) fwrite(STDERR, str_repeat('X', 8192));
    $encoded = json_encode($reply) . "\n";
    // Exercise partial NDJSON frames, not only a single read.
    fwrite($socket, substr($encoded, 0, 3));
    usleep(1000);
    fwrite($socket, substr($encoded, 3));
}
fclose($socket);
