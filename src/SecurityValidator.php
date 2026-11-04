<?php

declare(strict_types=1);

namespace BanishTV;

/**
 * SecurityValidator
 *
 * Enforces a strict whitelist of allowed ADB commands and protects critical
 * system packages from accidental removal. Every command and package is
 * vetted before execution. Provides clear, actionable reasons when an
 * action is blocked. Strong defaults: only package management, launcher
 * configuration, and safe intents are permitted.
 */
final class SecurityValidator
{
    /** @var list<string> Reasons for the most recent blocked action */
    private array $blockedReasons = [];

    /** @var list<string> Regex patterns for explicitly permitted commands */
    private array $allowedPatterns = [
        '/^pm list packages(?: -3)?$/',
        '/^pm disable-user --user 0 [a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/',
        '/^pm enable [a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/',
        '/^cmd package set-home-activity [a-z][a-z0-9_]*(\.[a-z0-9_]+)+\/\.[A-Za-z0-9.]+$/',
        '/^am start -n [a-z][a-z0-9_]*(\.[a-z0-9_]+)+\/\.[A-Za-z0-9.]+/',
        '/^am broadcast -a com\.lumilauncher\.[A-Z_]+/',
        '/^input keyevent (?:[0-9]+|KEYCODE_[A-Z0-9_]+)$/',
    ];

    /** @var list<string> Patterns that are never allowed (security guard) */
    private array $dangerousPatterns = [
        '/rm\s+-rf/',
        '/\bsu\b/',
        '/\breboot\b/',
        '/factory.*reset/',
        '/pm\s+uninstall/',
        '/settings put (global|secure|system)/',
        '/dumpsys/',
        '/(>|\||&&|;)\s*(rm|dd|mkfs|reboot)/',
        '/echo\s+.*>\s*\/system/',
    ];

    /**
     * Determine whether a package may be disabled.
     *
     * A package is unsafe if it appears in the profile's protected list
     * or violates global critical-component rules.
     *
     * @param string $pkg Package name (reverse-domain notation)
     * @param Profile $profile Brand-specific profile
     * @return bool True if the package can be safely disabled
     */
    public function isSafePackage(string $pkg, Profile $profile): bool
    {
        $this->blockedReasons = [];

        if (empty($pkg)) {
            $this->blockedReasons[] = 'Package name cannot be empty.';
            return false;
        }

        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $pkg)) {
            $this->blockedReasons[] = 'Package name does not match reverse-domain format.';
            return false;
        }

        $safePackages = $profile->getSafePackages();
        if (in_array($pkg, $safePackages, true)) {
            $this->blockedReasons[] = 'Package is protected by the active profile.';
            return false;
        }

        // Global critical components that must never be touched
        $globalCritical = [
            'com.android.systemui',
            'com.android.settings',
            'com.android.tv.settings',
            'com.google.android.gms',
            'com.google.android.inputmethod.latin',
        ];

        if (in_array($pkg, $globalCritical, true)) {
            $this->blockedReasons[] = 'Package is a globally protected system component.';
            return false;
        }

        return true;
    }

    /**
     * Statically validate an ADB shell command against a strict whitelist.
     *
     * Dangerous patterns are rejected first. Then the command must match
     * at least one allowed pattern. All validation is deterministic and
     * independent of live device state.
     *
     * @param string $cmd Full command string that would be passed to adb shell
     * @return bool True if the command is permitted
     */
    public function validateCommand(string $cmd): bool
    {
        $this->blockedReasons = [];

        $cmd = trim($cmd);
        if ($cmd === '') {
            $this->blockedReasons[] = 'Command cannot be empty.';
            return false;
        }

        // Block anything that looks remotely destructive
        foreach ($this->dangerousPatterns as $pattern) {
            if (preg_match($pattern, $cmd)) {
                $this->blockedReasons[] = "Command matches dangerous pattern '{$pattern}'.";
                return false;
            }
        }

        // Must match an explicit whitelist entry
        foreach ($this->allowedPatterns as $pattern) {
            if (preg_match($pattern, $cmd)) {
                return true;
            }
        }

        $this->blockedReasons[] = 'Command does not match any permitted pattern. Only package, launcher, and broadcast commands are allowed.';
        return false;
    }

    /**
     * Retrieve human-readable reasons why the previous validation failed.
     *
     * @return list<string>
     */
    public function getBlockedReasons(): array
    {
        return $this->blockedReasons;
    }
}
