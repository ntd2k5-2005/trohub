<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

abstract class BusinessModel extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'retired_at' => 'datetime',
            'snapshot' => 'array', 'requested_group_ids' => 'array', 'joined_at' => 'datetime', 'ended_at' => 'datetime', 'suspended_at' => 'datetime', 'completed_at' => 'datetime',
            'origin_id' => 'integer', 'source_revision' => 'integer', 'expected_version' => 'integer', 'required_approvals' => 'integer', 'applied_at' => 'datetime',
            'source_snapshot' => 'array', 'delegation_snapshot' => 'array', 'before' => 'array', 'after' => 'array', 'impact' => 'array', 'source_id' => 'integer', 'delegation_source_id' => 'integer', 'issuer_membership_id' => 'integer', 'membership_id' => 'integer', 'depth' => 'integer', 'max_depth' => 'integer',
            'cloud_epoch' => 'integer', 'cloud_cutoff_at' => 'datetime', 'cloud_resumed_at' => 'datetime', 'suppressed_at' => 'datetime',
            'amenity_ids' => 'array', 'custom_amenities' => 'array', 'rental_policy' => 'array', 'published_snapshot' => 'array',
            'amount' => 'integer', 'total' => 'integer', 'rent' => 'integer', 'deposit' => 'integer', 'deduction' => 'integer', 'rate' => 'integer', 'capacity' => 'integer', 'config_version' => 'integer', 'version' => 'integer',
            'image_urls' => 'array', 'rental_requested_at' => 'datetime', 'permissions' => 'array', 'property_ids' => 'array', 'room_ids' => 'array', 'access_group_ids' => 'array', 'scopes' => 'array', 'protected' => 'boolean', 'terms' => 'array', 'changes' => 'array', 'payload' => 'array', 'settings' => 'array',
            'active' => 'boolean', 'moderated' => 'boolean', 'handover_complete' => 'boolean', 'late' => 'boolean',
            'expires_at' => 'datetime', 'retention_until' => 'datetime', 'purged_at' => 'datetime', 'valid_from' => 'datetime', 'valid_until' => 'datetime', 'revoked_at' => 'datetime',
            'accepted_at' => 'datetime', 'cancelled_at' => 'datetime', 'declined_at' => 'datetime', 'confirmed_at' => 'datetime', 'closed_at' => 'datetime', 'issued_at' => 'datetime', 'due_at' => 'datetime', 'received_at' => 'datetime', 'settled_at' => 'datetime', 'read_at' => 'datetime',
            'last_seen_at' => 'datetime', 'last_event_at' => 'datetime', 'event_time' => 'datetime', 'ingest_time' => 'datetime', 'effective_from' => 'datetime', 'effective_to' => 'datetime', 'projected_at' => 'datetime',
        ];
    }
}
