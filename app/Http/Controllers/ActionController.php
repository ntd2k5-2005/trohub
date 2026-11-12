<?php

namespace App\Http\Controllers;

use App\Modules\AccessControl\ActionAuthorizer;
use App\Modules\AccessControl\AssignmentAdministration;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\Admin\GovernanceController;
use App\Modules\Admin\RuntimeSettings;
use App\Modules\Billing\BillingService;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\PaymentRequestService;
use App\Modules\Billing\ReceivingAccountService;
use App\Modules\Connection\ConnectionService;
use App\Modules\Connection\Models\Connection;
use App\Modules\DataPlatform\DataStoreHealth;
use App\Modules\DataPlatform\Journal;
use App\Modules\DataPlatform\ProjectionManager;
use App\Modules\Identity\AuthController;
use App\Modules\IoT\IoTService;
use App\Modules\IoT\Models\Device;
use App\Modules\IoT\Models\SafetyEvent;
use App\Modules\Listing\ListingService;
use App\Modules\Maintenance\MaintenanceService;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use App\Modules\Notification\NotificationService;
use App\Modules\Property\Models\Property;
use App\Modules\Property\Models\Room;
use App\Modules\Property\PropertyService;
use App\Modules\Rental\Models\DepositRefundDecision;
use App\Modules\Rental\Models\Rental;
use App\Modules\Rental\Models\RentalRequest;
use App\Modules\Rental\Models\RoomReservation;
use App\Modules\Rental\RefundService;
use App\Modules\Rental\RentalPolicy;
use App\Modules\Rental\RentalRequestService;
use App\Modules\Rental\RentalService;
use App\Modules\Subscription\Models\SubscriptionOrder;
use App\Modules\Subscription\SubscriptionService;
use App\Modules\Workspace\InvitationService;
use App\Modules\Workspace\WorkspaceGroups;
use App\Modules\Workspace\WorkspaceService;
use App\Modules\Workspace\Models\Workspace;
use App\Support\ScopedRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ActionController extends Controller
{
    public function __invoke(Request $r, string $action)
    {
        app(ActionAuthorizer::class)->request($r, $action);
        $u = $r->user();
        $w = $r->attributes->get('workspace');
        $id = (int) $r->input('id');
        $message = 'Đã lưu thay đổi.';
        if (in_array($action, ['property.save', 'room.save', 'listing.save'], true)) {
            $r->validate(['gallery' => 'nullable|array|max:'.config('housing.max_images'), 'gallery.*' => 'image|mimes:jpg,jpeg,png,webp|max:4096', 'remove_images' => 'nullable|array', 'remove_images.*' => 'uuid|distinct']);
        }
        if ($r->has('_groups_present')) {
            $r->merge(['access_group_ids' => $r->input('access_group_ids', [])]);
        }
        if (in_array($action, ['member.invite', 'member.update', 'workspace.group.save'], true)) {
            $r->merge(['permissions' => $r->input('permissions', [])]);
        }
        if (in_array($action, ['member.invite', 'member.update'], true) && $r->has('scope_mode')) {
            $r->validate(['scope_mode' => 'required|in:all,selected', 'property_ids' => $r->input('scope_mode') === 'selected' ? 'required|array|min:1' : 'nullable|array']);
            if ($r->input('scope_mode') === 'all') {
                $r->merge(['property_ids' => []]);
            }
        }
        switch ($action) {
            case 'listing.review':
                $data = $r->validate(['decision' => 'required|in:approve,reject', 'reason' => 'required|string|max:2000']);
                app(ListingService::class)->review($u, $id, $data['decision'], $data['reason']);
                break;
            case 'data.health':
                app(DataStoreHealth::class)->check();
                $message = 'Đã kiểm tra kết nối. Xem kết quả và thời điểm kiểm tra của từng dịch vụ bên dưới.';
                break;
            case 'refund.propose':
                $data = $r->validate(['deduction' => 'required|integer|min:0|max:1000000000', 'reason' => 'required|string|max:2000']);
                app(RefundService::class)->propose($u, $w, Rental::findOrFail($id), (int) $data['deduction'], $data['reason']);
                break;
            case 'refund.respond':
                $data = $r->validate(['decision' => 'required|in:accept,dispute', 'note' => 'nullable|string|max:2000']);
                app(RefundService::class)->respond($u, DepositRefundDecision::findOrFail($id), $data['decision'] === 'accept', $data['note'] ?? null);
                break;
            case 'refund.paid':
                $data = $r->validate(['reference' => 'required|string|max:100', 'confirm' => 'accepted']);
                app(RefundService::class)->paid($u, $w, DepositRefundDecision::findOrFail($id), $data['reference']);
                break;
            case 'rental_request.submit':
                $data = $r->validate(['listing_id' => 'required|integer|min:1']);
                app(RentalRequestService::class)->submit($u, $data['listing_id'], $r->all());

                return redirect()->route('ui.show', ['slug' => 'rentals', 'context' => 'personal'])->with('status', 'Đã gửi yêu cầu thuê. Chủ trọ sẽ xem xét điều kiện và phản hồi.');
            case 'rental_request.decide':
                $data = $r->validate(['decision' => 'required|in:accept,counter,reject', 'version' => 'required|integer|min:1']);
                app(RentalRequestService::class)->decide($u, $w, RentalRequest::findOrFail($id), $data['decision'], (int) $data['version'], $r->all());
                break;
            case 'rental_request.accept_offer':
                $data = $r->validate(['version' => 'required|integer|min:1', 'accept_terms' => 'accepted']);
                app(RentalRequestService::class)->acceptOffer($u, RentalRequest::findOrFail($id), (int) $data['version']);
                break;
            case 'rental_request.cancel':
                $data = $r->validate(['reason' => 'required|string|max:2000']);
                app(RentalRequestService::class)->cancel($u, RentalRequest::findOrFail($id), $data['reason']);
                break;
            case 'reservation.action':
                $data = $r->validate(['decision' => 'required|in:extend,release,retain,waive', 'version' => 'required|integer|min:1']);
                app(RentalRequestService::class)->reservationAction($u, $w, RoomReservation::findOrFail($id), $data['decision'], (int) $data['version'], $r->all());
                break;
            case 'rental.policy':
                $data = $r->validate(['level' => 'required|in:workspace,property,room', 'partial_deposit' => 'required|boolean']);
                $model = match ($data['level']) {
                    'workspace' => $w,
                    'property' => $w->properties()->findOrFail($r->integer('property_id')),
                    'room' => Room::whereHas('property', fn ($q) => $q->where('workspace_id', $w->id))->findOrFail($r->integer('room_id')),
                };
                $policy = app(RentalPolicy::class)->validate($r->all());
                DB::transaction(function () use ($model, $policy, $u, $w) {
                    $model->update(['rental_policy' => $policy]);
                    app(Journal::class)->record($u->id, 'rental.policy_changed', $model, $w->id, $policy);
                });
                break;
            case 'platform.bank.save':
                app(\App\Modules\Billing\PlatformBankService::class)->save($u,$r->all(),$id?:null);break;
            case 'platform.bank.adopt':
                $r->validate(['confirm'=>'accepted']);app(\App\Modules\Billing\PlatformBankService::class)->adoptConfigured($u);break;
            case 'platform.bank.default': case 'platform.bank.disable': case 'platform.bank.delete':
                $data=$r->validate(['version'=>'required|integer|min:1','confirm'=>'accepted']);
                app(\App\Modules\Billing\PlatformBankService::class)->change($u,$id,(int)$data['version'],substr($action,strlen('platform.bank.')));break;
            case 'bank.save':
                abort_unless($w, 403);
                app(ReceivingAccountService::class)->save($u, $w, $r->all(), $id ?: null);
                break;
            case 'bank.default':
                abort_unless($w, 403);
                $data = $r->validate(['property_id' => 'nullable|integer|min:1', 'room_id' => 'nullable|integer|min:1']);
                app(ReceivingAccountService::class)->setDefault($u, $w, $id, ! empty($data['property_id']) ? (int) $data['property_id'] : null, ! empty($data['room_id']) ? (int) $data['room_id'] : null);
                break;
            case 'bank.disable':
                abort_unless($w, 403);
                $data = $r->validate(['version' => 'required|integer|min:1', 'confirm' => 'accepted']);
                app(ReceivingAccountService::class)->disable($u, $w, $id, (int) $data['version']);
                break;
            case 'payment_request.issue':
                abort_unless($w, 403);
                $data = $r->validate(['kind' => 'required|in:invoice,deposit']);
                app(PaymentRequestService::class)->issue($u, $w, $data['kind'], $id);
                $message = 'Đã phát hành thông tin chuyển khoản. Khoản thu chưa được xác nhận nhận tiền.';
                break;
            case 'group.assignment.save':
                $data = $r->validate(['user_id' => 'required|integer|exists:users,id', 'access_group_id' => 'required|integer|exists:access_groups,id', 'target_workspace_id' => 'nullable|integer|exists:workspaces,id', 'property_id' => 'nullable|integer|exists:properties,id', 'room_id' => 'nullable|integer|exists:rooms,id', 'valid_from' => 'required|date', 'valid_until' => 'nullable|date|after:valid_from']);
                abort_if($w && (int) ($data['target_workspace_id'] ?? 0) !== $w->id, 403);
                app(AssignmentAdministration::class)->save($u, $data, $id ?: null);
                break;
            case 'group.assignment.revoke':
                $record = GroupAssignment::findOrFail($id);
                abort_if($w && $record->workspace_id !== $w->id, 403);
                app(AssignmentAdministration::class)->revoke($u, $id);
                break;
            case 'system.settings':
                app(RuntimeSettings::class)->save($u, $r->all());
                break;
            case 'listing.status':
                $data = $r->validate(['status' => 'required|in:published,paused,closed']);
                app(ListingService::class)->status($u, $id, $data['status']);
                break;
            case 'permission.change.approve': case 'permission.change.apply':
                abort_if($w, 403);
                $service = app(\App\Modules\AccessControl\PermissionPolicyChanges::class);
                if ($action === 'permission.change.approve') $service->approve($u, $id);
                else $service->apply($u, $id);
                break;
            case 'group.change.approve': case 'group.change.apply': case 'group.emergency_disable':
                $group = $action === 'group.emergency_disable' ? \App\Modules\AccessControl\Models\AccessGroup::findOrFail($id) : \App\Modules\AccessControl\Models\GroupChange::findOrFail($id)->group;
                abort_unless($group->workspace_id === $w?->id, 403);
                $service = app(\App\Modules\AccessControl\GroupChangeService::class);
                if ($action === 'group.change.approve') $service->approve($u, $id);
                elseif ($action === 'group.change.apply') $service->apply($u, $id);
                else $service->disable($u, $id, $r->validate(['reason' => 'required|string|max:1000'])['reason']);
                break;
            case 'workspace.group.save':
                abort_unless($w, 403);
                $data = $r->validate(['name' => 'required|string|max:100', 'status' => 'sometimes|in:active,retired,disabled', 'permissions' => 'present|array', 'permissions.*' => 'string|distinct|exists:permissions,code', 'active' => 'sometimes|boolean']);
                app(WorkspaceGroups::class)->save($u, $w, $id, $data);
                if ($id) $message = 'Đã tạo bản xem trước thay đổi. Hãy kiểm tra tác động và phê duyệt trước khi áp dụng.';
                break;
            case 'data.project':
                $data = $r->validate(['limit' => 'required|integer|min:1|max:100']);
                $count = app(ProjectionManager::class)->drain($data['limit']);

                return back()->with('status', 'Đã đồng bộ '.$count.' sự kiện. Các sự kiện gặp lỗi được giữ để thử lại.');
            case 'data.replay':
                $data = $r->validate(['target_workspace_id' => 'required|integer|min:0', 'from' => 'required|date', 'until' => 'required|date|after_or_equal:from']);
                $count = app(ProjectionManager::class)->replay($data['target_workspace_id'], $data['from'], $data['until']);

                return back()->with('status', 'Đã đưa '.$count.' sự kiện vào hàng chờ đồng bộ.');
            case 'profile.password':
                return app(AuthController::class)->changePassword($r);
            case 'invitation.cancel': case 'invitation.renew':
                abort_unless($w, 403);
                $token = app(InvitationService::class)->change($u, $w, $id, $action === 'invitation.renew');
                $response = back()->with('status', $token ? 'Đã tạo liên kết mời mới.' : 'Đã hủy lời mời.');

                return $token ? $response->with('invitation_url', route('invitation.accept', ['token' => $token])) : $response;
            case 'subscription.start':
                $data = $r->validate(['name' => 'required|string|max:120', 'plan' => 'required|in:monthly,yearly', 'reference' => 'required|string|max:100|unique:subscription_orders']);
                $workspace = app(SubscriptionService::class)->startPaid($u, $data);

                return redirect()->route('ui.show', ['slug' => 'subscription', 'context' => 'workspace', 'workspace_id' => $workspace->id])->with('status', 'Đã gửi đăng ký. Gói được kích hoạt sau khi đối soát thanh toán.');
            case 'maintenance.comment':
                $data = $r->validate(['note' => 'required|string|max:2000']);
                app(MaintenanceService::class)->comment($u, MaintenanceRequest::findOrFail($id), $data['note']);
                break;
            case 'workspace.save':
                abort_unless($w, 403);
                $data = $r->validate(['name' => 'required|string|max:120']);
                app(WorkspaceService::class)->rename($u, $w, $data['name']);
                break;
            case 'member.update':
                abort_unless($w, 403);
                $data = $r->validate(['access_group_id' => [Rule::requiredIf(! $r->has('access_group_ids')), 'nullable', 'integer', 'exists:access_groups,id'], 'access_group_ids' => 'sometimes|array', 'access_group_ids.*' => 'integer|distinct|exists:access_groups,id', 'room_ids' => 'nullable|array', 'room_ids.*' => 'integer|distinct|exists:rooms,id', 'permissions' => 'sometimes|array', 'permissions.*' => 'required|string|distinct|exists:permissions,code', 'property_ids' => 'nullable|array', 'property_ids.*' => 'integer', 'valid_until' => 'nullable|date|after:now']);
                app(WorkspaceService::class)->updateMembership($u, $w, $id, $data);
                break;
            case 'property.save':
                abort_unless($w, 403);
                $r->validate(['gallery' => 'nullable|array|max:'.config('housing.max_images'), 'gallery.*' => 'image|mimes:jpg,jpeg,png,webp|max:4096', 'remove_images' => 'nullable|array', 'remove_images.*' => 'uuid|distinct']);
                $data = $r->validate(['name' => 'required|string|max:120', 'address' => 'required|string|max:255', 'district' => 'required|string|max:120', 'public_address' => 'nullable|string|max:255', 'amenity_ids' => 'nullable|array', 'custom_amenities' => 'nullable|array', '_amenities_present' => 'nullable|boolean', 'description' => 'nullable|string|max:5000', 'image_urls' => 'nullable|string|max:25000']);
                app(PropertyService::class)->saveProperty($u, $w, $data + $r->only(['gallery', 'remove_images']), $id ?: null);
                break;
            case 'room.save':
                $data = $r->validate(['property_id' => 'required|integer', 'name' => 'required|string|max:80', 'area' => 'required|numeric|min:1|max:10000', 'rent' => 'required|integer|min:0|max:1000000000', 'deposit' => 'required|integer|min:0|max:1000000000', 'capacity' => 'required|integer|min:1|max:100', 'amenity_ids' => 'nullable|array', 'custom_amenities' => 'nullable|array', '_amenities_present' => 'nullable|boolean', 'length' => 'nullable|numeric|gt:0|max:1000', 'width' => 'nullable|numeric|gt:0|max:1000', 'floor' => 'nullable|string|max:30', 'room_type' => \App\Modules\Property\HousingTypes::rules(), 'available_from' => 'nullable|date', 'amenities' => 'nullable|string|max:3000', 'rules' => 'nullable|string|max:3000', 'image_urls' => 'nullable|string|max:25000']);
                $p = Property::findOrFail($data['property_id']);
                unset($data['property_id']);
                app(PropertyService::class)->saveRoom($u, $p, $data + $r->only(['gallery', 'remove_images']), $id ?: null);
                break;
            case 'room.inactivate':
                $room = app(ScopedRepository::class)->managedRoom($u, $id, 'property.manage');
                app(PropertyService::class)->saveRoom($u, $room->property, ['status' => 'inactive'], $id);
                break;
            case 'listing.save':
                $data = $r->validate(['room_id' => 'required|integer', 'title' => 'required|string|max:180', 'description' => 'required|string|max:10000', 'status' => 'required|in:draft,published,pending_review,paused,closed', 'cover' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096', 'remove_cover' => 'nullable|boolean', 'image_urls' => 'nullable|string|max:25000']);
                $roomId = $data['room_id'];
                unset($data['room_id']);
                app(ListingService::class)->save($u, $roomId, $data + $r->only(['gallery', 'remove_images']), $id ?: null);
                break;
            case 'favorite.toggle': app(ListingService::class)->toggleFavorite($u, $id);
                break;
            case 'connection.create':
                $data = $r->validate(['listing_id' => 'required|integer', 'message' => 'required|string|max:2000']);
                app(ConnectionService::class)->create($u, $data['listing_id'], $data['message']);
                break;
            case 'connection.update':
                $data = $r->validate(['status' => 'required|string', 'viewing_at' => 'nullable|date']);
                app(ConnectionService::class)->transition($u, Connection::findOrFail($id), $data['status'], $data['viewing_at'] ?? null);
                break;
            case 'rental.create':
                $data = $r->validate(['room_id' => 'required|integer', 'tenant_email' => 'required|email|max:254', 'tenant_name' => 'required|string|max:100', 'connection_id' => 'nullable|integer', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'rent' => 'required|integer|min:1|max:1000000000', 'deposit' => 'required|integer|min:0|max:1000000000', 'terms' => 'required|string|max:10000']);
                app(RentalService::class)->create($u, $data);
                break;
            case 'rental.reserve':
                app(RentalRequestService::class)->reserveManual($u, $w, Rental::findOrFail($id));
                break;
            case 'rental.invite':
                $token = app(WorkspaceService::class)->inviteTenant($u, Rental::findOrFail($id));

                return back()->with('status', 'Đã tạo lời mời. Người thuê tự đăng ký và liên kết tài khoản bằng email đã khai báo.')->with('invitation_url', route('invitation.accept', ['token' => $token]));
            case 'rental.confirm': case 'rental.activate': case 'rental.deposit': case 'rental.renew': case 'rental.request_move_out': case 'rental.settle': case 'rental.close': case 'rental.occupant': case 'rental.cancel':
                $op = substr($action, 7);
                $rules = match ($op) {
                    'confirm' => ['terms_quote' => 'required|string|size:64', 'confirm_terms' => 'accepted'],
                    'deposit' => ['amount' => 'required|integer|min:1|max:1000000000', 'reference' => 'required|string|min:3|max:100', 'confirm' => 'accepted'],
                    'renew' => ['ends_at' => 'required|date'], 'request_move_out' => ['move_out_at' => 'required|date|after_or_equal:today'], 'settle' => ['deduction' => 'required|integer|min:0', 'reason' => 'nullable|string|max:2000'], 'close' => ['handover_complete' => 'required|accepted'], 'occupant' => ['email' => 'required|email|exists:users,email'], 'cancel' => ['reason' => 'required|string|max:2000'], default => []
                };
                app(RentalService::class)->act($u, Rental::findOrFail($id), $op, $r->validate($rules));
                break;
            case 'service.create':
                $data = $r->validate(['property_id' => 'required|integer', 'name' => 'required|string|max:100', 'kind' => 'required|in:electricity,water,fixed', 'rate' => 'required|integer|min:0|max:1000000000', 'effective_from' => 'required|date']);
                app(BillingService::class)->createRate($u, $data);
                break;
            case 'meter.create':
                $data = $r->validate(['room_id' => 'required|integer', 'service_rate_id' => 'required|integer', 'period' => 'required|date_format:Y-m', 'current' => 'required|numeric|min:0|max:999999999', 'reason' => 'nullable|string|max:2000']);
                app(BillingService::class)->reading($u, $data);
                break;
            case 'invoice.create':
                $data = $r->validate(['rental_id' => 'required|integer', 'period' => 'required|date_format:Y-m', 'due_at' => 'required|date']);
                app(BillingService::class)->generate($u, Rental::findOrFail($data['rental_id']), $data['period'], $data['due_at']);
                break;
            case 'invoice.issue': app(BillingService::class)->issue($u, Invoice::findOrFail($id));
                break;
            case 'invoice.adjust':
                $data = $r->validate(['amount' => 'required|integer|between:-1000000000,1000000000', 'reason' => 'required|string|max:1000']);
                app(BillingService::class)->adjust($u, Invoice::findOrFail($id), $data['amount'], $data['reason']);
                break;
            case 'payment.create':
                $data = $r->validate(['amount' => 'required|integer|min:1|max:1000000000', 'method' => 'required|in:cash,transfer', 'reference' => 'required|string|max:100']);
                app(BillingService::class)->pay($u, Invoice::findOrFail($id), $data);
                break;
            case 'maintenance.create':
                $data = $r->validate(['room_id' => 'required|integer', 'title' => 'required|string|max:180', 'description' => 'required|string|max:10000']);
                app(MaintenanceService::class)->create($u, $data);
                break;
            case 'maintenance.update':
                $data = $r->validate(['status' => 'required|string', 'note' => 'required|string|max:2000']);
                app(MaintenanceService::class)->transition($u, MaintenanceRequest::findOrFail($id), $data['status'], $data['note']);
                break;
            case 'trial.start':
                $data = $r->validate(['name' => 'required|string|max:120']);
                $workspace = app(SubscriptionService::class)->trial($u, $data['name']);

                return redirect()->route('ui.show', ['slug' => 'workspace-dashboard', 'context' => 'workspace', 'workspace_id' => $workspace->id])->with('status', 'Đã tạo không gian quản lý.');
            case 'subscription.order':
                abort_unless($w, 403);
                $data = $r->validate(['plan' => 'required|in:monthly,yearly', 'reference' => 'required|string|max:100|unique:subscription_orders']);
                app(SubscriptionService::class)->order($u, $w, $data);
                $message = 'Đã gửi yêu cầu. Gói được kích hoạt sau khi quản trị viên đối soát thanh toán.';
                break;
            case 'subscription.confirm': app(SubscriptionService::class)->confirm($u, SubscriptionOrder::findOrFail($id));
                break;
            case 'member.invite':
                abort_unless($w, 403);
                $data = $r->validate(['email' => 'required|email', 'access_group_id' => [Rule::requiredIf(! $r->has('access_group_ids')), 'nullable', 'integer', 'exists:access_groups,id'], 'access_group_ids' => 'sometimes|array', 'access_group_ids.*' => 'integer|distinct|exists:access_groups,id', 'room_ids' => 'nullable|array', 'room_ids.*' => 'integer|distinct|exists:rooms,id', 'permissions' => 'sometimes|array', 'permissions.*' => 'required|string|distinct|exists:permissions,code', 'property_ids' => 'nullable|array', 'property_ids.*' => 'integer', 'valid_until' => 'nullable|date|after:now']);
                $token = app(WorkspaceService::class)->invite($u, $w, $data);

                return back()->with('status', 'Đã tạo lời mời. Gửi liên kết này cho người được mời.')->with('invitation_url', route('invitation.accept', ['token' => $token]));
            case 'member.end':
                abort_unless($w, 403);
                app(WorkspaceService::class)->endMembership($u, $w, $id);
                break;
            case 'transfer.submit':
                $data=$r->validate(['email'=>'required|email','reference'=>'required|string|max:100','reason'=>'required|string|min:5|max:2000']);
                app(\App\Modules\Workspace\OwnerTransferService::class)->submit($u,Workspace::findOrFail($id),$data['email'],$data['reference'],$data['reason']);
                break;
            case 'transfer.consent':
                $data=$r->validate(['version'=>'required|integer','password'=>'required|string','confirm'=>'accepted']);app(\App\Modules\Workspace\OwnerTransferService::class)->consent($u,$id,$data['version'],$data['password']);break;
            case 'transfer.evidence':
                $data=$r->validate(['version'=>'required|integer','evidence'=>'required|file|mimes:pdf,jpg,jpeg,png|max:4096']);app(\App\Modules\Workspace\OwnerTransferService::class)->evidence($u,$id,$data['version'],$data['evidence']);break;
            case 'transfer.review':
                $data=$r->validate(['version'=>'required|integer','decision'=>'required|in:review,reject','note'=>'required|string|min:5|max:2000']);app(\App\Modules\Workspace\OwnerTransferService::class)->review($u,$id,$data['version'],$data['decision'],$data['note']);break;
            case 'transfer.cancel':
                $data=$r->validate(['version'=>'required|integer','reason'=>'required|string|min:5|max:2000']);app(\App\Modules\Workspace\OwnerTransferService::class)->cancel($u,$id,$data['version'],$data['reason']);break;
            case 'property.move.propose':
                $data=$r->validate(['target_workspace_id'=>'required|integer','reference'=>'required|string|max:100','reason'=>'required|string|max:2000']);
                app(\App\Modules\Property\PropertyMoveService::class)->propose($u,Property::findOrFail($id),Workspace::findOrFail($data['target_workspace_id']),$data['reference'],$data['reason']);
                $message='Đã kiểm tra điều kiện chuyển nhà. Hãy xem kết quả trước khi xác nhận.';
                break;
            case 'property.move.commit':
                $r->validate(['confirm'=>'accepted']);app(\App\Modules\Property\PropertyMoveService::class)->commit($u,$id);
                $message='Đã chuyển nhà. Hãy kiểm tra khu nhận và phân công lại nhân sự nếu cần.';
                break;
            case 'plan.save':
                $data=$r->all();$data['features']=$r->input('features',[]);
                app(\App\Modules\Subscription\PlanCatalog::class)->save($u,$data);
                break;
            case 'plan.publish':
                $r->validate(['confirm'=>'accepted']);app(\App\Modules\Subscription\PlanCatalog::class)->publish($u,$id);
                break;
            case 'plan.availability':
                $data=$r->validate(['expected'=>'required|string','availability'=>'required|in:public,renewal_only,closed,archived','reason'=>'required|string|min:5|max:2000']);
                app(\App\Modules\Subscription\PlanCatalog::class)->availability($u,$id,$data['expected'],$data['availability'],$data['reason']);
                break;
            case 'plan.quota.assign':
                $data=$r->validate(['owner_id'=>'required|integer','plan_version_id'=>'required|integer','reason'=>'required|string|max:2000','confirm'=>'accepted']);
                app(\App\Modules\Subscription\PlanCatalog::class)->assignLegacyQuota($u,$data['owner_id'],$data['plan_version_id'],$data['reason']);
                break;
            case 'account.trial':
                app(\App\Modules\Subscription\AccountSubscriptionService::class)->trial($u);
                break;
            case 'account.workspace.create':
                $data=$r->validate(['name'=>'required|string|max:120']);
                app(\App\Modules\Subscription\AccountSubscriptionService::class)->createWorkspace($u,$data['name']);
                break;
            case 'account.quote':
                $data=$r->validate(['plan'=>'required|in:monthly,yearly','reference'=>'required|string|max:100']);
                app(\App\Modules\Subscription\AccountSubscriptionService::class)->quote($u,$data['plan'],$data['reference']);
                break;
            case 'account.subscription.confirm':
                $data=$r->validate(['amount'=>'required|integer|min:1','reference'=>'required|string|max:100','note'=>'required|string|min:5|max:2000','confirm'=>'accepted']);
                app(\App\Modules\Subscription\AccountSubscriptionService::class)->confirm($u,$id,$data['amount'],$data['reference'],$data['note']);
                break;
            case 'account.paid.start':
                $data=$r->validate(['plan'=>'required|in:monthly,yearly','reference'=>'required|string|max:100']);
                app(\App\Modules\Subscription\AccountSubscriptionService::class)->openPaid($u,$data['plan'],$data['reference']);
                break;
            case 'account.quote.cancel':
                $data=$r->validate(['reason'=>'required|string|min:5|max:1000','confirm'=>'accepted']);
                app(\App\Modules\Subscription\AccountSubscriptionService::class)->cancelQuote($u,\App\Modules\Subscription\Models\SubscriptionQuote::findOrFail($id),$data['reason']);
                break;
            case 'legacy.subscription.review':
                $data=$r->validate(['owner_id'=>'required|integer|exists:users,id','decision'=>'required|in:retained,unresolved,closed','source_hash'=>'required|string|size:64','note'=>'required|string|min:5|max:4000','confirm'=>'accepted']);
                app(\App\Modules\Subscription\LegacySubscriptionReview::class)->record($u,$data['owner_id'],$data['decision'],$data['source_hash'],$data['note']);
                break;
            case 'account.payment.report':
                $data=$r->validate(['reference'=>'required|string|max:100','note'=>'nullable|string|max:1000','confirm'=>'accepted']);
                app(\App\Modules\Subscription\AccountSubscriptionService::class)->report($u,\App\Modules\Subscription\Models\SubscriptionQuote::findOrFail($id),$data['reference'],$data['note']??null);
                break;
            case 'account.subscription.reverse':
                $data=$r->validate(['evidence'=>'required|string|min:10|max:4000','confirm'=>'accepted']);
                app(\App\Modules\Subscription\AccountSubscriptionService::class)->reverse($u,$id,$data['evidence']);
                break;
            case 'account.restrict':
                $data=$r->validate(['owner_id'=>'required|integer','cause'=>'required|string|max:80','reason'=>'required|string|max:2000']);
                app(\App\Modules\Subscription\AccountSubscriptionService::class)->restrict($u,$data['owner_id'],$data['cause'],$data['reason']);
                break;
            case 'account.release':
                $data=$r->validate(['reason'=>'required|string|max:2000']);
                app(\App\Modules\Subscription\AccountSubscriptionService::class)->release($u,$id,$data['reason']);
                break;
            case 'workspace.lifecycle':
                $data = $r->validate(['state' => 'required|in:active,paused,archived', 'version' => 'required|integer|min:1', 'reason' => 'required|string|max:1000', 'confirm' => 'accepted']);
                app(\App\Modules\Workspace\WorkspaceLifecycle::class)->transition($u, Workspace::findOrFail($id), $data['state'], $data['version'], $data['reason']);
                return redirect()->route('ui.show', ['slug' => 'profile-security', 'context' => 'personal', 'section' => 'access'])->with('status', 'Đã cập nhật trạng thái khu quản lý.');
            case 'workspace.restrict':
                $data = $r->validate(['target_workspace_id' => 'required|integer', 'cause' => 'required|string|max:80', 'reason' => 'required|string|max:2000']);
                app(\App\Modules\Workspace\WorkspaceLifecycle::class)->restrict($u, Workspace::findOrFail($data['target_workspace_id']), $data['cause'], $data['reason']);
                break;
            case 'workspace.release':
                $data = $r->validate(['reason' => 'required|string|max:2000']);
                app(\App\Modules\Workspace\WorkspaceLifecycle::class)->release($u, $id, $data['reason']);
                break;
            case 'asset.retire': case 'asset.restore':
                $data = $r->validate(['asset_type' => 'required|in:property,room', 'version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000', 'confirm' => 'accepted']);
                app(\App\Modules\Property\AssetLifecycle::class)->change($u, $w, $data['asset_type'], $id, $data['version'], $action === 'asset.restore', $data['reason']);
                break;
            case 'workspace.archive':
                $data = $r->validate(['reason' => 'required|string|max:1000', 'confirm' => 'accepted']);
                app(\App\Modules\Workspace\WorkspaceArchive::class)->archive($u, $w, $data['reason']);
                return redirect()->route('ui.show', ['slug' => 'profile-security', 'context' => 'personal', 'section' => 'access'])->with('status', 'Đã lưu trữ khu quản lý và tạm ngừng nhân sự. Dữ liệu và lịch sử được giữ nguyên.');
            case 'work.takeover':
                abort_unless($w, 403);
                app(\App\Modules\Workspace\WorkAssignmentService::class)->takeOver($u, $w, $id);
                $message = 'Đã tiếp nhận công việc bàn giao.';
                break;
            case 'payment.review.claim':
                abort_unless($w,403);
                app(\App\Modules\Billing\PaymentRequestService::class)->beginReview($u,$w,\App\Modules\Billing\Models\PaymentRequest::findOrFail($id));
                $message='Đã tiếp nhận kiểm tra khoản chuyển tiền. Khoản thu chưa được xác nhận.';
                break;
            case 'member.leave':
                $data = $r->validate(['reason' => 'required|string|max:1000', 'confirm' => 'accepted']);
                $member = \App\Modules\Workspace\Models\Membership::findOrFail($id);
                app(\App\Modules\Workspace\MembershipLifecycle::class)->end($u, $member->workspace, $id, $data['reason'], true);
                $message = 'Đã rời khu quản lý. Công việc còn mở đã được chuyển sang bàn giao.';
                break;
            case 'member.activate':
                abort_unless($w, 403);
                app(\App\Modules\Workspace\MembershipLifecycle::class)->activate($u, $w, $id);
                break;
            case 'member.suspend':
                abort_unless($w, 403);
                $data = $r->validate(['reason' => 'required|string|max:2000']);
                app(\App\Modules\Workspace\MembershipLifecycle::class)->suspend($u, $w, $id, $data['reason']);
                break;
            case 'grant.create':
                abort_unless($w, 403);
                $data = $r->validate(['user_id' => 'required|integer', 'permission' => 'required_without:permissions|string|exists:permissions,code', 'permissions' => 'required_without:permission|array|min:1', 'permissions.*' => 'required|string|distinct|exists:permissions,code', 'property_id' => 'nullable|integer', 'room_id' => 'nullable|integer|exists:rooms,id', 'valid_from' => 'required|date', 'valid_until' => 'required|date|after:valid_from', 'reason' => 'required|string|max:1000']);
                DB::transaction(function () use ($u, $w, $data) {
                    $codes = $data['permissions'] ?? [$data['permission']];
                    unset($data['permissions']);
                    foreach ($codes as $code) {
                        app(WorkspaceService::class)->grant($u, $w, array_replace($data, ['permission' => $code]));
                    }
                });
                break;
            case 'grant.revoke': app(WorkspaceService::class)->revokeGrant($u, $id);
                break;
            case 'iot.register':
                abort_unless(config('features.iot'), 404);
                $data = $r->validate(['room_id' => 'required|integer', 'name' => 'required|string|max:100', 'serial' => 'required|string|max:100|unique:devices']);
                $token = app(IoTService::class)->register($u, $data['room_id'], $data['name'], $data['serial']);

                return back()->with('status', 'Đã thêm thiết bị. Lưu khóa kết nối dưới đây; khóa chỉ hiển thị một lần.')->with('device_token', $token);
            case 'iot.move':
                abort_unless(config('features.iot'), 404);
                $data = $r->validate(['room_id' => 'required|integer']);
                app(IoTService::class)->move($u, Device::findOrFail($id), $data['room_id']);
                break;
            case 'iot.ack':
                abort_unless(config('features.iot'), 404);
                $data = $r->validate(['status' => 'required|in:acknowledged,closed']);
                app(IoTService::class)->acknowledge($u, SafetyEvent::findOrFail($id), $data['status']);
                break;
            case 'iot.configure':
                abort_unless(config('features.iot'), 404);
                $data = $r->validate(['heartbeat' => 'required|integer|between:10,300']);
                app(IoTService::class)->configure($u, Device::findOrFail($id), $data['heartbeat']);
                break;
            case 'iot.maintenance':
                abort_unless(config('features.iot'), 404);
                app(IoTService::class)->maintenance($u, SafetyEvent::findOrFail($id));
                break;
            case 'notification.read': app(NotificationService::class)->markRead($u, $id);
                break;
            case 'roommate.profile.save':
                app(\App\Modules\Roommate\RoommateProfileService::class)->save($u,$r->all());
                $message='Đã cập nhật hồ sơ sở thích riêng của bạn.';
                break;
            case 'roommate.profile.delete':
                $r->validate(['confirm'=>'accepted']);
                app(\App\Modules\Roommate\RoommateProfileService::class)->delete($u);
                $message='Đã xóa hồ sơ sở thích; nhu cầu ở ghép đang đăng được giữ nguyên.';
                break;
            case 'review.details.save':
                app(\App\Modules\Trust\ReviewDetailsService::class)->save($u,\App\Modules\Trust\Models\Review::findOrFail($id),$r->all());
                $message='Đã cập nhật đánh giá bổ sung.';
                break;
            case 'review.details.delete':
                $r->validate(['confirm'=>'accepted']);
                app(\App\Modules\Trust\ReviewDetailsService::class)->delete($u,\App\Modules\Trust\Models\Review::findOrFail($id));
                $message='Đã xóa đánh giá bổ sung; số sao và nhận xét cơ bản được giữ nguyên.';
                break;
            default: return app(GovernanceController::class)->action($r, $action);
        }

        return \App\Support\UiRedirect::success($r,$message);
    }
}
