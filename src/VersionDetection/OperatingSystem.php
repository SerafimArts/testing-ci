<?php

declare(strict_types=1);

namespace Tests\VersionDetection;

final readonly class OperatingSystem
{
    public function __construct(
        /**
         * @var non-empty-lowercase-string
         */
        public string $family,
        /**
         * @var non-empty-string
         */
        public string $name,
        public ?bool $isPosix = null,
        /**
         * @var non-empty-lowercase-string|null
         */
        public ?string $extension = null,
        public ?Version $version = null,
    ) {}
}