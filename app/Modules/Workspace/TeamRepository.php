<?php

namespace App\Modules\Workspace;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\DelegationPolicy;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\DataPlatform\Models\AuditEntry;
use App\Modules\Property\Models\Room;
use App\Modules\Workspace\Models\Invitation;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;

final class TeamRepository
{
    public function __construct(private DelegationPolicy $delegation) {}

    public function members(User $user, Workspace $workspace)
    {
        $this->authorize($user, $workspace);

        return Membership::with('user', 'group')->where('workspace_id', $workspace->id)->orderBy('id')->get()
            ->filter(fn ($m) => $this->visible($user, $workspace, $m->property_ids, $m->room_ids));
    }

    public function invitations(User $user, Workspace $workspace)
    {
        $this->authorize($user, $workspace);

        return Invitation::where('workspace_id', $workspace->id)->where('purpose', 'staff')->latest()->get()
            ->filter(fn ($i) => $this->visible($user, $workspace, $i->property_ids, $i->room_ids));
    }

    public function grants(User $user, Workspace $workspace, array $users)
    {
        $this->authorize($user, $workspace);

        return DirectGrant::where('workspace_id', $workspace->id)->whereIn('user_id', $users)->latest()->get()
            ->filter(fn ($g) => $this->visible($user, $workspace, $g->property_id ? [$g->property_id] : null, $g->room_id ? [$g->room_id] : null));
    }

    public function history(User $user, Workspace $workspace)
    {
        $this->authorize($user, $workspace);

        $query=AuditEntry::where('workspace_id', $workspace->id)->whereIn('action', array_keys(config('people.audit_labels')))
            ->when($workspace->owner_id !== $user->id, fn ($q) => $q->where('actor_id', $user->id));
        $ids=(clone $query)->get()->filter(fn($entry)=>app(\App\Modules\DataPlatform\AuditResourceScope::class)->visible($user,$entry))->pluck('id');
        return $query->whereIn('id',$ids)->latest()->paginate(config('business.pagination'))->withQueryString();
    }

    private function authorize(User $user, Workspace $workspace): void
    {
        $access = app(Authorization::class);
        abort_unless($access->allowed($user, 'member.view', $workspace) || $access->roomIds($user, $workspace, 'member.view') !== [], 403);
    }

    private function visible(User $user, Workspace $workspace, ?array $properties, ?array $rooms): bool
    {
        $access = app(Authorization::class);
        if ($access->allowed($user, 'member.view', $workspace)) {
            return true;
        }
        if (! $properties && ! $rooms) {
            return false;
        }
        $targets = Room::whereHas('property', fn ($q) => $q->where('workspace_id', $workspace->id))->when($properties, fn ($q) => $q->whereIn('property_id', $properties))->when($rooms, fn ($q) => $q->whereIn('id', $rooms))->pluck('id')->all();

        return $targets !== [] && ! array_diff($targets,$access->roomIds($user,$workspace,'member.view'));
    }
}
