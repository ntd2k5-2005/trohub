<?php

namespace App\Support;

use App\Contracts\Authorization as AccessService;
use App\Modules\Notification\Models\UserNotification;
use Illuminate\Http\Request;

final class ShellPresenter
{
    public function __construct(private AccessService $access) {}

    public function present(Request $request, array $page): array
    {
        $context = $request->attributes->get('ui.context');
        $workspace = $request->attributes->get('workspace');
        $effective = $request->attributes->get('ui.access');
        $params = ['context' => $context] + ($workspace ? ['workspace_id' => $workspace->id] : []);
        $active = config('navigation.active_parents.'.$page['slug'], $page['slug']);
        $section=$request->query('section');
        if($page['slug']==='admin-dashboard'&&$section==='ownership')$active='admin-accounts';
        $sectionParent=$section;
        if($section==='legacy')$sectionParent='requests';
        if($section==='order')$sectionParent=app(\App\Modules\Subscription\BusinessAccess::class)->can($request->user(),'subscriptions.view')?'requests':(app(\App\Modules\Subscription\BusinessAccess::class)->can($request->user(),'payments.view')?'payments':'revenue');
        $unread = $request->user() && $this->access->personal($request->user(), 'notification.view')
            ? app(\App\Modules\DataPlatform\Contracts\HotCache::class)->remember('notifications:'.$request->user()->id, 20,
                fn () => UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count()) : 0;
        $items = ModuleRegistry::navigation($context, $effective)->map(fn ($item) => [
            'label' => $item['label'] ?? $item['title'], 'slug' => $item['slug'],
            'group' => $item['group_order'] ?? 999,
            'icon' => config('navigation.icons.'.$item['slug'], 'grid'),
            'active' => $active === $item['slug'] && ($item['slug'] !== 'admin-dashboard' || !$request->query('section')),
            'url' => route('ui.show', ['slug' => $item['slug']] + $params),
            'badge' => $item['slug'] === 'notifications' ? $unread : 0,
        ]);
        if ($context === 'platform-admin' && $effective->can('ui.admin-dashboard')) {
            foreach (config('navigation.admin_sections', []) as $key => $definition) {
                if (($definition['nav'] ?? true) && collect($definition['permissions'])->contains(fn ($permission) => $this->access->global($request->user(), $permission))) {
                    $section = $request->query('section');
                    $items->push(['label' => $definition['label'], 'slug' => 'admin-dashboard', 'group' => $definition['group'], 'icon' => $definition['icon'], 'active' => $page['slug'] === 'admin-dashboard' && $sectionParent === $key, 'url' => route('ui.show', ['slug' => 'admin-dashboard', 'section' => $key] + $params), 'badge' => 0]);
                }
            }
            $items = $items->sortBy('group')->values();
        }
        $scope = null;
        if ($workspace) {
            $member = $this->access->member($request->user(), $workspace);
            $scope = $workspace->owner_id === $request->user()->id || $member?->property_ids === null
                ? 'Toàn bộ nhà trong khu quản lý'
                : ($workspace->properties()->whereIn('id', $member->property_ids)->pluck('name')->implode(', ') ?: 'Chưa được giao nhà');
        }

        return [
            'items' => $items, 'active' => $active, 'scope' => $scope, 'unread' => $unread,
            'notification_url' => $effective->can('ui.notifications') ? route('ui.show', ['slug' => 'notifications'] + $params) : null,
            'profile_url' => $request->user() && ($this->access->personal($request->user(), 'profile.update') || $this->access->personal($request->user(), 'profile.password')) ? route('ui.show', ['slug' => 'profile-security', 'context' => 'personal']) : null,
            'home_url' => route('ui.enter', $params),
        ];
    }
}
