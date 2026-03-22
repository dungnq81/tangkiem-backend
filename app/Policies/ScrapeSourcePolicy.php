<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\ScrapeSource;
use Illuminate\Auth\Access\HandlesAuthorization;

class ScrapeSourcePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ScrapeSource');
    }

    public function view(AuthUser $authUser, ScrapeSource $scrapeSource): bool
    {
        return $authUser->can('View:ScrapeSource');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ScrapeSource');
    }

    public function update(AuthUser $authUser, ScrapeSource $scrapeSource): bool
    {
        return $authUser->can('Update:ScrapeSource');
    }

    public function delete(AuthUser $authUser, ScrapeSource $scrapeSource): bool
    {
        return $authUser->can('Delete:ScrapeSource');
    }

    public function restore(AuthUser $authUser, ScrapeSource $scrapeSource): bool
    {
        return $authUser->can('Restore:ScrapeSource');
    }

    public function forceDelete(AuthUser $authUser, ScrapeSource $scrapeSource): bool
    {
        return $authUser->can('ForceDelete:ScrapeSource');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ScrapeSource');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ScrapeSource');
    }

    public function replicate(AuthUser $authUser, ScrapeSource $scrapeSource): bool
    {
        return $authUser->can('Replicate:ScrapeSource');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ScrapeSource');
    }

}