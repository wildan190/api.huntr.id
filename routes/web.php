<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $octaneServer   = config('octane.server', 'swoole');
    $cpuCount       = function_exists('swoole_cpu_num') ? swoole_cpu_num() : (intval(shell_exec('nproc')) ?: 4);
    $workers        = $cpuCount * 2;
    $phpVersion     = PHP_VERSION;
    $laravelVersion = app()->version();
    $env            = app()->environment();
    $memUsageMb     = round(memory_get_usage(true) / 1024 / 1024, 1);
    $peakMemMb      = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
    $memLimitMb     = intval(ini_get('memory_limit')) ?: 512;
    $memPct         = min(100, round($memUsageMb / $memLimitMb * 100));
    $workersPct     = min(100, round($workers / 32 * 100)); // scale to 32 max
    $startedAt      = now()->format('d M Y · H:i:s T');
    $phpMajor       = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

    // SVG arc gauge helper (returns path d= for a partial arc)
    // Returns: [strokeDasharray, strokeDashoffset] for a circle (r=40, circ=251.2)
    $circ = 2 * M_PI * 40;
    $memDash   = round($circ * $memPct / 100, 2);
    $memGap    = round($circ - $memDash, 2);
    $wrkDash   = round($circ * $workersPct / 100, 2);
    $wrkGap    = round($circ - $wrkDash, 2);
    $phpPct    = min(100, round((PHP_MAJOR_VERSION * 10 + PHP_MINOR_VERSION) / 90 * 100)); // 8.5 → ~94%
    $phpDash   = round($circ * $phpPct / 100, 2);
    $phpGap    = round($circ - $phpDash, 2);

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>⚡ Octane Status · Huntr API</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" />
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #07091a;
      --surface: #0f1626;
      --surface2: #161e30;
      --surface3: #1c2540;
      --border: rgba(255,255,255,0.06);
      --emerald: #10b981;
      --cyan: #06b6d4;
      --violet: #7c3aed;
      --amber: #f59e0b;
      --rose: #f43f5e;
      --text: #e2e8f0;
      --muted: #475569;
      --muted2: #64748b;
      --glow-g: rgba(16,185,129,0.4);
      --glow-c: rgba(6,182,212,0.4);
    }
    html, body {
      min-height: 100vh;
      background: var(--bg);
      color: var(--text);
      font-family: 'Inter', system-ui, sans-serif;
      display: flex; flex-direction: column;
      align-items: center;
    }
    body::before {
      content: '';
      position: fixed; inset: 0; pointer-events: none; z-index: 0;
      background:
        radial-gradient(ellipse 70% 45% at 15% 20%, rgba(16,185,129,0.06) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 85% 75%, rgba(124,58,237,0.06) 0%, transparent 60%),
        radial-gradient(ellipse 40% 30% at 50% 50%, rgba(6,182,212,0.03) 0%, transparent 60%);
    }

    /* ─── NAVBAR ─────────────────────────────────────────── */
    .navbar {
      width: 100%; position: relative; z-index: 10;
      background: rgba(15,22,38,0.85);
      backdrop-filter: blur(12px) saturate(1.5);
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; gap: 0;
      padding: 0 24px; height: 52px;
    }
    .navbar-brand {
      display: flex; align-items: center; gap: 8px;
      font-size: 13px; font-weight: 800; letter-spacing: -0.01em;
      background: linear-gradient(90deg, var(--emerald), var(--cyan));
      -webkit-background-clip: text; -webkit-text-fill-color: transparent;
      background-clip: text;
      margin-right: 24px; flex-shrink: 0;
    }
    .navbar-brand span { font-size: 16px; }
    .nav-links { display: flex; align-items: center; gap: 2px; flex: 1; }
    .nav-link {
      display: flex; align-items: center; gap: 6px;
      padding: 5px 12px; border-radius: 8px;
      font-size: 12px; font-weight: 600;
      text-decoration: none; color: var(--muted2);
      transition: all 0.15s ease;
      border: 1px solid transparent;
    }
    .nav-link:hover {
      color: var(--text);
      background: rgba(255,255,255,0.05);
      border-color: var(--border);
    }
    .nav-link .nav-dot {
      width: 6px; height: 6px; border-radius: 50%;
      flex-shrink: 0;
    }
    .nav-link.horizon .nav-dot { background: #f97316; box-shadow: 0 0 6px rgba(249,115,22,0.6); }
    .nav-link.pulse   .nav-dot { background: #8b5cf6; box-shadow: 0 0 6px rgba(139,92,246,0.6); }
    .nav-link.scramble .nav-dot { background: var(--cyan); box-shadow: 0 0 6px var(--glow-c); }
    .nav-link.api     .nav-dot { background: var(--emerald); box-shadow: 0 0 6px var(--glow-g); }

    .nav-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }
    .live-pill {
      display: flex; align-items: center; gap: 6px;
      padding: 4px 10px; border-radius: 99px;
      background: rgba(16,185,129,0.1);
      border: 1px solid rgba(16,185,129,0.22);
    }
    .live-dot {
      width: 7px; height: 7px; border-radius: 50%;
      background: var(--emerald);
      box-shadow: 0 0 8px var(--glow-g);
      animation: pulse-dot 1.8s ease-in-out infinite;
    }
    @keyframes pulse-dot {
      0%,100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.35; transform: scale(0.65); }
    }
    .live-pill span { font-size: 10px; font-weight: 800; color: var(--emerald); letter-spacing: 0.1em; }
    .env-badge {
      padding: 3px 9px; border-radius: 5px; font-size: 10px; font-weight: 700;
      background: rgba(245,158,11,0.12); color: var(--amber);
      border: 1px solid rgba(245,158,11,0.25); letter-spacing: 0.06em; text-transform: uppercase;
    }

    /* ─── MAIN CONTENT ──────────────────────────────────── */
    .page { width: 100%; max-width: 860px; padding: 28px 20px 48px; position: relative; z-index: 1; }

    /* ─── HERO ───────────────────────────────────────────── */
    .hero {
      display: flex; align-items: center; gap: 16px;
      margin-bottom: 28px;
    }
    .hero-icon-wrap { position: relative; width: 54px; height: 54px; flex-shrink: 0; }
    .hero-ring {
      position: absolute; inset: 0; border-radius: 50%;
      border: 1.5px solid transparent;
      background: linear-gradient(135deg, var(--emerald), var(--cyan)) border-box;
      -webkit-mask: linear-gradient(#fff 0 0) padding-box, linear-gradient(#fff 0 0);
      -webkit-mask-composite: destination-out; mask-composite: exclude;
      animation: spin-ring 5s linear infinite;
    }
    @keyframes spin-ring { to { transform: rotate(360deg); } }
    .hero-icon {
      position: absolute; inset: 7px; border-radius: 50%;
      background: linear-gradient(135deg, rgba(16,185,129,0.18), rgba(6,182,212,0.18));
      display: flex; align-items: center; justify-content: center; font-size: 20px;
    }
    .hero-text h1 { font-size: 22px; font-weight: 900; letter-spacing: -0.02em;
      background: linear-gradient(90deg, var(--emerald) 0%, var(--cyan) 100%);
      -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    }
    .hero-text p { font-size: 13px; color: var(--muted2); margin-top: 2px; }

    /* ─── SWOOLE BANNER ───────────────────────────────────── */
    .banner {
      display: flex; align-items: center; gap: 14px;
      padding: 14px 18px; border-radius: 12px; margin-bottom: 24px;
      background: linear-gradient(135deg, rgba(16,185,129,0.07), rgba(6,182,212,0.07));
      border: 1px solid rgba(16,185,129,0.15);
      position: relative; overflow: hidden;
    }
    .banner::after {
      content: ''; position: absolute; inset: 0;
      background: linear-gradient(90deg, transparent, rgba(16,185,129,0.05), transparent);
      background-size: 200% 100%;
      animation: shimmer 3s linear infinite;
    }
    @keyframes shimmer { 0% { background-position: -200% center; } 100% { background-position: 200% center; } }
    .banner-icon { font-size: 26px; flex-shrink: 0; position: relative; z-index: 1; }
    .banner-body { flex: 1; position: relative; z-index: 1; }
    .banner-body h2 { font-size: 13px; font-weight: 800; color: var(--emerald); margin-bottom: 2px; }
    .banner-body p { font-size: 11.5px; color: var(--muted2); line-height: 1.5; }

    /* ─── GAUGES ──────────────────────────────────────────── */
    .section-title {
      font-size: 10px; font-weight: 800; text-transform: uppercase;
      letter-spacing: 0.12em; color: var(--muted); margin-bottom: 14px;
    }
    .gauges-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 24px; }
    .gauge-card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 14px; padding: 18px 12px 14px;
      display: flex; flex-direction: column; align-items: center; gap: 0;
      transition: border-color 0.2s;
    }
    .gauge-card:hover { border-color: rgba(16,185,129,0.25); }
    .gauge-svg { width: 100px; height: 100px; }
    .gauge-track { fill: none; stroke: var(--surface3); stroke-width: 7; stroke-linecap: round; }
    .gauge-fill  { fill: none; stroke-width: 7; stroke-linecap: round;
      transform-origin: center; transform: rotate(-90deg);
      transition: stroke-dasharray 1s cubic-bezier(.4,0,.2,1);
    }
    .gauge-fill.emerald { stroke: url(#gGrad); filter: drop-shadow(0 0 4px rgba(16,185,129,0.5)); }
    .gauge-fill.cyan    { stroke: url(#cGrad); filter: drop-shadow(0 0 4px rgba(6,182,212,0.5)); }
    .gauge-fill.violet  { stroke: url(#vGrad); filter: drop-shadow(0 0 4px rgba(124,58,237,0.5)); }
    .gauge-center { text-align: center; margin-top: -10px; }
    .gauge-val { font-size: 16px; font-weight: 800; font-family: 'JetBrains Mono', monospace; line-height: 1.1; }
    .gauge-val.emerald { color: var(--emerald); }
    .gauge-val.cyan    { color: var(--cyan); }
    .gauge-val.violet  { color: #a78bfa; }
    .gauge-label { font-size: 10px; font-weight: 600; color: var(--muted2); margin-top: 4px; text-transform: uppercase; letter-spacing: 0.07em; }
    .gauge-sub { font-size: 9px; color: var(--muted); margin-top: 2px; }

    /* ─── STATS GRID ──────────────────────────────────────── */
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 24px; }
    .stat-card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 12px; padding: 12px 14px;
    }
    .stat-card:hover { border-color: rgba(255,255,255,0.1); }
    .stat-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.09em; color: var(--muted); margin-bottom: 5px; }
    .stat-val { font-size: 14px; font-weight: 700; font-family: 'JetBrains Mono', monospace; }
    .stat-val.g { color: var(--emerald); }
    .stat-val.c { color: var(--cyan); }
    .stat-val.a { color: var(--amber); }
    .stat-val.w { color: var(--text); }

    /* ─── PILLS ───────────────────────────────────────────── */
    .pills { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 24px; }
    .pill { padding: 4px 11px; border-radius: 99px; font-size: 10px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
    .pill.g { background: rgba(16,185,129,0.1);  color: var(--emerald); border: 1px solid rgba(16,185,129,0.22); }
    .pill.c { background: rgba(6,182,212,0.1);   color: var(--cyan);    border: 1px solid rgba(6,182,212,0.22); }
    .pill.v { background: rgba(124,58,237,0.1);  color: #a78bfa;        border: 1px solid rgba(124,58,237,0.22); }
    .pill.a { background: rgba(245,158,11,0.1);  color: var(--amber);   border: 1px solid rgba(245,158,11,0.22); }

    /* ─── FOOTER ──────────────────────────────────────────── */
    .footer {
      display: flex; align-items: center; justify-content: space-between;
      padding-top: 16px; border-top: 1px solid var(--border);
    }
    .footer span { font-size: 10px; color: var(--muted); }
    .footer a { font-size: 10px; color: rgba(16,185,129,0.5); text-decoration: none; transition: color 0.15s; }
    .footer a:hover { color: var(--emerald); }

    @media (max-width: 640px) {
      .gauges-grid { grid-template-columns: repeat(3, 1fr); gap: 8px; }
      .stats-grid  { grid-template-columns: repeat(2, 1fr); }
      .navbar-brand { display: none; }
    }
  </style>
</head>
<body>

  <!-- ─── NAVBAR ─────────────────────────────────────────── -->
  <nav class="navbar">
    <div class="navbar-brand"><span>⚡</span> Huntr API</div>
    <div class="nav-links">
      <a href="/horizon" class="nav-link horizon" target="_blank" rel="noopener">
        <div class="nav-dot"></div> Horizon
      </a>
      <a href="/pulse" class="nav-link pulse" target="_blank" rel="noopener">
        <div class="nav-dot"></div> Pulse
      </a>
      <a href="/docs/api" class="nav-link scramble" target="_blank" rel="noopener">
        <div class="nav-dot"></div> Scramble API
      </a>
      <a href="/api/health" class="nav-link api" target="_blank" rel="noopener">
        <div class="nav-dot"></div> Health Check
      </a>
      <a href="/up" class="nav-link api" target="_blank" rel="noopener">
        <div class="nav-dot"></div> /up
      </a>
    </div>
    <div class="nav-right">
      <span class="env-badge">{$env}</span>
      <div class="live-pill">
        <div class="live-dot"></div>
        <span>LIVE</span>
      </div>
    </div>
  </nav>

  <!-- ─── PAGE CONTENT ───────────────────────────────────── -->
  <div class="page">

    <!-- Hero -->
    <div class="hero">
      <div class="hero-icon-wrap">
        <div class="hero-ring"></div>
        <div class="hero-icon">⚡</div>
      </div>
      <div class="hero-text">
        <h1>Octane · Swoole</h1>
        <p>Huntr API &mdash; persistent in-memory worker mode</p>
      </div>
    </div>

    <!-- Banner -->
    <div class="banner">
      <div class="banner-icon">🚀</div>
      <div class="banner-body">
        <h2>Laravel Octane + Swoole is active</h2>
        <p>Requests are served from a long-lived in-memory Swoole worker. No PHP-FPM cold-boot overhead per request &mdash; coroutines, WebSocket, and async I/O ready.</p>
      </div>
    </div>

    <!-- SVG gradient defs (hidden) -->
    <svg width="0" height="0" style="position:absolute">
      <defs>
        <linearGradient id="gGrad" x1="0%" y1="0%" x2="100%" y2="0%">
          <stop offset="0%" stop-color="#10b981"/>
          <stop offset="100%" stop-color="#06b6d4"/>
        </linearGradient>
        <linearGradient id="cGrad" x1="0%" y1="0%" x2="100%" y2="0%">
          <stop offset="0%" stop-color="#06b6d4"/>
          <stop offset="100%" stop-color="#3b82f6"/>
        </linearGradient>
        <linearGradient id="vGrad" x1="0%" y1="0%" x2="100%" y2="0%">
          <stop offset="0%" stop-color="#7c3aed"/>
          <stop offset="100%" stop-color="#a78bfa"/>
        </linearGradient>
      </defs>
    </svg>

    <!-- ─── GAUGES ─────────────────────────────────────────── -->
    <div class="section-title">Server Meters</div>
    <div class="gauges-grid">

      <!-- Memory gauge -->
      <div class="gauge-card">
        <svg class="gauge-svg" viewBox="0 0 100 100">
          <circle class="gauge-track" cx="50" cy="50" r="40"/>
          <circle class="gauge-fill emerald" cx="50" cy="50" r="40"
            stroke-dasharray="{$memDash} {$memGap}"/>
        </svg>
        <div class="gauge-center">
          <div class="gauge-val emerald">{$memUsageMb}MB</div>
          <div class="gauge-label">Memory</div>
          <div class="gauge-sub">limit: {$memLimitMb}MB &middot; {$memPct}%</div>
        </div>
      </div>

      <!-- Workers gauge -->
      <div class="gauge-card">
        <svg class="gauge-svg" viewBox="0 0 100 100">
          <circle class="gauge-track" cx="50" cy="50" r="40"/>
          <circle class="gauge-fill cyan" cx="50" cy="50" r="40"
            stroke-dasharray="{$wrkDash} {$wrkGap}"/>
        </svg>
        <div class="gauge-center">
          <div class="gauge-val cyan">{$workers}</div>
          <div class="gauge-label">Workers</div>
          <div class="gauge-sub">cpu cores: {$cpuCount} &middot; ×2</div>
        </div>
      </div>

      <!-- PHP Version gauge -->
      <div class="gauge-card">
        <svg class="gauge-svg" viewBox="0 0 100 100">
          <circle class="gauge-track" cx="50" cy="50" r="40"/>
          <circle class="gauge-fill violet" cx="50" cy="50" r="40"
            stroke-dasharray="{$phpDash} {$phpGap}"/>
        </svg>
        <div class="gauge-center">
          <div class="gauge-val violet">{$phpMajor}</div>
          <div class="gauge-label">PHP Version</div>
          <div class="gauge-sub">v{$phpVersion}</div>
        </div>
      </div>

    </div>

    <!-- ─── STAT CARDS ─────────────────────────────────────── -->
    <div class="section-title">Runtime Info</div>
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-label">Engine</div>
        <div class="stat-val g">{$octaneServer}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Laravel</div>
        <div class="stat-val w">{$laravelVersion}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Peak Memory</div>
        <div class="stat-val a">{$peakMemMb} MB</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">PHP Full</div>
        <div class="stat-val w">{$phpVersion}</div>
      </div>
    </div>

    <!-- ─── CAPABILITY PILLS ───────────────────────────────── -->
    <div class="section-title">Capabilities</div>
    <div class="pills">
      <span class="pill g">✓ Swoole Extension</span>
      <span class="pill g">✓ Octane Enabled</span>
      <span class="pill c">✓ Coroutines</span>
      <span class="pill c">✓ WebSocket Ready</span>
      <span class="pill v">✓ Zero Cold Boot</span>
      <span class="pill v">✓ Async I/O</span>
      <span class="pill a">✓ Horizon</span>
      <span class="pill a">✓ Pulse</span>
      <span class="pill c">✓ Scramble API Docs</span>
    </div>

    <!-- ─── FOOTER ─────────────────────────────────────────── -->
    <div class="footer">
      <span>⚡ Started · {$startedAt}</span>
      <div style="display:flex;gap:16px">
        <a href="/horizon" target="_blank">→ Horizon</a>
        <a href="/pulse" target="_blank">→ Pulse</a>
        <a href="/docs/api" target="_blank">→ API Docs</a>
        <a href="/api/health" target="_blank">→ Health</a>
      </div>
    </div>

  </div><!-- /page -->

</body>
</html>
HTML;

    return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
});

// Include debug routes for development
if (app()->environment(['local', 'development', 'staging'])) {
    require __DIR__ . '/debug.php';
}

// Dynamic SEO & Sitemap fallbacks
Route::get('/robots.txt', function() {
    $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://app.huntr.id')), '/');
    $content = "User-agent: *\nAllow: /\nDisallow: /api/auth/\nDisallow: /api/admin/\nDisallow: /admin/\n\nSitemap: {$frontendUrl}/sitemap.xml";
    return response($content, 200)->header('Content-Type', 'text/plain');
});

Route::get('/llms.txt', function() {
    if (file_exists(public_path('llms.txt'))) {
        return response(file_get_contents(public_path('llms.txt')), 200)->header('Content-Type', 'text/plain');
    }
    $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://app.huntr.id')), '/');
    $catalogues = \App\Domain\Catalogue\Models\Catalogue::with('company')->get();
    
    $txt = "# Huntr Catalogue\n\n> Huntr is a B2B Procurement and Marketplace Platform. Discover products, vendors, and raise RFQs seamlessly.\n\n## Main Directory\n\n- [Home Page]({$frontendUrl}/)\n- [Catalogue Index]({$frontendUrl}/catalogues)\n\n## Products and Services\n\n";
    foreach ($catalogues as $item) {
        $brandStr = $item->brand ? " Brand: {$item->brand}." : '';
        $categoryStr = $item->category ? " Category: {$item->category}." : '';
        $vendorStr = ($item->company && $item->company->name) ? " Offered by: {$item->company->name}." : '';
        $specs = $item->specifications ? " Specs: {$item->specifications}." : '';
        $txt .= "- [{$item->name}]({$frontendUrl}/catalogues/{$item->id}) -{$brandStr}{$categoryStr}{$vendorStr}{$specs}\n";
    }
    return response($txt, 200)->header('Content-Type', 'text/plain');
});

Route::get('/sitemap.xml', function() {
    if (file_exists(public_path('sitemap.xml'))) {
        return response(file_get_contents(public_path('sitemap.xml')), 200)->header('Content-Type', 'application/xml');
    }
    $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://app.huntr.id')), '/');
    $now = now()->toAtomString();
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n    <sitemap>\n        <loc>{$frontendUrl}/sitemap-static.xml</loc>\n        <lastmod>{$now}</lastmod>\n    </sitemap>\n    <sitemap>\n        <loc>{$frontendUrl}/sitemap-products.xml</loc>\n        <lastmod>{$now}</lastmod>\n    </sitemap>\n</sitemapindex>";
    return response($xml, 200)->header('Content-Type', 'application/xml');
});

Route::get('/sitemap-static.xml', function() {
    if (file_exists(public_path('sitemap-static.xml'))) {
        return response(file_get_contents(public_path('sitemap-static.xml')), 200)->header('Content-Type', 'application/xml');
    }
    $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://app.huntr.id')), '/');
    $now = now()->toAtomString();
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n    <url>\n        <loc>{$frontendUrl}/</loc>\n        <lastmod>{$now}</lastmod>\n        <changefreq>daily</changefreq>\n        <priority>1.0</priority>\n    </url>\n    <url>\n        <loc>{$frontendUrl}/catalogues</loc>\n        <lastmod>{$now}</lastmod>\n        <changefreq>daily</changefreq>\n        <priority>0.8</priority>\n    </url>\n</urlset>";
    return response($xml, 200)->header('Content-Type', 'application/xml');
});

Route::get('/sitemap-products.xml', function() {
    if (file_exists(public_path('sitemap-products.xml'))) {
        return response(file_get_contents(public_path('sitemap-products.xml')), 200)->header('Content-Type', 'application/xml');
    }
    $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://app.huntr.id')), '/');
    $catalogues = \App\Domain\Catalogue\Models\Catalogue::with('company')->get();
    
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:image=\"http://www.google.com/schemas/sitemap-image/1.1\">\n";

    foreach ($catalogues as $item) {
        $loc = "{$frontendUrl}/catalogues/{$item->id}";
        $lastmod = $item->updated_at ? $item->updated_at->toAtomString() : now()->toAtomString();
        
        $xml .= "    <url>\n";
        $xml .= "        <loc>{$loc}</loc>\n";
        $xml .= "        <lastmod>{$lastmod}</lastmod>\n";
        $xml .= "        <changefreq>weekly</changefreq>\n";
        $xml .= "        <priority>0.7</priority>\n";

        if ($item->image_path) {
            $path = $item->image_path;
            if (filter_var($path, FILTER_VALIDATE_URL)) {
                $imgUrl = $path;
            } else {
                $disk = config('filesystems.default') === 's3' ? 's3' : 'public';
                /** @var \Illuminate\Filesystem\FilesystemAdapter $storageDisk */
                $storageDisk = \Illuminate\Support\Facades\Storage::disk($disk);
                $imgUrl = $storageDisk->url($path);

            }
            $xml .= "        <image:image>\n";
            $xml .= "            <image:loc>" . htmlspecialchars($imgUrl) . "</image:loc>\n";
            $xml .= "            <image:title>" . htmlspecialchars($item->name) . "</image:title>\n";
            $xml .= "        </image:image>\n";
        }

        $xml .= "    </url>\n";
    }

    $xml .= "</urlset>";
    return response($xml, 200)->header('Content-Type', 'application/xml');
});

