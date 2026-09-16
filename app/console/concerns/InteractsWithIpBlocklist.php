<?php

namespace App\Console\Concerns;

use Core\Security\IpBlocklist;

trait InteractsWithIpBlocklist
{
    private ?IpBlocklist $blocklistInstance = null;

    protected function blocklist(): IpBlocklist
    {
        return $this->blocklistInstance ??= new IpBlocklist();
    }

    protected function parseExpires(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d+)([mhd])$/i', $value, $matches) === 1) {
            $amount = (int) $matches[1];
            $unit = strtolower($matches[2]);
            $seconds = match ($unit) {
                'm' => $amount * 60,
                'h' => $amount * 3600,
                'd' => $amount * 86400,
                default => 0,
            };

            return date('Y-m-d H:i:s', time() + $seconds);
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}