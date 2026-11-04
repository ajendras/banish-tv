<?php

declare(strict_types=1);

namespace BanishTV;

use InvalidArgumentException;

/**
 * ProfileRegistry
 *
 * Central registry for all available TV profiles. Supports static registration
 * and auto-registration of built-in concrete profiles. The 'auto' key returns
 * a sensible default (GenericProfile). Additional profiles can be supplied via
 * plugins using the register() method.
 */
final class ProfileRegistry
{
    /**
     * @var array<string, Profile> Registered profiles keyed by normalized brand name
     */
    private static array $profiles = [];

    /**
     * Register a profile instance.
     *
     * Duplicate brands will overwrite previous registrations.
     */
    public static function register(Profile $profile): void
    {
        $key = strtolower($profile->getBrand());
        self::$profiles[$key] = $profile;
    }

    /**
     * Retrieve a profile by brand name. Supports special values 'auto' and 'generic'.
     *
     * @throws InvalidArgumentException if no profile can be resolved
     */
    public static function getForBrand(string $brand): Profile
    {
        $brand = strtolower(trim($brand));

        if (empty(self::$profiles)) {
            self::bootDefaultProfiles();
        }

        if ($brand === 'auto' || $brand === 'generic') {
            return self::$profiles['generic'] ?? self::createFallbackProfile();
        }

        if (isset(self::$profiles[$brand])) {
            return self::$profiles[$brand];
        }

        // Fuzzy match on brand name
        foreach (self::$profiles as $key => $profile) {
            if (str_contains($key, $brand) || str_contains(strtolower($profile->getBrand()), $brand)) {
                return $profile;
            }
        }

        // Final fallback
        return self::createFallbackProfile();
    }

    /**
     * Return all registered profiles.
     *
     * @return list<Profile>
     */
    public static function all(): array
    {
        if (empty(self::$profiles)) {
            self::bootDefaultProfiles();
        }

        return array_values(self::$profiles);
    }

    /**
     * Register the built-in profiles. This is the "auto-discovery" mechanism
     * for the core set. Plugin profiles can be registered at runtime.
     */
    private static function bootDefaultProfiles(): void
    {
        self::register(new GenericProfile());
        self::register(new GoogleTVProfile());
        self::register(new LGProfile());

        // Future plugin profiles would be discovered here via a configured
        // plugin directory and class map.
    }

    /**
     * Returns a safe fallback profile when brand lookup fails.
     */
    private static function createFallbackProfile(): Profile
    {
        return new GenericProfile();
    }

    /**
     * Clear registry (primarily for testing).
     */
    public static function reset(): void
    {
        self::$profiles = [];
    }
}
