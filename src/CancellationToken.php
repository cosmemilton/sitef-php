<?php
declare(strict_types=1);
namespace Cosmemilton\Sitef;

final class CancellationToken
{
    private bool $cancelled = false;
    public function cancel(): void { $this->cancelled = true; }
    public function isCancelled(): bool { return $this->cancelled; }
}
