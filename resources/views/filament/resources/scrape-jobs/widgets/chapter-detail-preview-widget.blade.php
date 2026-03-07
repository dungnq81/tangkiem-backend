<div>
	@php
		$record = $this->record;
		$item = $this->getItem();
		$isScraped = $record && in_array($record->status, [
			\App\Models\ScrapeJob::STATUS_SCRAPED,
			\App\Models\ScrapeJob::STATUS_DONE,
		]);
		$isDraft = $record && $record->status === \App\Models\ScrapeJob::STATUS_DRAFT;
		$isScraping = $record && $record->status === \App\Models\ScrapeJob::STATUS_SCRAPING;
		$isFailed = $record && $record->status === \App\Models\ScrapeJob::STATUS_FAILED;
	@endphp

	{{-- Empty state: no data yet --}}
	@if(!$item && ($isDraft || $isFailed))
		<div
			class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
			<div class="flex items-center gap-x-4 px-6 py-8 justify-center">
				<div class="text-center">
					<div
						class="w-12 h-12 mx-auto mb-3 flex items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
						<x-filament::icon icon="heroicon-o-document-text" class="w-6 h-6 text-gray-400" />
					</div>
					<h3 class="text-base font-semibold text-gray-950 dark:text-white mb-1">
						Chưa có nội dung
					</h3>
					<p class="text-sm text-gray-500 dark:text-gray-400">
						Bấm <strong>"Bắt đầu thu thập"</strong> để cào nội dung từ URL chi tiết chương.
					</p>
				</div>
			</div>
		</div>
	@endif

	{{-- Loading state --}}
	@if($isScraping)
		<div
			class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
			<div class="flex items-center gap-x-4 px-6 py-8 justify-center">
				<div class="text-center">
					<div
						class="w-12 h-12 mx-auto mb-3 flex items-center justify-center rounded-full bg-primary-100 dark:bg-primary-500/20 animate-pulse">
						<x-filament::icon icon="heroicon-o-arrow-path"
							class="w-6 h-6 text-primary-600 dark:text-primary-400 animate-spin" />
					</div>
					<h3 class="text-base font-semibold text-gray-950 dark:text-white mb-1">
						Đang thu thập...
					</h3>
					<p class="text-sm text-gray-500 dark:text-gray-400">
						Hệ thống đang cào nội dung từ URL. Vui lòng chờ trong giây lát.
					</p>
				</div>
			</div>
		</div>
	@endif

	{{-- Content editor: show when we have data --}}
	@if($item && ($isScraped || $this->isAlreadyImported()))
		<div
			class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
			{{-- Header --}}
			<div
				class="flex items-center justify-between px-6 py-3 border-b border-gray-200 dark:border-white/10 bg-gray-50/50 dark:bg-gray-800/50">
				<div class="flex items-center gap-x-3">
					<div
						class="shrink-0 w-8 h-8 flex items-center justify-center rounded-full {{ $this->isAlreadyImported() ? 'bg-success-100 dark:bg-success-500/20 text-success-600 dark:text-success-400' : 'bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400' }}">
						<x-filament::icon
							icon="{{ $this->isAlreadyImported() ? 'heroicon-o-check-circle' : 'heroicon-o-pencil-square' }}"
							class="w-5 h-5" />
					</div>
					<div>
						<h3 class="text-sm font-semibold text-gray-950 dark:text-white">
							{{ $this->isAlreadyImported() ? '✅ Đã import' : '📝 Xem trước & chỉnh sửa nội dung' }}
						</h3>
						<p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
							@if($this->isAlreadyImported())
								Chương đã được import thành công vào hệ thống.
							@else
								Chỉnh sửa nội dung, sau đó bấm <strong>Import</strong> để lưu vào hệ thống.
							@endif
						</p>
					</div>
				</div>

				@if(!$this->isAlreadyImported())
					<div class="flex items-center gap-2">
						<button wire:click="saveContent" wire:loading.attr="disabled" type="button"
							class="inline-flex items-center gap-x-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors ring-1 ring-gray-300 dark:ring-gray-600 shadow-sm disabled:opacity-50">
							<x-filament::icon icon="heroicon-o-check" class="w-4 h-4" />
							<span wire:loading.remove wire:target="saveContent">Lưu tạm</span>
							<span wire:loading wire:target="saveContent">Đang lưu...</span>
						</button>

						@if($this->isImportable())
							<button wire:click="importChapter" wire:loading.attr="disabled"
								wire:confirm="Bạn chắc chắn muốn import chương này? Nội dung hiện tại sẽ được lưu vào hệ thống."
								type="button"
								class="inline-flex items-center gap-x-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-white bg-success-600 hover:bg-success-500 transition-colors shadow-sm disabled:opacity-50">
								<x-filament::icon icon="heroicon-o-arrow-down-tray" class="w-4 h-4" />
								<span wire:loading.remove wire:target="importChapter">Import chương</span>
								<span wire:loading wire:target="importChapter">Đang import...</span>
							</button>
						@endif
					</div>
				@endif
			</div>

			{{-- Form fields --}}
			<div class="px-6 py-4 space-y-4">
				{{-- Title + Chapter Number row --}}
				<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
					<div class="md:col-span-2">
						<label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3 mb-1">
							<span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
								Tiêu đề chương
							</span>
						</label>
						<input type="text" wire:model.blur="chapterTitle" @if($this->isAlreadyImported()) disabled @endif
							placeholder="VD: Chương 1: Khởi đầu"
							class="fi-input block w-full rounded-lg border-none bg-white py-1.5 text-base text-gray-950 shadow-sm outline-none ring-1 transition duration-75 placeholder:text-gray-400 focus:ring-2 focus:ring-primary-600 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.400)] dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500 dark:disabled:text-gray-400 dark:disabled:[-webkit-text-fill-color:theme(colors.gray.400)] dark:disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.500)] sm:text-sm sm:leading-6 ring-gray-950/10 dark:ring-white/20 ps-3 pe-3" />
					</div>
					<div>
						<label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3 mb-1">
							<span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
								Số chương
							</span>
						</label>
						<input type="number" step="any" wire:model.blur="chapterNumber" @if($this->isAlreadyImported())
						disabled @endif placeholder="VD: 1"
							class="fi-input block w-full rounded-lg border-none bg-white py-1.5 text-base text-gray-950 shadow-sm outline-none ring-1 transition duration-75 placeholder:text-gray-400 focus:ring-2 focus:ring-primary-600 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.400)] dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500 dark:disabled:text-gray-400 dark:disabled:[-webkit-text-fill-color:theme(colors.gray.400)] dark:disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.500)] sm:text-sm sm:leading-6 ring-gray-950/10 dark:ring-white/20 ps-3 pe-3" />
					</div>
				</div>

				{{-- Content editor --}}
				<div>
					<label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3 mb-1">
						<span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
							Nội dung chương
						</span>
						@if($this->hasContent())
							<span class="text-xs text-gray-500 dark:text-gray-400">
								({{ number_format(str_word_count(strip_tags($chapterContent))) }} từ)
							</span>
						@endif
					</label>

					@if($this->isAlreadyImported())
						{{-- Read-only view --}}
						<div
							class="prose dark:prose-invert max-w-none p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50 ring-1 ring-gray-200 dark:ring-white/10 max-h-[600px] overflow-y-auto text-sm">
							{!! $chapterContent !!}
						</div>
					@else
						{{-- Editable rich text --}}
						<div x-data="{
										content: @js($chapterContent),
										init() {
											this.$watch('content', value => {
												$wire.set('chapterContent', value, false);
											});
										}
									}" wire:ignore>
							<div x-init="
											const editor = new Quill($refs.editor, {
												theme: 'snow',
												placeholder: 'Nội dung chương sẽ hiển thị ở đây sau khi thu thập...',
												modules: {
													toolbar: [
														['bold', 'italic', 'underline', 'strike'],
														[{ 'header': [1, 2, 3, false] }],
														[{ 'list': 'ordered'}, { 'list': 'bullet' }],
														['blockquote'],
														['clean'],
													]
												}
											});

											editor.root.innerHTML = content;

											editor.on('text-change', () => {
												content = editor.root.innerHTML;
											});
										">
								<div x-ref="editor" class="min-h-[400px] max-h-[600px]"></div>
							</div>
						</div>

						{{-- Quill CSS/JS --}}
						@push('styles')
							<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
							<style>
								.ql-editor {
									min-height: 400px;
									max-height: 600px;
									overflow-y: auto;
									font-size: 0.875rem;
									line-height: 1.6;
								}

								.ql-container {
									border-bottom-left-radius: 0.5rem;
									border-bottom-right-radius: 0.5rem;
								}

								.ql-toolbar {
									border-top-left-radius: 0.5rem;
									border-top-right-radius: 0.5rem;
								}

								.dark .ql-toolbar {
									border-color: rgba(255, 255, 255, 0.1);
									background: rgba(255, 255, 255, 0.05);
								}

								.dark .ql-container {
									border-color: rgba(255, 255, 255, 0.1);
									background: rgba(255, 255, 255, 0.03);
								}

								.dark .ql-editor {
									color: #e5e7eb;
								}

								.dark .ql-editor.ql-blank::before {
									color: #6b7280;
								}

								.dark .ql-toolbar .ql-stroke {
									stroke: #9ca3af;
								}

								.dark .ql-toolbar .ql-fill {
									fill: #9ca3af;
								}

								.dark .ql-toolbar .ql-picker-label {
									color: #9ca3af;
								}

								.dark .ql-toolbar button:hover .ql-stroke,
								.dark .ql-toolbar .ql-picker-label:hover .ql-stroke {
									stroke: #e5e7eb;
								}
							</style>
						@endpush
						@push('scripts')
							<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
						@endpush
					@endif
				</div>

				{{-- Source URL info --}}
				@if($item?->source_url)
					<div
						class="flex items-center gap-x-2 text-xs text-gray-500 dark:text-gray-400 pt-2 border-t border-gray-200 dark:border-white/10">
						<x-filament::icon icon="heroicon-o-link" class="w-3.5 h-3.5" />
						<span>Nguồn:</span>
						<a href="{{ $item->source_url }}" target="_blank" rel="noopener"
							class="text-primary-600 dark:text-primary-400 hover:underline truncate max-w-xl">
							{{ $item->source_url }}
						</a>
					</div>
				@endif
			</div>
		</div>
	@endif
</div>