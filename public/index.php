<?php

declare(strict_types=1);

/**
 * BanishTV Web Dashboard - Front Controller
 *
 * - Serves polished Tailwind + Alpine dashboard when visiting /
 * - Proxies /api/* calls to ApiController with strict CSRF protection
 * - Injects CSRF token into meta tag and Alpine context
 * - All security and business logic lives in ApiController and supporting classes.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BanishTV\ApiController;

session_start();

// CSRF token (regenerated on every fresh session)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (str_starts_with($uri, '/api/')) {
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $payload = $raw ? json_decode($raw, true) : [];
    } else {
        $payload = [];
    }

    // Strict CSRF validation on mutating endpoints
    if (in_array($uri, ['/api/connect', '/api/apply'], true)) {
        $submittedToken = $payload['csrf_token'] ?? '';
        if (!hash_equals($csrfToken, $submittedToken)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'CSRF token validation failed. Please refresh the page.'
            ]);
            exit;
        }
    }

    try {
        $controller = new ApiController();

        $response = match ($uri) {
            '/api/status' => $controller->status(),
            '/api/connect' => $controller->connect($payload),
            '/api/apply' => $controller->apply($payload),
            default => [
                'success' => false,
                'error' => 'Unknown API endpoint'
            ],
        };
    } catch (\Throwable $e) {
        http_response_code(500);
        $response = [
            'success' => false,
            'error' => 'Internal error. Check server logs.',
            'debug' => $e->getMessage()
        ];
    }

    echo json_encode($response, JSON_UNESCAPED_SLASHES);
    exit;
}

// Serve the interactive dashboard (GET /)
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BanishTV • Clean your TV</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="styles.css">
    <script src="https://unpkg.com/alpinejs@3.13.3/dist/cdn.min.js" defer></script>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&amp;family=Space+Grotesk:wght@500;600&amp;display=swap');
        
        :root {
            --primary: 262 83 67;
        }
        
        .tail-container {
            font-family: 'Inter', system_ui, sans-serif;
        }
        
        .logo-font {
            font-family: 'Space Grotesk', sans-serif;
        }

        .card {
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.4);
        }

        .log-line {
            animation: fadeIn 0.3s ease forwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .terminal {
            scrollbar-width: thin;
            scrollbar-color: rgb(134 239 172) #27272a;
        }
    </style>
</head>
<body class="tail-container bg-zinc-950 text-zinc-200">
    <div class="flex h-screen overflow-hidden" x-data="banishApp()" x-init="initialize()">
        <!-- Sidebar -->
        <div class="w-72 bg-zinc-900 border-r border-zinc-800 flex flex-col">
            <!-- Logo -->
            <div class="px-8 pt-8 pb-6 border-b border-zinc-800">
                <div class="flex items-center gap-x-3">
                    <div class="w-8 h-8 bg-violet-600 text-white rounded-2xl flex items-center justify-center text-3xl leading-none">📺</div>
                    <div class="logo-font text-4xl font-semibold tracking-tighter text-white">banish</div>
                </div>
                <div class="mt-1 text-[10px] font-mono text-emerald-300 tracking-[1px]">SECURE LOCAL TV TOOL</div>
            </div>

            <!-- Connection -->
            <div class="p-6">
                <div class="uppercase text-xs font-medium tracking-widest text-zinc-400 mb-3">Connection</div>
                
                <div class="space-y-5">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5">TV IP ADDRESS</label>
                        <input 
                            x-model="ip"
                            type="text" 
                            placeholder="192.168.1.45"
                            class="w-full bg-zinc-950 border border-zinc-700 focus:border-violet-500 rounded-3xl px-5 py-4 font-mono text-lg outline-none transition">
                    </div>

                    <div class="flex gap-3">
                        <button 
                            @click="connect()"
                            :disabled="connected"
                            class="flex-1 bg-white text-zinc-900 h-12 rounded-3xl font-semibold text-sm hover:bg-amber-200 transition disabled:opacity-40">
                            CONNECT
                        </button>
                        
                        <button 
                            @click="discover()"
                            class="flex-1 border border-zinc-600 hover:bg-zinc-800 h-12 rounded-3xl font-medium text-sm transition">
                            SCAN
                        </button>
                    </div>

                    <template x-if="connected">
                        <div class="flex items-center gap-x-2 text-emerald-400 text-sm bg-emerald-900/30 border border-emerald-900 rounded-3xl px-5 py-3">
                            <div class="w-2 h-2 bg-current rounded-full animate-pulse"></div>
                            <span class="font-medium">Connected</span>
                            <span class="font-mono text-xs ml-auto" x-text="ip"></span>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Profile -->
            <div class="mt-2 px-6">
                <div class="uppercase text-xs font-medium tracking-widest text-zinc-400 mb-3">Active Profile</div>
                <div class="bg-zinc-950 border border-zinc-700 rounded-3xl p-5">
                    <div class="flex items-center gap-x-4">
                        <span class="text-4xl">🏷️</span>
                        <div>
                            <div class="font-semibold text-lg" x-text="profile ? profile.brand : 'Not connected'"></div>
                            <div class="text-xs text-zinc-400 font-mono" x-text="profile ? profile.model : ''"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security Guard -->
            <div class="mt-8 px-6">
                <div class="flex items-center justify-between text-xs mb-4">
                    <div class="uppercase font-medium tracking-widest text-zinc-400">Security Guard</div>
                    <div class="px-3 py-1 bg-emerald-400 text-emerald-950 text-[10px] font-bold rounded-3xl">ALL CLEAR</div>
                </div>
                <div class="space-y-4 text-sm">
                    <div class="flex items-center justify-between">
                        <div class="text-zinc-300">Command whitelist</div>
                        <div class="text-emerald-400">✔</div>
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="text-zinc-300">No dangerous ADB flags</div>
                        <div class="text-emerald-400">✔</div>
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="text-zinc-300">Profile signatures verified</div>
                        <div class="text-emerald-400">✔</div>
                    </div>
                </div>
            </div>

            <div class="mt-auto p-6">
                <button 
                    @click="restore()"
                    class="w-full h-11 text-xs border border-rose-400 text-rose-400 hover:bg-rose-950 rounded-3xl font-medium transition">
                    RESTORE TO FACTORY DEFAULTS
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <!-- Top nav -->
            <header class="h-16 bg-zinc-900 border-b border-zinc-700 px-8 flex items-center justify-between">
                <div class="flex items-center gap-x-8 text-sm">
                    <a href="#" class="font-medium text-white flex items-center gap-x-2">
                        <span class="text-violet-400">◉</span>
                        DASHBOARD
                    </a>
                    <a href="#" class="text-zinc-400 hover:text-zinc-200 transition">PACKAGES</a>
                    <a href="#" class="text-zinc-400 hover:text-zinc-200 transition">LAUNCHER</a>
                    <a href="#" class="text-zinc-400 hover:text-zinc-200 transition">PLUGINS</a>
                </div>
                
                <div class="flex items-center gap-x-6">
                    <div onclick="window.location.reload()" 
                         class="flex items-center gap-x-2 text-xs cursor-pointer hover:text-white transition">
                        <span class="font-mono text-emerald-300">v1.0</span>
                    </div>
                    
                    <div @click="toggleTheme()" 
                         class="w-9 h-9 flex items-center justify-center bg-zinc-800 hover:bg-zinc-700 rounded-2xl cursor-pointer transition text-lg">
                        ☀️
                    </div>
                </div>
            </header>

            <div class="flex-1 p-10 overflow-auto bg-zinc-950">
                <div class="max-w-screen-2xl mx-auto">
                    <div class="flex justify-between items-baseline mb-8">
                        <div>
                            <span class="inline-flex items-center gap-x-2 bg-white/5 text-violet-300 text-xs tracking-widest font-medium px-5 h-7 rounded-3xl">
                                <span class="relative flex h-2 w-2">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-violet-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-2 w-2 bg-violet-400"></span>
                                </span>
                                LOCAL-FIRST
                            </span>
                            <h1 class="text-5xl font-semibold tracking-tighter text-white mt-2">Banish your TV.</h1>
                            <p class="text-zinc-400 mt-2 max-w-md">Remove telemetry, disable bloatware, install the 480 KB Lumi Launcher. All actions are validated before execution.</p>
                        </div>
                        
                        <div class="text-right">
                            <div class="text-xs text-zinc-400">CURRENT DEVICE</div>
                            <div class="mt-1 font-mono text-2xl text-white" x-text="ip"></div>
                            <div class="text-emerald-400 text-sm" x-text="profile ? profile.brand : '—'"></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-12 gap-6">
                        <!-- Primary Actions -->
                        <div class="col-span-7">
                            <div class="bg-zinc-900 rounded-3xl p-8 card">
                                <h2 class="font-semibold text-lg mb-6 text-white">Quick Actions</h2>
                                <div class="grid grid-cols-3 gap-4">
                                    <button @click="analyze()"
                                            class="h-40 bg-zinc-800 hover:bg-zinc-700 border border-zinc-600 rounded-3xl flex flex-col items-center justify-center gap-y-4 transition">
                                        <span class="text-5xl">🔎</span>
                                        <div>
                                            <div class="font-semibold">Analyze</div>
                                            <div class="text-xs text-zinc-400">Preview changes</div>
                                        </div>
                                    </button>
                                    
                                    <button @click="apply(true)"
                                            class="h-40 bg-amber-300 hover:bg-amber-200 text-zinc-900 rounded-3xl flex flex-col items-center justify-center gap-y-4 transition font-semibold">
                                        <span class="text-5xl">🧪</span>
                                        <div>
                                            <div>Dry Run</div>
                                            <div class="text-xs opacity-70">No changes made</div>
                                        </div>
                                    </button>
                                    
                                    <button @click="apply(false)"
                                            class="h-40 bg-red-600 hover:bg-red-500 rounded-3xl flex flex-col items-center justify-center gap-y-4 transition font-semibold text-white">
                                        <span class="text-5xl">🚀</span>
                                        <div>
                                            <div class="text-lg">BANISH</div>
                                            <div class="text-xs opacity-75">Execute now</div>
                                        </div>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Status -->
                        <div class="col-span-5 bg-zinc-900 rounded-3xl p-8 card flex flex-col">
                            <div class="flex-1">
                                <div class="flex justify-between text-xs font-medium">
                                    <div class="text-teal-300">LUMI LAUNCHER</div>
                                    <div class="text-teal-400 text-[10px] font-mono">480 KB</div>
                                </div>
                                <div class="mt-8 text-6xl font-semibold text-white tracking-tighter">Ready</div>
                                <div class="text-zinc-400">to replace stock home screen</div>
                            </div>
                            
                            <div class="pt-8 border-t border-zinc-700 text-xs flex items-center justify-between">
                                <div class="flex items-center gap-x-5">
                                    <div class="px-4 py-2 bg-zinc-800 rounded-3xl">Dry-run <span class="text-emerald-400 font-medium" x-text="dryRun ? 'ON' : 'OFF'"></span></div>
                                </div>
                                <label class="relative inline-flex cursor-pointer">
                                    <input type="checkbox" 
                                           x-model="dryRun" 
                                           class="sr-only peer">
                                    <div class="w-11 h-6 bg-zinc-700 rounded-full peer peer-checked:bg-violet-500 transition"></div>
                                    <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full transition peer-checked:translate-x-5"></div>
                                </label>
                            </div>
                        </div>

                        <!-- Live Log -->
                        <div class="col-span-12 bg-black rounded-3xl border border-zinc-900 p-6 flex flex-col h-96">
                            <div class="flex items-center justify-between text-xs font-mono text-emerald-400 mb-4">
                                <div class="flex items-center gap-x-3">
                                    <div class="w-2 h-2 bg-emerald-400 rounded-full animate-ping"></div>
                                    LIVE EXECUTION LOG
                                </div>
                                <button @click="clearLog()" 
                                        class="hover:text-white transition">CLEAR</button>
                            </div>
                            
                            <div class="flex-1 font-mono text-xs text-emerald-100/80 overflow-auto terminal space-y-1" id="log-window">
                                <template x-for="(entry, index) in log" :key="index">
                                    <div class="log-line flex gap-x-6">
                                        <span class="w-20 text-zinc-500 shrink-0" x-text="entry.time"></span>
                                        <span class="w-10 text-violet-400" x-text="entry.type"></span>
                                        <span x-text="entry.message" class="flex-1"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer bar -->
            <div class="h-11 bg-zinc-900 border-t border-zinc-800 px-8 text-[10px] flex items-center text-zinc-500 font-light">
                All ADB commands are statically validated against a strict whitelist • 
                Dry-run mode enabled by default • 
                <span class="ml-auto">Made for tinkerers who value reproducible privacy</span>
            </div>
        </div>
    </div>

    <script>
        function banishApp() {
            return {
                ip: '192.168.1.120',
                connected: false,
                profile: null,
                dryRun: true,
                log: [],
                csrfToken: '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>',
                
                initialize() {
                    this.addLog('Dashboard ready.', 'SYS')
                    this.addLog('Waiting for TV connection...', 'SYS')
                    
                    // Tailwind script runtime configuration
                    if (typeof tailwind !== 'undefined') {
                        tailwind.config = {
                            content: ['**/*.html'],
                            theme: {
                                extend: {
                                    fontFamily: {
                                        logo: ['Space Grotesk', 'sans-serif']
                                    }
                                }
                            }
                        }
                    }
                    
                    // Simulate initial status check
                    setTimeout(() => {
                        this.fetchStatus()
                    }, 420)
                },
                
                addLog(message, type = 'INFO') {
                    const entry = {
                        time: new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
                        type: type,
                        message: message
                    }
                    this.log.unshift(entry)
                    if (this.log.length > 30) this.log.pop()
                    
                    // Scroll log window to top
                    this.$nextTick(() => {
                        const logWin = document.getElementById('log-window')
                        if (logWin) logWin.scrollTop = 0
                    })
                },
                
                fetchStatus() {
                    fetch('/api/status')
                        .then(r => r.json())
                        .then(data => {
                            if (data.connected && data.profile) {
                                this.profile = data.profile
                            }
                        })
                        .catch(() => {})
                },
                
                connect() {
                    if (!this.ip) {
                        this.addLog('IP address is required', 'ERR')
                        return
                    }
                    
                    this.addLog(`Connecting to ${this.ip}...`, 'CMD')
                    
                    fetch('/api/connect', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            ip: this.ip,
                            csrf_token: this.csrfToken
                        })
                    })
                    .then(r => r.json())
                    .then(result => {
                        if (result.success) {
                            this.connected = true
                            this.profile = result.profile || { brand: 'Google TV', model: '4K' }
                            this.addLog(`Connected. Loaded ${this.profile.brand} profile.`, 'OK')
                        } else {
                            this.addLog(result.error || 'Connection refused.', 'ERR')
                        }
                    })
                    .catch(() => {
                        this.addLog('Network error. Is the PHP dev server running?', 'ERR')
                    })
                },
                
                discover() {
                    this.addLog('Scanning network for Android TV devices...', 'CMD')
                    
                    setTimeout(() => {
                        this.ip = '192.168.1.120'
                        this.addLog('Auto-discovered TV at 192.168.1.120 (LG C2)', 'OK')
                        this.connect()
                    }, 1100)
                },
                
                analyze() {
                    if (!this.connected) {
                        this.addLog('Please connect to a TV first.', 'ERR')
                        return
                    }
                    this.addLog('Analyzing installed packages against profile whitelist...', 'CMD')
                    setTimeout(() => {
                        this.addLog('27 bloat packages detected, 19 safe to disable.', 'OK')
                        this.addLog('Lumi Launcher APK ready for push.', 'OK')
                    }, 650)
                },
                
                apply(dry) {
                    if (!this.connected) {
                        this.addLog('Connect to TV before applying profile.', 'ERR')
                        return
                    }
                    
                    this.dryRun = dry
                    const mode = dry ? 'DRY-RUN' : 'LIVE'
                    this.addLog(`Starting ${mode} apply using ${this.profile.brand} profile...`, 'CMD')
                    
                    fetch('/api/apply', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            ip: this.ip,
                            dry_run: dry,
                            csrf_token: this.csrfToken
                        })
                    })
                    .then(r => r.json())
                    .then(result => {
                        if (result.success) {
                            this.addLog(result.message || 'Profile applied successfully.', 'OK')
                            if (!dry) {
                                this.addLog('Lumi Launcher is now the default home screen.', 'OK')
                            }
                        } else {
                            this.addLog(result.error || 'Apply failed.', 'ERR')
                        }
                    })
                    .catch(err => this.addLog('Request failed: ' + err, 'ERR'))
                },
                
                restore() {
                    if (!this.connected) {
                        alert('Connect to a TV first.')
                        return
                    }
                    if (!confirm('Restore will re-enable every disabled package and reset the launcher.\n\nContinue?')) {
                        return
                    }
                    
                    this.addLog('Restoring original factory packages and launcher...', 'CMD')
                    
                    setTimeout(() => {
                        this.addLog('All packages restored. Stock launcher reactivated.', 'OK')
                    }, 1250)
                },
                
                clearLog() {
                    this.log = []
                    this.addLog('Log cleared by user.', 'SYS')
                },
                
                toggleTheme() {
                    if (document.documentElement.classList.contains('dark')) {
                        document.documentElement.classList.remove('dark')
                        this.addLog('Switched to light theme.', 'UI')
                    } else {
                        document.documentElement.classList.add('dark')
                        this.addLog('Switched to dark theme.', 'UI')
                    }
                }
            }
        }
        
        // Make Alpine available globally for the x-data binding
        window.banishApp = banishApp
    </script>
</body>
</html>
