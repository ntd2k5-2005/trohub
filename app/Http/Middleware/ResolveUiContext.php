<?php

namespace App\Http\Middleware;

use App\Contracts\Authorization as AccessService;
use App\Support\Authorization\EffectiveAccess;
use App\Support\Authorization\UiAccessResolver;
use App\Support\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

final class ResolveUiContext
{
    public function __construct(private AccessService $access, private UiAccessResolver $resolver) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user) {
            $user->refresh();
            if((int)$request->session()->get('auth_version',0)!==(int)$user->auth_version){
                \Illuminate\Support\Facades\Auth::logout();$request->session()->invalidate();$request->session()->regenerateToken();
                return redirect()->route('login')->with('status','Phiên đăng nhập đã kết thúc sau thay đổi bảo mật. Vui lòng đăng nhập lại.');
            }
            abort_unless($user->status === 'active', 403, 'Tài khoản đang bị khóa.');
        }
        $contexts = ['public'];
        $workspace = null;
        $workspaces = collect();
        if ($user) {
            $contexts[] = 'personal';
            $workspaces = $this->access->workspaces($user);
            if ($workspaces->isNotEmpty()) {
                $contexts[] = 'workspace';
                if ($request->has('workspace_id')) {
                    $id = $request->input('workspace_id');
                    abort_unless(is_scalar($id) && filter_var($id, FILTER_VALIDATE_INT) && (int) $id > 0, 403);
                    $workspace = $workspaces->firstWhere('id', (int) $id);
                    abort_unless($workspace, 403);
                } else {
                    $workspace = $workspaces->firstWhere('id', (int) $request->session()->get('workspace_id')) ?? $workspaces->first();
                }
                if ($request->input('context') === 'workspace') {
                    $request->session()->put('workspace_id', $workspace->id);
                }
            } else {
                $request->session()->forget('workspace_id');
                abort_if($request->has('workspace_id'), 403);
            }
            if ($this->resolver->contextAvailable('platform-admin', $user)) {
                $contexts[] = 'platform-admin';
            }
            if ($this->resolver->contextAvailable('system-admin', $user)) {
                $contexts[] = 'system-admin';
            }
        }
        $context = $request->input('context', 'public');
        abort_unless(is_string($context) && in_array($context, $contexts, true), 403);
        $caps = [];
        foreach (ModuleRegistry::pages() as $p) {
            if (! in_array($context, $p['contexts'], true)) {
                continue;
            }
            $can = $this->resolver->canOpen($p, $context, $user, $workspace);
            if ($can) {
                $caps[] = 'ui.'.$p['slug'];
            }
        }
        $effective = new EffectiveAccess(accountActive: true, capabilities: $caps, allowedContexts: $contexts, profileKey: $user ? 'account' : 'guest', displayName: $user?->name, profileLabel: $workspace?->name);
        $request->attributes->set('ui.context', $context);
        $request->attributes->set('ui.access', $effective);
        $request->attributes->set('workspace', $context === 'workspace' ? $workspace : null);
        $meta = collect(config('navigation.contexts'))->only($contexts)->all();
        foreach ($meta as $key => &$item) {
            $item['home'] = $this->resolver->home($key, $user, $key === 'workspace' ? $workspace : null);
        }
        unset($item);
        $request->attributes->set('ui.contexts', $meta);
        View::share(['uiAccess' => $effective, 'contexts' => $meta, 'context' => $context, 'currentWorkspace' => $workspace, 'availableWorkspaces' => $workspaces]);

        return $next($request);
    }
}
