<x-filament-panels::page wire:poll.60s>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- Period Selector --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mb-6">
        <div class="flex items-center gap-3 flex-wrap">
            <div class="an-pulse"></div>

            {{-- Site Selector (only for self-hosted) --}}
            @if($source !== 'ga' && $sites->count() > 0)
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
    {{-- Resolve display data based on source --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    @php
        // When source='ga', swap all display data to GA4 versions
        $isGa = $source === 'ga' && $gaOverview;
        $displayOverview = $isGa ? $gaOverview : $overview;
        $displayPrevOverview = $isGa ? null : $prevOverview; // GA doesn't have previous period
        $displayTraffic = $isGa ? ($gaTraffic ?? collect()) : $traffic;
        $displayDevices = $isGa ? ($gaDevices ?? ['desktop' => 0, 'mobile' => 0, 'tablet' => 0]) : $devices;
        $displayReferrers = $isGa ? ($gaReferrers ?? collect()) : $referrers;
        $displayBrowsers = $isGa ? ($gaBrowsers ?? collect()) : $browsers;
        $displayOses = $isGa ? ($gaOses ?? collect()) : $oses;
        $displayCountries = $isGa ? ($gaCountries ?? collect()) : $countries;
        $displayHourly = $isGa ? ($gaHourly ?? array_fill(0, 24, 0)) : $hourly;
        $sourceLabel = $isGa ? 'GA4' : 'Self-hosted';
        $sourceIcon = $isGa ? '📊' : '🏠';
    @endphp

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- GA4 No Data Warning --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    @if($isGa && $displayOverview->totalViews === 0)
        <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-900/10 p-4 mb-6">
            <div class="flex items-start gap-3">
                <span class="text-xl">⚠️</span>
                <div>
                    <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Chưa có dữ liệu GA4</p>
                    <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                        Chạy <code class="px-1 py-0.5 rounded bg-amber-100 dark:bg-amber-900/50">php artisan ga:import</code> để import dữ liệu từ Google Analytics 4.
                        Dữ liệu GA4 chỉ khả dụng sau khi import thành công.
                    </p>
                </div>
            </div>
        </div>
    @endif

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
    {{-- All widgets below are hidden in 'compare' mode --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    @if($source !== 'compare')
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    @php
        $periodLabels = [
            '1d' => 'hôm nay', '7d' => '7 ngày', '14d' => '14 ngày',
            '30d' => '30 ngày', '90d' => '90 ngày',
        ];
        $safePeriod = is_string($period) ? $period : '7d';
        $periodLabel = $periodLabels[$safePeriod] ?? $safePeriod;
        $currentHour = (int) now()->format('G');
        $avgPerHour = $currentHour > 0 ? round($todayViews / $currentHour) : $todayViews;
    @endphp
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">

        {{-- Active Visitors (real-time) / GA4 source info --}}
        @if(!$isGa)
            <div class="an-card an-card--live">
                <p class="an-stat-label text-emerald-700 dark:text-emerald-300">Đang xem</p>
                <div class="flex items-baseline gap-2">
                    <span class="an-stat-num text-emerald-700 dark:text-emerald-300">{{ $activeVisitors }}</span>
                    <span class="text-xs text-emerald-600/70 dark:text-emerald-400/70">trong 30 phút</span>
                </div>
                <p class="text-sm text-emerald-600/80 dark:text-emerald-400/80 mt-2">
                    ~{{ number_format($avgPerHour) }} lượt/giờ hôm nay
                </p>
            </div>
        @else
            <div class="an-card an-card--live">
                <p class="an-stat-label text-amber-700 dark:text-amber-300">📊 Nguồn dữ liệu</p>
                <div class="flex items-baseline gap-2">
                    <span class="an-stat-num text-amber-700 dark:text-amber-300">GA4</span>
                </div>
                <p class="text-sm text-amber-600/80 dark:text-amber-400/80 mt-2">
                    Google Analytics 4 · Đã lọc bot
                </p>
            </div>
        @endif

        {{-- Total Views --}}
        @php
            $humanViews = $displayOverview->totalViews - $displayOverview->botViews;
            $prevHumanViews = $displayPrevOverview
                ? $displayPrevOverview->totalViews - $displayPrevOverview->botViews
                : null;
            $viewsChange = $prevHumanViews !== null
                ? \App\Filament\Pages\AnalyticsPage::percentChange($humanViews, $prevHumanViews)
                : null;
        @endphp
        <div class="an-card">
            <p class="an-stat-label text-gray-500 dark:text-gray-400">{{ $sourceIcon }} Lượt xem · {{ $periodLabel }}</p>
            <div class="flex items-baseline gap-2">
                <span class="an-stat-num text-gray-900 dark:text-white">{{ \App\Filament\Pages\AnalyticsPage::formatNumber($humanViews) }}</span>
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
                @if($isGa)
                    🚫 GA4 tự lọc bot traffic
                @else
                    🤖 {{ number_format($displayOverview->botViews) }} bot
                    @if($botStats['bot_percentage'] > 0)
                        <span class="text-amber-500">({{ $botStats['bot_percentage'] }}% tổng traffic)</span>
                    @endif
                @endif
            </p>
        </div>

        {{-- Unique Visitors --}}
        @php
            $visitorsChange = $displayPrevOverview
                ? \App\Filament\Pages\AnalyticsPage::percentChange($displayOverview->uniqueVisitors, $displayPrevOverview->uniqueVisitors)
                : null;
        @endphp
        <div class="an-card">
            <p class="an-stat-label text-gray-500 dark:text-gray-400">Khách truy cập</p>
            <div class="flex items-baseline gap-2">
                <span class="an-stat-num text-gray-900 dark:text-white">{{ \App\Filament\Pages\AnalyticsPage::formatNumber($displayOverview->uniqueVisitors) }}</span>
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
                {{ number_format($displayOverview->newVisitors) }} khách mới
            </p>
        </div>

        {{-- Bounce Rate --}}
        <div class="an-card">
            <p class="an-stat-label text-gray-500 dark:text-gray-400">Tỉ lệ thoát</p>
            <div class="flex items-baseline gap-2">
                <span class="an-stat-num text-gray-900 dark:text-white">{{ $displayOverview->bounceRate }}%</span>
            </div>
            <p class="text-xs text-gray-400 mt-2">
                TB {{ $displayOverview->avgPagesPerSession }} trang/phiên
            </p>
        </div>

    </div>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- Traffic Chart --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div class="an-section mb-6">
        <div class="an-section__header">
            <span class="text-base">📈</span>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $sourceIcon }} Lưu lượng truy cập</h3>
            <div class="ml-auto flex gap-3 text-xs">
                <span class="flex items-center gap-1">
                    <span class="inline-block w-3 h-3 rounded-sm" style="background: {{ $isGa ? '#f59e0b' : '#6366f1' }}"></span>
                    Lượt xem
                </span>
                <span class="flex items-center gap-1">
                    <span class="inline-block w-3 h-3 rounded-sm" style="background: #10b981"></span>
                    Khách
                </span>
            </div>
        </div>
        <div class="an-section__body">
            @if($displayTraffic->max('views') > 0)
                @php
                    $maxViews = max(1, $displayTraffic->max('views'));
                    $maxVisitors = max(1, $displayTraffic->max('visitors'));
                    $chartMax = max($maxViews, $maxVisitors);
                    $barColor = $isGa ? '#f59e0b' : '#6366f1';
                    $barColorLight = $isGa ? '#fbbf24' : '#818cf8';
                @endphp
                {{-- Y-axis hint --}}
                <div class="flex justify-between text-[10px] text-gray-400 dark:text-gray-500 mb-1 px-0.5">
                    <span>{{ number_format($chartMax) }}</span>
                    <span>0</span>
                </div>
                {{-- Bar chart --}}
                @php $barCount = $displayTraffic->count(); @endphp
                <div style="display: flex; align-items: flex-end; gap: {{ $barCount <= 7 ? 8 : 4 }}px; height: 140px; justify-content: center;">
                    @foreach($displayTraffic as $day)
                        <div
                            style="flex: {{ $barCount > 7 ? '1' : '0 0 auto' }}; width: {{ $barCount <= 7 ? 'clamp(24px, ' . (100 / max($barCount, 1)) . '%, 60px)' : 'auto' }}; max-width: 60px; display: flex; align-items: flex-end; justify-content: center; gap: 2px; height: 100%;"
                            title="{{ $day['label'] }}: {{ number_format($day['views']) }} lượt xem, {{ number_format($day['visitors']) }} khách"
                        >
                            {{-- Views bar --}}
                            <div
                                style="width: 45%; min-height: 3px; border-radius: 4px 4px 0 0; background: linear-gradient(180deg, {{ $barColor }}, {{ $barColorLight }}); height: {{ ($day['views'] / $chartMax) * 100 }}%;"
                            ></div>
                            {{-- Visitors bar --}}
                            <div
                                style="width: 45%; min-height: 3px; border-radius: 4px 4px 0 0; background: linear-gradient(180deg, #10b981, #34d399); height: {{ ($day['visitors'] / $chartMax) * 100 }}%;"
                            ></div>
                        </div>
                    @endforeach
                </div>
                {{-- Date labels --}}
                <div class="flex justify-between text-[10px] text-gray-400 dark:text-gray-500 mt-2 px-0.5">
                    <span>{{ $displayTraffic->first()['label'] }}</span>
                    @if($displayTraffic->count() > 2)
                        <span>{{ $displayTraffic->values()->get((int) floor($displayTraffic->count() / 2))['label'] ?? '' }}</span>
                    @endif
                    <span>{{ $displayTraffic->last()['label'] }}</span>
                </div>
            @else
                <div class="an-empty">
                    <div class="an-empty__icon">📊</div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Chưa có dữ liệu</p>
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        @if($isGa)
                            Chạy <code>php artisan ga:import</code> để import dữ liệu GA4
                        @else
                            Dữ liệu sẽ xuất hiện khi có lượt truy cập được ghi nhận
                        @endif
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
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $sourceIcon }} Thiết bị</h3>
            </div>
            <div class="an-section__body">
                @php
                    $totalDevices = $displayDevices['desktop'] + $displayDevices['mobile'] + $displayDevices['tablet'];
                    $desktopPct = $totalDevices > 0 ? round(($displayDevices['desktop'] / $totalDevices) * 100) : 0;
                    $mobilePct = $totalDevices > 0 ? round(($displayDevices['mobile'] / $totalDevices) * 100) : 0;
                    $tabletPct = $totalDevices > 0 ? round(($displayDevices['tablet'] / $totalDevices) * 100) : 0;
                @endphp
                @if($totalDevices > 0)
                    <div class="flex items-center gap-8">
                        {{-- CSS donut --}}
                        <div
                            class="an-donut"
                            style="background: conic-gradient(
                                {{ $isGa ? '#f59e0b' : '#6366f1' }} 0% {{ $desktopPct }}%,
                                #10b981 {{ $desktopPct }}% {{ $desktopPct + $mobilePct }}%,
                                {{ $isGa ? '#fb923c' : '#f59e0b' }} {{ $desktopPct + $mobilePct }}% 100%
                            );"
                        >
                            <div class="an-donut__center text-gray-700 dark:text-gray-300">
                                {{ number_format($totalDevices) }}
                            </div>
                        </div>
                        <div class="an-donut-legend">
                            <div class="an-donut-legend__item">
                                <span class="an-donut-legend__dot" style="background: {{ $isGa ? '#f59e0b' : '#6366f1' }}"></span>
                                <span class="text-gray-700 dark:text-gray-300">Desktop</span>
                                <span class="ml-auto font-bold text-gray-900 dark:text-white">{{ $desktopPct }}%</span>
                            </div>
                            <div class="an-donut-legend__item">
                                <span class="an-donut-legend__dot" style="background: #10b981"></span>
                                <span class="text-gray-700 dark:text-gray-300">Mobile</span>
                                <span class="ml-auto font-bold text-gray-900 dark:text-white">{{ $mobilePct }}%</span>
                            </div>
                            <div class="an-donut-legend__item">
                                <span class="an-donut-legend__dot" style="background: {{ $isGa ? '#fb923c' : '#f59e0b' }}"></span>
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
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $sourceIcon }} Nguồn truy cập</h3>
            </div>
            <div class="an-section__body">
                @if($displayReferrers->isNotEmpty())
                    @php $maxRef = $displayReferrers->max('count'); @endphp
                    @foreach($displayReferrers as $ref)
                        <div class="an-bar-item">
                            <div class="an-bar-bg {{ $isGa ? 'bg-amber-500' : 'bg-indigo-500' }}" style="width: {{ $maxRef > 0 ? ($ref['count'] / $maxRef) * 100 : 0 }}%"></div>
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
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $sourceIcon }} Trình duyệt</h3>
            </div>
            <div class="an-section__body">
                @if($displayBrowsers->isNotEmpty())
                    @php $maxBr = $displayBrowsers->max('count'); @endphp
                    @foreach($displayBrowsers as $br)
                        <div class="an-bar-item">
                            <div class="an-bar-bg {{ $isGa ? 'bg-amber-400' : 'bg-sky-500' }}" style="width: {{ $maxBr > 0 ? ($br['count'] / $maxBr) * 100 : 0 }}%"></div>
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
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $sourceIcon }} Hệ điều hành</h3>
            </div>
            <div class="an-section__body">
                @if($displayOses->isNotEmpty())
                    @php $maxOs = $displayOses->max('count'); @endphp
                    @foreach($displayOses as $os)
                        <div class="an-bar-item">
                            <div class="an-bar-bg {{ $isGa ? 'bg-orange-500' : 'bg-emerald-500' }}" style="width: {{ $maxOs > 0 ? ($os['count'] / $maxOs) * 100 : 0 }}%"></div>
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
    <div class="grid grid-cols-1 {{ !$isGa ? 'lg:grid-cols-2' : '' }} gap-6 mb-6">

        {{-- Hourly Heatmap --}}
        <div class="an-section">
            <div class="an-section__header">
                <span class="text-base">⏰</span>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $sourceIcon }} Phân bố theo giờ</h3>
            </div>
            <div class="an-section__body">
                @php
                    $maxHourly = max(1, max($displayHourly));
                    $heatHigh = $isGa ? 'bg-amber-500 text-white' : 'bg-indigo-500 text-white';
                    $heatMed = $isGa ? 'bg-amber-400 text-white' : 'bg-indigo-400 text-white';
                    $heatLow = $isGa ? 'bg-amber-200 dark:bg-amber-800 text-amber-800 dark:text-amber-200' : 'bg-indigo-200 dark:bg-indigo-800 text-indigo-800 dark:text-indigo-200';
                    $heatMin = $isGa ? 'bg-amber-100 dark:bg-amber-900 text-amber-600 dark:text-amber-400' : 'bg-indigo-100 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-400';
                @endphp
                <div class="an-hourly">
                    @foreach($displayHourly as $h => $count)
                        @php
                            $intensity = $maxHourly > 0 ? ($count / $maxHourly) : 0;
                            $bgColor = $intensity > 0.75 ? $heatHigh
                                : ($intensity > 0.5 ? $heatMed
                                : ($intensity > 0.25 ? $heatLow
                                : ($intensity > 0 ? $heatMin
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

        {{-- Page Types (Self-hosted only) --}}
        @if(!$isGa)
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
        @endif
    </div>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- Countries --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    @if($displayCountries->isNotEmpty())
        <div class="an-section mb-6">
            <div class="an-section__header">
                <span class="text-base">🌍</span>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $sourceIcon }} Quốc gia truy cập</h3>
            </div>
            <div class="an-section__body">
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                    @foreach($displayCountries as $country)
                        <div class="flex items-center gap-2 p-2 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                            <span class="text-lg">{{ $country['name'] }}</span>
                            <span class="font-bold text-sm text-gray-900 dark:text-white">{{ number_format($country['count']) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- Top IPs Monitoring (Self-hosted only) --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    @if(!$isGa)
        <div class="an-section mb-6">
            <div class="an-section__header">
                <span class="text-base">🔍</span>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Top IP truy cập</h3>
                <span class="ml-auto text-xs text-gray-400 dark:text-gray-500">{{ $topIps->count() }} IPs · raw data (30 ngày)</span>
            </div>
            <div class="an-section__body">
                @if($topIps->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-700">
                                    <th class="text-left py-2 px-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">#</th>
                                    <th class="text-left py-2 px-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">IP Address</th>
                                    <th class="text-right py-2 px-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Lượt xem</th>
                                    <th class="text-center py-2 px-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Bot</th>
                                    <th class="text-left py-2 px-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Lần cuối</th>
                                    <th class="text-left py-2 px-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Browser</th>
                                    <th class="text-left py-2 px-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">OS</th>
                                    <th class="text-center py-2 px-3 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">🌍</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($topIps as $index => $ip)
                                    <tr @class([
                                        'border-b border-gray-50 dark:border-gray-800/50 transition-colors',
                                        'bg-amber-50/50 dark:bg-amber-900/10' => $ip['is_bot'],
                                        'hover:bg-gray-50 dark:hover:bg-gray-800/30' => !$ip['is_bot'],
                                        'hover:bg-amber-50 dark:hover:bg-amber-900/20' => $ip['is_bot'],
                                    ])>
                                        <td class="py-2 px-3 text-gray-400 dark:text-gray-500 tabular-nums">{{ $index + 1 }}</td>
                                        <td class="py-2 px-3 font-mono text-xs text-gray-700 dark:text-gray-300">
                                            {{ $ip['ip_address'] }}
                                        </td>
                                        <td class="py-2 px-3 text-right font-bold tabular-nums text-gray-900 dark:text-white">
                                            {{ number_format($ip['total_views']) }}
                                        </td>
                                        <td class="py-2 px-3 text-center">
                                            @if($ip['is_bot'])
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                                    🤖 Bot
                                                </span>
                                            @else
                                                <span class="text-xs text-emerald-500">✓</span>
                                            @endif
                                        </td>
                                        <td class="py-2 px-3 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                            {{ \Carbon\Carbon::parse($ip['latest_visit'])->diffForHumans() }}
                                        </td>
                                        <td class="py-2 px-3 text-xs text-gray-600 dark:text-gray-400" @if($ip['user_agent'] ?? null) title="{{ $ip['user_agent'] }}" @endif>{{ $ip['browser'] }}</td>
                                        <td class="py-2 px-3 text-xs text-gray-600 dark:text-gray-400">{{ $ip['os'] }}</td>
                                        <td class="py-2 px-3 text-center text-xs">{{ $ip['country_code'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @php
                        $botCount = $topIps->where('is_bot', true)->count();
                        $humanCount = $topIps->where('is_bot', false)->count();
                    @endphp
                    <div class="flex gap-4 mt-3 text-[10px] text-gray-400 dark:text-gray-500">
                        <span>✓ {{ $humanCount }} người thật</span>
                        <span>🤖 {{ $botCount }} bot</span>
                        <span>💡 Dữ liệu raw — chỉ có sau khi aggregation chạy flush Redis → DB</span>
                    </div>
                @else
                    <div class="an-empty">
                        <div class="an-empty__icon">🔍</div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Chưa có dữ liệu IP</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500">
                            IP sẽ được ghi nhận sau khi migration chạy và có lượt truy cập mới
                        </p>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @endif {{-- end: source !== 'compare' --}}

</x-filament-panels::page>
