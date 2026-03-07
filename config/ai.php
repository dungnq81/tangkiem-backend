<?php

declare(strict_types=1);

/**
 * AI Configuration — Providers, Models & Features
 *
 * Last updated: 2026-03-07
 * Run `/update-ai-models` workflow to refresh model lists from provider docs.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | AI Providers for Scrape Extraction
    |--------------------------------------------------------------------------
    |
    | API keys are loaded from .env. Do NOT store keys in database.
    |
    */

    'providers' => [

        /*
        |----------------------------------------------------------------------
        | Google Gemini — Primary provider (100% FREE)
        |----------------------------------------------------------------------
        | Free: 250 RPD (2.5 Flash), 1000 RPD (Flash Lite), 100 RPD (Pro)
        | Unique: Google Search grounding, Imagen image generation
        | Best for: content generation, internet search, image generation
        |
        | Docs: https://ai.google.dev/gemini-api/docs/models
        */
        'gemini' => [
            'api_key'       => env('GEMINI_API_KEY'),
            'base_url'      => 'https://generativelanguage.googleapis.com/v1beta',
            'api_format'    => 'google',
            'default_model' => 'gemini-2.5-flash-lite',
            'models'        => [
                // ── Gemini 3 (Preview) ────────
                'gemini-3.1-pro-preview'          => 'Gemini 3.1 Pro (preview)',         // Advanced intelligence, agentic
                'gemini-3-flash-preview'          => 'Gemini 3 Flash (preview)',         // Frontier-class, cost-effective
                'gemini-3.1-flash-lite-preview'   => 'Gemini 3.1 Flash Lite (preview)', // Frontier-class, budget-friendly

                // ── Gemini 2.5 (Stable) ───────
                'gemini-2.5-pro'               => 'Gemini 2.5 Pro',                 // Complex tasks, deep reasoning
                'gemini-2.5-flash'             => 'Gemini 2.5 Flash',               // Best price-performance, 10 RPM
                'gemini-2.5-flash-lite'        => 'Gemini 2.5 Flash Lite',          // Fastest, cheapest, 15 RPM
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Groq — High-speed inference (100% FREE)
        |----------------------------------------------------------------------
        | Free: 500K tokens/ngày, ~6,000-14,400 RPD (Llama)
        | Format: OpenAI-compatible
        | Best for: high-volume tasks, fast HTML extraction, content cleaning
        |
        | Docs: https://console.groq.com/docs/models
        */
        'groq' => [
            'api_key'       => env('GROQ_API_KEY'),
            'base_url'      => 'https://api.groq.com/openai/v1',
            'default_model' => 'llama-3.1-8b-instant',
            'models'        => [
                // ── Production Models ────────
                'llama-3.3-70b-versatile'                       => 'Llama 3.3 70B',
                'llama-3.1-8b-instant'                          => 'Llama 3.1 8B',
                'openai/gpt-oss-120b'                           => 'GPT-OSS 120B',
                'openai/gpt-oss-20b'                            => 'GPT-OSS 20B',

                // ── Preview Models (thử nghiệm, có thể bị xóa) ────
                'meta-llama/llama-4-scout-17b-16e-instruct'     => 'Llama 4 Scout 17B (preview)',
                'qwen/qwen3-32b'                                => 'Qwen 3 32B (preview)',
                'moonshotai/kimi-k2-instruct-0905'              => 'Kimi K2 (preview)',
                'openai/gpt-oss-safeguard-20b'                  => 'GPT-OSS Safeguard 20B (preview)',
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Cerebras — Ultra-fast LPU inference (100% FREE)
        |----------------------------------------------------------------------
        | Free: 1M tokens/ngày, ~14,400 RPD
        | Format: OpenAI-compatible
        | Best for: fallback provider, high-speed inference, large models
        |
        | Docs: https://inference-docs.cerebras.ai/
        */
        'cerebras' => [
            'api_key'       => env('CEREBRAS_API_KEY'),
            'base_url'      => 'https://api.cerebras.ai/v1',
            'default_model' => 'llama-3.1-8b-instant',
            'models'        => [
                // ── Production Models ────────
                'llama-3.1-8b-instant'          => 'Llama 3.1 8B',              // ~2200 tok/s
                'openai/gpt-oss-120b'           => 'GPT-OSS 120B',             // ~3000 tok/s

                // ── Preview Models ────────
                'qwen-3-235b-a22b-instruct-2507' => 'Qwen 3 235B Instruct (preview)',  // ~1400 tok/s
                'zai-glm-4.7'                    => 'GLM 4.7 (preview)',               // ~1000 tok/s
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | DeepSeek — Cost-effective Chinese AI (trả phí, rất rẻ)
        |----------------------------------------------------------------------
        | Model: DeepSeek-V3.2 (128K context)
        | Format: OpenAI-compatible
        | Best for: tiếng Trung/Việt, suy luận, coding
        |
        | Docs: https://api-docs.deepseek.com/
        */
        'deepseek' => [
            'api_key'       => env('DEEPSEEK_API_KEY'),
            'base_url'      => 'https://api.deepseek.com',
            'default_model' => 'deepseek-chat',
            'models'        => [
                'deepseek-chat'     => 'DeepSeek V3.2 Chat',        // non-thinking, 128K context
                'deepseek-reasoner' => 'DeepSeek V3.2 Reasoner',    // thinking mode
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | OpenAI — Frontier AI models (trả phí)
        |----------------------------------------------------------------------
        | GPT-5.x, GPT-4.1, o-series reasoning models
        | Format: OpenAI native
        | Best for: complex reasoning, coding, general intelligence
        |
        | Docs: https://platform.openai.com/docs/models
        */
        'openai' => [
            'api_key'       => env('OPENAI_API_KEY'),
            'base_url'      => 'https://api.openai.com/v1',
            'default_model' => 'gpt-4.1-mini',
            'models'        => [
                // ── GPT-5.x ─────────
                'gpt-5.4'         => 'GPT-5.4',                  // Latest frontier model, 1M context
                'gpt-5.4-mini'    => 'GPT-5.4 Mini',             // Compact, cost-efficient

                // ── GPT-4.1 ─────────
                'gpt-4.1'         => 'GPT-4.1',                  // Best non-reasoning model, 1M context
                'gpt-4.1-mini'    => 'GPT-4.1 Mini',             // Fast, affordable
                'gpt-4.1-nano'    => 'GPT-4.1 Nano',             // Fastest, cheapest

                // ── o-series (Reasoning) ────
                'o3'              => 'o3',                        // Deep reasoning
                'o3-pro'          => 'o3 Pro',                    // Enhanced reasoning reliability
                'o4-mini'         => 'o4-mini',                   // Fast reasoning, coding + vision
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Grok (xAI) — Elon Musk's AI (trả phí)
        |----------------------------------------------------------------------
        | Format: OpenAI-compatible
        | Best for: general tasks, reasoning, X/Twitter integration
        |
        | Docs: https://docs.x.ai/docs/models
        */
        'grok' => [
            'api_key'       => env('GROK_API_KEY'),
            'base_url'      => 'https://api.x.ai/v1',
            'default_model' => 'grok-3-mini',
            'models'        => [
                // ── Grok 4.x ────────
                'grok-4'                         => 'Grok 4',                       // Latest reasoning model
                'grok-4-fast'                    => 'Grok 4 Fast',                  // Speed-optimized
                'grok-4-1-fast-non-reasoning'    => 'Grok 4.1 Fast (non-reasoning)',

                // ── Grok 3.x ────────
                'grok-3'              => 'Grok 3',                    // Flagship, 131K context
                'grok-3-mini'         => 'Grok 3 Mini',              // Compact, fast
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Anthropic — Claude AI (trả phí)
        |----------------------------------------------------------------------
        | Claude 4.x family: Opus, Sonnet, Haiku
        | Format: Anthropic Messages API (NOT OpenAI-compatible)
        | Best for: coding, reasoning, long-context analysis
        |
        | Docs: https://docs.anthropic.com/en/docs/about-claude/models
        */
        'anthropic' => [
            'api_key'       => env('ANTHROPIC_API_KEY'),
            'base_url'      => 'https://api.anthropic.com',
            'api_format'    => 'anthropic',                          // NOT OpenAI-compatible
            'default_model' => 'claude-sonnet-4-6',
            'models'        => [
                // ── Claude 4.6 (Latest) ────────
                'claude-opus-4-6'       => 'Claude Opus 4.6',       // Most intelligent, complex agentic
                'claude-sonnet-4-6'     => 'Claude Sonnet 4.6',     // Balanced speed + intelligence

                // ── Claude 4.5 ────────
                'claude-opus-4-5'       => 'Claude Opus 4.5',       // Previous gen, structured outputs
                'claude-sonnet-4-5'     => 'Claude Sonnet 4.5',     // Previous gen, agents + coding
                'claude-haiku-4-5'      => 'Claude Haiku 4.5',      // Fastest, cheapest
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Abacus AI — RouteLLM Aggregator (trả phí)
        |----------------------------------------------------------------------
        | Intelligent router: tự chọn model tối ưu (GPT/Claude/Gemini)
        | Hoặc chỉ định model cụ thể. "route-llm" = auto-route.
        | Format: OpenAI-compatible
        | Best for: tối ưu chi phí/chất lượng tự động, truy cập nhiều model
        |
        | Docs: https://abacus.ai/docs/routellm
        */
        'abacus' => [
            'api_key'       => env('ABACUS_API_KEY'),
            'base_url'      => 'https://routellm.abacus.ai/v1',
            'default_model' => 'route-llm',
            'models'        => [
                // ── Auto Router ─────────
                'route-llm'         => 'RouteLLM (auto-select)',        // Tự chọn model tối ưu

                // ── OpenAI via Abacus ────
                'gpt-5.2'           => 'GPT-5.2 (via Abacus)',
                'gpt-5.1'           => 'GPT-5.1 (via Abacus)',
                'gpt-4.1'           => 'GPT-4.1 (via Abacus)',
                'gpt-4.1-mini'      => 'GPT-4.1 Mini (via Abacus)',
                'o3'                => 'o3 (via Abacus)',
                'o4-mini'           => 'o4-mini (via Abacus)',

                // ── Claude via Abacus ────
                'claude-sonnet-4-6' => 'Claude Sonnet 4.6 (via Abacus)',
                'claude-opus-4-6'   => 'Claude Opus 4.6 (via Abacus)',
                'claude-4-5-sonnet' => 'Claude 4.5 Sonnet (via Abacus)',
                'claude-4-5-haiku'  => 'Claude 4.5 Haiku (via Abacus)',

                // ── Gemini via Abacus ────
                'gemini-3.1-pro'    => 'Gemini 3.1 Pro (via Abacus)',
                'gemini-3-flash'    => 'Gemini 3 Flash (via Abacus)',
                'gemini-2.5-pro'    => 'Gemini 2.5 Pro (via Abacus)',
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Blackbox AI — Code-focused AI (miễn phí giới hạn)
        |----------------------------------------------------------------------
        | Proprietary Blackbox-V4 model, trained on 2T+ lines of code
        | Also routes to GPT, Claude, Gemini, DeepSeek, Llama
        | Format: OpenAI-compatible
        | Best for: coding, code review, debug
        |
        | Docs: https://www.blackbox.ai/docs
        */
        'blackbox' => [
            'api_key'       => env('BLACKBOX_API_KEY'),
            'base_url'      => 'https://api.blackbox.ai',
            'default_model' => 'blackboxai',
            'models'        => [
                // ── Blackbox Proprietary ────
                'blackboxai'              => 'Blackbox AI V4',             // Code-specialized, 2T+ lines training
                'blackboxai-pro'          => 'Blackbox AI Pro',            // Enhanced code model

                // ── Third-party via Blackbox ────
                'gpt-4o'                  => 'GPT-4o (via Blackbox)',
                'gemini-2.5-pro'          => 'Gemini 2.5 Pro (via Blackbox)',
                'claude-sonnet-4-6'       => 'Claude Sonnet 4.6 (via Blackbox)',
                'deepseek-v3'             => 'DeepSeek V3 (via Blackbox)',
                'llama-4-scout'           => 'Llama 4 Scout (via Blackbox)',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Image Generation
    |--------------------------------------------------------------------------
    |
    | Default: gemini-2.5-flash-image (Nano Banana — nhanh, miễn phí)
    | Alternative: gemini-3-pro-image-preview (Nano Banana Pro, 4K, thinking)
    |
    | Supported aspect ratios:
    | 1:1, 2:3, 3:2, 3:4, 4:3, 4:5, 5:4, 9:16, 16:9, 21:9
    |
    */

    'imagen' => [
        'model'                => env('IMAGEN_MODEL', 'gemini-2.5-flash-image'),
        'default_aspect_ratio' => '2:3',

        // Presets cho từng use case
        'aspect_ratios' => [
            'cover'     => '2:3',   // Ảnh bìa truyện (portrait book cover)
            'thumbnail' => '1:1',   // Thumbnail vuông
            'banner'    => '16:9',  // Banner tiêu chuẩn
            'wide'      => '21:9',  // Banner ultra-wide / cinematic
            'portrait'  => '3:4',   // Portrait
            'landscape' => '4:3',   // Landscape
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Features Reference
    |--------------------------------------------------------------------------
    |
    | Feature toggles are stored in the `settings` table (ai.* keys).
    | These defaults serve as documentation and fallback reference.
    |
    */

    'features' => [
        'auto_categorize'    => ['label' => 'AI Phân loại tự động',       'description' => 'Gợi ý thể loại, tags, loại truyện, nguồn gốc'],
        'auto_summary'       => ['label' => 'AI Tạo mô tả',              'description' => 'Tạo mô tả truyện từ nội dung chương'],
        'content_clean'      => ['label' => 'AI Dọn dẹp nội dung',       'description' => 'Loại bỏ quảng cáo, watermark, text rác'],
        'content_moderation' => ['label' => 'AI Kiểm duyệt bình luận',   'description' => 'Tự động ẩn bình luận spam, toxic, NSFW'],
        'cover_generation'   => ['label' => 'AI Tạo ảnh bìa',            'description' => 'Tạo ảnh bìa truyện bằng Gemini Imagen'],
    ],

];
