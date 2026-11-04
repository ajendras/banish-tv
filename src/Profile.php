<?php

declare(strict_types=1);

namespace BanishTV;

/**
 * Profile
 *
 * Abstract base for all brand-specific debloat profiles. Concrete implementations
 * supply vetted lists of bloatware and safe-to-keep packages. All ADB actions
 * generated from these lists are statically validated before execution.
 *
 * Profiles are designed to be simple, auditable, and extensible. New brands
 * are added by creating a final class extending Profile and registering it
 * with ProfileRegistry (auto-registered in the boot process).
 */
abstract class Profile
{
    /**
     * Human-readable brand name used in reports and UI.
     */
    abstract public function getBrand(): string;

    /**
     * List of known bloat, telemetry, and ad-related packages for this brand.
     *
     * @return list<string>
     */
    abstract public function getBloatPackages(): array;

    /**
     * Packages that should never be disabled (core system, input, settings, etc).
     *
     * @return list<string>
     */
    abstract public function getSafePackages(): array;

    /**
     * Configuration for the minimal Lumi Launcher (Apple-TV style).
     *
     * @return array{name: string, component: string, rows: array}
     */
    public function getLauncherConfig(): array
    {
        return [
            'name' => 'Lumi Launcher',
            'component' => 'com.lumilauncher/.MainActivity',
            'apkSize' => '480 KB',
            'rows' => [
                [
                    'name' => 'Favorites',
                    'apps' => [],
                ],
                [
                    'name' => 'Streaming',
                    'apps' => [
                        'com.netflix.ninja',
                        'com.disney.disneyplus',
                        'com.amazon.amazonvideo.livingroom',
                    ],
                ],
                [
                    'name' => 'Media',
                    'apps' => [
                        'com.google.android.youtube.tv',
                    ],
                ],
            ],
        ];
    }

    /**
     * Returns the final list of packages that are safe to disable.
     *
     * @return list<string>
     */
    final public function getPackagesToDisable(): array
    {
        $bloat = $this->getBloatPackages();
        $safe = $this->getSafePackages();

        return array_values(array_diff($bloat, $safe));
    }

    /**
     * Returns a short description of the profile for UI/CLI.
     */
    final public function getDescription(): string
    {
        return sprintf(
            '%s profile — %d packages to disable, %d packages protected',
            $this->getBrand(),
            count($this->getPackagesToDisable()),
            count($this->getSafePackages())
        );
    }
}

/**
 * GenericProfile
 *
 * Baseline profile used for unknown brands and as the fallback for 'auto'.
 * Contains the most common bloat packages seen across Android TV platforms.
 */
final class GenericProfile extends Profile
{
    public function getBrand(): string
    {
        return 'Generic';
    }

    public function getBloatPackages(): array
    {
        return [
            'com.google.android.youtube.tv',
            'com.netflix.ninja',
            'com.amazon.amazonvideo.livingroom',
            'com.disney.disneyplus',
            'com.google.android.videos',
            'com.android.chrome',
            'com.google.android.googlequicksearchbox',
            'com.google.android.apps.tv.dreams',
            'com.google.android.tvrecommendations',
            'com.google.android.apps.tv.launcherx',
            'com.google.android.tv.remote',
            'com.android.vending', // Play Store can be kept or removed depending on use-case
        ];
    }

    public function getSafePackages(): array
    {
        return [
            'com.android.systemui',
            'com.google.android.gms',
            'com.android.settings',
            'com.android.tv.settings',
            'com.google.android.inputmethod.latin',
            'com.google.android.tv',
            'com.android.vending',
        ];
    }
}

/**
 * GoogleTVProfile
 *
 * Tailored for Google TV / Android TV devices. Extends the generic list with
 * Google-specific telemetry and recommendation services.
 */
final class GoogleTVProfile extends Profile
{
    public function getBrand(): string
    {
        return 'Google TV';
    }

    public function getBloatPackages(): array
    {
        return array_merge((new GenericProfile())->getBloatPackages(), [
            'com.google.android.apps.tv.launcher',
            'com.google.android.apps.tv.unifiedsettings',
            'com.google.android.tv.bugreport',
            'com.google.android.tv.setup',
            'com.google.android.katniss',
        ]);
    }

    public function getSafePackages(): array
    {
        return array_merge((new GenericProfile())->getSafePackages(), [
            'com.google.android.apps.tv.launcher',
        ]);
    }
}

/**
 * LGProfile
 *
 * LG-specific profile with additional webOS-like bloat that appears on LG TVs
 * running Android TV.
 */
final class LGProfile extends Profile
{
    public function getBrand(): string
    {
        return 'LG';
    }

    public function getBloatPackages(): array
    {
        return array_merge((new GenericProfile())->getBloatPackages(), [
            'com.lge.tv.livetv',
            'com.lge.tv.remote',
            'com.lge.videocall',
            'com.lge.browser',
            'com.lge.smartshare',
            'com.lge.tvsetup',
        ]);
    }

    public function getSafePackages(): array
    {
        return (new GenericProfile())->getSafePackages();
    }
}
