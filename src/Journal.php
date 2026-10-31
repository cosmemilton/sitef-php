<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

interface Journal
{
    public function acquire(): void;
    public function read(): ?array;
    public function write(array $record): void;
    public function release(): void;
}
