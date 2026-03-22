<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\ApiDomain;
use Illuminate\Auth\Access\HandlesAuthorization;

class ApiDomainPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ApiDomain');
    }

    public function view(AuthUser $authUser, ApiDomain $apiDomain): bool
    {
        return $authUser->can('View:ApiDomain');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ApiDomain');
    }

    public function update(AuthUser $authUser, ApiDomain $apiDomain): bool
    {
        return $authUser->can('Update:ApiDomain');
    }

    public function delete(AuthUser $authUser, ApiDomain $apiDomain): bool
    {
        return $authUser->can('Delete:ApiDomain');
    }

    public function restore(AuthUser $authUser, ApiDomain $apiDomain): bool
    {
        return $authUser->can('Restore:ApiDomain');
    }

    public function forceDelete(AuthUser $authUser, ApiDomain $apiDomain): bool
    {
        return $authUser->can('ForceDelete:ApiDomain');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ApiDomain');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ApiDomain');
    }

    public function replicate(AuthUser $authUser, ApiDomain $apiDomain): bool
    {
        return $authUser->can('Replicate:ApiDomain');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ApiDomain');
    }

}