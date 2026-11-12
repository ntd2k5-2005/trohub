<?php

namespace App\Modules\Listing;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\DataPlatform\Journal;
use App\Modules\Listing\Models\Favorite;
use App\Modules\Listing\Models\Listing;
use App\Modules\Property\HousingMedia;
use App\Modules\Property\Models\Room;
use App\Support\HousingImages;
use App\Support\ScopedRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ListingService
{
    public function __construct(private ScopedRepository $scope, private Journal $journal) {}

    public function toggleFavorite(User $user, int $id): void
    {
        abort_unless(app(Authorization::class)->personal($user, 'favorite.manage'), 403);
        DB::transaction(function () use ($user, $id) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = Favorite::where('user_id', $user->id)->where('listing_id', $id)->first();
            if ($existing) {
                $existing->delete();

                return;
            }
            $this->available()->findOrFail($id);
            $favorite = Favorite::create(['user_id' => $user->id, 'listing_id' => $id]);
            $this->journal->record($user->id, 'favorite.created', $favorite);
        });
    }

    public function available()
    {
        return Listing::where('status', 'published')->where('moderated', false)->whereHas('room', fn ($q) => $q->where('status', 'vacant')->whereDoesntHave('rentals', fn ($r) => $r->whereIn('status', ['active', 'move_out_pending']))
            ->whereHas('property.workspace', function ($w) {
                $cloud = app(\App\Modules\Subscription\PropertyCloud::class);
                $cloud->eligible($w);
                $w->where('workspaces.lifecycle', 'active');
                if ($cloud->ready()) $w->whereColumn('workspaces.cloud_epoch', 'listings.cloud_epoch');
            }));
    }

    public function save(User $user, int $roomId, array $data, ?int $id = null): Listing
    {
        $status = $data['status'] ?? 'draft';
        $permission = $id ? 'listing.update' : 'listing.create';
        $room = $this->scope->managedRoom($user, $roomId, $permission);
        $existing = $id ? $room->listings()->findOrFail($id) : null;
        if ($status !== ($existing?->status ?? 'draft')) {
            if (in_array($status, ['published', 'pending_review'], true)) {
                $this->scope->managedRoom($user, $roomId, 'listing.publish');
            }
            if (in_array($status, ['paused', 'closed'], true)) {
                $this->scope->managedRoom($user, $roomId, 'listing.pause');
            }
        }

        return $this->persist($user, $room, $data, $id, $permission);
    }

    public function status(User $user, int $id, string $status): Listing
    {
        abort_unless(in_array($status, ['published', 'paused', 'closed'], true), 422);
        $listing = Listing::findOrFail($id);
        $room = $this->scope->managedRoom($user, $listing->room_id, $status === 'published' ? 'listing.publish' : 'listing.pause');

        return $this->persist($user, $room, ['status' => $status], $id, $status === 'published' ? 'listing.publish' : 'listing.pause');
    }

    private function persist(User $user, Room $room, array $data, ?int $id, string $permission): Listing
    {
        $permissions = [$permission];
        $authority = function () use ($user, $room, &$permissions) {
            foreach (array_unique($permissions) as $required) {
                $current = $this->scope->managedRoom($user, $room->id, $required);
                abort_unless($current->property->workspace_id === $room->property->workspace_id, 409);
            }
        };

        return app(\App\Modules\Subscription\PropertyCloud::class)->transaction($room->property->workspace_id, function () use ($user, $room, $data, $id, &$permissions, $authority) {
            if (! $id || in_array($data['status'] ?? null, ['published', 'pending_review'], true)) app(\App\Modules\Workspace\WorkspaceLifecycle::class)->requireAdmission($room->property->workspace);
            $files = $data['gallery'] ?? [];
            $remove = $data['remove_images'] ?? [];
            unset($data['gallery'], $data['remove_images']);
            $room = $room->newQuery()->lockForUpdate()->findOrFail($room->id);
            $listing = $id ? $room->listings()->findOrFail($id) : new Listing(['room_id' => $room->id]);
            if (isset($data['status']) && $data['status'] !== ($listing->status ?? 'draft')) {
                if (in_array($data['status'], ['published', 'pending_review'], true)) $permissions[] = 'listing.publish';
                if (in_array($data['status'], ['paused', 'closed'], true)) $permissions[] = 'listing.pause';
                $authority();
            }
            if ($listing->exists && $listing->published_at && ($data['status'] ?? null) === 'draft') {
                throw ValidationException::withMessages(['status' => 'Tin đã công khai có thể tạm dừng hoặc kết thúc, không chuyển lại thành bản nháp.']);
            }
            if (in_array($data['status'] ?? null, ['published', 'pending_review'], true)) {
                if ($room->status !== 'vacant' || $listing->moderated || $room->rentals()->whereIn('status', ['active', 'move_out_pending'])->exists()) {
                    throw ValidationException::withMessages(['status' => 'Phòng hoặc tin chưa đủ điều kiện công khai.']);
                }
                $data['published_at'] = now();
                if ($room->listings()->where('status', 'published')->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                    throw ValidationException::withMessages(['status' => 'Phòng đã có tin đang công khai. Hãy cập nhật hoặc tạm dừng tin đó trước.']);
                }
                if (config('housing.listing_review_required')) {
                    $data['status'] = 'pending_review';
                }
                if ($data['status'] === 'pending_review') {
                    unset($data['published_at']);
                }
            }
            if (! empty($data['remove_cover'])) {
                $data['cover_path'] = null;
            }
            unset($data['remove_cover']);
            if (($data['cover'] ?? null) instanceof UploadedFile) {
                $data['cover_path'] = $data['cover']->store('listing-covers', 'local');
            }
            unset($data['cover']);
            $listing->fill(HousingImages::normalize($data))->save();
            app(HousingMedia::class)->save($listing, $files, $remove);
            if (in_array($listing->status, ['published', 'pending_review'], true)) {
                $this->snapshot($user, $listing);
            }
            $this->journal->record($user->id, 'listing.saved', $listing, $room->property->workspace_id);

            return $listing;
        }, $authority);
    }

    private function snapshot(User $actor, Listing $listing): void
    {
        $snapshot = ['title' => $listing->title, 'description' => $listing->description, 'status' => $listing->status, 'room' => $listing->room->only(['rent', 'deposit', 'area', 'capacity', 'amenity_ids', 'custom_amenities', 'amenities', 'rules', 'available_from']), 'images' => HousingImages::for($listing), 'captured_at' => now()->toIso8601String()];
        $version = $listing->content_version + 1;
        $workspace = app(\App\Modules\Subscription\PropertyCloud::class)->requireRoom($listing->room_id);
        $stamp = app(\App\Modules\Subscription\PropertyCloud::class)->snapshot($workspace);
        $listing->update(['published_snapshot' => $snapshot, 'content_version' => $version, 'cloud_epoch' => $stamp['epoch']]);
        DB::table('listing_versions')->insert(['listing_id' => $listing->id, 'version' => $version, 'actor_id' => $actor->id, 'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function review(User $admin, int $id, string $decision, string $reason): void
    {
        abort_unless(app(Authorization::class)->global($admin, 'listing.moderate'), 403);
        abort_unless(in_array($decision, ['approve', 'reject'], true) && trim($reason) !== '', 422);
        DB::transaction(function () use ($admin, $id, $decision, $reason) {
            $candidate = Listing::findOrFail($id);
            $room = Room::whereKey($candidate->room_id)->lockForUpdate()->firstOrFail();
            $listing = Listing::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($listing->status === 'pending_review' || $listing->moderated, 409, 'Tin không còn chờ xử lý.');
            if ($decision === 'approve') {
                $workspace = $room->property->workspace;
                abort_unless(in_array($workspace->status, ['active', 'trial'], true) && $workspace->expires_at->isFuture() && $room->status === 'vacant' && ! $room->rentals()->whereIn('status', ['active', 'move_out_pending'])->exists(), 409, 'Phòng hoặc gói hiện không đủ điều kiện công khai.');
                abort_if($room->listings()->where('status', 'published')->where('id', '!=', $id)->exists(), 409, 'Phòng đã có tin đang công khai.');
                $listing->update(['status' => 'published', 'moderated' => false, 'published_at' => now()]);
                $this->snapshot($admin, $listing);
            } else {
                $listing->update(['status' => 'paused', 'moderated' => true]);
            }
            $this->journal->record($admin->id, 'listing.reviewed', $listing, null, ['decision' => $decision, 'reason' => $reason]);
            $this->journal->notify($room->property->workspace->owner_id, 'Tin cho thuê có kết quả kiểm duyệt', $reason);
        });
    }
}
