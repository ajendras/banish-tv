<?php

declare(strict_types=1);

namespace BanishTV\Tests;

use PHPUnit\Framework\TestCase;
use BanishTV\ADBClient;
use BanishTV\DebloatEngine;
use BanishTV\SecurityValidator;
use BanishTV\PluginManager;
use BanishTV\ProfileRegistry;
use BanishTV\GenericProfile;
use BanishTV\GoogleTVProfile;
use BanishTV\LGProfile;

final class EngineTest extends TestCase
{
    private ADBClient $adb;
    private SecurityValidator $validator;
    private PluginManager $plugins;

    protected function setUp(): void
    {
        $this->adb = new ADBClient();
        $this->adb->connect('192.168.1.50', 5);
        $this->validator = new SecurityValidator();
        $this->plugins = new PluginManager();
    }

    public function testAnalyzeReturnsStructuredReport(): void
    {
        $engine = new DebloatEngine($this->adb, $this->validator, $this->plugins);
        $profile = new GenericProfile();
        $report = $engine->analyze($profile);

        $this->assertIsArray($report);
        $this->assertArrayHasKey('brand', $report);
        $this->assertArrayHasKey('bloatDetected', $report);
        $this->assertArrayHasKey('toDisable', $report);
        $this->assertArrayHasKey('safePackages', $report);
        $this->assertArrayHasKey('actions', $report);
        $this->assertArrayHasKey('launcher', $report);
        $this->assertArrayHasKey('log', $report);
        $this->assertEquals('Generic', $report['brand']);
        $this->assertGreaterThan(0, $report['bloatDetected']);
        $this->assertStringContainsString('Analysis started for Generic profile', $report['log'][0]);
        $this->assertStringContainsString('Analysis complete — ready for dry-run or live apply', $report['log'][5]);
    }

    public function testDryRunProducesExpectedReportStructure(): void
    {
        $engine = new DebloatEngine($this->adb, $this->validator, $this->plugins);
        $profile = new GenericProfile();
        $report = $engine->apply($profile, dryRun: true);

        $this->assertTrue($report['success']);
        $this->assertTrue($report['dryRun']);
        $this->assertEquals('Generic', $report['brand']);
        $this->assertGreaterThan(5, $report['disabledCount']);
        $this->assertEquals(0, $report['skippedCount']);
        $this->assertIsArray($report['log']);
        $this->assertIsArray($report['launcher']);
        $this->assertStringContainsString('=== BanishTV DRY-RUN ===', $report['log'][0]);
        $this->assertStringContainsString('✓ Plugin beforeApply hooks executed', $report['log'][3]);
        $this->assertStringContainsString('Dry-run completed — no changes were made to the TV', $report['log'][array_key_last($report['log']) - 1]);
    }

    public function testValidatorRejectsDangerousCommands(): void
    {
        $dangerous = [
            'rm -rf /system',
            'reboot',
            'pm uninstall com.example.app',
            'echo "bad" > /system/build.prop',
            'settings put global adb_enabled 1',
        ];

        foreach ($dangerous as $cmd) {
            $this->assertFalse(
                $this->validator->validateCommand($cmd),
                "Dangerous command should be rejected: {$cmd}"
            );
            $this->assertNotEmpty($this->validator->getBlockedReasons());
        }
    }

    public function testProfileMatrixAndAutoDiscovery(): void
    {
        ProfileRegistry::reset();

        $profiles = [
            ProfileRegistry::getForBrand('auto'),
            ProfileRegistry::getForBrand('google'),
            ProfileRegistry::getForBrand('lg'),
            new GenericProfile(),
            new GoogleTVProfile(),
            new LGProfile(),
        ];

        foreach ($profiles as $profile) {
            $this->assertNotEmpty($profile->getBloatPackages());
            $this->assertNotEmpty($profile->getSafePackages());
            $toDisable = $profile->getPackagesToDisable();
            $this->assertNotEmpty($toDisable);
            $this->assertCount(
                count($profile->getBloatPackages()) - count(array_intersect($profile->getBloatPackages(), $profile->getSafePackages())),
                $toDisable
            );

            $engine = new DebloatEngine($this->adb, $this->validator, $this->plugins);
            $report = $engine->analyze($profile);
            $this->assertEquals($profile->getBrand(), $report['brand']);
            $this->assertArrayHasKey('launcher', $report);
        }

        $this->assertInstanceOf(GenericProfile::class, ProfileRegistry::getForBrand('unknown-brand'));
    }

    public function testPluginHooksExecutedOnlyForApply(): void
    {
        $callCount = 0;
        $this->plugins->register('test-counter', static function () use (&$callCount): void {
            $callCount++;
        });

        $engine = new DebloatEngine($this->adb, $this->validator, $this->plugins);
        $profile = new GenericProfile();

        // analyze must not trigger apply hooks
        $engine->analyze($profile);
        $this->assertSame(0, $callCount, 'analyze() must not run before/afterApply hooks');

        $callCount = 0;
        $engine->apply($profile, dryRun: true);
        $this->assertSame(2, $callCount, 'apply() must run beforeApply and afterApply exactly once each');
    }
}
