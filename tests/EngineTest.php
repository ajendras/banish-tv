<?php

declare(strict_types=1);

namespace BanishTV\Tests;

use PHPUnit\Framework\TestCase;
use BanishTV\DebloatEngine;
use BanishTV\ADBClient;
use BanishTV\SecurityValidator;
use BanishTV\PluginManager;
use BanishTV\Profile;
use BanishTV\GenericProfile;
use BanishTV\GoogleTVProfile;
use BanishTV\LGProfile;
use BanishTV\ProfileRegistry;

/**
 * EngineTest
 *
 * Covers dry-run report assertions (including accurate disabledCount),
 * validator rejections of dangerous commands, profile matrix compatibility,
 * and full report structure validation. This ensures the PHP 8.2 CI job passes.
 */
final class EngineTest extends TestCase
{
    private ADBClient $adb;
    private SecurityValidator $validator;
    private PluginManager $plugins;
    private DebloatEngine $engine;

    protected function setUp(): void
    {
        $this->adb = new ADBClient();
        $this->adb->connect('192.168.1.42', 5);
        $this->validator = new SecurityValidator();
        $this->plugins = new PluginManager();
        $this->engine = new DebloatEngine(
            $this->adb,
            $this->validator,
            $this->plugins
        );
    }

    public function testDryRunReportsCorrectDisabledCount(): void
    {
        $profile = new GenericProfile();
        $report = $this->engine->apply($profile, true);

        $this->assertTrue($report['success']);
        $this->assertTrue($report['dryRun']);
        $this->assertSame(7, $report['disabledCount']);
        $this->assertSame(0, $report['skippedCount']);
        $this->assertStringContainsString('DRY-RUN', implode("\n", $report['log']));
        $this->assertStringContainsString('[DRY] Would disable:', implode("\n", $report['log']));
        $this->assertArrayHasKey('launcher', $report);
        $this->assertIsArray($report['launcher']);
    }

    public function testLiveApplyIncrementsDisabledCount(): void
    {
        $profile = new GenericProfile();
        $report = $this->engine->apply($profile, false);

        $this->assertTrue($report['success']);
        $this->assertFalse($report['dryRun']);
        $this->assertSame(7, $report['disabledCount']);
        $this->assertSame(0, $report['skippedCount']);
    }

    /**
     * @dataProvider provideProfiles
     */
    public function testProfileMatrix(Profile $profile): void
    {
        $report = $this->engine->analyze($profile);

        $this->assertSame($profile->getBrand(), $report['brand']);
        $this->assertIsInt($report['bloatDetected']);
        $this->assertIsInt($report['toDisable']);
        $this->assertLessThanOrEqual($report['toDisable'], $report['bloatDetected']);
        $this->assertNotEmpty($report['log']);
        $this->assertArrayHasKey('launcher', $report);
    }

    public static function provideProfiles(): array
    {
        return [
            'generic' => [new GenericProfile()],
            'google-tv' => [new GoogleTVProfile()],
            'lg' => [new LGProfile()],
        ];
    }

    public function testValidatorRejectsDangerousCommands(): void
    {
        $validator = new SecurityValidator();

        $dangerousCommands = [
            'rm -rf /system',
            'reboot',
            'pm uninstall com.example.app',
            'settings put global adb_enabled 1',
            'echo "root" > /system/build.prop',
        ];

        foreach ($dangerousCommands as $cmd) {
            $this->assertFalse(
                $validator->validateCommand($cmd),
                "Dangerous command should be rejected: {$cmd}"
            );
            $this->assertNotEmpty(
                $validator->getBlockedReasons(),
                "Blocked reasons should be populated for: {$cmd}"
            );
        }
    }

    public function testAnalyzeRequiresConnection(): void
    {
        $unconnectedAdb = new ADBClient();
        $engine = new DebloatEngine(
            $unconnectedAdb,
            new SecurityValidator(),
            new PluginManager()
        );

        $profile = new GenericProfile();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ADBClient must be connected before analysis.');

        $engine->analyze($profile);
    }

    public function testReportStructureAndPluginHooks(): void
    {
        $profile = ProfileRegistry::getForBrand('auto');
        $report = $this->engine->apply($profile, true);

        $expectedKeys = [
            'success',
            'brand',
            'log',
            'disabledCount',
            'skippedCount',
            'dryRun',
            'message',
            'launcher',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $report, "Report must contain key: {$key}");
        }

        $this->assertIsArray($report['log']);
        $this->assertIsArray($report['launcher']);
        $this->assertIsBool($report['dryRun']);
        $this->assertStringContainsString('Plugin beforeApply hooks executed', implode("\n", $report['log']));
        $this->assertStringContainsString('Plugin afterApply hooks executed', implode("\n", $report['log']));
    }
}
