<?php

declare(strict_types=1);

namespace Tests\VersionDetection;

final readonly class Version implements \Stringable
{
    public function __construct(
        /**
         * Major OS version number.
         *
         * @var int<0, max>
         */
        public int $major = 0,
        /**
         * Minor OS version number.
         *
         * @var int<0, max>|null
         */
        public ?int $minor = null,
        /**
         * Patch OS version number.
         *
         * @var int<0, max>|null
         */
        public ?int $patch = null,
        /**
         * Build OS version number.
         *
         * @var int<0, max>|null
         */
        public ?int $build = null,
    ) {}

    /**
     * @return non-empty-lowercase-string
     */
    public function __toString(): string
    {
        $result = (string) $this->major;

        foreach ([$this->minor, $this->patch, $this->build] as $segment) {
            if ($segment === null) {
                return $result;
            }

            $result .= '.' . $segment;
        }

        return $result;
    }
}
