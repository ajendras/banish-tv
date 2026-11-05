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
        ProfileRegistry::reset();
        $this->adb = new ADBClient();
        $this->adb->connect('192.168.1.100', 5);
        $this->validator = new SecurityValidator();
        $this->plugins = new PluginManager();
    }

    public function testAnalyzeReturnsExpectedReportStructure(): void
    {
        $engine = new DebloatEngine($this->adb, $this->validator, $this->plugins);
        $profile = new GenericProfile();
        $report = $engine->analyze($profile);

        $this->assertIsArray($report);
        $this->assertArrayHasKey('brand', $report);
        $this->assertSame('Generic', $report['brand']);
        $this->assertArrayHasKey('bloatDetected', $report);
        $this->assertArrayHasKey('toDisable', $report);
        $this->assertArrayHasKey('safePackages', $report);
        $this->assertArrayHasKey('actions', $report);
        $this->assertArrayHasKey('launcher', $report);
        $this->assertArrayHasKey('log', $report);
        $this->assertIsArray($report['log']);
        $this->assertGreaterThan(0, count($report['log']));
        $this->assertStringContainsString('Analysis started for Generic profile', $report['log'][0]);
        $this->assertStringContainsString('Analysis complete', $report['log'][array_key_last($report['log'])]);
    }

    public function testDryRunApplyProducesLogWithoutSideEffects(): void
    {
        $engine = new DebloatEngine($this->adb, $this->validator, $this->plugins);
        $profile = new GenericProfile();
        $report = $engine->apply($profile, dryRun: true);

        $this->assertTrue($report['success']);
        $this->assertTrue($report['dryRun']);
        $this->assertArrayHasKey('log', $report);
        $this->assertArrayHasKey('disabledCount', $report);
        $this->assertArrayHasKey('skippedCount', $report);
        $this->assertArrayHasKey('launcher', $report);

        $logStr = implode("\n", $report['log']);
        $this->assertStringContainsString('=== LumiTV DRY-RUN ===', $logStr);
        $this->assertStringContainsString('Profile : Generic', $logStr);
        $this->assertStringContainsString('Would disable:', $logStr);
        $this->assertStringContainsString('Would push and configure: Lumi Launcher', $logStr);
        $this->assertStringContainsString('Dry-run completed — no changes were made to the TV', $logStr);
        $this->assertStringContainsString('DRY-RUN COMPLETE', $logStr);
        $this->assertStringContainsString('Dry-run completed. Review the log above', $report['message']);
    }

    public function testLiveApplyCallsDisableOnADB(): void
    {
        $engine = new DebloatEngine($this->adb, $this->validator, $this->plugins);
        $profile = new GenericProfile();
        $report = $engine->apply($profile, dryRun: false);

        $this->assertTrue($report['success']);
        $this->assertFalse($report['dryRun']);

        $logStr = implode("\n", $report['log']);
        $this->assertStringContainsString('=== LumiTV LIVE APPLY ===', $logStr);
        $this->assertStringContainsString('Disabling:', $logStr);
        $this->assertStringContainsString('Installing and configuring: Lumi Launcher', $logStr);
        $this->assertStringContainsString('All changes applied successfully', $logStr);
        $this->assertStringContainsString('LUMI COMPLETE', $logStr);
    }

    public function testValidatorRejectsDangerousCommands(): void
    {
        $badCommands = [
            'rm -rf /system',
            'reboot',
            'pm uninstall com.example.app',
            'settings put global test 1',
            'echo "bad" > /data/local.prop',
            'su -c "whoami"',
        ];

        foreach ($badCommands as $cmd) {
            $this->assertFalse(
                $this->validator->validateCommand($cmd),
                "Command '{$cmd}' should have been rejected"
            );
            $this->assertNotEmpty($this->validator->getBlockedReasons());
        }
    }

    public function testProfileRegistryAutoDiscovery(): void
    {
        $generic = ProfileRegistry::getForBrand('auto');
        $this->assertInstanceOf(GenericProfile::class, $generic);
        $this->assertSame('Generic', $generic->getBrand());

        $google = ProfileRegistry::getForBrand('GoogleTV');
        $this->assertInstanceOf(GoogleTVProfile::class, $google);
        $this->assertSame('Google TV', $google->getBrand());

        $lg = ProfileRegistry::getForBrand('LG');
        $this->assertInstanceOf(LGProfile::class, $lg);
        $this->assertSame('LG', $lg->getBrand());
    }

    public function testPluginHooksAreExecuted(): void
    {
        $callCount = 0;
        $this->plugins->register('test-hook', static function () use (&$callCount): void {
            $callCount++;
        });

        $engine = new DebloatEngine($this->adb, $this->validator, $this->plugins);
        $profile = new GenericProfile();
        $engine->apply($profile, dryRun: true);

        $this->assertSame(2, $callCount, 'beforeApply and afterApply hooks should each have run once');
    }

    public function testAnalyzeWithLGProfile(): void
    {
        $engine = new DebloatEngine($this->adb, $this->validator, $this->plugins);
        $profile = new LGProfile();
        $report = $engine->analyze($profile);

        $this->assertSame('LG', $report['brand']);
        $this->assertGreaterThan(10, $report['bloatDetected']);
        $this->assertGreaterThan(0, $report['toDisable']);
        $this->assertStringContainsString('LG profile', $report['log'][0]);
    }

    public function testSafePackageValidation(): void
    {
        $profile = new GenericProfile();
        $protected = 'com.android.settings';

        $this->assertFalse($this->validator->isSafePackage($protected, $profile));
        $this->assertNotEmpty($this->validator->getBlockedReasons());

        $safeBloat = 'com.google.android.youtube.tv';
        $this->assertTrue($this->validator->isSafePackage($safeBloat, $profile));
    }
}
