<?php

namespace App\Http\Controllers;

use App\Support\Authorization\EffectiveAccess;
use App\Support\ModuleRegistry;
use App\Support\ScreenPresenter;
use App\Support\ShellPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UiController extends Controller
{
    public function enter(Request $request): RedirectResponse
    {
        $context = $request->attributes->get('ui.context');
        $home = $request->attributes->get('ui.contexts')[$context]['home'] ?? null;
        abort_unless($home, 403);

        return redirect()->route('ui.show', ['slug' => $home, 'context' => $context] + ($context === 'workspace' ? ['workspace_id' => $request->attributes->get('workspace')->id] : []));
    }

    public function home(Request $request): RedirectResponse
    {
        /** @var EffectiveAccess|null $access */
        $access = $request->attributes->get('ui.access');
        abort_unless($access instanceof EffectiveAccess, 500);

        // Trang gốc ưu tiên khu khám phá nếu tài khoản được phép dùng khu này.
        // Các tài khoản quản trị nội bộ không có public context sẽ về context mặc định.
        $context = $access->canUseContext('public')
            ? 'public'
            : (string) $request->attributes->get('ui.context', $access->allowedContexts[0] ?? 'public');

        $home = config("navigation.contexts.$context.home", 'marketplace-search');

        return redirect()->route('ui.show', ['slug' => $home, 'context' => $context]);
    }

    public function show(Request $request, string $slug = 'marketplace-search'): View
    {
        /** @var EffectiveAccess|null $access */
        $access = $request->attributes->get('ui.access');
        $context = (string) $request->attributes->get('ui.context', 'public');

        abort_unless($access instanceof EffectiveAccess, 500);

        // Không tồn tại là 404; tồn tại nhưng sai context/quyền mới là 403.
        abort_if(ModuleRegistry::findPage($slug) === null, 404);

        $page = ModuleRegistry::findAccessiblePage($slug, $context, $access);
        abort_unless($page !== null, 403);

        if ($slug === 'admin-dashboard' && $context === 'platform-admin') {
            $section = $request->validate(['section' => 'nullable|string|in:'.implode(',', array_keys(config('navigation.admin_sections', [])))])['section'] ?? null;
            if ($section) {
                $definition = config('navigation.admin_sections.'.$section);
                abort_unless(collect($definition['permissions'])->contains(fn ($permission) => app(\App\Contracts\Authorization::class)->global($request->user(), $permission)), 403);
                $page['title'] = $definition['label'];
                $page['breadcrumbs'] = [['label'=>config('navigation.contexts.platform-admin.label'),'url'=>route('ui.show',['slug'=>'admin-dashboard','context'=>'platform-admin'])], ['label'=>$definition['label'],'url'=>route('ui.show',['slug'=>'admin-dashboard','context'=>'platform-admin','section'=>$section])]];
            }
        }

        return view($page['view'] ?? 'product.screen', [
            'screen' => app(ScreenPresenter::class)->present($request, $slug),
            'page' => $page,
            'context' => $context,
            'navigation' => ModuleRegistry::navigation($context, $access),
            'contexts' => $request->attributes->get('ui.contexts'),
            'shell' => app(ShellPresenter::class)->present($request, $page),
            'uiAccess' => $access,
        ]);
    }
}
