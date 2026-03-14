<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Author;
use App\Models\SlugRedirect;

class AuthorObserver
{
    /**
     * Handle the Author "updating" event.
     * Create slug redirect when slug changes.
     */
    public function updating(Author $author): void
    {
        if ($author->isDirty('slug')) {
            $oldSlug = $author->getOriginal('slug');

            if ($oldSlug) {
                SlugRedirect::createForSlugChange('author', $author->id, $oldSlug);
            }
        }
    }
}
