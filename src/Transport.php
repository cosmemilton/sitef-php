<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

interface Transport
{
    /** @param array<string,mixed> $parameters @return array<string,mixed> */
    public function call(string $method, array $parameters = []): array;
    public function close(): void;
}
