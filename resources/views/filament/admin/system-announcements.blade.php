<x-filament-panels::page>
    {{-- Recent Announcements History --}}
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-clock class="w-5 h-5 text-gray-400" />
                Lịch sử thông báo gần đây
            </div>
        </x-slot>

        @if($recentAnnouncements->isEmpty())
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                <x-heroicon-o-bell-slash class="w-12 h-12 mx-auto mb-3 opacity-50" />
                <p>Chưa có thông báo hệ thống nào.</p>
                <p class="text-sm mt-1">Nhấn "Gửi thông báo" để tạo thông báo đầu tiên.</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach($recentAnnouncements as $announcement)
                    <div class="flex items-start gap-3 p-3 rounded-lg bg-gray-50 dark:bg-white/5">
                        <x-heroicon-o-megaphone class="w-5 h-5 text-primary-500 mt-0.5 shrink-0" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <h4 class="font-medium text-sm">{{ $announcement->data['title'] ?? '—' }}</h4>
                                <span class="text-xs text-gray-400 shrink-0">
                                    {{ $announcement->created_at->diffForHumans() }}
                                </span>
                            </div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                {{ Str::limit($announcement->data['message'] ?? '', 200) }}
                            </p>
                            @if(!empty($announcement->data['action_url']))
                                <a href="{{ $announcement->data['action_url'] }}" target="_blank"
                                    class="text-xs text-primary-500 hover:underline mt-1 inline-block">
                                    {{ $announcement->data['action_url'] }}
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>