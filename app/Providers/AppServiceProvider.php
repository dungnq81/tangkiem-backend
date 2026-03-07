<?php

namespace App\Providers;

use App\Models\Author;
use App\Models\Category;
use App\Models\Chapter;
use App\Models\ChapterContent;
use App\Models\Comment;
use App\Models\Story;
use App\Observers\AuthorObserver;
use App\Observers\CategoryObserver;
use App\Observers\ChapterContentObserver;
use App\Observers\ChapterObserver;
use App\Observers\CommentObserver;
use App\Observers\StoryObserver;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ─── Morph Map ──────────────────────────────────────────────
        // Enforce short aliases for all polymorphic relationships.
        // Without this, Laravel stores FQCN (App\Models\Story) but
        // Observer/scope code compares against short strings.
        Relation::enforceMorphMap([
            'story'   => Story::class,
            'chapter' => Chapter::class,
            'user'    => \App\Models\User::class,
        ]);

        // ─── Observers ──────────────────────────────────────────────
        Story::observe(StoryObserver::class);
        Category::observe(CategoryObserver::class);
        Author::observe(AuthorObserver::class);
        Chapter::observe(ChapterObserver::class);
        ChapterContent::observe(ChapterContentObserver::class);
        Comment::observe(CommentObserver::class);
    }
}
