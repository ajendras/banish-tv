<?php

declare(strict_types=1);

namespace BanishTV;

/**
 * PluginManager
 *
 * Lightweight, extensible hook system for pre- and post-apply actions.
 * Plugins register a name and a callable. The callable receives the
 * active Profile and ADBClient instance. Hooks run in registration
 * order and are isolated from core failures (exceptions are swallowed
 * so a faulty plugin cannot brick the debloat process).
 *
 * This architecture allows community profiles, extra ad-block lists,
 * custom telemetry removal steps, or additional UI panels without
 * touching the core engine.
 */
final class PluginManager
{
    /**
     * @var array<string, callable> Registered hooks keyed by plugin name
     */
    private array $hooks = [];

    /**
     * Register a plugin hook.
     *
     * The callable will be executed on every beforeApply() and afterApply()
     * call. The closure may capture external context (e.g. CLI OutputInterface).
     *
     * @param string $name Unique plugin identifier (for logging/UI)
     * @param callable $hook Signature: fn(Profile $profile, ADBClient $adb): void
     * @throws \InvalidArgumentException on empty name
     */
    public function register(string $name, callable $hook): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Plugin name cannot be empty.');
        }

        $this->hooks[$name] = $hook;
    }

    /**
     * Execute all registered hooks before a profile is applied.
     *
     * Typical uses: install extra packages, run custom discovery, modify
     * the profile on the fly, or log additional telemetry sources.
     */
    public function beforeApply(Profile $p, ADBClient $adb): void
    {
        foreach ($this->hooks as $name => $hook) {
            try {
                $hook($p, $adb);
            } catch (\Throwable $e) {
                // Plugins must never break the core pipeline.
                // In a production version this would be logged via PSR-3.
            }
        }
    }

    /**
     * Execute all registered hooks after a profile has been applied.
     *
     * Typical uses: run cleanup, push extra configuration files, display
     * a custom success message, or trigger a safe reboot prompt.
     */
    public function afterApply(Profile $p, ADBClient $adb): void
    {
        foreach ($this->hooks as $name => $hook) {
            try {
                $hook($p, $adb);
            } catch (\Throwable $e) {
                // Fail-safe for third-party code
            }
        }
    }

    /**
     * Return the list of currently registered plugin names.
     * Useful for the dashboard status panel.
     *
     * @return list<string>
     */
    public function getRegisteredPlugins(): array
    {
        return array_keys($this->hooks);
    }
}
