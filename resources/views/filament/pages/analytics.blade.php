<x-filament-panels::page wire:poll.60s>
    {{-- Inline styles scoped to analytics page --}}
    <style>
        /* ═══ Analytics Card ═══ */
        .an-card {
            position: relative;
            overflow: hidden;
            border-radius: 1rem;
            padding: 1.25rem 1.5rem;
            background: white;
            border: 1px solid rgba(0, 0, 0, 0.06);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .dark .an-card {
            background: rgba(255, 255, 255, 0.03);
            border-color: rgba(255, 255, 255, 0.06);
        }
        .an-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px -4px rgba(0, 0, 0, 0.1);
        }
        .dark .an-card:hover {
            box-shadow: 0 8px 24px -4px rgba(0, 0, 0, 0.4);
        }
        .an-card--live {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            border-color: rgba(16, 185, 129, 0.2);
        }
        .dark .an-card--live {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.08) 0%, rgba(16, 185, 129, 0.15) 100%);
            border-color: rgba(16, 185, 129, 0.2);
        }

        /* ═══ Stat numbers ═══ */
        .an-stat-num {
            font-size: 1.75rem;
            font-weight: 800;
            line-height: 1;
            font-variant-numeric: tabular-nums;
            letter-spacing: -0.03em;
        }
        .an-stat-label {
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        /* ═══ Trend badge ═══ */
        .an-trend {
            display: inline-flex;
            align-items: center;
            gap: 0.15rem;
            padding: 0.15rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.6875rem;
            font-weight: 600;
        }
        .an-trend--up {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
        }
        .dark .an-trend--up {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
        }
        .an-trend--down {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }
        .dark .an-trend--down {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
        }
        .an-trend--neutral {
            background: rgba(107, 114, 128, 0.08);
            color: #6b7280;
        }
        .dark .an-trend--neutral {
            background: rgba(107, 114, 128, 0.12);
            color: #9ca3af;
        }

        /* ═══ Section container ═══ */
        .an-section {
            border-radius: 1rem;
            background: white;
            border: 1px solid rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }
        .dark .an-section {
            background: rgba(255, 255, 255, 0.03);
            border-color: rgba(255, 255, 255, 0.06);
        }
        .an-section__header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .dark .an-section__header {
            border-bottom-color: rgba(255, 255, 255, 0.04);
        }
        .an-section__body {
            padding: 1.5rem;
        }

        /* ═══ Period selector ═══ */
        .an-period {
            display: inline-flex;
            gap: 0.25rem;
            padding: 0.25rem;
            border-radius: 0.75rem;
            background: rgba(0, 0, 0, 0.04);
        }
        .dark .an-period {
            background: rgba(255, 255, 255, 0.04);
        }
        .an-period__btn {
            padding: 0.375rem 0.875rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            border: none;
            background: transparent;
            color: #6b7280;
        }
        .dark .an-period__btn {
            color: #9ca3af;
        }
        .an-period__btn:hover {
            background: rgba(0, 0, 0, 0.04);
            color: #374151;
        }
        .dark .an-period__btn:hover {
            background: rgba(255, 255, 255, 0.06);
            color: #d1d5db;
        }
        .an-period__btn--active {
            background: white !important;
            color: #111827 !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }
        .dark .an-period__btn--active {
            background: rgba(255, 255, 255, 0.1) !important;
            color: #f3f4f6 !important;
        }

        /* ═══ Bar list ═══ */
        .an-bar-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0;
            position: relative;
        }
        .an-bar-item + .an-bar-item {
            border-top: 1px solid rgba(0, 0, 0, 0.04);
        }
        .dark .an-bar-item + .an-bar-item {
            border-top-color: rgba(255, 255, 255, 0.04);
        }
        .an-bar-bg {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            border-radius: 0.375rem;
            opacity: 0.08;
        }
        .dark .an-bar-bg {
            opacity: 0.12;
        }
        .an-bar-name {
            flex: 1;
            font-size: 0.8125rem;
            font-weight: 500;
            position: relative;
            z-index: 1;
        }
        .an-bar-count {
            font-size: 0.8125rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            position: relative;
            z-index: 1;
        }

        /* ═══ Chart placeholder (simple bar chart) ═══ */
        .an-minichart {
            display: flex;
            align-items: flex-end;
            gap: 2px;
            height: 120px;
        }
        .an-minichart__bar {
            flex: 1;
            border-radius: 2px 2px 0 0;
            min-height: 2px;
            transition: height 0.3s ease;
            position: relative;
        }
        .an-minichart__bar--views {
            background: linear-gradient(180deg, #6366f1, #818cf8);
        }
        .an-minichart__bar--visitors {
            background: linear-gradient(180deg, #10b981, #34d399);
        }

        /* ═══ Donut chart (CSS) ═══ */
        .an-donut {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            position: relative;
        }
        .an-donut__center {
            position: absolute;
            inset: 25%;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.6875rem;
            font-weight: 700;
        }
        .dark .an-donut__center {
            background: #1a1a2e;
        }
        .an-donut-legend {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .an-donut-legend__item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8125rem;
        }
        .an-donut-legend__dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* ═══ Pulse dot ═══ */
        .an-pulse {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            position: relative;
        }
        .an-pulse::before {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            background: rgba(16, 185, 129, 0.3);
            animation: an-glow 2s ease-in-out infinite;
        }
        @keyframes an-glow {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(2); opacity: 0; }
        }

        /* ═══ Hourly heatmap ═══ */
        .an-hourly {
            display: grid;
            grid-template-columns: repeat(24, 1fr);
            gap: 2px;
        }
        .an-hourly__cell {
            aspect-ratio: 1;
            border-radius: 0.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.5625rem;
            font-weight: 500;
            font-variant-numeric: tabular-nums;
            cursor: default;
            transition: transform 0.15s;
        }
        .an-hourly__cell:hover {
            transform: scale(1.2);
            z-index: 1;
        }

        /* ═══ Empty state ═══ */
        .an-empty {
            text-align: center;
            padding: 3rem 2rem;
        }
        .an-empty__icon {
            width: 4rem;
            height: 4rem;
            margin: 0 auto 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 1rem;
            background: rgba(107, 114, 128, 0.08);
            font-size: 2rem;
        }
        .dark .an-empty__icon {
            background: rgba(107, 114, 128, 0.12);
        }
    </style>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- Period Selector --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mb-6">
        <div class="flex items-center gap-3 flex-wrap">
            <div class="an-pulse"></div>

            {{-- Site Selector --}}
            @if($sites->count() > 0)
                <select
                    wire:change="setSiteId($event.target.value)"
                    class="text-xs font-medium rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 px-3 py-1.5 focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500 transition"
                >
                    <option value="" @selected(!$siteId)>🌐 Tất cả sites</option>
                    @foreach($sites as $site)
                        <option value="{{ $site->id }}" @selected($siteId == $site->id)>
                            {{ $site->name }} ({{ $site->domain }})
                        </option>
                    @endforeach
                </select>
            @endif

            <span class="text-sm font-medium text-gray-600 dark:text-gray-400">
                {{ $dateFrom }} — {{ $dateTo }}
            </span>
            @if($gaEnabled)
                <div class="an-period ml-2">
                    @foreach(['self' => '🏠 Self-hosted', 'ga' => '📊 GA4', 'compare' => '⚖️ So sánh'] as $key => $label)
                        <button
                            wire:click="setSource('{{ $key }}')"
                            @class(['an-period__btn', 'an-period__btn--active' => $source === $key])
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="an-period">
            @foreach(['1d' => 'Hôm nay', '7d' => '7 ngày', '14d' => '14 ngày', '30d' => '30 ngày', '90d' => '90 ngày'] as $key => $label)
                <button
                    wire:click="setPeriod('{{ $key }}')"
                    @class(['an-period__btn', 'an-period__btn--active' => $period === $key])
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- Quick Stats Row --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">

        {{-- Active Visitors (real-time) --}}
        <div class="an-card an-card--live">
            <p class="an-stat-label text-emerald-700 dark:text-emerald-300">Đang xem</p>
            <div class="flex items-baseline gap-2">
                <span class="an-stat-num text-emerald-700 dark:text-emerald-300">{{ $activeVisitors }}</span>
                <span class="text-xs text-emerald-600/70 dark:text-emerald-400/70">trong 30 phút</span>
            </div>
            <p class="text-sm text-emerald-600/80 dark:text-emerald-400/80 mt-2">
                {{ \App\Filament\Pages\AnalyticsPage::formatNumber($todayViews) }} lượt xem hôm nay
            </p>
        </div>

        {{-- Total Views --}}
        @php
            $viewsChange = \App\Filament\Pages\AnalyticsPage::percentChange($overview->totalViews, $prevOverview->totalViews);
        @endphp
        <div class="an-card">
            <p class="an-stat-label text-gray-500 dark:text-gray-400">Lượt xem</p>
            <div class="flex items-baseline gap-2">
                <span class="an-stat-num text-gray-900 dark:text-white">{{ \App\Filament\Pages\AnalyticsPage::formatNumber($overview->totalViews) }}</span>
                @if($viewsChange !== null)
                    <span @class([
                        'an-trend',
                        'an-trend--up' => $viewsChange > 0,
                        'an-trend--down' => $viewsChange < 0,
                        'an-trend--neutral' => $viewsChange == 0,
                    ])>
                        {{ $viewsChange > 0 ? '↑' : ($viewsChange < 0 ? '↓' : '–') }}
                        {{ abs($viewsChange) }}%
                    </span>
                @endif
            </div>
            <p class="text-xs text-gray-400 mt-2">
                🤖 {{ number_format($overview->botViews) }} bot
            </p>
        </div>

        {{-- Unique Visitors --}}
        @php
            $visitorsChange = \App\Filament\Pages\AnalyticsPage::percentChange($overview->uniqueVisitors, $prevOverview->uniqueVisitors);
        @endphp
        <div class="an-card">
            <p class="an-stat-label text-gray-500 dark:text-gray-400">Khách truy cập</p>
            <div class="flex items-baseline gap-2">
                <span class="an-stat-num text-gray-900 dark:text-white">{{ \App\Filament\Pages\AnalyticsPage::formatNumber($overview->uniqueVisitors) }}</span>
                @if($visitorsChange !== null)
                    <span @class([
                        'an-trend',
                        'an-trend--up' => $visitorsChange > 0,
                        'an-trend--down' => $visitorsChange < 0,
                        'an-trend--neutral' => $visitorsChange == 0,
                    ])>
                        {{ $visitorsChange > 0 ? '↑' : ($visitorsChange < 0 ? '↓' : '–') }}
                        {{ abs($visitorsChange) }}%
                    </span>
                @endif
            </div>
            <p class="text-xs text-gray-400 mt-2">
                {{ number_format($overview->newVisitors) }} khách mới
            </p>
        </div>

        {{-- Bounce Rate --}}
        <div class="an-card">
            <p class="an-stat-label text-gray-500 dark:text-gray-400">Tỉ lệ thoát</p>
            <div class="flex items-baseline gap-2">
                <span class="an-stat-num text-gray-900 dark:text-white">{{ $overview->bounceRate }}%</span>
            </div>
            <p class="text-xs text-gray-400 mt-2">
                TB {{ $overview->avgPagesPerSession }} trang/phiên
            </p>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- GA4 Comparison (only when source = 'compare') --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    @if($gaEnabled && $source === 'compare' && $gaOverview)
        <div class="an-section mb-6">
            <div class="an-section__header">
                <span class="text-base">⚖️</span>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">So sánh: Self-hosted vs Google Analytics 4</h3>
            </div>
            <div class="an-section__body">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <th class="text-left py-2 px-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Metric</th>
                                <th class="text-right py-2 px-3 text-xs font-semibold uppercase" style="color: #6366f1">🏠 Self-hosted</th>
                                <th class="text-right py-2 px-3 text-xs font-semibold uppercase" style="color: #f59e0b">📊 GA4</th>
                                <th class="text-right py-2 px-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Chênh lệch</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $compareMetrics = [
                                    ['label' => 'Lượt xem', 'self' => $overview->totalViews, 'ga' => $gaOverview->totalViews],
                                    ['label' => 'Khách truy cập', 'self' => $overview->uniqueVisitors, 'ga' => $gaOverview->uniqueVisitors],
                                    ['label' => 'Khách mới', 'self' => $overview->newVisitors, 'ga' => $gaOverview->newVisitors],
                                    ['label' => 'Tỉ lệ thoát (%)', 'self' => $overview->bounceRate, 'ga' => $gaOverview->bounceRate],
                                    ['label' => 'Trang/phiên', 'self' => $overview->avgPagesPerSession, 'ga' => $gaOverview->avgPagesPerSession],
                                ];
                            @endphp
                            @foreach($compareMetrics as $m)
                                @php
                                    $diff = $m['self'] - $m['ga'];
                                    $diffPct = $m['ga'] > 0 ? round(($diff / $m['ga']) * 100, 1) : ($m['self'] > 0 ? 100 : 0);
                                @endphp
                                <tr class="border-b border-gray-50 dark:border-gray-800/50">
                                    <td class="py-2.5 px-3 font-medium text-gray-700 dark:text-gray-300">{{ $m['label'] }}</td>
                                    <td class="py-2.5 px-3 text-right font-bold tabular-nums" style="color: #6366f1">{{ is_float($m['self']) ? $m['self'] : number_format($m['self']) }}</td>
                                    <td class="py-2.5 px-3 text-right font-bold tabular-nums" style="color: #f59e0b">{{ is_float($m['ga']) ? $m['ga'] : number_format($m['ga']) }}</td>
                                    <td class="py-2.5 px-3 text-right">
                                        @if($diffPct != 0)
                                            <span @class([
                                                'an-trend',
                                                'an-trend--up' => $diff > 0,
                                                'an-trend--down' => $diff < 0,
                                            ])>
                                                {{ $diff > 0 ? '+' : '' }}{{ $diffPct }}%
                                            </span>
                                        @else
                                            <span class="text-xs text-gray-400">–</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-3">
                    💡 Chênh lệch là bình thường — Self-hosted đo tất cả request, GA4 lọc bot và chỉ đếm session có JS.
                </p>
            </div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- Traffic Chart --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div class="an-section mb-6">
        <div class="an-section__header">
            <span class="text-base">📈</span>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Lưu lượng truy cập</h3>
            <div class="ml-auto flex gap-3 text-xs">
                <span class="flex items-center gap-1">
                    <span class="inline-block w-3 h-3 rounded-sm" style="background: #6366f1"></span>
                    Lượt xem
                </span>
                <span class="flex items-center gap-1">
                    <span class="inline-block w-3 h-3 rounded-sm" style="background: #10b981"></span>
                    Khách
                </span>
            </div>
        </div>
        <div class="an-section__body">
            @if($traffic->max('views') > 0)
                @php
                    $maxViews = max(1, $traffic->max('views'));
                @endphp
                <div class="an-minichart mb-2">
                    @foreach($traffic as $day)
                        <div class="flex flex-col items-stretch flex-1 gap-0.5" style="height: 120px; display: flex; flex-direction: column; justify-content: flex-end;" title="{{ $day['label'] }}: {{ $day['views'] }} views, {{ $day['visitors'] }} visitors">
                            <div class="an-minichart__bar an-minichart__bar--views" style="height: {{ ($day['views'] / $maxViews) * 100 }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-between text-[10px] text-gray-400 dark:text-gray-500 px-0.5">
                    <span>{{ $traffic->first()['label'] }}</span>
                    <span>{{ $traffic->last()['label'] }}</span>
                </div>
            @else
                <div class="an-empty">
                    <div class="an-empty__icon">📊</div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Chưa có dữ liệu</p>
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        Dữ liệu sẽ xuất hiện khi có lượt truy cập được ghi nhận
                    </p>
                </div>
            @endif
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- Row: Devices + Referrers --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

        {{-- Device Breakdown --}}
        <div class="an-section">
            <div class="an-section__header">
                <span class="text-base">📱</span>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Thiết bị</h3>
            </div>
            <div class="an-section__body">
                @php
                    $totalDevices = $devices['desktop'] + $devices['mobile'] + $devices['tablet'];
                    $desktopPct = $totalDevices > 0 ? round(($devices['desktop'] / $totalDevices) * 100) : 0;
                    $mobilePct = $totalDevices > 0 ? round(($devices['mobile'] / $totalDevices) * 100) : 0;
                    $tabletPct = $totalDevices > 0 ? round(($devices['tablet'] / $totalDevices) * 100) : 0;
                @endphp
                @if($totalDevices > 0)
                    <div class="flex items-center gap-8">
                        {{-- CSS donut --}}
                        <div
                            class="an-donut"
                            style="background: conic-gradient(
                                #6366f1 0% {{ $desktopPct }}%,
                                #10b981 {{ $desktopPct }}% {{ $desktopPct + $mobilePct }}%,
                                #f59e0b {{ $desktopPct + $mobilePct }}% 100%
                            );"
                        >
                            <div class="an-donut__center text-gray-700 dark:text-gray-300">
                                {{ number_format($totalDevices) }}
                            </div>
                        </div>
                        <div class="an-donut-legend">
                            <div class="an-donut-legend__item">
                                <span class="an-donut-legend__dot" style="background: #6366f1"></span>
                                <span class="text-gray-700 dark:text-gray-300">Desktop</span>
                                <span class="ml-auto font-bold text-gray-900 dark:text-white">{{ $desktopPct }}%</span>
                            </div>
                            <div class="an-donut-legend__item">
                                <span class="an-donut-legend__dot" style="background: #10b981"></span>
                                <span class="text-gray-700 dark:text-gray-300">Mobile</span>
                                <span class="ml-auto font-bold text-gray-900 dark:text-white">{{ $mobilePct }}%</span>
                            </div>
                            <div class="an-donut-legend__item">
                                <span class="an-donut-legend__dot" style="background: #f59e0b"></span>
                                <span class="text-gray-700 dark:text-gray-300">Tablet</span>
                                <span class="ml-auto font-bold text-gray-900 dark:text-white">{{ $tabletPct }}%</span>
                            </div>
                        </div>
                    </div>
                @else
                    <p class="text-sm text-gray-400 text-center py-4">Chưa có dữ liệu</p>
                @endif
            </div>
        </div>

        {{-- Top Referrers --}}
        <div class="an-section">
            <div class="an-section__header">
                <span class="text-base">🔗</span>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Nguồn truy cập</h3>
            </div>
            <div class="an-section__body">
                @if($referrers->isNotEmpty())
                    @php $maxRef = $referrers->max('count'); @endphp
                    @foreach($referrers as $ref)
                        <div class="an-bar-item">
                            <div class="an-bar-bg bg-indigo-500" style="width: {{ $maxRef > 0 ? ($ref['count'] / $maxRef) * 100 : 0 }}%"></div>
                            <span class="an-bar-name text-gray-700 dark:text-gray-300">{{ $ref['name'] }}</span>
                            <span class="an-bar-count text-gray-900 dark:text-white">{{ number_format($ref['count']) }}</span>
                        </div>
                    @endforeach
                @else
                    <p class="text-sm text-gray-400 text-center py-4">Chưa có dữ liệu</p>
                @endif
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- Row: Browsers + OS --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

        {{-- Top Browsers --}}
        <div class="an-section">
            <div class="an-section__header">
                <span class="text-base">🌐</span>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Trình duyệt</h3>
            </div>
            <div class="an-section__body">
                @if($browsers->isNotEmpty())
                    @php $maxBr = $browsers->max('count'); @endphp
                    @foreach($browsers as $br)
                        <div class="an-bar-item">
                            <div class="an-bar-bg bg-sky-500" style="width: {{ $maxBr > 0 ? ($br['count'] / $maxBr) * 100 : 0 }}%"></div>
                            <span class="an-bar-name text-gray-700 dark:text-gray-300">{{ $br['name'] }}</span>
                            <span class="an-bar-count text-gray-900 dark:text-white">{{ number_format($br['count']) }}</span>
                        </div>
                    @endforeach
                @else
                    <p class="text-sm text-gray-400 text-center py-4">Chưa có dữ liệu</p>
                @endif
            </div>
        </div>

        {{-- Top OS --}}
        <div class="an-section">
            <div class="an-section__header">
                <span class="text-base">💻</span>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Hệ điều hành</h3>
            </div>
            <div class="an-section__body">
                @if($oses->isNotEmpty())
                    @php $maxOs = $oses->max('count'); @endphp
                    @foreach($oses as $os)
                        <div class="an-bar-item">
                            <div class="an-bar-bg bg-emerald-500" style="width: {{ $maxOs > 0 ? ($os['count'] / $maxOs) * 100 : 0 }}%"></div>
                            <span class="an-bar-name text-gray-700 dark:text-gray-300">{{ $os['name'] }}</span>
                            <span class="an-bar-count text-gray-900 dark:text-white">{{ number_format($os['count']) }}</span>
                        </div>
                    @endforeach
                @else
                    <p class="text-sm text-gray-400 text-center py-4">Chưa có dữ liệu</p>
                @endif
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- Row: Hourly Distribution + Page Types --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

        {{-- Hourly Heatmap --}}
        <div class="an-section">
            <div class="an-section__header">
                <span class="text-base">⏰</span>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Phân bố theo giờ</h3>
            </div>
            <div class="an-section__body">
                @php
                    $maxHourly = max(1, max($hourly));
                @endphp
                <div class="an-hourly">
                    @foreach($hourly as $h => $count)
                        @php
                            $intensity = $maxHourly > 0 ? ($count / $maxHourly) : 0;
                            $bgColor = $intensity > 0.75 ? 'bg-indigo-500 text-white'
                                : ($intensity > 0.5 ? 'bg-indigo-400 text-white'
                                : ($intensity > 0.25 ? 'bg-indigo-200 dark:bg-indigo-800 text-indigo-800 dark:text-indigo-200'
                                : ($intensity > 0 ? 'bg-indigo-100 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-400'
                                : 'bg-gray-100 dark:bg-gray-800 text-gray-400 dark:text-gray-500')));
                        @endphp
                        <div class="an-hourly__cell {{ $bgColor }}" title="{{ $h }}h: {{ number_format($count) }} lượt xem">
                            {{ $h }}
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-between text-[10px] text-gray-400 mt-2">
                    <span>Sáng sớm</span>
                    <span>Trưa</span>
                    <span>Tối</span>
                </div>
            </div>
        </div>

        {{-- Page Types --}}
        <div class="an-section">
            <div class="an-section__header">
                <span class="text-base">📑</span>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Theo loại trang</h3>
            </div>
            <div class="an-section__body">
                @if($pageTypes->isNotEmpty())
                    @php
                        $maxPt = $pageTypes->max('total_views');
                        $typeLabels = [
                            'story'    => '📖 Truyện',
                            'chapter'  => '📄 Chương',
                            'category' => '🏷️ Danh mục',
                            'search'   => '🔍 Tìm kiếm',
                            'ranking'  => '🏆 Bảng xếp hạng',
                            'author'   => '✍️ Tác giả',
                        ];
                    @endphp
                    @foreach($pageTypes as $pt)
                        <div class="an-bar-item">
                            <div class="an-bar-bg bg-violet-500" style="width: {{ $maxPt > 0 ? ($pt['total_views'] / $maxPt) * 100 : 0 }}%"></div>
                            <span class="an-bar-name text-gray-700 dark:text-gray-300">
                                {{ $typeLabels[$pt['page_type']] ?? $pt['page_type'] }}
                            </span>
                            <span class="an-bar-count text-gray-900 dark:text-white">{{ number_format($pt['total_views']) }}</span>
                        </div>
                    @endforeach
                @else
                    <p class="text-sm text-gray-400 text-center py-4">Chưa có dữ liệu</p>
                @endif
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- Countries --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    @if($countries->isNotEmpty())
        <div class="an-section mb-6">
            <div class="an-section__header">
                <span class="text-base">🌍</span>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Quốc gia truy cập</h3>
            </div>
            <div class="an-section__body">
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                    @foreach($countries as $country)
                        <div class="flex items-center gap-2 p-2 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                            <span class="text-lg">{{ $country['name'] }}</span>
                            <span class="font-bold text-sm text-gray-900 dark:text-white">{{ number_format($country['count']) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

</x-filament-panels::page>
