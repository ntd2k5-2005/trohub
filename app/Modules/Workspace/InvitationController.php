<?php

namespace App\Modules\Workspace;

use App\Http\Controllers\Controller;
use App\Modules\Notification\NotificationService;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\Request;

final class InvitationController extends Controller
{
    public function show(Request $request, int $invitation, InvitationService $service)
    {
        $invite = $service->received($request->user(), $invitation);
        app(NotificationService::class)->readKey($request->user(), 'invitation:'.$invite->id.':recipient:'.$request->user()->id);

        return view('product.invitation', [
            'invite' => $invite, 'workspace' => Workspace::findOrFail($invite->workspace_id),
            'permissionNames' => $service->permissionNames($invite), 'invitationState' => $service->state($invite),
            'acceptUrl' => route('invitation.respond', ['invitation' => $invite->id]),
            'membership' => \App\Modules\Workspace\Models\Membership::where('invitation_id', $invite->id)->first(),
        ]);
    }

    public function respond(Request $request, int $invitation, InvitationService $service)
    {
        $data = $request->validate(['decision' => 'required|in:accept,decline']);
        $service->received($request->user(), $invitation);
        if ($data['decision'] === 'accept') {
            app(WorkspaceService::class)->acceptInvitation($request->user(), $invitation);
        } else {
            $service->decline($request->user(), $invitation);
        }

        $pending = \App\Modules\Workspace\Models\Membership::where('invitation_id', $invitation)->where('status', 'pending')->exists();
        return redirect()->route('invitation.show', ['invitation' => $invitation])->with('status', $data['decision'] === 'accept' ? ($pending ? 'Đã chấp nhận lời mời. Bạn đang chờ người quản lý kích hoạt quyền làm việc.' : 'Đã chấp nhận lời mời.') : 'Đã từ chối lời mời.');
    }
}
