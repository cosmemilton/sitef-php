<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$failed = false; $count = 0;
foreach ($files as $file) {
    $relative = substr($file->getPathname(), strlen($root) + 1);
    if ($file->getExtension() !== 'php' || preg_match('~^(vendor|work|native)[\\\\/]~', $relative) === 1) continue;
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-l', $file->getPathname()], [STDIN, STDOUT, STDERR], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process) || proc_close($process) !== 0) $failed = true;
    $count++;
}
if ($count === 0) { fwrite(STDERR, "No PHP files were linted.\n"); exit(1); }
exit($failed ? 1 : 0);
