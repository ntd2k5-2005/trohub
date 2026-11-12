<?php

namespace App\Support\Authorization;

use App\Contracts\Authorization as AccessService;
use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\Support\ModuleRegistry;

final class UiAccessResolver
{
    public function __construct(private AccessService $access) {}

    public function canOpen(array $page, string $context, ?User $user, ?Workspace $workspace): bool
    {
        if (! in_array($context, $page['contexts'], true) || ($user && $user->status !== 'active')) {
            return false;
        }
        if ($context !== 'public' && ! $user) {
            return false;
        }
        if ($context === 'workspace' && ! $workspace) {
            return false;
        }
        if ($page['slug'] === 'notifications') {
            return $user && $this->access->personal($user, 'notification.view');
        }
        if ($page['slug'] === 'conversations' && $context === 'workspace') {
            return $this->access->allowed($user, 'chat.view', $workspace) || $this->access->roomIds($user, $workspace, 'chat.view') !== [];
        }
        if ($page['slug'] === 'workspace-dashboard') {
            return $this->access->allowed($user, 'workspace.view', $workspace) || $this->access->roomIds($user, $workspace, 'workspace.view') !== [] || app(\App\Modules\Billing\DashboardScope::class)->financial($user,$workspace);
        }
        if ($context === 'personal') {
            $personal = config('authorization.personal_pages.'.$page['slug']);
            if ($personal !== null) {
                return collect($personal)->contains(fn ($code) => $this->access->personal($user, $code));
            }
            if (in_array($page['slug'], config('navigation.personal_ownership_pages', []), true)) {
                return true;
            }
        }
        $permissions = config('navigation.page_permissions.'.$page['slug'], array_filter([$page['permission']]));
        if (! $permissions) {
            return true;
        }
        if (! $user) {
            return false;
        }
        foreach ($permissions as $permission) {
            if ($context === 'workspace') {
                if ($this->access->allowed($user, $permission, $workspace) || $this->access->propertyIds($user, $workspace, $permission) !== []) {
                    return true;
                }
            } elseif ($this->access->global($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function contextAvailable(string $context, User $user): bool
    {
        return ModuleRegistry::pages()->contains(fn ($page) => ! empty(config('navigation.page_permissions.'.$page['slug'], array_filter([$page['permission']])))
            && $this->canOpen($page, $context, $user, null));
    }

    public function home(string $context, ?User $user, ?Workspace $workspace): ?string
    {
        $preferred = config("navigation.contexts.$context.home");
        $page = ModuleRegistry::findPage($preferred);
        if ($page && $this->canOpen($page, $context, $user, $workspace)) {
            return $preferred;
        }

        return ModuleRegistry::pages()->first(fn ($p) => ($p['nav'] ?? true) && $this->canOpen($p, $context, $user, $workspace))['slug'] ?? null;
    }
}
