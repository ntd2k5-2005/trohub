<?php

namespace App\Modules\Workspace;

use App\Contracts\Authorization;
use App\Contracts\WorkAssignments;
use App\Models\User;
use App\Modules\DataPlatform\Journal;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\MembershipHandover;
use App\Modules\Workspace\Models\WorkAssignment;
use App\Modules\Workspace\Models\Workspace;

final class WorkAssignmentService implements WorkAssignments
{
    public function takeOver(User $actor, Workspace $workspace, int $id): void
    {
        $candidate = WorkAssignment::where('workspace_id', $workspace->id)->where('status', 'handover_pending')->findOrFail($id);
        $spec = config('people.work_resources.'.$candidate->resource_type);
        abort_unless($spec && $spec['permission'] === $candidate->permission, 403);
        $resource = $spec['model']::findOrFail($candidate->resource_id);
        abort_unless($resource->room->property->workspace_id === $workspace->id, 403);
        app(\App\Modules\Subscription\PropertyCloud::class)->managedTransaction($actor, $resource->room_id, $candidate->permission, function () use ($actor, $workspace, $id) {
            $a = WorkAssignment::where('workspace_id', $workspace->id)->where('status', 'handover_pending')->lockForUpdate()->findOrFail($id);
            $this->claim($actor, $workspace->id, $a->resource_type, $a->resource_id, $a->permission);
        });
    }

    public function claim(User $actor, int $workspace, string $type, int $id, string $permission): void
    {
        $spec = config('people.work_resources.'.$type);
        abort_unless($spec && $permission === $spec['permission'], 422);
        $resource = $spec['model']::findOrFail($id);
        abort_if(isset($spec['states'])&&!in_array($resource->status,$spec['states'],true),409,'Hồ sơ đã kết thúc hoặc không còn ở bước cần tiếp nhận.');
        $room = $resource->room;
        abort_unless($room->property->workspace_id === $workspace, 403);
        $w = Workspace::findOrFail($workspace);
        app(Authorization::class)->authorize($actor, $permission, $w, $room->property_id, $room->id);
        $member = app(Authorization::class)->member($actor, $w);
        // Owners control the workspace without being a staff membership.
        if (! $member) {
            foreach (WorkAssignment::where('workspace_id', $workspace)->where('resource_type', $type)->where('resource_id', $id)->whereIn('status', ['open','handover_pending'])->get() as $previous) {
                $previous->update(['status' => 'reassigned', 'current_key'=>null, 'version' => $previous->version + 1]);
                if (! WorkAssignment::where('handover_id', $previous->handover_id)->where('status', 'handover_pending')->exists()) MembershipHandover::find($previous->handover_id)?->update(['status' => 'completed']);
                app(Journal::class)->record($actor->id, 'work.owner_takeover', $previous, $workspace, ['resource_type' => $type, 'resource_id' => $id]);
            }
            return;
        }
        $key = $type.':'.$id;
        $old = WorkAssignment::where('current_key', $key)->lockForUpdate()->first();
        abort_if($old && $old->membership_id !== $member->id,409,'Hồ sơ đã có người phụ trách. Cần bàn giao trước khi tiếp nhận.');
        $record = WorkAssignment::firstOrCreate(['current_key' => $key], ['workspace_id' => $workspace, 'membership_id' => $member->id, 'actor_id' => $actor->id, 'resource_type' => $type, 'resource_id' => $id, 'permission' => $permission]);
        $pending = WorkAssignment::where('workspace_id', $workspace)->where('resource_type', $type)->where('resource_id', $id)->where('status', 'handover_pending')->get();
        foreach ($pending as $previous) {
            $previous->update(['status' => 'reassigned', 'version' => $previous->version + 1]);
            if (! WorkAssignment::where('handover_id', $previous->handover_id)->where('status', 'handover_pending')->exists()) MembershipHandover::find($previous->handover_id)?->update(['status' => 'completed']);
        }
        app(Journal::class)->record($actor->id, 'work.assigned', $record, $workspace, ['resource_type' => $type, 'resource_id' => $id]);
    }

    public function complete(int $workspace, string $type, int $id): void
    {
        $rows=WorkAssignment::where('workspace_id',$workspace)->where('resource_type',$type)->where('resource_id',$id)->whereIn('status',['open','handover_pending'])->lockForUpdate()->get();
        foreach($rows as$row){$row->update(['status'=>'completed','current_key'=>null,'completed_at'=>now(),'version'=>$row->version+1]);if($row->handover_id&&!WorkAssignment::where('handover_id',$row->handover_id)->where('status','handover_pending')->exists())MembershipHandover::find($row->handover_id)?->update(['status'=>'completed']);}
    }

    public function assertCurrent(User $actor,int $workspace,string $type,int $id):void
    {
        abort_if(WorkAssignment::where('workspace_id',$workspace)->where('resource_type',$type)->where('resource_id',$id)->where('status','handover_pending')->exists(),409,'Công việc cần được tiếp nhận trước khi tiếp tục.');
        $current=WorkAssignment::where('current_key',$type.':'.$id)->lockForUpdate()->first();
        if($current){
            if(Workspace::whereKey($workspace)->where('owner_id',$actor->id)->exists())$this->claim($actor,$workspace,$type,$id,$current->permission);
            else $this->requireCurrent($actor,$current->id);
        }
    }

    public function requireCurrent(User $actor, int $id): WorkAssignment
    {
        $assignment = WorkAssignment::findOrFail($id);
        $member = Membership::whereKey($assignment->membership_id)->where('user_id', $actor->id)->where('status', 'active')->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->first();
        abort_unless($member && $assignment->status === 'open', 403, 'Công việc đang chờ bàn giao hoặc bạn không còn phụ trách.');
        $spec = config('people.work_resources.'.$assignment->resource_type);
        abort_unless($spec && $assignment->permission === $spec['permission'], 403);
        $room = $spec['model']::findOrFail($assignment->resource_id)->room;
        abort_unless($room->property->workspace_id === $assignment->workspace_id, 403);
        app(Authorization::class)->authorize($actor, $assignment->permission, $member->workspace, $room->property_id, $room->id);
        return $assignment;
    }
}
