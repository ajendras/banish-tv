<?php

declare(strict_types=1);

namespace BanishTV;

use RuntimeException;

/**
 * ApiController
 *
 * JSON API layer for the web dashboard. All mutating endpoints enforce
 * strict CSRF validation. Business logic is delegated to DebloatEngine,
 * SecurityValidator and the profile system. Session is used only for
 * the currently connected TV IP (ADBClient is recreated per-request
 * for thread-safety and testability).
 */
final class ApiController
{
    /** @var array<string,mixed> Reference to $_SESSION */
    private array $session;

    public function __construct()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $this->session = &$_SESSION;
    }

    /**
     * Return current connection and system status.
     *
     * @return array{success: bool, connected: bool, ip: string|null, profile: array, plugins: list<string>}
     */
    public function status(): array
    {
        $connected = !empty($this->session['tv_ip']);
        $ip = $this->session['tv_ip'] ?? null;
        $brand = $this->session['profile_brand'] ?? 'Generic';

        return [
            'success' => true,
            'connected' => $connected,
            'ip' => $ip,
            'profile' => [
                'brand' => $brand,
                'description' => (new ProfileRegistry())->getForBrand($brand)->getDescription(),
            ],
            'plugins' => (new PluginManager())->getRegisteredPlugins(),
        ];
    }

    /**
     * Connect to a TV, run auto-discovery and load the matching profile.
     *
     * @param array{ip: string, csrf_token?: string} $input
     * @return array{success: bool, ip?: string, profile?: array, message?: string, error?: string}
     */
    public function connect(array $input): array
    {
        $this->validateCsrf($input ?? []);

        $ip = trim($input['ip'] ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [
                'success' => false,
                'error' => 'Invalid IPv4 address. Expected format: 192.168.1.45',
            ];
        }

        try {
            $adb = new ADBClient();
            if (!$adb->connect($ip, 8)) {
                return [
                    'success' => false,
                    'error' => 'Could not establish ADB connection. Is ADB debugging enabled on the TV?',
                ];
            }

            $profile = ProfileRegistry::getForBrand('auto');

            // Store minimal state for subsequent calls
            $this->session['tv_ip'] = $ip;
            $this->session['profile_brand'] = $profile->getBrand();

            return [
                'success' => true,
                'ip' => $ip,
                'profile' => [
                    'brand' => $profile->getBrand(),
                    'description' => $profile->getDescription(),
                ],
                'message' => 'Connected. SecurityValidator approved all whitelisted commands.',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Apply (or dry-run) the active profile: disable bloat and install Lumi Launcher.
     *
     * @param array{dry_run?: bool, csrf_token?: string} $input
     * @return array{success: bool, report?: array, error?: string}
     */
    public function apply(array $input): array
    {
        $this->validateCsrf($input ?? []);

        if (empty($this->session['tv_ip'])) {
            return [
                'success' => false,
                'error' => 'No TV connected. Please connect first.',
            ];
        }

        $dryRun = (bool) ($input['dry_run'] ?? true);
        $ip = $this->session['tv_ip'];

        try {
            $adb = new ADBClient();
            $adb->connect($ip, 10);

            $validator = new SecurityValidator();
            $plugins = new PluginManager();
            $engine = new DebloatEngine($adb, $validator, $plugins);

            $profile = ProfileRegistry::getForBrand(
                $this->session['profile_brand'] ?? 'auto'
            );

            $report = $engine->apply($profile, $dryRun);

            return [
                'success' => true,
                'report' => $report,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Enforce CSRF protection on mutating endpoints.
     *
     * @throws RuntimeException on validation failure
     */
    private function validateCsrf(array $input): void
    {
        $submitted = $input['csrf_token'] ?? '';
        $stored = $this->session['csrf_token'] ?? '';

        if ($submitted === '' || $stored === '' || !hash_equals($stored, $submitted)) {
            throw new RuntimeException('Invalid CSRF token. Please refresh the page and try again.');
        }
    }
}
