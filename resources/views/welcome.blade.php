<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Huntr.id API - Backend Overview</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=fira-code:400,500|instrument-sans:400,500,600,700" rel="stylesheet" />

        <!-- Styles / Scripts -->
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            <script src="https://cdn.tailwindcss.com"></script>
            <style>
                body { font-family: 'Instrument Sans', sans-serif; }
                code, pre { font-family: 'Fira Code', monospace; }
            </style>
        @endif
    </head>
    <body class="bg-[#0a0a0a] text-[#EDEDEC] antialiased selection:bg-[#f53003] selection:text-white">
        <div class="max-w-5xl mx-auto px-6 py-12 md:py-20">
            <!-- Header -->
            <header class="mb-16 border-b border-[#ffffff10] pb-8">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 bg-[#f53003] rounded-xl flex items-center justify-center shadow-lg shadow-[#f5300340]">
                        <span class="text-white font-bold text-2xl">H</span>
                    </div>
                    <h1 class="text-2xl font-bold tracking-tight">Huntr.id <span class="text-[#706f6c] font-medium">API Core</span></h1>
                </div>
                <p class="text-[#A1A09A] text-lg max-w-2xl">
                    Layanan backend untuk platform pengadaan B2B Huntr.id. Dibangun dengan fokus pada skalabilitas, keterbacaan kode, dan arsitektur yang terstruktur.
                </p>
            </header>

            <div class="grid md:grid-cols-12 gap-12">
                <!-- Main Content -->
                <div class="md:col-span-8 space-y-12">
                    <!-- Architecture Section -->
                    <section>
                        <h2 class="text-xl font-bold mb-6 flex items-center gap-2">
                            <span class="text-[#f53003]">01.</span> Arsitektur & Pola
                        </h2>
                        <div class="bg-[#161615] border border-[#ffffff10] rounded-2xl p-6 space-y-4">
                            <p class="text-[#A1A09A]">
                                Backend ini mengadopsi prinsip <span class="text-white font-medium">Domain-Driven Design (DDD)</span> untuk memisahkan logika bisnis dari infrastruktur framework:
                            </p>
                            <ul class="space-y-4">
                                <li class="flex gap-4">
                                    <div class="text-[#f53003] font-mono text-sm mt-1">/Domain</div>
                                    <div>
                                        <div class="font-medium">Business Logic Layer</div>
                                        <div class="text-sm text-[#706f6c]">Berisi Actions, Repositories, Models, dan Http Requests yang dikelompokkan berdasarkan konteks bisnis (Auth, Company, Order, dll).</div>
                                    </div>
                                </li>
                                <li class="flex gap-4">
                                    <div class="text-[#f53003] font-mono text-sm mt-1">Actions</div>
                                    <div>
                                        <div class="font-medium">Single Responsibility Use Cases</div>
                                        <div class="text-sm text-[#706f6c]">Setiap operasi bisnis didefinisikan dalam satu class Action (misal: <code>RegisterUserAction</code>).</div>
                                    </div>
                                </li>
                                <li class="flex gap-4">
                                    <div class="text-[#f53003] font-mono text-sm mt-1">Repos</div>
                                    <div>
                                        <div class="font-medium">Data Access Abstraction</div>
                                        <div class="text-sm text-[#706f6c]">Menggunakan Repository Pattern untuk memisahkan logika kueri database dari logika aplikasi.</div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </section>

                    <!-- API Status -->
                    <section>
                        <h2 class="text-xl font-bold mb-6 flex items-center gap-2">
                            <span class="text-[#f53003]">02.</span> Informasi Sistem
                        </h2>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                            <div class="bg-[#161615] border border-[#ffffff10] rounded-xl p-4">
                                <div class="text-xs text-[#706f6c] uppercase mb-1">PHP Version</div>
                                <div class="font-mono text-sm">{{ PHP_VERSION }}</div>
                            </div>
                            <div class="bg-[#161615] border border-[#ffffff10] rounded-xl p-4">
                                <div class="text-xs text-[#706f6c] uppercase mb-1">Laravel</div>
                                <div class="font-mono text-sm">{{ app()->version() }}</div>
                            </div>
                            <div class="bg-[#161615] border border-[#ffffff10] rounded-xl p-4">
                                <div class="text-xs text-[#706f6c] uppercase mb-1">Environment</div>
                                <div class="font-mono text-sm">{{ app()->environment() }}</div>
                            </div>
                            <div class="bg-[#161615] border border-[#ffffff10] rounded-xl p-4">
                                <div class="text-xs text-[#706f6c] uppercase mb-1">Queue</div>
                                <div class="font-mono text-sm">Redis/Horizon</div>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Sidebar / Links -->
                <div class="md:col-span-4 space-y-8">
                    <div>
                        <h3 class="text-sm font-bold text-[#706f6c] uppercase tracking-wider mb-4">Developer Consoles</h3>
                        <div class="space-y-3">
                            <a href="/docs/api" class="flex items-center justify-between p-4 bg-[#161615] border border-[#ffffff10] rounded-xl hover:border-[#f5300350] transition-all group">
                                <span class="font-medium group-hover:text-[#f53003]">API Documentation</span>
                                <span class="text-xs bg-[#f5300320] text-[#f53003] px-2 py-0.5 rounded">Scramble</span>
                            </a>
                            <a href="/pulse" class="flex items-center justify-between p-4 bg-[#161615] border border-[#ffffff10] rounded-xl hover:border-[#f5300350] transition-all group">
                                <span class="font-medium group-hover:text-[#f53003]">Monitoring</span>
                                <span class="text-xs bg-purple-500/20 text-purple-400 px-2 py-0.5 rounded">Pulse</span>
                            </a>
                            <a href="/horizon" class="flex items-center justify-between p-4 bg-[#161615] border border-[#ffffff10] rounded-xl hover:border-[#f5300350] transition-all group">
                                <span class="font-medium group-hover:text-[#f53003]">Queue Manager</span>
                                <span class="text-xs bg-pink-500/20 text-pink-400 px-2 py-0.5 rounded">Horizon</span>
                            </a>
                        </div>
                    </div>

                    <div class="p-6 bg-[#f5300310] border border-[#f5300320] rounded-2xl">
                        <h3 class="text-sm font-bold text-[#f53003] uppercase tracking-wider mb-2">Endpoint Root</h3>
                        <p class="text-xs text-[#A1A09A] mb-4">Base URL untuk akses API versi 1.</p>
                        <code class="text-xs bg-black/50 p-2 rounded block border border-[#ffffff05]">
                            {{ config('app.url') }}/api/v1
                        </code>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <footer class="mt-24 pt-8 border-t border-[#ffffff10] flex justify-between items-center">
                <div class="text-sm text-[#706f6c]">
                    &copy; {{ date('Y') }} Huntr Technologies. Built with Discipline.
                </div>
                <div class="flex gap-6">
                    <a href="https://laravel.com" class="text-[#706f6c] hover:text-white transition-colors">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0L12.0001 2.39999L18.4118 6.10051L18.4117 13.5015L21.6 15.3418V7.9408L12 2.39999L12 0ZM12 24L12 21.6L5.58824 17.8995L5.58824 10.4985L2.4 8.65824V16.0592L12 21.6001L12 24ZM12 15.3418L15.2118 13.5015L15.2118 9.80051L12 11.6408L8.78824 9.80051L8.78824 13.5015L12 15.3418ZM12 8.35824L15.2118 6.51798L12.0001 4.67772L8.78824 6.51798L12 8.35824Z"/></svg>
                    </a>
                </div>
            </footer>
        </div>
    </body>
</html>
