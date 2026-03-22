<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Story;
use Illuminate\Auth\Access\HandlesAuthorization;

class StoryPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Story');
    }

    public function view(AuthUser $authUser, Story $story): bool
    {
        return $authUser->can('View:Story');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Story');
    }

    public function update(AuthUser $authUser, Story $story): bool
    {
        return $authUser->can('Update:Story');
    }

    public function delete(AuthUser $authUser, Story $story): bool
    {
        return $authUser->can('Delete:Story');
    }

    public function restore(AuthUser $authUser, Story $story): bool
    {
        return $authUser->can('Restore:Story');
    }

    public function forceDelete(AuthUser $authUser, Story $story): bool
    {
        return $authUser->can('ForceDelete:Story');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Story');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Story');
    }

    public function replicate(AuthUser $authUser, Story $story): bool
    {
        return $authUser->can('Replicate:Story');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Story');
    }

}