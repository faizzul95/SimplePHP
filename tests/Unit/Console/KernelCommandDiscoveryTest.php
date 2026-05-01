<?php

declare(strict_types=1);

use Core\Console\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelCommandDiscoveryTest extends TestCase
{
    public function testKernelDiscoversClassBasedCommandsFromAppConsoleDirectory(): void
    {
        $kernel = new Kernel();
        $kernel->bootstrap();

        self::assertTrue($kernel->has('about'));
        self::assertTrue($kernel->has('db:status'));
    }
}