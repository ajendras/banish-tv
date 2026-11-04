# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-04

### Added

- Auto Discovery — Scans network, identifies brand/model and loads the matching vetted profile automatically.
- Security Guard — Every ADB command is statically validated against a strict whitelist before execution.
- Pluggable Profiles — Brand profiles are simple PHP classes; add new ones or override via the plugin system.
- Lumi Launcher — Lightweight 480 KB home screen with configurable rows, dark mode and smooth animations.
- Dry-Run Mode — Simulates every change and shows an exact diff with zero impact on the TV.
- Live Dashboard — Responsive web UI with real-time logs, progress, dark/light mode and one-click actions.
- Atomic Restore — Single command or button restores every disabled package and original launcher.
- Tested Core — Profiles and engine covered by PHPUnit; prevents regressions on every change.
- Plugin Hooks — Extend with custom pre/post actions, extra UI panels or third-party ad blockers.
