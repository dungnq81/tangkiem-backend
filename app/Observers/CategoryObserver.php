<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Category;
use App\Models\SlugRedirect;

class CategoryObserver
{
    /**
     * Handle the Category "creating" event.
     * Set depth and path based on parent.
     */
    public function creating(Category $category): void
    {
        if ($category->parent_id) {
            $parent = Category::query()->find($category->parent_id);

            if ($parent) {
                $category->depth = $parent->depth + 1;
                $category->path = $parent->path
                    ? "{$parent->path}/{$parent->id}"
                    : "/{$parent->id}";
            }
        } else {
            $category->depth = 0;
            $category->path = null;
        }
    }

    /**
     * Handle the Category "updating" event.
     * Create slug redirect when slug changes.
     */
    public function updating(Category $category): void
    {
        // Slug redirect
        if ($category->isDirty('slug')) {
            $oldSlug = $category->getOriginal('slug');

            if ($oldSlug) {
                SlugRedirect::createForSlugChange('category', $category->id, $oldSlug);
            }
        }

        // Update depth and path if parent changes
        if ($category->isDirty('parent_id')) {
            if ($category->parent_id) {
                $parent = Category::query()->find($category->parent_id);

                if ($parent) {
                    $category->depth = $parent->depth + 1;
                    $category->path = $parent->path
                        ? "{$parent->path}/{$parent->id}"
                        : "/{$parent->id}";
                }
            } else {
                $category->depth = 0;
                $category->path = null;
            }
        }
    }

    /**
     * Handle the Category "updated" event.
     * Update children's path and depth when parent changes.
     */
    public function updated(Category $category): void
    {
        if ($category->isDirty('parent_id')) {
            $this->updateChildrenPaths($category);
        }
    }

    /**
     * Handle the Category "created" event.
     * Increment parent's children_count.
     */
    public function created(Category $category): void
    {
        if ($category->parent_id) {
            Category::query()->where('id', $category->parent_id)
                ->increment('children_count');
        }
    }

    /**
     * Handle the Category "deleted" event.
     * Decrement parent's children_count.
     */
    public function deleted(Category $category): void
    {
        if ($category->parent_id) {
            Category::query()->where('id', $category->parent_id)
                ->decrement('children_count');
        }
    }

    /**
     * Recursively update children's paths.
     */
    protected function updateChildrenPaths(Category $category): void
    {
        $children = Category::query()->where('parent_id', $category->id)->get();

        foreach ($children as $child) {
            $child->depth = $category->depth + 1;
            $child->path = $category->path
                ? "{$category->path}/{$category->id}"
                : "/{$category->id}";
            $child->saveQuietly();

            // Recursively update grandchildren
            $this->updateChildrenPaths($child);
        }
    }
}
