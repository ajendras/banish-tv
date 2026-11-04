<?php

declare(strict_types=1);

namespace BanishTV;

/**
 * ADBClient
 *
 * Secure wrapper for communicating with an Android/Google TV over Wi-Fi.
 * All commands are expected to be pre-validated by SecurityValidator.
 * This implementation uses deterministic simulation for reproducibility,
 * zero-risk local development, and full test coverage. Real ADB execution
 * can be swapped in by replacing the shell() method with proc_open/exec
 * while keeping the same public contract.
 */
final class ADBClient
{
    private bool $connected = false;
    private string $ipAddress = '';

    /** @var array<string> Mock packages that simulate a typical smart TV */
    private array $mockPackages = [
        'com.android.systemui',
        'com.google.android.tv',
        'com.google.android.youtube.tv',
        'com.netflix.ninja',
        'com.amazon.amazonvideo.livingroom',
        'com.disney.disneyplus',
        'com.google.android.apps.tv.launcher',
        'com.google.android.videos',
        'com.android.vending',
        'com.google.android.gms',
        'com.google.android.googlequicksearchbox',
        'com.android.chrome',
    ];

    /** @var array<string> Packages that should never be disabled */
    private array $protectedPackages = [
        'com.android.settings',
        'com.android.tv.settings',
        'com.google.android.inputmethod.latin',
    ];

    /**
     * Connect to an Android TV via ADB over TCP.
     *
     * @param string $ip TV IP address (validated)
     * @param int $timeout Connection timeout in seconds (1-30)
     * @return bool True if connection succeeded
     * @throws \InvalidArgumentException on bad input
     */
    public function connect(string $ip, int $timeout = 10): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new \InvalidArgumentException(
                'Invalid IP address. Expected a valid IPv4 address (e.g. 192.168.1.45).'
            );
        }

        if ($timeout < 1 || $timeout > 30) {
            throw new \InvalidArgumentException('Timeout must be between 1 and 30 seconds.');
        }

        // Simulate network latency for realism in UI/CLI
        usleep(250_000);

        $this->ipAddress = $ip;
        $this->connected = true;

        return true;
    }

    /**
     * Execute an ADB shell command (simulated with deterministic responses).
     *
     * @throws \RuntimeException if not connected
     */
    public function shell(string $cmd): string
    {
        if (!$this->connected) {
            throw new \RuntimeException('Not connected to a device. Call connect($ip) first.');
        }

        // Real implementation note: would be similar to:
        // $safeCmd = sprintf('timeout 8 adb -s %s:5555 shell %s', $this->ipAddress, escapeshellarg($cmd));
        // return trim((string) @shell_exec($safeCmd)) ?: 'OK';

        if (str_contains($cmd, 'pm list packages')) {
            $all = array_merge($this->mockPackages, $this->protectedPackages);
            return implode("\n", array_map(static fn(string $p): string => "package:{$p}", $all));
        }

        if (str_contains($cmd, 'pm disable-user')) {
            return 'Success';
        }

        if (str_contains($cmd, 'pm enable')) {
            return 'Success';
        }

        if (str_contains($cmd, 'set-home-activity') || str_contains($cmd, 'launcher')) {
            return 'Success: default launcher updated.';
        }

        if (str_contains($cmd, 'am start') || str_contains($cmd, 'input keyevent')) {
            return 'OK';
        }

        return 'Command executed (simulated)';
    }

    /**
     * Return list of installed packages.
     *
     * @return list<string>
     */
    public function listPackages(bool $userOnly = false): array
    {
        $output = $this->shell('pm list packages' . ($userOnly ? ' -3' : ''));

        $packages = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'package:')) {
                $packages[] = substr($line, 8);
            }
        }

        return $packages;
    }

    /**
     * Disable a package (pm disable-user --user 0).
     *
     * @throws \InvalidArgumentException on malformed package name
     */
    public function disable(string $pkg): bool
    {
        if (!$this->connected) {
            return false;
        }

        if (empty($pkg) || !preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $pkg)) {
            throw new \InvalidArgumentException(
                "Invalid package name '{$pkg}'. Must be a valid reverse-domain string."
            );
        }

        if (in_array($pkg, $this->protectedPackages, true)) {
            return false; // never disable critical system components
        }

        $this->shell("pm disable-user --user 0 {$pkg}");
        return true;
    }

    /**
     * Configure custom launcher (Lumi).
     *
     * @param string $component e.g. "com.lumilauncher/.MainActivity"
     * @throws \InvalidArgumentException on bad component format
     */
    public function setLauncher(string $component): bool
    {
        if (!$this->connected) {
            return false;
        }

        if (empty($component) || !str_contains($component, '/')) {
            throw new \InvalidArgumentException(
                'Launcher component must be in the form package/.ActivityName'
            );
        }

        $this->shell("cmd package set-home-activity {$component}");
        $this->shell("am start -n {$component} --user 0");

        return true;
    }

    /**
     * Helper for tests and status checks.
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function getConnectedIp(): string
    {
        return $this->ipAddress;
    }
}
