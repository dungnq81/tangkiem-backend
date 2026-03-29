<x-filament-panels::page wire:poll.120s>

	{{-- ═══════════════════════════════════════════════════════════════ --}}
	{{-- Welcome Header --}}
	{{-- ═══════════════════════════════════════════════════════════════ --}}
	<div class="an-card mb-6"
		style="background: linear-gradient(135deg, rgba(99,102,241,0.04) 0%, rgba(139,92,246,0.06) 100%); border-color: rgba(99,102,241,0.12);">
		<div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
			<div class="flex items-center gap-4">
				{{-- Avatar --}}
				<div class="relative shrink-0">
					<div
						class="w-14 h-14 rounded-full bg-linear-to-br from-indigo-500/20 to-violet-500/20 border-2 border-indigo-500/30 flex items-center justify-center transition hover:scale-105 hover:border-indigo-500/60">
						<x-filament::icon icon="heroicon-o-user-circle" class="w-8 h-8 text-indigo-500" />
					</div>
					<div class="an-pulse absolute bottom-0.5 right-0.5" style="width: 10px; height: 10px;"></div>
				</div>

				<div>
					<h2 class="text-lg font-bold text-gray-900 dark:text-white leading-tight">
						{{ $greeting }}, <span
							class="bg-linear-to-br from-indigo-500 to-violet-500 bg-clip-text text-transparent">{{ $userName }}</span>
					</h2>
					<div class="flex items-center gap-3 mt-1.5 flex-wrap">
						<span
							class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
							<x-filament::icon icon="heroicon-o-shield-check" class="w-3.5 h-3.5" />
							{{ auth()->user()?->roles?->first()?->display_label ?? 'Người dùng' }}
						</span>
						<span
							class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
							<x-filament::icon icon="heroicon-o-calendar-days" class="w-3.5 h-3.5" />
							{{ $formattedDate }}
						</span>
					</div>
				</div>
			</div>

			{{-- Quick actions --}}
			<div class="flex items-center gap-2 flex-wrap">
				<a href="{{ url('/admin/stories/create') }}"
					class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-semibold text-white bg-linear-to-br from-indigo-500 to-indigo-600 shadow-md shadow-indigo-500/25 hover:shadow-lg hover:shadow-indigo-500/30 transition hover:-translate-y-0.5">
					<x-filament::icon icon="heroicon-o-plus-circle" class="w-4 h-4" />
					Thêm truyện
				</a>
				<a href="{{ url('/admin/analytics') }}"
					class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-semibold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20 hover:bg-indigo-500/20 transition hover:-translate-y-0.5">
					<x-filament::icon icon="heroicon-o-chart-bar-square" class="w-4 h-4" />
					Analytics
				</a>
			</div>
		</div>
	</div>

	{{-- ═══════════════════════════════════════════════════════════════ --}}
	{{-- Primary Stats Row --}}
	{{-- ═══════════════════════════════════════════════════════════════ --}}
	<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">

		{{-- Stories --}}
		<div class="an-card">
			<p class="an-stat-label text-gray-500 dark:text-gray-400">Tổng truyện</p>
			<div class="flex items-baseline gap-2">
				<span class="an-stat-num text-gray-900 dark:text-white">{{ number_format($storyTotal) }}</span>
				<span class="an-trend an-trend--up">+{{ $storyMonth }} tháng này</span>
			</div>
			<div class="mt-3 flex items-end gap-px h-8">
				@php $maxTrend = max(1, max($storyTrend)); @endphp
				@foreach($storyTrend as $v)
					<div class="flex-1 rounded-sm min-h-[2px]"
						style="height: {{ ($v / $maxTrend) * 100 }}%; background: linear-gradient(180deg, #6366f1, #818cf8);">
					</div>
				@endforeach
			</div>
		</div>

		{{-- Chapters --}}
		<div class="an-card">
			<p class="an-stat-label text-gray-500 dark:text-gray-400">Tổng chương</p>
			<div class="flex items-baseline gap-2">
				<span class="an-stat-num text-gray-900 dark:text-white">{{ number_format($chapterTotal) }}</span>
				<span class="an-trend an-trend--up">+{{ $chapterMonth }} tháng này</span>
			</div>
			<div class="mt-3 flex items-end gap-px h-8">
				@php $maxTrend = max(1, max($chapterTrend)); @endphp
				@foreach($chapterTrend as $v)
					<div class="flex-1 rounded-sm min-h-[2px]"
						style="height: {{ ($v / $maxTrend) * 100 }}%; background: linear-gradient(180deg, #10b981, #34d399);">
					</div>
				@endforeach
			</div>
		</div>

		{{-- Users --}}
		<div class="an-card">
			<p class="an-stat-label text-gray-500 dark:text-gray-400">Người dùng</p>
			<div class="flex items-baseline gap-2">
				<span class="an-stat-num text-gray-900 dark:text-white">{{ number_format($userTotal) }}</span>
				<span class="an-trend an-trend--up">+{{ $userMonth }} tháng này</span>
			</div>
			<div class="mt-3 flex items-end gap-px h-8">
				@php $maxTrend = max(1, max($userTrend)); @endphp
				@foreach($userTrend as $v)
					<div class="flex-1 rounded-sm min-h-[2px]"
						style="height: {{ ($v / $maxTrend) * 100 }}%; background: linear-gradient(180deg, #0ea5e9, #38bdf8);">
					</div>
				@endforeach
			</div>
		</div>

		{{-- Comments --}}
		<div class="an-card">
			<p class="an-stat-label text-gray-500 dark:text-gray-400">Bình luận</p>
			<div class="flex items-baseline gap-2">
				<span class="an-stat-num text-gray-900 dark:text-white">{{ number_format($commentTotal) }}</span>
				<span class="an-trend an-trend--up">+{{ $commentMonth }} tháng này</span>
			</div>
			<div class="mt-3 flex items-end gap-px h-8">
				@php $maxTrend = max(1, max($commentTrend)); @endphp
				@foreach($commentTrend as $v)
					<div class="flex-1 rounded-sm min-h-[2px]"
						style="height: {{ ($v / $maxTrend) * 100 }}%; background: linear-gradient(180deg, #f59e0b, #fbbf24);">
					</div>
				@endforeach
			</div>
		</div>
	</div>

	{{-- ═══════════════════════════════════════════════════════════════ --}}
	{{-- Secondary Stats Row --}}
	{{-- ═══════════════════════════════════════════════════════════════ --}}
	<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
		@php
			$secondaryStats = [
				['label' => 'Đã xuất bản', 'value' => number_format($storyPublished), 'sub' => $publishedPct . '% truyện', 'icon' => '✅', 'color' => '#10b981'],
				['label' => 'Tổng lượt xem', 'value' => self::formatNumber($totalViews), 'sub' => 'tổng stories', 'icon' => '👁️', 'color' => '#6366f1'],
				['label' => 'Tổng số từ', 'value' => $formattedWords, 'sub' => 'TB ' . $avgWords . '/chương', 'icon' => '✏️', 'color' => '#0ea5e9'],
				['label' => 'Tác giả', 'value' => number_format($authorCount), 'sub' => 'tác giả', 'icon' => '✍️', 'color' => '#8b5cf6'],
				['label' => 'Danh mục', 'value' => number_format($categoryCount), 'sub' => 'thể loại', 'icon' => '🏷️', 'color' => '#f59e0b'],
				['label' => 'Media', 'value' => number_format($mediaCount), 'sub' => 'tệp', 'icon' => '📸', 'color' => '#ec4899'],
			];
		@endphp
		@foreach($secondaryStats as $stat)
			<div class="an-card text-center">
				<div class="text-2xl mb-1">{{ $stat['icon'] }}</div>
				<div class="text-lg font-extrabold text-gray-900 dark:text-white tabular-nums">{{ $stat['value'] }}</div>
				<div class="text-[10px] font-semibold uppercase tracking-wider mt-0.5" style="color: {{ $stat['color'] }}">
					{{ $stat['label'] }}</div>
				<div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">{{ $stat['sub'] }}</div>
			</div>
		@endforeach
	</div>

	{{-- ═══════════════════════════════════════════════════════════════ --}}
	{{-- Recent Activity --}}
	{{-- ═══════════════════════════════════════════════════════════════ --}}
	<div class="an-section">
		<div class="an-section__header">
			<span class="text-base">📋</span>
			<h3 class="text-sm font-semibold text-gray-900 dark:text-white">Hoạt động gần đây</h3>
		</div>
		<div class="an-section__body">
			@if($recentActivity->isNotEmpty())
				<div class="space-y-0">
					@foreach($recentActivity as $log)
						<div class="an-bar-item">
							@php
								$eventConfig = match ($log->event) {
									'created' => ['bg' => 'bg-emerald-500', 'text' => 'text-emerald-600 dark:text-emerald-400', 'badge' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400', 'label' => 'Tạo mới'],
									'updated' => ['bg' => 'bg-sky-500', 'text' => 'text-sky-600 dark:text-sky-400', 'badge' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400', 'label' => 'Cập nhật'],
									'deleted' => ['bg' => 'bg-red-500', 'text' => 'text-red-600 dark:text-red-400', 'badge' => 'bg-red-500/10 text-red-600 dark:text-red-400', 'label' => 'Xóa'],
									default => ['bg' => 'bg-gray-400', 'text' => 'text-gray-500', 'badge' => 'bg-gray-500/10 text-gray-500', 'label' => $log->event ?? '—'],
								};

								$subjectMap = [
									'App\Models\Story' => 'Truyện',
									'App\Models\Chapter' => 'Chương',
									'App\Models\User' => 'Người dùng',
									'App\Models\Comment' => 'Bình luận',
									'App\Models\Category' => 'Danh mục',
									'App\Models\Tag' => 'Tag',
									'App\Models\Author' => 'Tác giả',
									'App\Models\ScrapeJob' => 'Thu thập',
									'App\Models\ScrapeSource' => 'Nguồn',
								];
								$subjectLabel = $subjectMap[$log->subject_type] ?? ($log->subject_type ? class_basename($log->subject_type) : '—');
							@endphp

							<span class="shrink-0 text-xs text-gray-400 dark:text-gray-500 tabular-nums w-20">
								{{ $log->created_at->diffForHumans(short: true) }}
							</span>

							<span
								class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-semibold {{ $eventConfig['badge'] }}">
								{{ $eventConfig['label'] }}
							</span>

							<span
								class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-medium bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400">
								{{ $subjectLabel }}
							</span>

							<span class="flex-1 text-sm text-gray-700 dark:text-gray-300 truncate">
								{{ \Illuminate\Support\Str::limit($log->description, 60) }}
							</span>
						</div>
					@endforeach
				</div>
			@else
				<div class="an-empty">
					<div class="an-empty__icon">📋</div>
					<p class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Chưa có hoạt động nào</p>
					<p class="text-xs text-gray-400 dark:text-gray-500">Các hoạt động sẽ được ghi nhận tại đây</p>
				</div>
			@endif
		</div>
	</div>

</x-filament-panels::page>
