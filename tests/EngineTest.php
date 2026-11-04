<?php

declare(strict_types=1);

namespace BanishTV\Tests;

use BanishTV\ADBClient;
use BanishTV\DebloatEngine;
use BanishTV\GenericProfile;
use BanishTV\GoogleTVProfile;
use BanishTV\LGProfile;
use BanishTV\PluginManager;
use BanishTV\Profile;
use BanishTV\ProfileRegistry;
use BanishTV\SecurityValidator;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{
    private ADBClient $adb;
    private SecurityValidator $validator;
    private PluginManager $plugins;
    private DebloatEngine $engine;

    protected function setUp(): void
    {
        $this->adb = new ADBClient();
        // Connect to a deterministic fake IP for all tests
        $this->adb->connect('192.168.1.100', 5);

        $this->validator = new SecurityValidator();
        $this->plugins = new PluginManager();
        $this->engine = new DebloatEngine(
            $this->adb,
            $this->validator,
            $this->plugins
        );

        // Ensure default profiles are registered
        ProfileRegistry::reset();
        ProfileRegistry::getForBrand('auto'); // triggers bootDefaultProfiles()
    }

    public function testDryRunProducesExactCommandLogWithZeroSideEffects(): void
    {
        $profile = new GenericProfile();
        $report = $this->engine->apply($profile, true);

        $this->assertTrue($report['success']);
        $this->assertTrue($report['dryRun']);
        $this->assertArrayHasKey('log', $report);
        $this->assertGreaterThan(8, count($report['log']));
        $this->assertStringContainsString('DRY-RUN', $report['log'][0]);
        $this->assertStringContainsString('Would disable:', implode(' ', $report['log']));
        $this->assertStringContainsString('Dry-run completed', $report['message']);
        $this->assertEquals(0, $report['disabledCount']);
    }

    public function testLiveApplyExecutesRealChangesAndUpdatesReport(): void
    {
        $profile = new GoogleTVProfile();
        $report = $this->engine->apply($profile, false);

        $this->assertTrue($report['success']);
        $this->assertFalse($report['dryRun']);
        $this->assertGreaterThan(0, $report['disabledCount']);
        $this->assertStringContainsString('BANISH COMPLETE', end($report['log']));
        $this->assertStringContainsString('Reboot recommended', implode(' ', $report['log']));
    }

    public function testSecurityValidatorRejectsDangerousCommands(): void
    {
        $dangerous = [
            'rm -rf /system',
            'reboot',
            'pm uninstall -k com.android.settings',
            'settings put global package_verifier_user_consent 1',
            'echo "root" > /data/local.prop',
            'su -c "whoami"',
            'factory reset',
        ];

        foreach ($dangerous as $cmd) {
            $this->assertFalse(
                $this->validator->validateCommand($cmd),
                "Dangerous command should be rejected: {$cmd}"
            );
            $this->assertNotEmpty(
                $this->validator->getBlockedReasons(),
                "Blocked reasons must be populated for: {$cmd}"
            );
        }
    }

    public function testSecurityValidatorAcceptsWhitelistedCommands(): void
    {
        $allowed = [
            'pm list packages',
            'pm list packages -3',
            'pm disable-user --user 0 com.google.android.youtube.tv',
            'pm enable com.android.vending',
            'cmd package set-home-activity com.lumilauncher/.MainActivity',
            'am start -n com.lumilauncher/.MainActivity --user 0',
            'am broadcast -a com.lumilauncher.CONFIG_UPDATED',
            'input keyevent KEYCODE_HOME',
        ];

        foreach ($allowed as $cmd) {
            $this->assertTrue(
                $this->validator->validateCommand($cmd),
                "Whitelisted command should be accepted: {$cmd}"
            );
        }
    }

    public function testProfileCompatibilityMatrix(): void
    {
        $profiles = [
            new GenericProfile(),
            new GoogleTVProfile(),
            new LGProfile(),
        ];

        foreach ($profiles as $profile) {
            $brand = $profile->getBrand();
            $bloat = $profile->getBloatPackages();
            $safe = $profile->getSafePackages();
            $toDisable = $profile->getPackagesToDisable();

            $this->assertNotEmpty($brand);
            $this->assertIsList($bloat);
            $this->assertIsList($safe);
            $this->assertIsList($toDisable);

            // No package should be both bloat and safe
            $this->assertEmpty(
                array_intersect($bloat, $safe),
                "{$brand} profile must not list packages in both bloat and safe arrays"
            );

            // All packages to disable must be in bloat and not in safe
            foreach ($toDisable as $pkg) {
                $this->assertContains($pkg, $bloat);
                $this->assertNotContains($pkg, $safe);
            }

            // Launcher config must be present and valid
            $launcher = $profile->getLauncherConfig();
            $this->assertArrayHasKey('component', $launcher);
            $this->assertArrayHasKey('rows', $launcher);
            $this->assertNotEmpty($launcher['rows']);
        }
    }

    public function testAnalyzeReturnsWellStructuredReport(): void
    {
        $profile = new LGProfile();
        $report = $this->engine->analyze($profile);

        $requiredKeys = [
            'brand',
            'bloatDetected',
            'toDisable',
            'safePackages',
            'actions',
            'launcher',
            'log',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $report);
        }

        $this->assertSame('LG', $report['brand']);
        $this->assertIsInt($report['bloatDetected']);
        $this->assertIsInt($report['toDisable']);
        $this->assertIsInt($report['safePackages']);
        $this->assertIsArray($report['launcher']);
        $this->assertIsArray($report['log']);
        $this->assertGreaterThan(4, count($report['log']));
        $this->assertStringContainsString('Analysis complete', $report['log'][count($report['log']) - 1]);
    }

    public function testPluginHooksAreCalledBeforeAndAfterApply(): void
    {
        $profile = new GenericProfile();
        $calls = [];

        $this->plugins->register('test-hook', static function (Profile $p, ADBClient $a) use (&$calls): void {
            $calls[] = $p->getBrand();
        });

        $this->engine->apply($profile, true);

        $this->assertCount(2, $calls); // beforeApply + afterApply
        $this->assertSame('Generic', $calls[0]);
        $this->assertSame('Generic', $calls[1]);
    }

    public function testValidatorRejectsProtectedPackagesFromProfileAndGlobalList(): void
    {
        $profile = new GoogleTVProfile();

        $protected = [
            'com.android.settings',
            'com.google.android.gms',
            'com.android.systemui',
            'com.google.android.inputmethod.latin',
        ];

        foreach ($protected as $pkg) {
            $this->assertFalse(
                $this->validator->isSafePackage($pkg, $profile),
                "Package {$pkg} must be rejected by SecurityValidator"
            );
            $reasons = $this->validator->getBlockedReasons();
            $this->assertNotEmpty($reasons);
            $this->assertStringContainsString('protected', strtolower(implode(' ', $reasons)));
        }
    }

    public function testReportStructureForApplyMatchesFrontendExpectations(): void
    {
        $profile = new GenericProfile();
        $report = $this->engine->apply($profile, true);

        $expectedStructure = [
            'success',
            'brand',
            'log',
            'disabledCount',
            'skippedCount',
            'dryRun',
            'message',
            'launcher',
        ];

        foreach ($expectedStructure as $key) {
            $this->assertArrayHasKey($key, $report);
        }

        $this->assertIsBool($report['success']);
        $this->assertIsBool($report['dryRun']);
        $this->assertIsArray($report['log']);
        $this->assertIsArray($report['launcher']);
        $this->assertStringContainsString('Dry-run completed', $report['message']);
    }
}
