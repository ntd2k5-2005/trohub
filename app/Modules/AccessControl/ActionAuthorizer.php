<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\ReceivingAccount;
use App\Modules\Connection\Models\Connection;
use App\Modules\IoT\Models\Device;
use App\Modules\IoT\Models\SafetyEvent;
use App\Modules\Listing\Models\Listing;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use App\Modules\Property\Models\Property;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\Models\DepositRefundDecision;
use App\Modules\Rental\Models\Rental;
use App\Modules\Rental\Models\RentalRequest;
use App\Modules\Rental\Models\RoomReservation;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use App\Support\ScopedRepository;
use Illuminate\Http\Request;

final class ActionAuthorizer
{
    public function __construct(private AccessService $access) {}

    public function permission(string $action, array $data = []): ?string
    {
        return match ($action) {
            'platform.bank.save' => empty($data['id']) ? 'bank_account.create' : 'bank_account.update',
            'platform.bank.adopt' => 'bank_account.create', 'platform.bank.default' => 'bank_account.set_default', 'platform.bank.disable' => 'bank_account.disable', 'platform.bank.delete' => 'bank_account.delete',
            'listing.review' => 'listing.moderate',
            'data.health' => 'data.view',
            'refund.propose', 'refund.paid' => 'deposit.settle', 'refund.respond' => 'tenant.confirm',
            'rental_request.submit' => 'contact.create', 'rental_request.decide' => 'rental.create', 'rental_request.accept_offer', 'rental_request.cancel' => 'contact.schedule',
            'reservation.action' => match ($data['decision'] ?? '') {
                'extend' => 'reservation.extend', 'waive' => 'deposit.waive', default => 'reservation.resolve'
            },
            'rental.policy' => ($data['level'] ?? '') === 'workspace' ? 'workspace.update' : (($data['level'] ?? '') === 'property' ? 'property.update' : 'room.update'),
            'bank.save' => empty($data['id']) ? 'bank_account.create' : 'bank_account.update', 'bank.disable' => 'bank_account.disable', 'bank.default' => 'bank_account.set_default',
            'payment_request.issue' => 'payment_request.issue',
            'profile' => 'profile.update','profile.password' => 'profile.password','favorite.toggle' => 'favorite.manage',
            'property.save' => empty($data['id']) ? 'property.create' : 'property.update', 'room.save' => empty($data['id']) ? 'room.create' : 'room.update','room.inactivate' => 'room.deactivate',
            'listing.save' => empty($data['id']) ? 'listing.create' : 'listing.update', 'listing.status' => ($data['status'] ?? '') === 'published' ? 'listing.publish' : 'listing.pause',
            'connection.create' => 'contact.create','connection.update' => 'connection.manage',
            'rental.create', 'rental.reserve' => 'rental.create','rental.invite' => 'rental.create','rental.activate' => 'rental.activate','rental.deposit' => 'deposit.record','rental.renew' => 'rental.renew','rental.request_move_out' => 'rental.move_out','rental.settle' => 'deposit.settle','rental.close' => 'rental.close','rental.occupant' => 'rental.occupant','rental.cancel' => 'rental.cancel','rental.confirm' => 'tenant.confirm',
            'service.create' => 'utility.rate','meter.create' => 'utility.record','invoice.create' => 'invoice.create','invoice.issue' => 'invoice.issue','invoice.adjust' => 'invoice.adjust','payment.create' => 'payment.record',
            'maintenance.create' => 'maintenance.create','maintenance.comment' => 'maintenance.comment','maintenance.update' => match ($data['status'] ?? '') {
                'acknowledged' => 'maintenance.receive','closed' => 'maintenance.close',default => 'maintenance.process'
            },
            'member.activate', 'member.suspend' => 'member.update',
            'member.leave' => 'membership.leave',
            'work.takeover' => 'maintenance.process',
            'payment.review.claim' => 'payment.verify',
            'workspace.archive' => 'workspace.update',
            'workspace.lifecycle' => 'workspace.update', 'workspace.restrict', 'workspace.release' => 'system.configure',
            'account.trial','account.workspace.create','account.quote','account.paid.start','account.quote.cancel','account.payment.report' => 'workspace.create', 'account.subscription.confirm','account.subscription.reverse' => 'payment.verify','legacy.subscription.review' => 'subscription.approve', 'account.restrict','account.release' => 'system.configure',
            'plan.publish','plan.availability' => 'subscription.plan.publish', 'plan.save' => 'subscription.plan.edit', 'plan.quota.assign'=>'subscription.plan.manage',
            'property.move.propose','property.move.commit' => 'property.move',
            'transfer.submit' => 'workspace.update','transfer.review' => 'workspace.transfer.review','transfer.consent','transfer.evidence','transfer.cancel' => 'profile.view',
            'asset.retire', 'asset.restore' => ($data['asset_type'] ?? '') === 'property' ? 'property.update' : ($action === 'asset.restore' ? 'room.update' : 'room.deactivate'),
            'group.change.approve', 'group.change.apply', 'group.emergency_disable' => 'member.group.update',
            'member.invite' => 'member.invite','member.update' => 'member.update','member.end' => 'member.end','grant.create','grant.revoke' => 'member.delegate','invitation.cancel','invitation.renew' => 'member.invite','workspace.save' => 'workspace.update','workspace.group.save' => empty($data['id']) ? 'member.group.create' : 'member.group.update',
            'subscription.order' => 'subscription.manage','subscription.confirm' => 'subscription.approve',
            'permission.change.approve','permission.change.apply','permission.save','category.save' => 'access.catalog.edit','group.save' => empty($data['id']) ? 'access.group.create' : 'access.group.update','account.group' => 'access.assign','global.grant' => 'access.delegate','account.status' => 'account.lock',
            'report.account','report.create' => 'community.report','report.resolve' => 'report.manage','listing.moderate' => 'listing.moderate','review.create','review.details.save','review.details.delete' => 'community.review',
            'roommate.profile.save','roommate.profile.delete' => 'roommate.update',
            'roommate.save' => empty($data['id']) ? 'roommate.create' : 'roommate.update','roommate.close' => 'roommate.close','roommate.connect' => 'roommate.connect','roommate.respond' => 'roommate.respond',
            'iot.register' => 'iot.register','iot.move' => 'iot.move','iot.configure' => 'iot.configure','iot.ack' => ($data['status'] ?? 'acknowledged') === 'closed' ? 'iot.close' : 'iot.ack','iot.maintenance' => 'maintenance.create',
            'notification.read' => 'notification.read','trial.start','subscription.start' => 'workspace.create',
            'data.project' => 'data.project','data.replay' => 'data.replay','system.settings' => 'system.configure','group.assignment.save','group.assignment.revoke' => empty($data['target_workspace_id']) ? 'access.assign' : 'member.delegate',
            default => null,
        };
    }

    public function allows(User $u, string $action, array $data, ?Workspace $workspace = null, bool $form = false): bool
    {
        $permission = $this->permission($action, $data);
        if (! $permission || $u->status !== 'active') {
            return false;
        }
        if (str_starts_with($action, 'platform.bank.')) return !$workspace && $this->access->global($u,$permission) && ($action !== 'platform.bank.adopt' || $this->access->global($u,'bank_account.set_default'));
        if ($action === 'roommate.save') {
            if ($workspace || !config('features.roommate') || !$this->access->personal($u, $permission)) return false;
            $need = !empty($data['id']) ? \App\Modules\Roommate\Models\RoommateNeed::cloudAccessible()->where('user_id', $u->id)->find((int)$data['id']) : null;
            if (!empty($data['id']) && !$need) return false;
            if (($data['mode'] ?? $need?->mode ?? 'search_together') !== 'has_room') return true;
            $roomId = (int)($data['room_id'] ?? $need?->room_id ?? 0);
            return $form && !$roomId || in_array($roomId, app(ScopedRepository::class)->currentRoomIds($u), true);
        }
        if (in_array($action,['roommate.profile.save','roommate.profile.delete'],true)) return !$workspace && empty($data['id']) && (!isset($data['user_id']) || (int)$data['user_id']===$u->id) && config('features.roommate') && $this->access->personal($u,$permission);
        if (in_array($action,['review.details.save','review.details.delete'],true)) {
            $review=\App\Modules\Trust\Models\Review::find((int)($data['id']??0));
            return !$workspace && $review && $review->user_id===$u->id && $this->access->personal($u,$permission);
        }
        if ($action === 'member.leave') {
            $m = Membership::find((int) ($data['id'] ?? 0));
            return ! $workspace && $m && $m->user_id === $u->id && $m->status !== 'ended' && $m->workspace->owner_id !== $u->id && $this->access->personal($u, 'membership.leave');
        }
        if (in_array($action, ['permission.change.approve', 'permission.change.apply'], true)) return ! $workspace && $this->access->global($u, $permission);
        if ($action === 'workspace.lifecycle') {
            $target = Workspace::find((int) ($data['id'] ?? 0));
            return $target && (! $workspace || $workspace->id === $target->id) && $this->access->canManageLifecycle($u, $target);
        }
        if (in_array($action, ['workspace.restrict', 'workspace.release'], true)) return ! $workspace && $this->access->global($u, 'system.configure');
        if (in_array($action, ['account.subscription.confirm','account.subscription.reverse'],true)) return !$workspace && app(\App\Modules\Subscription\BusinessAccess::class)->can($u,'payments.verify');
        if (in_array($action, ['account.restrict','account.release'],true)) return !$workspace && $this->access->global($u,$permission);
        if (in_array($action,['account.payment.report','account.quote.cancel'],true)) {
            $quote=\App\Modules\Subscription\Models\SubscriptionQuote::find((int)($data['id']??0));
            return !$workspace && $quote && $quote->accountSubscription->owner_id===$u->id && $this->access->personal($u,'workspace.create');
        }
        if (in_array($action,['account.trial','account.workspace.create','account.quote','account.paid.start'],true)) return ! $workspace && $this->access->personal($u,'workspace.create');
        if($action==='legacy.subscription.review')return !$workspace&&$this->access->global($u,'subscription.approve');
        if(in_array($action,['plan.save','plan.publish','plan.quota.assign','plan.availability'],true))return !$workspace&&app(\App\Modules\Subscription\BusinessAccess::class)->can($u,match($action){'plan.publish','plan.availability'=>'plans.publish','plan.quota.assign'=>'subscription.plan.manage',default=>'plans.edit'});
        if($action==='transfer.submit'){$target=Workspace::find($data['id']??0);return !$workspace&&$target&&$target->owner_id===$u->id&&$this->access->canManageLifecycle($u,$target);}
        if(in_array($action,['transfer.consent','transfer.evidence','transfer.cancel','transfer.review'],true)){
            if($workspace)return false;$case=\App\Modules\Workspace\Models\OwnerTransferCase::find($data['id']??0);if(!$case)return false;
            return $action==='transfer.review'?$this->access->global($u,'workspace.transfer.review'):in_array($u->id,[$case->source_owner_id,$case->recipient_id],true);
        }
        if(in_array($action,['property.move.propose','property.move.commit'],true)){
            $move=$action==='property.move.commit'?\App\Modules\Property\Models\PropertyMove::find($data['id']??0):null;
            $p=Property::find($move?->property_id??($data['id']??0));
            return $workspace&&$p&&($move?$move->source_workspace_id===$workspace->id:$p->workspace_id===$workspace->id)&&$this->access->allowed($u,'property.move',$p->workspace,$p->id);
        }
        if (in_array($action, ['asset.retire', 'asset.restore'], true)) {
            $type = $data['asset_type'] ?? '';
            $record = $type === 'property' ? Property::find($data['id'] ?? 0) : ($type === 'room' ? Room::find($data['id'] ?? 0) : null);
            $property = $record instanceof Room ? $record->property : $record;
            return $workspace && $property && $property->workspace_id === $workspace->id && $this->access->allowed($u, $permission, $workspace, $property->id, $type === 'room' ? $record->id : null);
        }
        if ($action === 'work.takeover') {
            $a = \App\Modules\Workspace\Models\WorkAssignment::find((int) ($data['id'] ?? 0));
            if (! $a || ! $workspace || $a->workspace_id !== $workspace->id || $a->status !== 'handover_pending') return false;
            $spec = config('people.work_resources.'.$a->resource_type);
            if (! $spec || $spec['permission'] !== $a->permission) return false;
            $room = $spec['model']::find($a->resource_id)?->room;
            return $room && $room->property->workspace_id === $workspace->id && $this->access->allowed($u, $a->permission, $workspace, $room->property_id, $room->id);
        }
        if($action==='payment.review.claim'){
            $record=\App\Modules\Billing\Models\PaymentRequest::find((int)($data['id']??0));
            return $workspace&&$record&&$record->workspace_id===$workspace->id&&in_array($record->status,config('people.work_resources.payment_review.states'),true)&&$this->access->allowed($u,'payment.verify',$workspace,$record->property_id,$record->rental->room_id);
        }
        if ($action === 'workspace.archive') return $workspace && collect(config('authorization.workspace_archive_permissions'))->every(fn ($code) => $this->access->allowed($u, $code, $workspace));
        if (in_array($action, ['group.change.approve', 'group.change.apply', 'group.emergency_disable'], true)) {
            $id = (int) ($data['id'] ?? 0);
            $g = $action === 'group.emergency_disable' ? \App\Modules\AccessControl\Models\AccessGroup::find($id) : \App\Modules\AccessControl\Models\GroupChange::find($id)?->group;
            if (! $g || $g->workspace_id !== $workspace?->id) return false;
            return $workspace ? ($this->access->allowed($u, 'member.group.update', $workspace) || $this->access->roomIds($u, $workspace, 'member.group.update') !== []) : $this->access->global($u, $action === 'group.emergency_disable' ? 'access.group.disable' : 'access.group.update');
        }
        if (str_starts_with($action, 'bank.')) {
            if (! $workspace) {
                return false;
            }
            $id = (int) ($data['id'] ?? 0);
            $record = $id ? ReceivingAccount::where('workspace_id', $workspace->id)->find($id) : null;
            if ($id && ! $record) {
                return false;
            }
            $property = $action === 'bank.default' ? ($data['property_id'] ?? null) : ($record?->property_id ?? ($data['property_id'] ?? null));
            $roomId = $action === 'bank.default' ? ($data['room_id'] ?? null) : null;
            if ($roomId) {
                $room = Room::find((int) $roomId);
                if (! $room || $room->property->workspace_id !== $workspace->id) {
                    return false;
                }
                $property = $room->property_id;
            }
            if ($form && $action === 'bank.default' && ! $property) {
                return $this->access->allowed($u, $permission, $workspace) || $this->access->propertyIds($u, $workspace, $permission) !== [];
            }
            if ($form && ! $id && ! $property) {
                return $this->access->allowed($u, $permission, $workspace) || $this->access->propertyIds($u, $workspace, $permission) !== [];
            }

            return $this->access->allowed($u, $permission, $workspace, $property ? (int) $property : null, $roomId ? (int) $roomId : null);
        }
        if (str_starts_with($action, 'rental_request.')) {
            if ($action === 'rental_request.submit') {
                return ! $workspace && $this->access->personal($u, 'contact.create');
            }
            $record = RentalRequest::find((int) ($data['id'] ?? 0));
            if (! $record) {
                return false;
            }
            if ($action !== 'rental_request.decide') {
                return ! $workspace && app(\App\Modules\Subscription\PropertyCloud::class)->active($record->workspace_id) && $record->user_id === $u->id && $this->access->personal($u, 'contact.schedule');
            }

            return $workspace && $record->workspace_id === $workspace->id && $this->access->allowed($u, $permission, $workspace, $record->room->property_id, $record->room_id);
        }
        if (str_starts_with($action, 'refund.')) {
            $record = $action === 'refund.propose' ? Rental::find((int) ($data['id'] ?? 0)) : DepositRefundDecision::find((int) ($data['id'] ?? 0))?->rental;
            if (! $record) {
                return false;
            }
            if ($action === 'refund.respond') {
                return ! $workspace && app(\App\Modules\Subscription\PropertyCloud::class)->active($record->room->property->workspace_id) && $record->tenant_id === $u->id && $this->access->personal($u, 'tenant.confirm');
            }

            return $workspace && $record->room->property->workspace_id === $workspace->id && $this->access->allowed($u, 'deposit.settle', $workspace, $record->room->property_id, $record->room_id);
        }
        if ($action === 'reservation.action') {
            $record = RoomReservation::find((int) ($data['id'] ?? 0));

            return $record && $workspace && $record->room->property->workspace_id === $workspace->id && $this->access->allowed($u, $permission, $workspace, $record->room->property_id, $record->room_id);
        }
        if ($action === 'rental.policy') {
            return $workspace && match ($data['level'] ?? '') {
                'workspace' => $this->access->allowed($u, $permission, $workspace),
                'property' => $this->access->allowed($u, $permission, $workspace, (int) ($data['property_id'] ?? 0)),
                'room' => ($room = Room::find((int) ($data['room_id'] ?? 0))) && $room->property->workspace_id === $workspace->id && $this->access->allowed($u, $permission, $workspace, $room->property_id, $room->id),
                default => false,
            };
        }
        if ($action === 'payment_request.issue') {
            if (! $workspace) {
                return false;
            }
            $kind = $data['kind'] ?? '';
            $record = $kind === 'invoice' ? Invoice::find((int) ($data['id'] ?? 0)) : ($kind === 'deposit' ? Rental::find((int) ($data['id'] ?? 0)) : null);
            $room = $record instanceof Invoice ? $record->rental->room : $record?->room;

            return $room && $room->property->workspace_id === $workspace->id && $this->access->allowed($u, $permission, $workspace, $room->property_id, $room->id);
        }
        if (str_starts_with($action, 'iot.') && ! config('features.iot')) {
            return false;
        }
        if ($action === 'grant.revoke') {
            $g = DirectGrant::find((int) ($data['id'] ?? 0));
            if ($g && ($g->user_id === $u->id || $workspace && $g->workspace_id !== $workspace->id)) {
                return false;
            }
            if ($g && ! $g->workspace_id) {
                return $this->access->global($u, 'access.revoke');
            }
        }
        if (str_starts_with($action, 'group.assignment.')) {
            if ((int) ($data['user_id'] ?? 0) === $u->id) {
                return false;
            }
            $w = ! empty($data['target_workspace_id']) ? Workspace::find((int) $data['target_workspace_id']) : null;

            return $w ? (! $workspace || $workspace->id === $w->id) && ($this->access->allowed($u, 'member.delegate', $w) || $this->access->roomIds($u, $w, 'member.delegate') !== []) : $this->access->global($u, 'access.assign');
        }
        if (in_array($action, ['account.status', 'permission.save', 'category.save', 'group.save', 'account.group', 'global.grant', 'report.resolve', 'listing.moderate', 'listing.review', 'subscription.confirm', 'data.health', 'data.project', 'data.replay', 'system.settings'], true)) {
            return $this->access->global($u, $permission);
        }
        $room = null;
        $property = null;
        $id = (int) ($data['id'] ?? 0);
        if ($id && in_array($action, ['member.update', 'member.end', 'member.activate', 'member.suspend'], true) && $workspace) {
            $m = Membership::where('workspace_id', $workspace->id)->find($id);
            if (! $m || $m->user_id === $u->id) {
                return false;
            }
            if ($form && $workspace->owner_id !== $u->id && ! app(DelegationPolicy::class)->canWorkspace($u, $workspace, app(DelegationPolicy::class)->membershipCodes($m), $m->property_ids, now()->toDateTimeString(), $m->room_ids)) {
                return false;
            }
        }
        if (str_starts_with($action, 'rental.') && $id) {
            $r = Rental::find($id);
            if (! $r) {
                return false;
            }
            if (! $workspace && $r->tenant_id === $u->id && in_array($action, ['rental.confirm', 'rental.request_move_out'], true)) {
                return app(\App\Modules\Subscription\PropertyCloud::class)->active($r->room->property->workspace_id) && $this->access->personal($u, $action === 'rental.confirm' ? 'tenant.confirm' : 'tenant.move_out');
            }
            $room = $r->room;
        } elseif (in_array($action, ['invoice.issue', 'invoice.adjust', 'payment.create'], true) && $id) {
            $room = Invoice::find($id)?->rental?->room;
        } elseif ($action === 'invoice.create' && ! empty($data['rental_id'])) {
            $room = Rental::find((int) $data['rental_id'])?->room;
        } elseif (in_array($action, ['room.inactivate', 'room.save'], true) && $id) {
            $room = Room::find($id);
        } elseif ($action === 'property.save' && $id) {
            $property = Property::find($id);
        } elseif (in_array($action, ['maintenance.comment', 'maintenance.update'], true) && $id) {
            $m = MaintenanceRequest::find($id);
            if (! $m) {
                return false;
            }
            if (! $workspace && $action === 'maintenance.comment' && $m->reporter_id === $u->id && $this->access->personal($u, $permission)) {
                return app(\App\Modules\Subscription\PropertyCloud::class)->active($m->room->property->workspace_id);
            }
            $room = $m->room;
        } elseif (in_array($action, ['iot.move', 'iot.configure'], true) && $id) {
            $room = Device::find($id)?->assignments()->whereNull('effective_to')->first()?->room;
        } elseif (in_array($action, ['iot.ack', 'iot.maintenance'], true) && $id) {
            $room = SafetyEvent::find($id)?->room;
        } elseif (in_array($action, ['listing.save', 'listing.status'], true)) {
            $room = ! empty($data['room_id']) ? Room::find((int) $data['room_id']) : Listing::find($id)?->room;
        } elseif ($action === 'connection.update' && $id) {
            $c = Connection::find($id);
            if (! $c) {
                return false;
            }
            if (! $workspace && $c->user_id === $u->id) {
                return app(\App\Modules\Subscription\PropertyCloud::class)->active($c->listing->room->property->workspace_id) && $this->access->personal($u, 'contact.schedule');
            }
            $room = $c->listing->room;
        }
        if (! $room && ! empty($data['room_id']) && $action !== 'iot.move') {
            $room = Room::find((int) $data['room_id']);
        }
        if (! $property && ! empty($data['property_id'])) {
            $property = Property::find((int) $data['property_id']);
        }
        if ($action === 'maintenance.create' && ! $workspace && $this->access->personal($u, $permission) && ($form || $room && in_array($room->id, app(ScopedRepository::class)->currentRoomIds($u), true))) {
            return true;
        }
        if ($action === 'property.save' && ! $id) {
            return $workspace && $this->access->allowed($u, 'property.create', $workspace);
        }
        if ($action === 'room.save' && ! $id && ! $property) {
            return $workspace && $workspace->properties()->pluck('id')->contains(fn ($pid) => $this->access->allowed($u, 'room.create', $workspace, $pid));
        }
        if (! $workspace && $room && in_array($action, ['iot.configure', 'iot.ack'], true) && $this->access->personal($u, $permission) && in_array($room->id, app(ScopedRepository::class)->currentRoomIds($u), true)) {
            return true;
        }
        if ($room || $property) {
            if (! $workspace) {
                return false;
            }
            $property ??= $room->property;
            $actual = $property->workspace;
            if ($workspace && $workspace->id !== $actual->id) {
                return false;
            }

            return $this->access->allowed($u, $permission, $actual, $property->id, $room?->id);
        }
        if ($workspace) {
            return $this->access->allowed($u, $permission, $workspace) || ($form || str_starts_with($action, 'member.') || str_starts_with($action, 'grant.') || str_starts_with($action, 'invitation.') ? $this->access->roomIds($u, $workspace, $permission) !== [] : false);
        }

        return $this->access->personal($u, $permission);
    }

    public function request(Request $r, string $action): void
    {
        $r->validate(['id' => 'nullable|integer|min:1', 'room_id' => 'nullable|integer|min:1', 'property_id' => 'nullable|integer|min:1', 'rental_id' => 'nullable|integer|min:1']);
        abort_unless($this->allows($r->user(), $action, $r->all(), $r->attributes->get('workspace')), 403, 'Bạn không có quyền thực hiện thao tác này.');
    }
}
