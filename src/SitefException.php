<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

final class SitefException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $uncertain = false,
        public readonly ?int $nativeStatus = null,
        ?\Throwable $previous = null,
    ) { parent::__construct($message, 0, $previous); }
}
