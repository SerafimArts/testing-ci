<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\VarDumper\VarDumper;
use Tests\VersionDetection\NativeOperatingSystemDetector;

final class VersionDetectionTest extends TestCase
{
    public function testVersionDetection(): void
    {
        $this->expectNotToPerformAssertions();

        $detector = new NativeOperatingSystemDetector();

        VarDumper::dump($detector->detect());
    }
}