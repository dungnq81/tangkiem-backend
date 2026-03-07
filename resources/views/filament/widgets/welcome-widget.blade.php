<x-filament-widgets::widget>
    <x-filament::section>
        <div class="tk-welcome-widget">
            {{-- Main Content --}}
            <div class="tk-welcome-widget__content">
                {{-- Left: Greeting & Info --}}
                <div class="tk-welcome-widget__left">
                    {{-- Greeting --}}
                    <div class="tk-welcome-widget__greeting">
                        <div class="tk-welcome-widget__avatar">
                            <div class="tk-welcome-widget__avatar-circle">
                                <x-filament::icon icon="heroicon-o-user-circle"
                                    class="tk-welcome-widget__avatar-icon" />
                            </div>
                            <div class="tk-welcome-widget__status-dot"></div>
                        </div>

                        <div class="tk-welcome-widget__text">
                            <h2 class="tk-welcome-widget__title">
                                {{ $this->getGreeting() }}, <span
                                    class="tk-welcome-widget__name">{{ auth()->user()?->name ?? 'Quản trị viên' }}</span>
                            </h2>
                            <p class="tk-welcome-widget__subtitle">
                                Chào mừng bạn quay lại bảng điều khiển Tàng Kiếm
                            </p>
                        </div>
                    </div>

                    {{-- Info badges --}}
                    <div class="tk-welcome-widget__badges">
                        {{-- Role Badge --}}
                        <div class="tk-welcome-widget__badge tk-welcome-widget__badge--role">
                            <x-filament::icon :icon="$this->getRoleIcon()" class="tk-welcome-widget__badge-icon" />
                            <span>{{ $this->getRoleLabel() }}</span>
                        </div>

                        {{-- Date Badge --}}
                        <div class="tk-welcome-widget__badge tk-welcome-widget__badge--date">
                            <x-filament::icon icon="heroicon-o-calendar-days" class="tk-welcome-widget__badge-icon" />
                            <span>{{ $this->getFormattedDate() }}</span>
                        </div>

                        {{-- System Status --}}
                        <div class="tk-welcome-widget__badge tk-welcome-widget__badge--status">
                            <span class="tk-welcome-widget__pulse"></span>
                            <span>Hệ thống hoạt động bình thường</span>
                        </div>
                    </div>
                </div>

                {{-- Right: Actions --}}
                <div class="tk-welcome-widget__right">
                    <div class="tk-welcome-widget__actions">
                        <a href="{{ url('/admin/stories/create') }}"
                            class="tk-welcome-widget__action-btn tk-welcome-widget__action-btn--primary">
                            <x-filament::icon icon="heroicon-o-plus-circle" class="tk-welcome-widget__action-icon" />
                            <span>Thêm truyện</span>
                        </a>

                        <a href="{{ url('/admin/scrape-jobs') }}"
                            class="tk-welcome-widget__action-btn tk-welcome-widget__action-btn--secondary">
                            <x-filament::icon icon="heroicon-o-globe-alt" class="tk-welcome-widget__action-icon" />
                            <span>Thu thập</span>
                        </a>

                        <form method="POST" action="{{ route('filament.admin.auth.logout') }}"
                            class="tk-welcome-widget__logout-form">
                            @csrf
                            <button type="submit"
                                class="tk-welcome-widget__action-btn tk-welcome-widget__action-btn--logout">
                                <x-filament::icon icon="heroicon-o-arrow-right-start-on-rectangle"
                                    class="tk-welcome-widget__action-icon" />
                                <span>Đăng xuất</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>