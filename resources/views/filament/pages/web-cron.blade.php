<x-filament-panels::page wire:poll.15s>
    {{-- Inline styles scoped to this page --}}
    <style>
        /* ═══ Status Card Gradients ═══ */
        .wc-card {
            position: relative;
            overflow: hidden;
            border-radius: 1rem;
            padding: 1.25rem 1.5rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .wc-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px -4px rgba(0, 0, 0, 0.12);
        }

        .dark .wc-card:hover {
            box-shadow: 0 8px 24px -4px rgba(0, 0, 0, 0.4);
        }

        .wc-card--active {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .dark .wc-card--active {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.08) 0%, rgba(16, 185, 129, 0.15) 100%);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .wc-card--running {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .dark .wc-card--running {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.08) 0%, rgba(59, 130, 246, 0.15) 100%);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .wc-card--inactive {
            background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
            border: 1px solid rgba(107, 114, 128, 0.15);
        }

        .dark .wc-card--inactive {
            background: linear-gradient(135deg, rgba(107, 114, 128, 0.06) 0%, rgba(107, 114, 128, 0.12) 100%);
            border: 1px solid rgba(107, 114, 128, 0.2);
        }

        .wc-card--stats {
            background: white;
            border: 1px solid rgba(0, 0, 0, 0.06);
        }

        .dark .wc-card--stats {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
        }

        /* ═══ Icon containers ═══ */
        .wc-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 0.75rem;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .wc-icon--green {
            background: rgba(16, 185, 129, 0.12);
        }

        .wc-icon--blue {
            background: rgba(59, 130, 246, 0.12);
        }

        .wc-icon--gray {
            background: rgba(107, 114, 128, 0.12);
        }

        .wc-icon--amber {
            background: rgba(245, 158, 11, 0.12);
        }

        .wc-icon--violet {
            background: rgba(139, 92, 246, 0.12);
        }

        .dark .wc-icon--green {
            background: rgba(16, 185, 129, 0.15);
        }

        .dark .wc-icon--blue {
            background: rgba(59, 130, 246, 0.15);
        }

        .dark .wc-icon--gray {
            background: rgba(107, 114, 128, 0.15);
        }

        .dark .wc-icon--amber {
            background: rgba(245, 158, 11, 0.15);
        }

        .dark .wc-icon--violet {
            background: rgba(139, 92, 246, 0.15);
        }

        /* ═══ Pulse dot with glow ═══ */
        .wc-pulse {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            position: relative;
        }

        .wc-pulse::before {
            content: '';
            position: absolute;
            inset: -3px;
            border-radius: 50%;
            animation: wc-glow 2s ease-in-out infinite;
        }

        .wc-pulse--green {
            background: #10b981;
        }

        .wc-pulse--green::before {
            background: rgba(16, 185, 129, 0.3);
        }

        .wc-pulse--blue {
            background: #3b82f6;
        }

        .wc-pulse--blue::before {
            background: rgba(59, 130, 246, 0.3);
        }

        .wc-pulse--gray {
            background: #9ca3af;
        }

        .wc-pulse--gray::before {
            background: rgba(156, 163, 175, 0.2);
        }

        @keyframes wc-glow {

            0%,
            100% {
                transform: scale(1);
                opacity: 1;
            }

            50% {
                transform: scale(1.8);
                opacity: 0;
            }
        }

        /* ═══ Progress bar for success rate ═══ */
        .wc-progress {
            height: 4px;
            border-radius: 2px;
            background: rgba(0, 0, 0, 0.06);
            margin-top: 0.75rem;
            overflow: hidden;
        }

        .dark .wc-progress {
            background: rgba(255, 255, 255, 0.06);
        }

        .wc-progress__bar {
            height: 100%;
            border-radius: 2px;
            transition: width 0.6s ease;
        }

        .wc-progress__bar--green {
            background: linear-gradient(90deg, #10b981, #34d399);
        }

        .wc-progress__bar--amber {
            background: linear-gradient(90deg, #f59e0b, #fbbf24);
        }

        .wc-progress__bar--red {
            background: linear-gradient(90deg, #ef4444, #f87171);
        }

        /* ═══ Log table enhancements ═══ */
        .wc-table {
            width: 100%;
            font-size: 0.8125rem;
            border-collapse: separate;
            border-spacing: 0;
        }

        .wc-table thead th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--wc-th-color, #6b7280);
            border-bottom: 2px solid var(--wc-border, rgba(0, 0, 0, 0.06));
            white-space: nowrap;
        }

        .dark .wc-table thead th {
            --wc-th-color: #9ca3af;
            --wc-border: rgba(255, 255, 255, 0.06);
        }

        .wc-table tbody td {
            padding: 0.75rem 1rem;
            vertical-align: middle;
            border-bottom: 1px solid var(--wc-row-border, rgba(0, 0, 0, 0.04));
        }

        .dark .wc-table tbody td {
            --wc-row-border: rgba(255, 255, 255, 0.04);
        }

        .wc-table tbody tr {
            transition: background-color 0.15s ease;
        }

        .wc-table tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }

        .dark .wc-table tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.02);
        }

        .wc-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* ═══ Badge styles ═══ */
        .wc-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            white-space: nowrap;
        }

        .wc-badge--success {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
        }

        .dark .wc-badge--success {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
        }

        .wc-badge--partial {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
        }

        .dark .wc-badge--partial {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
        }

        .wc-badge--failed {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }

        .dark .wc-badge--failed {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
        }

        .wc-badge--running {
            background: rgba(59, 130, 246, 0.1);
            color: #2563eb;
        }

        .dark .wc-badge--running {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
        }

        .wc-badge--timed_out {
            background: rgba(239, 68, 68, 0.08);
            color: #b91c1c;
        }

        .dark .wc-badge--timed_out {
            background: rgba(239, 68, 68, 0.12);
            color: #fca5a5;
        }

        /* ═══ Task pill ═══ */
        .wc-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            padding: 0.15rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 11px;
            font-weight: 500;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            letter-spacing: -0.01em;
            cursor: default;
            transition: opacity 0.15s;
        }

        .wc-pill:hover {
            opacity: 0.8;
        }

        .wc-pill--ok {
            background: rgba(16, 185, 129, 0.08);
            color: #059669;
            border: 1px solid rgba(16, 185, 129, 0.15);
        }

        .dark .wc-pill--ok {
            background: rgba(16, 185, 129, 0.1);
            color: #6ee7b7;
            border-color: rgba(16, 185, 129, 0.2);
        }

        .wc-pill--err {
            background: rgba(239, 68, 68, 0.08);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.15);
        }

        .dark .wc-pill--err {
            background: rgba(239, 68, 68, 0.1);
            color: #fca5a5;
            border-color: rgba(239, 68, 68, 0.2);
        }

        /* ═══ Trigger badge ═══ */
        .wc-trigger {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.2rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.6875rem;
            font-weight: 500;
        }

        .wc-trigger--heartbeat {
            background: rgba(236, 72, 153, 0.08);
            color: #db2777;
        }

        .dark .wc-trigger--heartbeat {
            background: rgba(236, 72, 153, 0.12);
            color: #f9a8d4;
        }

        .wc-trigger--manual {
            background: rgba(139, 92, 246, 0.08);
            color: #7c3aed;
        }

        .dark .wc-trigger--manual {
            background: rgba(139, 92, 246, 0.12);
            color: #c4b5fd;
        }

        .wc-trigger--server_cron {
            background: rgba(6, 182, 212, 0.08);
            color: #0891b2;
        }

        .dark .wc-trigger--server_cron {
            background: rgba(6, 182, 212, 0.12);
            color: #67e8f9;
        }

        /* ═══ Memory bar ═══ */
        .wc-mem {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.6875rem;
            font-variant-numeric: tabular-nums;
        }

        .wc-mem__bar {
            width: 2.5rem;
            height: 4px;
            border-radius: 2px;
            background: rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }

        .dark .wc-mem__bar {
            background: rgba(255, 255, 255, 0.06);
        }

        .wc-mem__fill {
            height: 100%;
            border-radius: 2px;
            background: linear-gradient(90deg, #8b5cf6, #a78bfa);
            transition: width 0.3s ease;
        }

        /* ═══ Section header ═══ */
        .wc-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
        }

        .dark .wc-section-header {
            border-bottom-color: rgba(255, 255, 255, 0.04);
        }

        /* ═══ Empty state ═══ */
        .wc-empty {
            text-align: center;
            padding: 3rem 2rem;
        }

        .wc-empty__icon {
            width: 3.5rem;
            height: 3.5rem;
            margin: 0 auto 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 1rem;
            background: rgba(107, 114, 128, 0.08);
            font-size: 1.5rem;
        }

        .dark .wc-empty__icon {
            background: rgba(107, 114, 128, 0.12);
        }

        /* ═══ Stat number ═══ */
        .wc-stat-num {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1;
            font-variant-numeric: tabular-nums;
            letter-spacing: -0.02em;
        }

        .wc-stat-label {
            font-size: 0.6875rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        /* Duration mono */
        .wc-duration {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-variant-numeric: tabular-nums;
            font-size: 0.75rem;
        }

        /* Time column */
        .wc-time {
            font-variant-numeric: tabular-nums;
            line-height: 1.5;
        }
    </style>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- Status Dashboard --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-8">

        {{-- Card 1: Current Status --}}
        <div @class([
            'wc-card',
            'wc-card--running' => $status['is_running'],
            'wc-card--active' => $status['enabled'] && !$status['is_running'],
            'wc-card--inactive' => !$status['enabled'],
        ])>
            <div class="flex items-start gap-3.5">
                <div @class([
                    'wc-icon',
                    'wc-icon--blue' => $status['is_running'],
                    'wc-icon--green' => $status['enabled'] && !$status['is_running'],
                    'wc-icon--gray' => !$status['enabled'],
                ])>
                    @if($status['is_running'])
                        ⚡
                    @elseif($status['enabled'])
                        🟢
                    @else
                        ⏸️
                    @endif
                </div>
                <div class="min-w-0 flex-1">
                    <p class="wc-stat-label text-gray-500 dark:text-gray-400">Trạng thái</p>
                    <p class="text-base font-bold leading-tight">
                        @if($status['is_running'])
                            <span class="text-blue-600 dark:text-blue-400">Đang chạy</span>
                        @elseif($status['enabled'])
                            <span class="text-emerald-600 dark:text-emerald-400">Hoạt động</span>
                        @else
                            <span class="text-gray-500 dark:text-gray-400">Đã tắt</span>
                        @endif
                    </p>
                    @if($status['enabled'])
                        <div class="flex items-center gap-1.5 mt-2">
                            <div @class([
                                'wc-pulse',
                                'wc-pulse--blue' => $status['is_running'],
                                'wc-pulse--green' => !$status['is_running'],
                            ])></div>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                Ping mỗi {{ $status['interval'] }}s
                            </span>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Card 2: Last Run --}}
        <div class="wc-card wc-card--stats">
            <div class="flex items-start gap-3.5">
                <div class="wc-icon wc-icon--amber">🕐</div>
                <div class="min-w-0 flex-1">
                    <p class="wc-stat-label text-gray-500 dark:text-gray-400">Lần chạy cuối</p>
                    @if($status['last_run'])
                        <p class="text-base font-bold leading-tight text-gray-900 dark:text-white">
                            {{ $status['last_run']['ago'] }}
                        </p>
                        <div class="flex items-center gap-2 mt-2 flex-wrap">
                            @php
                                $lrBadge = match ($status['last_run']['status']) {
                                    'success' => 'wc-badge--success',
                                    'partial' => 'wc-badge--partial',
                                    'failed' => 'wc-badge--failed',
                                    'timed_out' => 'wc-badge--timed_out',
                                    default => 'wc-badge--running',
                                };
                                $lrDot = match ($status['last_run']['status']) {
                                    'success' => '●',
                                    'partial' => '◐',
                                    'failed' => '✕',
                                    'timed_out' => '⏰',
                                    default => '◌',
                                };
                            @endphp
                            <span class="wc-badge {{ $lrBadge }}">
                                {{ $lrDot }} {{ ucfirst($status['last_run']['status']) }}
                            </span>
                            @if($status['last_run']['duration_ms'])
                                <span class="text-xs text-gray-400 dark:text-gray-500 font-mono">
                                    {{ number_format($status['last_run']['duration_ms'] / 1000, 1) }}s
                                </span>
                            @endif
                        </div>
                    @else
                        <p class="text-sm text-gray-400 dark:text-gray-500 mt-0.5">Chưa từng chạy</p>
                    @endif
                </div>
            </div>
        </div>

        {{-- Card 3: 24h Stats --}}
        <div class="wc-card wc-card--stats">
            <div class="flex items-start gap-3.5">
                <div class="wc-icon wc-icon--blue">📊</div>
                <div class="min-w-0 flex-1">
                    <p class="wc-stat-label text-gray-500 dark:text-gray-400">24 giờ qua</p>
                    <div class="flex items-baseline gap-1">
                        <span
                            class="wc-stat-num text-gray-900 dark:text-white">{{ $status['stats_24h']['total'] }}</span>
                        <span class="text-xs text-gray-400">lần chạy</span>
                    </div>
                    <div class="flex items-center gap-3 mt-1.5">
                        <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">
                            ✓ {{ $status['stats_24h']['success'] }}
                        </span>
                        @if($status['stats_24h']['failed'] > 0)
                            <span class="text-xs font-medium text-red-500 dark:text-red-400">
                                ✕ {{ $status['stats_24h']['failed'] }}
                            </span>
                        @endif
                        @if($status['stats_24h']['total'] > 0)
                            <span class="text-xs text-gray-400 dark:text-gray-500">
                                ~{{ number_format($status['stats_24h']['avg_duration_ms'] / 1000, 0) }}s/lần
                            </span>
                        @endif
                    </div>
                    @if($status['stats_24h']['total'] > 0)
                        @php
                            $rate24 = $status['stats_24h']['success_rate'];
                            $barClass24 = $rate24 >= 90 ? 'wc-progress__bar--green' : ($rate24 >= 70 ? 'wc-progress__bar--amber' : 'wc-progress__bar--red');
                        @endphp
                        <div class="wc-progress">
                            <div class="wc-progress__bar {{ $barClass24 }}" style="width: {{ $rate24 }}%"></div>
                        </div>
                        <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-1">
                            Tỉ lệ thành công: {{ $rate24 }}%
                        </p>
                    @endif
                </div>
            </div>
        </div>

        {{-- Card 4: 7d Stats --}}
        <div class="wc-card wc-card--stats">
            <div class="flex items-start gap-3.5">
                <div class="wc-icon wc-icon--violet">📈</div>
                <div class="min-w-0 flex-1">
                    <p class="wc-stat-label text-gray-500 dark:text-gray-400">7 ngày qua</p>
                    <div class="flex items-baseline gap-1">
                        <span
                            class="wc-stat-num text-gray-900 dark:text-white">{{ $status['stats_7d']['total'] }}</span>
                        <span class="text-xs text-gray-400">lần chạy</span>
                    </div>
                    <div class="flex items-center gap-3 mt-1.5">
                        <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">
                            ✓ {{ $status['stats_7d']['success'] }}
                        </span>
                        @if($status['stats_7d']['failed'] > 0)
                            <span class="text-xs font-medium text-red-500 dark:text-red-400">
                                ✕ {{ $status['stats_7d']['failed'] }}
                            </span>
                        @endif
                    </div>
                    @if($status['stats_7d']['total'] > 0)
                        @php
                            $rate7d = $status['stats_7d']['success_rate'];
                            $barClass7d = $rate7d >= 90 ? 'wc-progress__bar--green' : ($rate7d >= 70 ? 'wc-progress__bar--amber' : 'wc-progress__bar--red');
                        @endphp
                        <div class="wc-progress">
                            <div class="wc-progress__bar {{ $barClass7d }}" style="width: {{ $rate7d }}%"></div>
                        </div>
                        <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-1">
                            Tỉ lệ thành công: {{ $rate7d }}%
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- Configuration Form --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{ $this->content }}

    {{-- ═══════════════════════════════════════════════════════════════ --}}
    {{-- Execution History --}}
    {{-- ═══════════════════════════════════════════════════════════════ --}}
    <div
        class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mt-8 overflow-hidden">
        <div class="wc-section-header">
            <div class="flex items-center gap-2.5">
                <span class="text-base">📋</span>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                    Lịch sử thực thi
                </h3>
                <span class="wc-badge wc-badge--success" style="font-size: 0.625rem; padding: 0.1rem 0.4rem;">
                    {{ $logs->count() }} bản ghi
                </span>
            </div>
        </div>

        <div class="px-0 pb-0">
            @if($logs->isEmpty())
                <div class="wc-empty">
                    <div class="wc-empty__icon">📭</div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                        Chưa có lịch sử thực thi
                    </p>
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        Bật Web Cron và đợi vài phút để thấy dữ liệu
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="wc-table">
                        <thead>
                            <tr>
                                <th>Thời gian</th>
                                <th>Trạng thái</th>
                                <th>Thời lượng</th>
                                <th>Trigger</th>
                                <th>RAM</th>
                                <th>Tác vụ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($logs as $log)
                                <tr>
                                    {{-- Time --}}
                                    <td>
                                        <div class="wc-time">
                                            <span class="text-xs font-medium text-gray-700 dark:text-gray-200"
                                                title="{{ $log->started_at->format('Y-m-d H:i:s') }}">
                                                {{ $log->started_at->format('d/m H:i') }}
                                            </span>
                                            <br>
                                            <span class="text-[11px] text-gray-400 dark:text-gray-500">
                                                {{ $log->started_at->diffForHumans() }}
                                            </span>
                                        </div>
                                    </td>

                                    {{-- Status --}}
                                    <td>
                                        @php
                                            $sBadge = match ($log->status) {
                                                'success' => 'wc-badge--success',
                                                'partial' => 'wc-badge--partial',
                                                'failed' => 'wc-badge--failed',
                                                'timed_out' => 'wc-badge--timed_out',
                                                'running' => 'wc-badge--running',
                                                default => '',
                                            };
                                            $sDot = match ($log->status) {
                                                'success' => '●',
                                                'partial' => '◐',
                                                'failed' => '✕',
                                                'timed_out' => '⏰',
                                                'running' => '◌',
                                                default => '?',
                                            };
                                        @endphp
                                        <span class="wc-badge {{ $sBadge }}">
                                            {{ $sDot }} {{ ucfirst($log->status) }}
                                        </span>
                                    </td>

                                    {{-- Duration --}}
                                    <td>
                                        @if($log->duration_ms !== null)
                                            <span class="wc-duration text-gray-600 dark:text-gray-300">
                                                @if($log->duration_ms < 1000)
                                                    {{ $log->duration_ms }}ms
                                                @elseif($log->duration_ms < 60000)
                                                    {{ number_format($log->duration_ms / 1000, 1) }}s
                                                @else
                                                    {{ number_format($log->duration_ms / 60000, 1) }}m
                                                @endif
                                            </span>
                                        @else
                                            <span class="text-gray-300 dark:text-gray-600">—</span>
                                        @endif
                                    </td>

                                    {{-- Trigger --}}
                                    <td>
                                        <span class="wc-trigger wc-trigger--{{ $log->trigger }}">
                                            @php
                                                $tIcon = match ($log->trigger) {
                                                    'heartbeat' => '💓',
                                                    'manual' => '👆',
                                                    'server_cron' => '🖥',
                                                    default => '❓',
                                                };
                                                $tLabel = match ($log->trigger) {
                                                    'heartbeat' => 'Auto',
                                                    'manual' => 'Manual',
                                                    'server_cron' => 'Server',
                                                    default => $log->trigger,
                                                };
                                            @endphp
                                            {{ $tIcon }} {{ $tLabel }}
                                        </span>
                                    </td>

                                    {{-- Memory --}}
                                    <td>
                                        @if($log->memory_peak_mb)
                                            <div class="wc-mem text-gray-500 dark:text-gray-400">
                                                <div class="wc-mem__bar">
                                                    <div class="wc-mem__fill"
                                                        style="width: {{ min(100, ($log->memory_peak_mb / 128) * 100) }}%"></div>
                                                </div>
                                                <span>{{ $log->memory_peak_mb }}M</span>
                                            </div>
                                        @else
                                            <span class="text-gray-300 dark:text-gray-600">—</span>
                                        @endif
                                    </td>

                                    {{-- Tasks --}}
                                    <td>
                                        @if($log->tasks_summary)
                                            <div class="flex flex-wrap gap-1">
                                                @foreach($log->tasks_summary as $task)
                                                    @php
                                                        $pillClass = $task['status'] === 'success' ? 'wc-pill--ok' : 'wc-pill--err';
                                                        $pillDot = $task['status'] === 'success' ? '✓' : '✕';
                                                        $shortName = str_replace(['scrape:', 'maintenance:', 'ai:', 'queue:'], '', $task['task']);
                                                        $tooltip = $task['task'];
                                                        if (!empty($task['error'])) {
                                                            $tooltip .= "\n❌ " . $task['error'];
                                                        }
                                                        if (!empty($task['output'])) {
                                                            $tooltip .= "\n→ " . \Illuminate\Support\Str::limit($task['output'], 120);
                                                        }
                                                        if ($task['duration_ms'] ?? 0) {
                                                            $tooltip .= "\n⏱ " . number_format($task['duration_ms'] / 1000, 1) . "s";
                                                        }
                                                    @endphp
                                                    <span class="wc-pill {{ $pillClass }}" title="{{ $tooltip }}">
                                                        {{ $pillDot }} {{ $shortName }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="text-gray-300 dark:text-gray-600">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
