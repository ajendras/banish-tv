<?php

declare(strict_types=1);

namespace BanishTV;

/**
 * Launcher
 *
 * Generates a minimal Apple-TV-style home screen (Lumi Launcher) on-the-fly,
 * injects the user-defined row configuration into the APK assets, pushes
 * the package via ADB, and configures the TV to treat it as the default
 * launcher. All operations are fully simulated for safety and testability.
 * Real APK compilation would require a build pipeline; here we produce
 * a valid ZIP structure that demonstrates the concept.
 */
final class Launcher
{
    /**
     * Build a custom Lumi Launcher APK containing the supplied row configuration.
     *
     * The resulting file is a ZIP with a manifest, a JSON asset, and placeholder
     * binaries that bring the conceptual size close to the advertised 480 KB.
     *
     * @param array{name?: string, component?: string, rows: array<int, array>, apkSize?: string} $config
     * @return string Absolute path to the generated .apk file
     * @throws \InvalidArgumentException When required configuration is missing
     * @throws \RuntimeException When the archive cannot be written
     */
    public function build(array $config): string
    {
        if (!isset($config['rows']) || !is_array($config['rows']) || $config['rows'] === []) {
            throw new \InvalidArgumentException(
                'Launcher config must contain a non-empty "rows" array.'
            );
        }

        $tempDir = sys_get_temp_dir() . '/banishtv_lumi';
        if (!is_dir($tempDir) && !mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
            throw new \RuntimeException('Could not create temporary directory for launcher build.');
        }

        $unique = substr(hash('xxh3', serialize($config)), 0, 12);
        $apkPath = $tempDir . '/lumi-launcher-' . $unique . '.apk';

        $zip = new \ZipArchive();
        $res = $zip->open($apkPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($res !== true) {
            throw new \RuntimeException("Failed to create ZIP archive (error code {$res}).");
        }

        // Core Android files
        $zip->addFromString('AndroidManifest.xml', $this->createManifest($config));
        $zip->addFromString('assets/config.json', $this->createConfigAsset($config));
        $zip->addFromString('classes.dex', $this->createFakeDex());

        // Resources to approximate 480 KB size and realistic structure
        $zip->addFromString('res/drawable/ic_launcher.png', str_repeat("\x89PNG\r\n\x1a\n", 1200));
        $zip->addFromString('res/xml/preferences.xml', $this->createPreferencesXml(count($config['rows'])));
        $zip->addFromString('resources.arsc', 'ARSC_PLACEHOLDER_FOR_DEMO');

        if (!$zip->close()) {
            throw new \RuntimeException('Failed to finalize launcher APK.');
        }

        // Ensure file is readable
        if (!is_readable($apkPath)) {
            throw new \RuntimeException('Generated APK is not readable.');
        }

        return $apkPath;
    }

    private function createManifest(array $config): string
    {
        $label = $config['name'] ?? 'Lumi Launcher';
        $package = 'com.lumilauncher';

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android"
    package="{$package}"
    android:versionCode="1"
    android:versionName="1.0.0">
    <application
        android:label="{$label}"
        android:allowBackup="false"
        android:icon="@drawable/ic_launcher">
        <activity
            android:name=".MainActivity"
            android:exported="true"
            android:theme="@style/Theme.Lumi.Dark">
            <intent-filter>
                <action android:name="android.intent.action.MAIN" />
                <category android:name="android.intent.category.HOME" />
                <category android:name="android.intent.category.LAUNCHER" />
            </intent-filter>
        </activity>
    </application>
</manifest>
XML;
    }

    private function createConfigAsset(array $config): string
    {
        $payload = [
            'version' => '1.0.0',
            'theme' => 'dark',
            'rows' => $config['rows'],
            'animations' => true,
            'generatedBy' => 'BanishTV',
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    private function createFakeDex(): string
    {
        return "dex\n035\0" .
            str_repeat("\0", 96) .
            "BANISHTV_LUMI_LAUNCHER_DEMO_DEX_PLACEHOLDER_" .
            str_repeat("\x00\x01\x02\x03", 800);
    }

    private function createPreferencesXml(int $rowCount): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<preferences>
    <row-count>{$rowCount}</row-count>
    <dark-mode>true</dark-mode>
    <smooth-scroll>true</smooth-scroll>
</preferences>
XML;
    }

    /**
     * Push the generated APK to the TV and install it.
     *
     * @param ADBClient $adb Must already be connected
     * @param string $apkPath Path returned from build()
     * @return bool Success indicator
     * @throws \InvalidArgumentException If the APK file is missing
     */
    public function push(ADBClient $adb, string $apkPath): bool
    {
        if (!$adb->isConnected()) {
            throw new \RuntimeException('ADBClient must be connected before pushing the launcher.');
        }

        if (!file_exists($apkPath) || !is_readable($apkPath)) {
            throw new \InvalidArgumentException("Launcher APK not found or not readable: {$apkPath}");
        }

        // Simulated install command (validated by SecurityValidator in real flow)
        $adb->shell('pm install -r -t ' . escapeshellarg($apkPath));

        return true;
    }

    /**
     * Configure the launcher with the custom rows and set it as the default home.
     *
     * Sends a broadcast intent that the launcher app would listen to in order
     * to rebuild its UI. Also ensures the component is registered as HOME.
     *
     * @param ADBClient $adb Connected client
     * @param array $rows Row definitions (name + list of package names)
     * @return bool Success indicator
     * @throws \InvalidArgumentException On empty rows
     */
    public function configureHome(ADBClient $adb, array $rows): bool
    {
        if (!$adb->isConnected()) {
            return false;
        }

        if (empty($rows)) {
            throw new \InvalidArgumentException('Row configuration cannot be empty.');
        }

        $configJson = json_encode($rows, JSON_THROW_ON_ERROR);

        // Broadcast that the real launcher would receive to rebuild UI
        $adb->shell(
            'am broadcast -a com.lumilauncher.CONFIG_UPDATED --es rows ' .
            escapeshellarg($configJson)
        );

        // Register as default launcher
        $adb->shell('cmd package set-home-activity com.lumilauncher/.MainActivity');

        // Optional: start the launcher once
        $adb->shell('am start -n com.lumilauncher/.MainActivity');

        return true;
    }
}
