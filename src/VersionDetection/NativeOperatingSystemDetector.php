<?php

declare(strict_types=1);

namespace Tests\VersionDetection;

final readonly class NativeOperatingSystemDetector
{
    public function detect(): OperatingSystem
    {
        $family = $this->detectFamily();

        return new OperatingSystem(
            family: $family,
            name: $this->detectName(),
            isPosix: $this->detectIsPosix($family),
            extension: $this->detectExtension($family),
            version: $this->detectVersion($family),
        );
    }

    /**
     * @return non-empty-lowercase-string
     */
    private function detectFamily(): string
    {
        return match (\PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'BSD', 'Solaris' => 'unix',
            'Linux' => 'linux',
            'Darwin' => 'osx',
            default => 'other',
        };
    }

    /**
     * @return non-empty-string
     */
    private function detectName(): string
    {
        $default = \php_uname('s');

        return match (\PHP_OS_FAMILY) {
            'BSD', 'Solaris', 'Linux' => $this->tryDetectLinuxName()
                ?? $default,
            // TODO Darwin
            'Darwin' => $default,
            default => $default,
        };
    }

    /**
     * @return non-empty-string|null
     */
    private function tryDetectLinuxName(): ?string
    {
        return $this->tryDetectLinuxNameFromEtcRelease('/etc/os-release', 'NAME')
            ?? $this->tryDetectLinuxNameFromEtcRelease('/etc/lsb-release', 'DISTRIB_ID')
            ?? $this->tryDetectLinuxNameFromWindowsSubsystem();
    }

    /**
     * @return non-empty-string|null
     */
    private function tryDetectLinuxNameFromWindowsSubsystem(): ?string
    {
        $fullName = $_SERVER['WSL_DISTRO_NAME'] ?? null;

        if ($fullName === null) {
            return null;
        }

        \preg_match('/\D+/', (string)$fullName, $matches);

        if (!\is_string($matches[0]) || $matches[0] === '') {
            return null;
        }

        $name = self::trimNonWords($matches[0]);

        if ($name === '') {
            return null;
        }

        return $name;
    }

    /**
     * @param non-empty-string $pathname
     * @param non-empty-string $expectedKey
     * @return non-empty-string|null
     */
    private function tryDetectLinuxNameFromEtcRelease(string $pathname, string $expectedKey): ?string
    {
        if (!\is_readable($pathname)) {
            return null;
        }

        $stream = \fopen($pathname, 'rb');

        try {
            foreach ($this->parseKeyValStream($stream) as $key => $value) {
                if ($key === $expectedKey) {
                    return $value;
                }
            }

            return null;
        } finally {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
    }

    /**
     * @param resource $stream
     * @return iterable<non-empty-string, non-empty-string>
     */
    private function parseKeyValStream(mixed $stream): iterable
    {
        while (!\feof($stream)) {
            $line = (string)\fgets($stream);

            if (($keyEndsAt = \strpos($line, '=')) === false) {
                continue;
            }

            $key = self::trimNonWords(\substr($line, 0, $keyEndsAt));
            $value = self::trimNonWords(\substr($line, $keyEndsAt + 1));

            if ($key !== '' && $value !== '') {
                yield $key => $value;
            }
        }
    }

    /**
     * @param non-empty-lowercase-string $family
     */
    private function detectIsPosix(string $family): ?bool
    {
        return match ($family) {
            'windows' => false,
            'unix', 'linux', 'osx' => true,
            default => null,
        };
    }

    /**
     * @param non-empty-lowercase-string $family
     * @return non-empty-lowercase-string|null
     */
    private function detectExtension(string $family): ?string
    {
        if (\defined('\\PHP_SHLIB_SUFFIX')) {
            return \PHP_SHLIB_SUFFIX;
        }

        return match ($family) {
            'windows' => 'dll',
            'linux', 'unix' => 'so',
            'osx' => 'dylib',
            default => \PHP_SHLIB_SUFFIX === '' ? \PHP_SHLIB_SUFFIX : null,
        };
    }

    /**
     * @param non-empty-lowercase-string $family
     */
    private function detectVersion(string $family): ?Version
    {
        return match ($family) {
            'windows' => $this->tryDetectWindowsVersion(),
            // TODO Is OSX should be detected like any *nix OS?
            'linux', 'unix', 'osx' => $this->tryDetectLinuxKernelVersion(),
            default => new Version(),
        };
    }

    private function tryDetectWindowsVersion(): ?Version
    {
        return $this->tryDetectAccurateWindowsVersion()
            ?? $this->tryDetectKernelModeDriverWindowsVersion()
            ?? $this->tryDetectApproximateWindowsVersion();
    }

    /**
     * Try to detect Windows version using builtin constants.
     */
    private function tryDetectAccurateWindowsVersion(): ?Version
    {
        $isSupported = \defined('PHP_WINDOWS_VERSION_MAJOR')
            && \defined('PHP_WINDOWS_VERSION_MINOR')
            && \defined('PHP_WINDOWS_VERSION_BUILD');

        if (!$isSupported) {
            return null;
        }

        return new Version(
            major: \PHP_WINDOWS_VERSION_MAJOR,
            minor: \PHP_WINDOWS_VERSION_MINOR,
            patch: \PHP_WINDOWS_VERSION_BUILD,
        );
    }

    /**
     * Try to detect version using kernel mode-driver API.
     *
     * @link https://learn.microsoft.com/en-us/windows-hardware/drivers/ddi/wdm/
     */
    private function tryDetectKernelModeDriverWindowsVersion(): ?Version
    {
        if (!Runtime::isAvailable()) {
            return null;
        }

        try {
            /**
             * @var object{
             *     RtlGetVersion: callable(object): int<min, max>
             * } $ffi
             * @phpstan-var \FFI $ffi
             */
            $ffi = \FFI::cdef(
                <<<'CDATA'
                typedef long NTSTATUS;
                typedef unsigned int ULONG;
                typedef unsigned int WCHAR;

                typedef struct _OSVERSIONINFOW {
                    ULONG dwOSVersionInfoSize;
                    ULONG dwMajorVersion;
                    ULONG dwMinorVersion;
                    ULONG dwBuildNumber;
                    ULONG dwPlatformId;
                    WCHAR szCSDVersion[128];
                } OSVERSIONINFOW, *PRTL_OSVERSIONINFOW;

                NTSTATUS RtlGetVersion(
                    PRTL_OSVERSIONINFOW lpVersionInformation
                );
                CDATA,
                'ntdll.dll'
            );

            /**
             * @var object{
             *     dwOSVersionInfoSize: int<0, max>,
             *     dwMajorVersion: int<0, max>,
             *     dwMinorVersion: int<0, max>,
             *     dwBuildNumber: int<0, max>,
             *     dwPlatformId: int<0, max>,
             *     szCSDVersion: non-empty-list<int<0, 4294967295>>
             * } $struct
             * @phpstan-var CData $struct
             */
            $struct = $ffi->new('OSVERSIONINFOW');

            $result = $ffi->RtlGetVersion(\FFI::addr($struct));
        } catch (\Throwable) {
            return null;
        }

        if ($result !== 0) {
            return null;
        }

        return new Version(
            major: $struct->dwMajorVersion,
            minor: $struct->dwMinorVersion,
            patch: $struct->dwBuildNumber,
        );
    }

    /**
     * Detect approximate Windows version using uname
     */
    private function tryDetectApproximateWindowsVersion(): ?Version
    {
        /**
         * @var int|null $major
         * @var int|null $minor
         */
        [$major, $minor] = \sscanf(\php_uname('r'), '%d.%d');

        if ($major === null) {
            return null;
        }

        /** @var int|null $patch */
        [$patch] = \sscanf(\php_uname('v'), 'build %d');

        return new Version(
            major: \max($major, 0),
            minor: $minor === null ? null : \max($minor, 0),
            patch: $patch === null ? null : \max($patch, 0),
        );
    }

    /**
     * Detect Linux kernel version using uname
     */
    private function tryDetectLinuxKernelVersion(): ?Version
    {
        /**
         * @var int|null $major
         * @var int|null $minor
         * @var int|null $patch
         * @var int|null $build
         */
        [$major, $minor, $patch, $build] = \sscanf(\php_uname('r'), '%d.%d.%d.%d');

        if ($major === null) {
            return null;
        }

        return new Version(
            major: \max($major, 0),
            minor: $minor === null ? null : \max($minor, 0),
            patch: $patch === null ? null : \max($patch, 0),
            build: $build === null ? null : \max($build, 0),
        );
    }

    private static function trimNonWords(string $value): string
    {
        return \preg_replace('/(\W+$)|(^\W+)/', '', $value);
    }
}