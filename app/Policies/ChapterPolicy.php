<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Chapter;
use Illuminate\Auth\Access\HandlesAuthorization;

class ChapterPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Chapter');
    }

    public function view(AuthUser $authUser, Chapter $chapter): bool
    {
        return $authUser->can('View:Chapter');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Chapter');
    }

    public function update(AuthUser $authUser, Chapter $chapter): bool
    {
        return $authUser->can('Update:Chapter');
    }

    public function delete(AuthUser $authUser, Chapter $chapter): bool
    {
        return $authUser->can('Delete:Chapter');
    }

    public function restore(AuthUser $authUser, Chapter $chapter): bool
    {
        return $authUser->can('Restore:Chapter');
    }

    public function forceDelete(AuthUser $authUser, Chapter $chapter): bool
    {
        return $authUser->can('ForceDelete:Chapter');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Chapter');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Chapter');
    }

    public function replicate(AuthUser $authUser, Chapter $chapter): bool
    {
        return $authUser->can('Replicate:Chapter');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Chapter');
    }

}