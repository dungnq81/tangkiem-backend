<div
    class="space-y-3 text-sm leading-relaxed text-gray-600 dark:text-gray-400 p-4 bg-gray-50/50 dark:bg-white/5 rounded-lg border border-gray-100 dark:border-white/10">
    <div class="flex items-center gap-2 mb-1">
        <span class="p-1 rounded bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        </span>
        <strong class="text-gray-900 dark:text-white font-medium">Hướng dẫn Provider & API Key</strong>
    </div>

    <p>Để kích hoạt AI, bạn cần cấu hình API Key tương ứng trong file <code>.env</code>. Hệ thống sẽ tự động sử dụng cơ
        chế <strong>Fallback</strong> (Gemini → Groq → Cerebras → DeepSeek → OpenAI → Grok → Anthropic → Abacus →
        Blackbox) nếu model chính
        gặp lỗi hoặc hết hạn mức.</p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-2">
        <div class="flex flex-col gap-0.5">
            <span class="font-semibold text-gray-800 dark:text-gray-200 uppercase text-[10px] tracking-wider">Miễn phí &
                Đa dụng</span>
            <ul class="space-y-1 text-xs">
                <li>• <strong>Gemini:</strong> Search grounding, tạo ảnh. <a href="https://aistudio.google.com/apikey"
                        target="_blank" class="text-primary-600 dark:text-primary-400 hover:underline">Lấy Key →</a>
                </li>
                <li>• <strong>Cerebras / Groq:</strong> Tốc độ xử lý cực nhanh (LPU). <a
                        href="https://cloud.cerebras.ai/" target="_blank"
                        class="text-primary-600 dark:text-primary-400 hover:underline">Cerebras</a> / <a
                        href="https://console.groq.com/keys" target="_blank"
                        class="text-primary-600 dark:text-primary-400 hover:underline">Groq</a></li>
            </ul>
        </div>
        <div class="flex flex-col gap-0.5">
            <span class="font-semibold text-gray-800 dark:text-gray-200 uppercase text-[10px] tracking-wider">Trả phí
                (Premium)</span>
            <ul class="space-y-1 text-xs">
                <li>• <strong>DeepSeek (V3.2):</strong> Chi phí cực rẻ, suy luận mạnh. <a
                        href="https://platform.deepseek.com/api_keys" target="_blank"
                        class="text-primary-600 dark:text-primary-400 hover:underline">Lấy Key →</a></li>
                <li>• <strong>OpenAI:</strong> GPT-5.x, GPT-4.1, o-series. <a
                        href="https://platform.openai.com/api-keys" target="_blank"
                        class="text-primary-600 dark:text-primary-400 hover:underline">Lấy Key →</a></li>
                <li>• <strong>Grok (xAI):</strong> Reasoning model mạnh. <a href="https://console.x.ai/" target="_blank"
                        class="text-primary-600 dark:text-primary-400 hover:underline">Lấy Key →</a></li>
                <li>• <strong>Anthropic:</strong> Claude Opus, Sonnet, Haiku. <a
                        href="https://console.anthropic.com/settings/keys" target="_blank"
                        class="text-primary-600 dark:text-primary-400 hover:underline">Lấy Key →</a></li>
            </ul>
        </div>
        <div class="flex flex-col gap-0.5">
            <span class="font-semibold text-gray-800 dark:text-gray-200 uppercase text-[10px] tracking-wider">Aggregator
                (Đa model)</span>
            <ul class="space-y-1 text-xs">
                <li>• <strong>Abacus AI:</strong> RouteLLM, auto-route tối ưu. <a href="https://abacus.ai"
                        target="_blank" class="text-primary-600 dark:text-primary-400 hover:underline">Lấy Key →</a>
                </li>
                <li>• <strong>Blackbox AI:</strong> Code-focused, Blackbox-V4. <a href="https://www.blackbox.ai"
                        target="_blank" class="text-primary-600 dark:text-primary-400 hover:underline">Lấy Key →</a>
                </li>
            </ul>
        </div>
    </div>
</div>