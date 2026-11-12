<?php

namespace App\Modules\AccessControl\Models;

use App\Support\BusinessModel;

class DirectGrant extends BusinessModel
{
    protected $table = 'direct_grants';

    protected static function booted(): void
    {
        static::updating(function ($grant) {
            if ($grant->authority_kind !== 'legacy' && $grant->getOriginal('authority_kind') && $grant->isDirty(['user_id', 'membership_id', 'workspace_id', 'grantor_id', 'permission', 'property_id', 'room_id', 'valid_from', 'valid_until', 'source_kind', 'source_id', 'source_snapshot', 'delegation_source_kind', 'delegation_source_id', 'delegation_snapshot', 'authority_kind', 'issuer_membership_id'])) abort(409, 'Nguồn, phạm vi và thời hạn đã ghi nhận không được đổi. Hãy thu hồi và cấp lại.');
            if ($grant->isDirty('revoked_at') && $grant->revoked_at) $grant->status = 'revoked';
        });
        static::updated(fn () => app(\App\Modules\AccessControl\GrantGraph::class)->reconcile());
        static::creating(function ($grant) {
            if ($grant->workspace_id && \Illuminate\Support\Facades\Schema::hasColumn('direct_grants', 'membership_id')) {
                $grant->membership_id ??= \App\Modules\Workspace\Models\Membership::where('workspace_id', $grant->workspace_id)->where('user_id', $grant->user_id)->where('status', '!=', 'ended')->value('id');
            }
        });
    }
}
