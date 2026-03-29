<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScrapeSource extends Model
{
    use SoftDeletes;

    protected $table = 'scrape_sources';

    protected $fillable = [
        'name',
        'base_url',
        'render_type',
        'extraction_method',
        'ai_provider',
        'ai_model',
        'ai_prompt_template',
        'default_headers',
        'delay_ms',
        'max_concurrency',
        'max_retries',
        'is_active',
        'cleanup_after_days',
        'notes',
        'clean_patterns',
        'remove_selectors',
        'remove_text_patterns',
    ];

    protected $casts = [
        'default_headers' => 'array',
        'clean_patterns' => 'array',
        'delay_ms' => 'integer',
        'max_concurrency' => 'integer',
        'max_retries' => 'integer',
        'is_active' => 'boolean',
        'cleanup_after_days' => 'integer',
    ];

    // ═══════════════════════════════════════════════════════════════
    // Relationships
    // ═══════════════════════════════════════════════════════════════

    public function jobs(): HasMany
    {
        return $this->hasMany(ScrapeJob::class, 'source_id');
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Story::class, 'scrape_source_id');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class, 'scrape_source_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'scrape_source_id');
    }

    public function authors(): HasMany
    {
        return $this->hasMany(Author::class, 'scrape_source_id');
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    public function isSpa(): bool
    {
        return $this->render_type === 'spa';
    }

    public function isSsr(): bool
    {
        return $this->render_type === 'ssr';
    }

    public function usesAi(): bool
    {
        return $this->extraction_method === 'ai_prompt';
    }

    public function usesCssSelectors(): bool
    {
        return $this->extraction_method === 'css_selector';
    }
}
