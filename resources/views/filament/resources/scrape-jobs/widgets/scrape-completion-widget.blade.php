<div>
	@if($allDone)
		<div
			class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
			<div class="flex items-center gap-x-4 px-6 py-4 bg-success-50/50 dark:bg-success-500/10">
				<div
					class="shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-success-100 dark:bg-success-500/20 text-success-600 dark:text-success-400">
					<x-filament::icon icon="heroicon-o-check-circle" class="w-6 h-6" />
				</div>
				<div class="flex-1">
					<h3 class="text-base font-semibold text-gray-950 dark:text-white">
						Tất cả {{ $total }} tác vụ đã hoàn thành
					</h3>
					<p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
						Hệ thống đã hoàn tất xử lý tất cả các tác vụ thu thập dữ liệu hiện có.
					</p>
				</div>
				<div class="shrink-0 flex items-center gap-3">
					<span
						class="hidden sm:inline-flex items-center gap-x-1.5 rounded-md bg-success-100 px-2 py-1 text-xs font-medium text-success-700 dark:bg-success-500/20 dark:text-success-400 ring-1 ring-inset ring-success-600/20">
						<span class="relative flex h-2 w-2">
							<span
								class="animate-ping absolute inline-flex h-full w-full rounded-full bg-success-400 opacity-75"></span>
							<span class="relative inline-flex rounded-full h-2 w-2 bg-success-500"></span>
						</span>
						Hoàn tất
					</span>

					@if($this->confirming)
						<div class="flex items-center gap-2">
							<span class="text-sm text-danger-600 dark:text-danger-400 font-medium">Xác nhận xóa?</span>
							<button wire:click="deleteAllDone" type="button"
								class="inline-flex items-center gap-x-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-white bg-danger-600 hover:bg-danger-500 transition-colors shadow-sm">
								<x-filament::icon icon="heroicon-o-trash" class="w-4 h-4" />
								Xóa
							</button>
							<button wire:click="cancelDelete" type="button"
								class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors ring-1 ring-gray-300 dark:ring-gray-600 shadow-sm">
								Hủy
							</button>
						</div>
					@else
						<button wire:click="confirmDelete" type="button"
							class="inline-flex items-center gap-x-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-white bg-danger-600 hover:bg-danger-500 transition-colors shadow-sm">
							<x-filament::icon icon="heroicon-o-trash" class="w-4 h-4" />
							Xóa tất cả
						</button>
					@endif
				</div>
			</div>
		</div>
	@endif
</div>
