<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Centralized SEO metadata character limits.
 *
 * Two types of limits:
 * - PROMPT_*  = what we tell the AI to TARGET (optimal for Google SERP display)
 * - MAX_*     = what code ENFORCES as hard limit (allows buffer for edge cases)
 *
 * Vietnamese SEO notes:
 * ─────────────────────────────────────────────────────────────────
 * Google measures display width in pixels, not characters:
 * - Title:       ~580px ≈ 55-65 English chars ≈ 55-70 Vietnamese chars
 * - Description: ~920px ≈ 150-160 English chars ≈ 150-170 Vietnamese chars
 *
 * Vietnamese uses Latin charset + diacritics (ă, ê, ơ, ư) — similar pixel
 * width to English. However, tên truyện/tác giả tiếng Trung phiên âm thường
 * dài (e.g. "Đấu La Đại Lục IV Chung Cực Đấu La"), nên cần buffer rộng hơn.
 *
 * Strategy:
 * - PROMPT limits: gợi ý AI viết vừa đủ hiển thị tốt trên Google SERP
 * - MAX limits: cho phép dài hơn SERP (Google vẫn đọc full content cho ranking,
 *   chỉ truncate hiển thị — không bị phạt)
 * ─────────────────────────────────────────────────────────────────
 *
 * Usage:
 *   use App\Support\SeoLimits;
 *
 *   // In Filament form:
 *   TextInput::make('meta_title')->maxLength(SeoLimits::MAX_TITLE)
 *
 *   // In AI prompt:
 *   "meta_title: tối đa " . SeoLimits::PROMPT_TITLE . " ký tự"
 *
 *   // In code enforcement:
 *   Str::limit($title, SeoLimits::MAX_TITLE, '')
 */
final class SeoLimits
{
    // ═══════════════════════════════════════════════════════════════
    // AI Prompt Hints (optimal for Google SERP display)
    // ═══════════════════════════════════════════════════════════════

    /** Suggested title length for AI-generated content (chars). */
    public const PROMPT_TITLE = 70;

    /** Suggested description length for AI-generated content (chars). */
    public const PROMPT_DESCRIPTION = 160;

    // ═══════════════════════════════════════════════════════════════
    // Hard Limits (code enforcement — form validation + Str::limit)
    // ═══════════════════════════════════════════════════════════════

    /** Maximum allowed meta_title length (chars). */
    public const MAX_TITLE = 120;

    /** Maximum allowed meta_description length (chars). */
    public const MAX_DESCRIPTION = 250;

    /** Maximum allowed meta_keywords length (chars). */
    public const MAX_KEYWORDS = 500;
}
