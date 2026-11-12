<?php

namespace App\Modules\AccessControl;

use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\Workspace\Models\Workspace;

final class WorkspaceGroupCopies
{
    public function forMembership(Workspace $workspace, \App\Modules\Workspace\Models\Membership $member, array $ids, ?array $properties, ?array $rooms, ?string $until): array
    {
        $result = [];
        foreach ($ids as $id) {
            $group = AccessGroup::where('scope', 'workspace')->findOrFail($id);
            if ($group->status === 'active' && $group->active) { $result[] = $this->resolve($workspace, (int) $id)->id; continue; }
            $existing = $member->access_group_id === $group->id || \App\Modules\AccessControl\Models\GroupAssignment::where('membership_id', $member->id)->where('access_group_id', $group->id)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->exists();
            abort_unless($group->workspace_id === $workspace->id && $existing, 403, 'Không thể gán mới nhóm đã ngừng mở rộng hoặc tạm ngừng.');
            foreach ([[$member->property_ids, $properties], [$member->room_ids, $rooms]] as [$old, $new]) abort_if($old !== null && ($new === null || array_diff(array_map('intval', $new), $old)), 403, 'Không mở rộng phạm vi từ nhóm đã ngừng nhận thay đổi.');
            abort_if($member->valid_until && (! $until || \Illuminate\Support\Carbon::parse($until)->gt($member->valid_until)), 403);
            $result[] = $group->id;
        }
        return array_values(array_unique($result));
    }

    public function resolve(Workspace $workspace, int $id, bool $newAssignment = true): AccessGroup
    {
        $g = AccessGroup::where('scope', 'workspace')->findOrFail($id);
        abort_unless($g->workspace_id === null || $g->workspace_id === $workspace->id, 403);
        if (! $g->workspace_id) {
            $g = AccessGroup::firstOrCreate(['workspace_id' => $workspace->id, 'source_template_id' => $g->id], ['scope' => 'workspace', 'name' => $g->name, 'permissions' => $g->permissions, 'active' => $g->active, 'status' => $g->status ?? ($g->active ? 'active' : 'disabled')]);
        }
        abort_unless($g->active && (! $newAssignment || $g->status === 'active'), 403, 'Nhóm này không còn nhận thêm người hoặc mở rộng quyền.');
        return $g;
    }

    public function ids(Workspace $workspace, array $ids): array
    {
        return array_values(array_unique(array_map(fn ($id) => $this->resolve($workspace, (int) $id)->id, $ids)));
    }
}
