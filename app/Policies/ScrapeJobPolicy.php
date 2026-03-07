<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\ScrapeJob;
use Illuminate\Auth\Access\HandlesAuthorization;

class ScrapeJobPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ScrapeJob');
    }

    public function view(AuthUser $authUser, ScrapeJob $scrapeJob): bool
    {
        return $authUser->can('View:ScrapeJob');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ScrapeJob');
    }

    public function update(AuthUser $authUser, ScrapeJob $scrapeJob): bool
    {
        return $authUser->can('Update:ScrapeJob');
    }

    public function delete(AuthUser $authUser, ScrapeJob $scrapeJob): bool
    {
        return $authUser->can('Delete:ScrapeJob');
    }

    public function restore(AuthUser $authUser, ScrapeJob $scrapeJob): bool
    {
        return $authUser->can('Restore:ScrapeJob');
    }

    public function forceDelete(AuthUser $authUser, ScrapeJob $scrapeJob): bool
    {
        return $authUser->can('ForceDelete:ScrapeJob');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ScrapeJob');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ScrapeJob');
    }

    public function replicate(AuthUser $authUser, ScrapeJob $scrapeJob): bool
    {
        return $authUser->can('Replicate:ScrapeJob');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ScrapeJob');
    }

}