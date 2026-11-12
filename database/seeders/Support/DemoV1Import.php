<?php

namespace Database\Seeders\Support;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * A bounded historical import, not an alternate business API. All writes belong
 * to one dataset, in one transaction. Existing users/groups are never updated.
 * Financial records below are explicitly synthetic cash/manual history.
 */
final class DemoV1Import
{
    public const DATASET = 'TROHUB_DEMO_V1';
    private CarbonImmutable $date;
    private array $columns = [], $ids = [], $rows = [], $events = [];

    public function run(): void
    {
        $sqlite = config('database.default') === 'sqlite' && (config('database.connections.sqlite.database') === ':memory:'
            || str_starts_with(str_replace('\\', '/', (string) config('database.connections.sqlite.database')), str_replace('\\', '/', dirname(__DIR__, 3).'/kho/demo-v1/')));
        $mysqlSandbox = app()->environment('testing') && config('database.default') === 'mysql'
            && config('database.connections.mysql.host') === '127.0.0.1' && (int) config('database.connections.mysql.port') === 3319
            && in_array(config('database.connections.mysql.database'), ['trohub_demo_v1','trohub_demo_v1_upgrade'], true);
        if (! $sqlite && ! $mysqlSandbox && getenv('TROHUB_DEMO_APPLY_APPROVED') !== self::DATASET) {
            throw new RuntimeException('Working database apply needs explicit manager approval and backup.');
        }
        if (! app()->environment(['local', 'testing']) || ! Schema::hasTable('demo_dataset_records')) {
            throw new RuntimeException('Demo import requires local/testing and the additive registry migration.');
        }
        $this->date = CarbonImmutable::parse(config('demo_v1.reference_date'))->startOfDay();
        $password = config('demo_v1.password');
        if (! is_string($password) || strlen($password) < 8) throw new RuntimeException('Set TROHUB_DEMO_PASSWORD in the process environment.');
        DB::transaction(function () use ($password): void {
            DB::table('demo_dataset_runs')->insertOrIgnore(['dataset' => self::DATASET, 'reference_date' => $this->date->toDateString(), 'status' => 'preparing', 'created_at' => now(), 'updated_at' => now()]);
            $run = DB::table('demo_dataset_runs')->where('dataset', self::DATASET)->lockForUpdate()->first();
            if ($run->reference_date !== $this->date->toDateString()) throw new RuntimeException('Reference date is immutable for an existing dataset.');
            if ($run->status === 'complete') {
                $check = (new DemoV1Validator)->validate($password);
                if (! $check['passed']) throw new RuntimeException('Existing dataset failed validation; refusing to silently repair or duplicate it.');
                echo "Existing dataset validated; no rows changed.\n";
                return;
            }
            if (DB::table('demo_dataset_records')->where('dataset', self::DATASET)->exists()) throw new RuntimeException('Partial dataset needs review before resume.');
            if (DB::table('users')->whereIn('email', array_map(fn ($i) => "user{$i}.demo@example.com", range(1, 300)))->exists()) throw new RuntimeException('Unowned demo email collision; existing account preserved.');
            $this->users($password);
            $this->housing();
            $this->rentals();
            $this->billing();
            $this->connectionsAndChat();
            $this->community();
            $this->staff();
            $this->safety();
            $this->subscriptions();
            $this->notifications();
            $this->history();
            $this->audit();
            $check = (new DemoV1Validator)->validate($password);
            if (! $check['passed']) throw new RuntimeException('Import rolled back: '.json_encode(array_filter($check['checks'], fn ($c) => ! $c['passed']), JSON_UNESCAPED_UNICODE));
            DB::table('demo_dataset_runs')->where('dataset', self::DATASET)->update(['status' => 'complete', 'counts' => json_encode($check['counts']), 'updated_at' => now()]);
            echo "TROHUB_DEMO_V1 imported and validated atomically.\n";
        });
    }

    private function time(int $days = 0): string { return $this->date->addDays($days)->setTime(10, 0)->toDateTimeString(); }
    private function put(string $table, string|int $key, array $data): int|string
    {
        $cols = $this->columns[$table] ??= Schema::getColumnListing($table);
        foreach ($data as $name => $value) {
            if (! in_array($name, $cols, true)) throw new RuntimeException("Unsupported {$table}.{$name}; do not discard business fields.");
            if (is_array($value)) $data[$name] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        foreach (['created_at', 'updated_at'] as $col) if (in_array($col, $cols, true)) $data[$col] ??= $this->time($col==='updated_at'?0:-300);
        $id = $data['id'] ?? null;
        if ($id !== null) DB::table($table)->insert($data);
        else $id = DB::table($table)->insertGetId($data);
        DB::table('demo_dataset_records')->insert(['dataset' => self::DATASET, 'table_name' => $table, 'demo_key' => (string) $key, 'record_id' => (string) $id, 'created_at' => now()]);
        $this->ids[$table][$key] = $id;
        $this->rows[$table][$key] = $data + ['id' => $id];
        return $id;
    }
    private function id(string $table, int|string $key): int|string { return $this->ids[$table][$key]; }
    private function event(string $table, int|string $key, string $action, ?int $actor, ?int $workspace, ?string $at = null): void
    {
        $this->events[] = ['table' => $table, 'key' => $key, 'action' => $action, 'actor' => $actor, 'workspace' => $workspace, 'at' => $at ?? $this->rows[$table][$key]['created_at']];
    }
    private function group(string $key): AccessGroup
    {
        return AccessGroup::where('key', config('authorization.'.$key))->whereNull('workspace_id')->firstOrFail();
    }
    private function users(string $password): void
    {
        $ordinary = $this->group('registration_group'); $tenant = $this->group('tenant_group');
        $last = ['Nguyễn', 'Trần', 'Lê', 'Phạm', 'Hoàng', 'Vũ', 'Đặng', 'Bùi', 'Đỗ', 'Hồ'];
        $middle = ['Minh', 'Thanh', 'Ngọc', 'Hoài', 'Quỳnh', 'Gia'];
        $first = ['An', 'Bình', 'Chi', 'Dũng', 'Giang', 'Hà', 'Hân', 'Khang', 'Linh', 'Nam', 'Phúc', 'Thảo', 'Trang', 'Tú', 'Uyên'];
        for ($i = 1; $i <= 300; $i++) {
            $id = $this->put('users', $i, ['name' => $last[($i-1)%10].' '.$middle[intdiv($i-1, 10)%6].' '.$first[intdiv($i-1, 20)%15], 'email' => "user{$i}.demo@example.com", 'password' => Hash::make($password), 'created_at' => $this->time(-1100), 'email_verified_at' => $this->time(-1100), 'email_activation_required' => false, 'password_login_enabled' => true, 'access_group_id' => $ordinary->id, 'status' => $i > 295 ? 'locked' : 'active']);
            $this->put('group_assignments', 'ordinary-'.$i, ['user_id' => $id, 'access_group_id' => $ordinary->id, 'scope_key' => 'personal', 'origin' => 'registration']);
            if ($i >= 36 && $i <= 225) $this->put('group_assignments', 'tenant-'.$i, ['user_id' => $id, 'access_group_id' => $tenant->id, 'scope_key' => 'personal', 'origin' => 'rental']);
        }
    }
    private function privateGroup(int $workspaceKey, AccessGroup $template): int
    {
        $key = $workspaceKey.'-'.$template->id;
        if (isset($this->ids['access_groups'][$key])) return $this->id('access_groups', $key);
        return $this->put('access_groups', $key, ['name' => $template->name, 'scope' => 'workspace', 'permissions' => $template->permissions, 'active' => true, 'status' => 'active', 'workspace_id' => $this->id('workspaces', $workspaceKey), 'source_template_id' => $template->id]);
    }
    private function housing(): void
    {
        $areas = ['Thủ Đức', 'Bình Thạnh', 'Gò Vấp', 'Tân Bình', 'Quận 7', 'Bình Tân', 'Quận 12'];
        $names = ['An Nhiên', 'Bình Minh', 'Hoa Nắng', 'Mộc Lan', 'Sông Xanh', 'Hướng Dương', 'Phố Nhỏ'];
        $types = array_keys(config('housing.room_types'));
        for ($i = 1; $i <= 35; $i++) {
            $w = $this->put('workspaces', $i, ['owner_id' => $this->id('users', $i), 'name' => 'Khu trọ '.$names[($i-1)%7].' '.(intdiv($i-1, 7)+1), 'plan' => 'yearly', 'status' => 'active', 'lifecycle' => 'active', 'expires_at' => $this->date->addYear()->toDateTimeString(), 'provenance' => 'synthetic', 'created_at' => $this->time(-1099), 'rental_policy' => ['hold_hours' => 48, 'partial_deposit' => $i%5===0]]);
            $g = $this->privateGroup($i, $this->group('owner_group'));
            $this->put('group_assignments', 'owner-'.$i, ['user_id' => $this->id('users', $i), 'access_group_id' => $g, 'workspace_id' => $w, 'scope_key' => 'workspace:'.$w, 'origin' => 'ownership']);
        }
        for ($p = 1; $p <= 70; $p++) {
            $w = intdiv($p-1, 2)+1; $area = $areas[($p-1)%7];
            $this->put('properties', $p, ['workspace_id' => $this->id('workspaces', $w), 'name' => 'Nhà '.$names[($p-1)%7].' '.$p, 'address' => 'Lô '.$p.', đường Nội Khu, '.$area.' (địa chỉ minh họa)', 'public_address' => 'Khu dân cư minh họa, '.$area, 'district' => $area, 'description' => 'Nhà nhiều cửa sổ, lối đi riêng, có khu để xe và thiết bị phòng cháy. Khu vực chung được vệ sinh định kỳ.', 'amenity_ids' => ['parking', 'shared_wifi', 'fire_safety'], 'custom_amenities' => ['Sân phơi chung']]);
            foreach (['electricity' => 3500, 'water' => 18000, 'fixed' => 100000] as $kind => $price) $this->put('service_rates', $p.'-'.$kind, ['property_id' => $this->id('properties', $p), 'name' => ['electricity' => 'Điện', 'water' => 'Nước', 'fixed' => 'Internet'][$kind], 'kind' => $kind, 'rate' => $price, 'effective_from' => $this->date->subYear()->startOfYear()->toDateString()]);
            for ($v=0; $v<2; $v++) $this->put('housing_media', $p.'-'.$v, ['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, self::DATASET.':photo:'.$p.':'.$v)->toString(), 'workspace_id' => $this->id('workspaces', $w), 'resource_type' => 'property', 'resource_id' => $this->id('properties', $p), 'path' => 'housing-images/demo-v1/property-'.$p.'-'.$v.'.png', 'position' => $v]);
        }
        for ($i=1; $i<=420; $i++) {
            $p = intdiv($i-1,6)+1; $width=3+($i%4)*0.5; $length=5+($i%5); $type=$types[($i-1)%count($types)];
            $rent=2000000+(int)($width*$length*65000)+($type==='apartment'?1600000:0);
            $this->put('rooms', $i, ['property_id' => $this->id('properties', $p), 'name' => 'Phòng '.(100+($i-1)%6+1), 'area' => $width*$length, 'length' => $length, 'width' => $width, 'floor' => 1+(($i-1)%3), 'room_type' => $type, 'rent' => $rent, 'deposit' => $i%5===0?0:$rent, 'capacity' => 2+($i%2), 'status' => $i<=145?'occupied':($i<=345?'vacant':($i<=365?'reserved':'inactive')), 'amenities' => 'Vệ sinh riêng, cửa sổ, khu bếp, chỗ để xe', 'amenity_ids' => ['private_bathroom','window','kitchen', $i%2?'air_conditioner':'balcony'], 'custom_amenities' => ['Kệ sách'], 'rules' => 'Giữ yên tĩnh sau 22 giờ. Không hút thuốc trong phòng; vật nuôi cần được thống nhất trước.']);
            $this->event('rooms', $i, 'room.created', $this->id('users', intdiv($p-1,2)+1), $this->id('workspaces', intdiv($p-1,2)+1));
        }
        for ($i=1; $i<=280; $i++) {
            $roomKey = $i<=180?145+$i:($i<=220?$i-180:($i<=240?125+$i:($i<=260?$i+85:$i+105)));
            $r=$this->rows['rooms'][$roomKey]; $pKey=intdiv($roomKey-1,6)+1; $p=$this->rows['properties'][$pKey]; $status=$i<=180?'published':($i<=240?'paused':'draft');
            $intro = ['Không gian có cửa sổ đón nắng buổi sáng', 'Phòng gọn gàng, thuận tiện đi làm', 'Chỗ ở yên tĩnh cho người cần nghỉ ngơi', 'Căn phòng với góc bếp và khu sinh hoạt riêng', 'Nơi ở thoáng sáng cho hai người'][$i%5];
            $desc=$intro.'. Diện tích '.number_format($r['area'],1,',','.').' m² tại '.$p['district'].', tầng '.$r['floor'].'. Giá thuê '.number_format($r['rent'],0,',','.').' đồng/tháng. '.($r['deposit']?'Cọc một tháng, hoàn sau khi đối soát theo hợp đồng.':'Không yêu cầu đặt cọc; hai bên vẫn cần xác nhận hợp đồng.').' Điện 3.500 đồng/kWh, nước 18.000 đồng/m³; Internet 100.000 đồng/tháng. '.($i%2?'Có điều hòa và cửa sổ lớn.':'Có ban công, phù hợp trồng vài chậu cây nhỏ.').' Có thể hẹn xem trước khi quyết định thuê.';
            $snapshot=['title'=>$intro.' tại '.$p['district'], 'description'=>$desc, 'rent'=>$r['rent'], 'area'=>$r['area'], 'deposit'=>$r['deposit'], 'district'=>$p['district'], 'room_type'=>$r['room_type']];
            $this->put('listings',$i,['room_id'=>$r['id'],'title'=>$snapshot['title'],'description'=>$desc,'status'=>$status,'published_at'=>$status==='draft'?null:$this->time($roomKey<=40?-295:-90),'published_snapshot'=>$status==='draft'?null:$snapshot,'content_version'=>$status==='draft'?0:1, 'created_at'=>$this->time($roomKey<=40?-299:-100)]);
            if ($status!=='draft') $this->put('listing_versions',$i,['listing_id'=>$this->id('listings',$i),'version'=>1,'actor_id'=>$this->id('users',intdiv($pKey-1,2)+1),'snapshot'=>$snapshot,'created_at'=>$this->time($roomKey<=40?-295:-90)]);
            $this->event('listings',$i,'listing.created',$this->id('users',intdiv($pKey-1,2)+1),$p['workspace_id']);
        }
    }
    private function rentals(): void
    {
        for ($i=1;$i<=320;$i++) {
            $signed=$i<=200; $active=$i<=145; $holding=$i>200&&$i<=220;
            $roomKey=$active?$i:($signed?($i<=155?$i-145:($i-10)):($holding?$i+145:($i<=250?$i+145:146+($i-251)%180)));
            $r=$this->rows['rooms'][$roomKey];$pKey=intdiv($roomKey-1,6)+1;$wKey=intdiv($pKey-1,2)+1;$workspace=$this->id('workspaces',$wKey);
            $tenantKey=$active?$i+35:($signed?$i+25:226+($i-201)%70);$tenant=$this->id('users',$tenantKey);$owner=$this->id('users',$wKey);
            $start=$active?$this->date->subMonths($i<=100?5:4)->startOfMonth():($signed?$this->date->subMonths($i<=155?9:8)->startOfMonth():$this->date->addDay());
            $end=$active?$start->addYear()->subDay():($signed?$start->addMonths(4)->subDay():$start->addYear()->subDay());
            $created=$signed?$start->subDays(2)->setTime(12,0)->toDateTimeString():$this->time($holding?-1:-5);
            $policy=['hold_hours'=>48,'extension_hours'=>24,'max_extensions'=>1,'exception_hours'=>24,'partial_deposit'=>false,'deposit_amount'=>$r['deposit'],'deposit_terms'=>config('rental_workflow.deposit_terms')];
            $status=$signed||$holding?'accepted':($i<=250?($i%2?'expired':'cancelled'):['submitted','counter_offered','rejected','cancelled'][($i-251)%4]);
            $request=$this->put('rental_requests',$i,['user_id'=>$tenant,'room_id'=>$r['id'],'workspace_id'=>$workspace,'status'=>$status,'active_key'=>$holding||in_array($status,['submitted','counter_offered'])?$tenant.':'.$r['id']:null,'terms_snapshot'=>['rent'=>$r['rent'],'deposit'=>$r['deposit'],'starts_at'=>$start->toDateString(),'ends_at'=>$end->toDateString(),'people'=>1,'deposit_terms'=>$policy['deposit_terms'],'policy'=>$policy], 'message'=>'Tôi đã xem thông tin phòng và mong được trao đổi về ngày nhận phòng.','decision_reason'=>in_array($status,['cancelled','rejected','expired'])?'Khách thay đổi kế hoạch hoặc chưa hoàn tất đúng hạn.':null,'terms_accepted_at'=>$created,'created_at'=>$created]);
            $this->event('rental_requests',$i,'rental_request.submitted',$tenant,$workspace);
            if ($i>250) continue;
            if($i>200){
                // RentalService::create grants this personal context even for a draft.
                $g=$this->group('tenant_group');
                $this->put('group_assignments','candidate-tenant-'.$tenantKey,['user_id'=>$tenant,'access_group_id'=>$g->id,'scope_key'=>'personal','origin'=>'rental']);
            }
            $rental=$this->put('rentals',$i,['room_id'=>$r['id'],'tenant_id'=>$tenant,'starts_at'=>$start->toDateString(),'ends_at'=>$end->toDateString(),'rent'=>$r['rent'],'deposit'=>$r['deposit'],'terms'=>'Thanh toán tiền mặt theo hóa đơn đã phát hành. Giữ yên tĩnh sau 22 giờ. Tiền cọc hoàn sau khi đối soát công nợ và bàn giao; khấu trừ cần có thỏa thuận.','status'=>$active?'active':($signed?'closed':($holding?'draft':'cancelled')),'confirmed_at'=>$signed?$start->subDay()->setTime(10,0)->toDateTimeString():null,'signed_at'=>$signed?$start->setTime(9,0)->toDateTimeString():null,'residency_started_at'=>$signed?$start->setTime(9,0)->toDateTimeString():null,'closed_at'=>$signed&&!$active?$end->setTime(17,0)->toDateTimeString():($i>220?$this->time(-3):null),'move_out_at'=>$signed&&!$active?$end->toDateString():null,'handover_complete'=>$signed&&!$active,'created_at'=>$created]);
            DB::table('rental_requests')->where('id',$request)->update(['rental_id'=>$rental]);
            $this->put('room_reservations',$i,['room_id'=>$r['id'],'active_room_id'=>$holding?$r['id']:null,'rental_request_id'=>$request,'rental_id'=>$rental,'status'=>$signed?'completed':($holding?($r['deposit']>0?'holding':'deposit_confirmed'):($i%2?'expired':'released')),'hold_until'=>$signed?$start->setTime(12,0)->toDateTimeString():$this->time($holding?1:-3),'policy_snapshot'=>$policy,'reason'=>$i>220?'Hồ sơ thử nghiệm đã hết hạn hoặc khách đổi kế hoạch.':null,'created_at'=>$created]);
            $this->event('room_reservations',$i,'reservation.created',$owner,$workspace);
            if (! $signed) continue;
            foreach ([1=>['draft',$created,$owner],2=>['draft',$start->subDay()->setTime(10,0)->toDateTimeString(),$tenant],3=>['active',$start->setTime(9,0)->toDateTimeString(),$owner]] + ($active?[]:[4=>['closed',$end->setTime(17,0)->toDateTimeString(),$owner]]) as $version=>[$vStatus,$vAt,$vActor]) {
                $this->put('contract_versions',$i.'-'.$version,['rental_id'=>$rental,'version'=>$version,'actor_id'=>$vActor,'terms'=>['starts_at'=>$start->toDateString(),'ends_at'=>$end->toDateString(),'rent'=>$r['rent'],'deposit'=>$r['deposit'],'terms'=>$this->rows['rentals'][$i]['terms'],'status'=>$vStatus,'rental_policy'=>$policy],'created_at'=>$vAt]);
            }
            $this->put('occupants',$i,['rental_id'=>$rental,'user_id'=>$tenant,'relationship'=>'primary','starts_at'=>$start->toDateString(),'ends_at'=>$active?null:$end->toDateString(),'created_at'=>$start->setTime(9,0)->toDateTimeString()]);
            $this->event('rentals',$i,'rental.confirmed',$tenant,$workspace,$start->subDay()->setTime(10,0)->toDateTimeString());
            $this->event('rentals',$i,'rental.activated',$owner,$workspace,$start->setTime(9,0)->toDateTimeString());
            if ($r['deposit']>0) {
                $paid=$start->subDay()->setTime(10,30)->toDateTimeString();
                $this->put('payment_request_transactions',$i,['rental_id'=>$rental,'amount'=>$r['deposit'],'reference'=>self::DATASET.'-CASH-DEPOSIT-'.$i,'verified_by'=>$owner,'verified_at'=>$paid,'kind'=>'deposit','method'=>'cash','reason'=>'Nhập lịch sử thu tiền mặt mô phỏng; không phải giao dịch ngân hàng.','created_at'=>$paid]);
                $this->put('deposits',$i,['rental_id'=>$rental,'amount'=>$r['deposit'],'received_at'=>$paid,'settled_at'=>$active?null:$end->setTime(16,0)->toDateTimeString(),'deduction'=>0,'reason'=>$active?null:'Đã đối soát công nợ và bàn giao đầy đủ.','created_at'=>$paid]);
                if (!$active) $this->put('deposit_refund_decisions',$i,['rental_id'=>$rental,'amount_received'=>$r['deposit'],'deduction'=>0,'reason'=>'Hoàn toàn bộ cọc sau bàn giao. Lịch sử tiền mặt mô phỏng.','status'=>'paid','payer_accepted_at'=>$end->setTime(16,0)->toDateTimeString(),'refunded_at'=>$end->setTime(16,30)->toDateTimeString(),'refund_reference'=>self::DATASET.'-CASH-REFUND-'.$i,'created_by'=>$owner,'created_at'=>$end->setTime(15,0)->toDateTimeString()]);
            }
        }
    }
    private function billing(): void
    {
        $n=0;$paidActive=0;$unpaid=0;
        // Closed rentals must have no outstanding debt. Allocate these first.
        foreach (array_merge(range(146,200),range(1,145)) as $i) {
            $r=$this->rows['rentals'][$i];$roomKey=$i<=145?$i:($i<=155?$i-145:$i-10);$pKey=intdiv($roomKey-1,6)+1;$wKey=intdiv($pKey-1,2)+1;$owner=$this->id('users',$wKey);$w=$this->id('workspaces',$wKey);
            $start=CarbonImmutable::parse($r['starts_at']);$months=$i<=100?5:4;
            for($m=0;$m<$months;$m++) {
                $n++;$period=$start->addMonths($m);$at=$period->setTime(8,0)->toDateTimeString();$paid=$i>145||$paidActive<380;
                if($i<=145&&$paid)$paidActive++;
                $overdue=!$paid&&++$unpaid<=130;
                $due=$paid?$period->addDays(5)->toDateString():($overdue?$period->addDays(7)->toDateString():$this->date->addDays(3)->toDateString());
                $electric=45+(($i+$m)%80);$water=3+(($i+$m)%8);$total=$r['rent']+$electric*3500+$water*18000+100000;
                $invoice=$this->put('invoices',$n,['rental_id'=>$r['id'],'period'=>$period->format('Y-m'),'due_at'=>$due,'status'=>'issued','total'=>$total,'issued_at'=>$at,'created_at'=>$at]);
                $this->event('invoices',$n,'invoice.created',$owner,$w,$at);$this->event('invoices',$n,'invoice.issued',$owner,$w,$at);
                foreach([['Tiền phòng',1,$r['rent']],['Tiền điện',$electric,3500],['Tiền nước',$water,18000],['Internet',1,100000]] as $j=>[$name,$qty,$price])$this->put('invoice_items',$n.'-'.$j,['invoice_id'=>$invoice,'description'=>$name,'quantity'=>$qty,'unit_price'=>$price,'amount'=>$qty*$price,'actor_id'=>$owner,'created_at'=>$at]);
                foreach(['electricity'=>$electric,'water'=>$water] as $kind=>$usage){
                    $prev=($i>145?0:($roomKey<=10?4:0))*($kind==='electricity'?100:10)+$m*($kind==='electricity'?130:15);
                    // Continuous, per-room meter history, including previous tenancy.
                    $previous=DB::table('meter_readings')->where('room_id',$r['room_id'])->where('service_rate_id',$this->id('service_rates',$pKey.'-'.$kind))->orderByDesc('period')->value('current')??0;
                    $key=$n.'-'.$kind;
                    $this->put('meter_readings',$key,['room_id'=>$r['room_id'],'service_rate_id'=>$this->id('service_rates',$pKey.'-'.$kind),'period'=>$period->format('Y-m'),'previous'=>$previous,'current'=>$previous+$usage,'usage'=>$usage,'actor_id'=>$owner,'created_at'=>$at]);
                    $this->event('meter_readings',$key,'utility.recorded',$owner,$w,$at);
                }
                if($paid){$payAt=$period->addDays(3)->setTime(10,0)->toDateTimeString();$this->put('payments',$n,['invoice_id'=>$invoice,'actor_id'=>$owner,'amount'=>$total,'method'=>'cash','reference'=>self::DATASET.'-CASH-INV-'.$n,'paid_at'=>$payAt,'created_at'=>$payAt]);$this->event('payments',$n,'payment.recorded',$owner,$w,$payAt);}
            }
        }
    }
    private function connectionsAndChat(): void
    {
        for($i=1;$i<=320;$i++){
            $lKey=1+($i-1)%180;$l=$this->rows['listings'][$lKey];$user=$this->id('users',226+($i-1)%70);$roomKey=$lKey+145;$wKey=intdiv($roomKey-1,12)+1;$w=$this->id('workspaces',$wKey);$owner=$this->id('users',$wKey);
            $view=$i<=120;$at=$this->time(-7);$message='Chào anh/chị, tôi muốn hỏi thêm về '.$l['title'].'. Phòng còn nhận khách xem không?';
            $c=$this->put('connections',$i,['user_id'=>$user,'listing_id'=>$l['id'],'status'=>$view?($i%2?'viewing_confirmed':'viewing_scheduled'):'contacted','message'=>$message,'viewing_at'=>$view?$this->time(1+($i%7)):null,'viewing_proposed_by'=>$view?$user:null,'cloud_epoch'=>1,'created_at'=>$at]);
            if($i>180)continue;
            $thread=$this->put('chat_threads',$i,['context_type'=>'rental_contact','context_id'=>$c,'workspace_id'=>$w,'created_at'=>$at,'updated_at'=>$this->time(-1)]);
            $this->put('chat_messages',$i.'-0',['thread_id'=>$thread,'sender_id'=>$user,'sender_side'=>'requester','kind'=>'context','body'=>'','client_id'=>'initial-context','created_at'=>$at]);
            $this->event('chat_messages',$i.'-0','chat.message.created',$user,$w);
            $msgs=12+($i<=160?1:0); // 180 context + 2320 text = 2500
            $conversation=[$message,'Chào bạn, phòng vẫn còn. Bạn dự định chuyển vào ngày nào?','Tôi dự kiến đầu tháng tới. Tiền điện và nước được tính thế nào?','Điện 3.500 đồng/kWh, nước 18.000 đồng/m³, có đồng hồ riêng.','Tôi có thể đến xem vào chiều cuối tuần được không?','Được bạn nhé, mình cùng thống nhất giờ trước khi đến.','Phòng có cửa sổ và chỗ để xe máy phải không ạ?','Phòng có cửa sổ; xe được để trong khu chung có khóa cổng.','Cảm ơn anh/chị. Cho tôi xin nội quy để xem trước.','Mình giữ yên tĩnh sau 22 giờ và vệ sinh khu chung. Điều khoản có trong hồ sơ thuê.','Tôi sẽ xem kỹ thông tin và phản hồi lịch hẹn.','Bạn cứ trao đổi ở đây nếu cần làm rõ thêm nhé.','Tôi đã nhận thông tin, cảm ơn anh/chị.'];
            $last=0;
            for($j=1;$j<=$msgs;$j++){$mine=$j%2===1;$key=$i.'-'.$j;$last=$this->put('chat_messages',$key,['thread_id'=>$thread,'sender_id'=>$mine?$user:$owner,'sender_side'=>$mine?'requester':'management','kind'=>'text','body'=>$conversation[$j-1],'client_id'=>self::DATASET.'-'.$key,'created_at'=>CarbonImmutable::parse($at)->addMinutes($j*13+$i%11)->toDateTimeString()]);$this->event('chat_messages',$key,'chat.message.created',$mine?$user:$owner,$w);}
            $this->put('chat_reads',$i.'-owner',['thread_id'=>$thread,'user_id'=>$owner,'side'=>'management','last_message_id'=>$last,'created_at'=>$this->time(-6)]);
            if($i%3!==0)$this->put('chat_reads',$i.'-user',['thread_id'=>$thread,'user_id'=>$user,'side'=>'requester','last_message_id'=>$last,'created_at'=>$this->time(-6)]);
        }
        for($i=1;$i<=400;$i++)$this->put('favorites',$i,['user_id'=>$this->id('users',226+($i-1)%70),'listing_id'=>$this->id('listings',1+($i-1)%180),'created_at'=>$this->time(-3)]);
    }
    private function community(): void
    {
        $areas=['Thủ Đức','Bình Thạnh','Gò Vấp','Tân Bình','Quận 7','Bình Tân','Quận 12'];
        for($i=1;$i<=100;$i++)$this->put('roommate_needs',$i,['user_id'=>$this->id('users',$i<=70?225+$i:$i-35),'district'=>$i<=70?$areas[($i-1)%7]:$this->rows['properties'][intdiv($i-71,6)+1]['district'],'budget'=>2000000+($i%5)*500000,'move_in'=>$this->date->addDays(7)->toDateString(),'lifestyle'=>['Giữ không gian yên tĩnh, thích đọc sách.','Làm việc giờ hành chính, gọn gàng, không hút thuốc.','Thích nấu ăn và chia sẻ việc dọn dẹp.'][$i%3],'status'=>$i<=80?'open':($i<=90?'closed':'expired'),'expires_at'=>$this->time($i>90?-1:30),'mode'=>$i<=70?'search_together':'has_room','room_id'=>$i<=70?null:$this->id('rooms',$i-70),'people_needed'=>1,'budget_basis'=>'per_person','duration_months'=>12,'created_at'=>$this->time(-10)]);
        for($i=1;$i<=30;$i++)$this->put('roommate_requests',$i,['roommate_need_id'=>$this->id('roommate_needs',$i),'user_id'=>$this->id('users',270+($i%20)),'status'=>['pending','accepted','rejected'][$i%3],'created_at'=>$this->time(-4)]);
        for($i=1;$i<=90;$i++){$r=$this->rows['rentals'][$i];$p=intdiv($i-1,6)+1;$w=$this->id('workspaces',intdiv($p-1,2)+1);$titles=['Vòi nước rỉ nhẹ','Ổ điện khu bếp cần kiểm tra','Khóa cửa hơi khó xoay','Đèn trong phòng chập chờn','Ống thoát nước thoát chậm'];$this->put('maintenance_requests',$i,['room_id'=>$r['room_id'],'rental_id'=>$r['id'],'reporter_id'=>$r['tenant_id'],'title'=>$titles[$i%5],'description'=>'Nhờ bên quản lý sắp xếp kiểm tra vào buổi chiều. Tôi có thể hỗ trợ mở cửa theo lịch hẹn.','status'=>['new','acknowledged','in_progress','resolved','closed'][$i%5],'created_at'=>$this->time(-14)]);$this->event('maintenance_requests',$i,'maintenance.created',$r['tenant_id'],$w);}
        for($i=1;$i<=70;$i++)$this->put('reports',$i,['reporter_id'=>$this->id('users',230+($i%60)),'target_type'=>'listing','target_id'=>$this->id('listings',$i),'reason'=>['Cần kiểm tra lại mô tả tiện ích.','Giá và phần mô tả có thông tin cần làm rõ.','Đề nghị kiểm tra thông tin địa điểm.'][$i%3],'status'=>$i%3?'open':'resolved','resolution'=>$i%3?null:'Đã kiểm tra và yêu cầu bên đăng làm rõ thông tin.','created_at'=>$this->time(-5)]);
        for($i=1;$i<=40;$i++){$r=$this->rows['rentals'][145+$i];$at=CarbonImmutable::parse($r['closed_at'])->addDays(2)->toDateTimeString();$this->put('reviews',$i,['rental_id'=>$r['id'],'user_id'=>$r['tenant_id'],'rating'=>3+($i%3),'comment'=>['Phòng thoáng, việc bàn giao và hoàn cọc rõ ràng.','Khu vực khá yên tĩnh, bên quản lý hỗ trợ khi có sửa chữa.','Có chỗ để xe thuận tiện; nên bổ sung đèn ở hành lang.','Điện nước có chỉ số riêng, đối soát cuối kỳ dễ hiểu.'][$i%4],'created_at'=>$at]);$roomKey=$i<=10?$i:135+$i;$this->event('reviews',$i,'review.created',$r['tenant_id'],$this->id('workspaces',intdiv($roomKey-1,12)+1),$at);}
    }
    private function staff(): void
    {
        $template=AccessGroup::where('scope','workspace')->whereNull('workspace_id')->where('name','Quản lý vận hành')->firstOrFail();
        for($i=1;$i<=35;$i++){
            $w=$this->id('workspaces',$i);$user=$this->id('users',230+$i);$g=$this->privateGroup($i,$template);$owner=$this->id('users',$i);$p=$this->id('properties',$i*2-1);
            $inv=$this->put('invitations','accepted-'.$i,['workspace_id'=>$w,'email'=>$this->rows['users'][230+$i]['email'],'recipient_id'=>$user,'access_group_id'=>$g,'access_group_ids'=>[$g],'token_hash'=>hash('sha256',random_bytes(32)),'expires_at'=>$this->time(-20),'accepted_at'=>$this->time(-25),'property_ids'=>[$p],'inviter_id'=>$owner,'created_at'=>$this->time(-27)]);
            $this->put('memberships',$i,['workspace_id'=>$w,'user_id'=>$user,'access_group_id'=>$g,'status'=>'active','property_ids'=>[$p],'permissions_mode'=>'replace','current_key'=>$w.':'.$user,'inviter_id'=>$owner,'invitation_id'=>$inv,'joined_at'=>$this->time(-25),'permission_origin'=>'organization','created_at'=>$this->time(-25)]);
            $this->put('invitations','pending-'.$i,['workspace_id'=>$w,'email'=>$this->rows['users'][265+($i%30)]['email'],'recipient_id'=>$this->id('users',265+($i%30)),'access_group_id'=>$g,'access_group_ids'=>[$g],'token_hash'=>hash('sha256',random_bytes(32)),'expires_at'=>$this->time(5),'property_ids'=>[$p],'inviter_id'=>$owner,'created_at'=>$this->time(-1)]);
        }
    }
    private function safety(): void
    {
        for($i=1;$i<=25;$i++){
            $wKey=intdiv($i-1,12)+1;$w=$this->id('workspaces',$wKey);$r=$this->id('rooms',$i);
            $device=$this->put('devices',$i,['workspace_id'=>$w,'name'=>'Cảm biến mô phỏng phòng '.(100+($i-1)%6+1),'serial'=>self::DATASET.'-SIM-'.$i,'token_hash'=>hash('sha256',random_bytes(32)),'kind'=>'safety_sensor','risk'=>'warning','health'=>'healthy','last_seen_at'=>$this->time(0),'last_event_at'=>$this->time(-1),'created_at'=>$this->time(-30)]);
            $this->put('device_assignments',$i,['device_id'=>$device,'room_id'=>$r,'effective_from'=>$this->time(-30),'created_at'=>$this->time(-30)]);
            $this->put('device_configs',$i,['device_id'=>$device,'version'=>1,'settings'=>['local_safety'=>true,'heartbeat_seconds'=>180,'simulated'=>true],'effective_from'=>$this->time(-30),'created_at'=>$this->time(-30)]);
            for($j=1;$j<=6;$j++){$at=$this->time(-7+$j);$this->put('safety_events',$i.'-'.$j,['device_id'=>$device,'room_id'=>$r,'event_id'=>Uuid::uuid5(Uuid::NAMESPACE_URL,self::DATASET.':safety:'.$i.':'.$j)->toString(),'event_time'=>$at,'ingest_time'=>$at,'config_version'=>1,'risk'=>$j%3===0?'warning':'normal','health'=>'healthy','reason'=>$j%3===0?'Tình huống cảnh báo khói mô phỏng.':'Bản tin trạng thái mô phỏng.','status'=>$j<6?'closed':'open','payload'=>['dataset'=>self::DATASET,'simulated'=>true,'smoke_ppm'=>$j%3===0?180:12,'temperature_c'=>26+$j],'created_at'=>$at]);}
        }
    }
    private function subscriptions(): void
    {
        $approver=User::whereNotIn('id',array_values($this->ids['users']))->get()->first(fn($u)=>app(Authorization::class)->global($u,'subscription.approve'));
        for($i=1;$i<=120;$i++){$wKey=1+($i-1)%35;$paid=$i<=105&&$approver; $at=$i<=105?$this->date->subYears(2-intdiv($i-1,35))->toDateTimeString():$this->time(-1);$this->put('subscription_orders',$i,['workspace_id'=>$this->id('workspaces',$wKey),'plan'=>'yearly','status'=>$paid?'confirmed':'pending','reference'=>self::DATASET.($paid?'-SIM-MANUAL-':'-PENDING-').$i,'confirmed_by'=>$paid?$approver->id:null,'created_at'=>$at,'updated_at'=>$at]);}
    }
    private function notifications(): void
    {
        // Each notification points to an actual dataset record, never a fabricated event.
        $sources=[['invoices','Hóa đơn đã phát hành','my-invoices',900],['chat_messages','Bạn có tin nhắn mới','conversations',400],['maintenance_requests','Yêu cầu sửa chữa đã tiếp nhận','maintenance',90],['invitations','Bạn có lời mời tham gia quản lý','invitations',70],['room_reservations','Thông tin giữ phòng đã cập nhật','rentals',40]];
        $i=0;
        foreach($sources as [$table,$title,$screen,$amount]) foreach(array_slice(array_keys($this->rows[$table]),$table==='room_reservations'?180:0,$amount) as $key) {
            $i++; $row=$this->rows[$table][$key];
            if($table==='invoices')$user=$this->rows['rentals'][array_search($row['rental_id'],$this->ids['rentals'],true)]['tenant_id'];
            elseif($table==='chat_messages'){$tKey=array_search($row['thread_id'],$this->ids['chat_threads'],true);$c=$this->rows['connections'][$tKey];$user=$row['sender_side']==='management'?$c['user_id']:$this->rows['workspaces'][intdiv(145+$tKey-1,12)+1]['owner_id'];}
            elseif($table==='maintenance_requests')$user=$row['reporter_id'];
            elseif($table==='room_reservations')$user=$this->rows['rental_requests'][$key]['user_id'];
            else $user=$row['recipient_id'];
            $noticeWorkspace=match($table){
                'invoices'=>DB::table('rentals')->join('rooms','rooms.id','=','rentals.room_id')->join('properties','properties.id','=','rooms.property_id')->where('rentals.id',$row['rental_id'])->value('properties.workspace_id'),
                'chat_messages'=>DB::table('chat_threads')->where('id',$row['thread_id'])->value('workspace_id'),
                'maintenance_requests'=>DB::table('rooms')->join('properties','properties.id','=','rooms.property_id')->where('rooms.id',$row['room_id'])->value('properties.workspace_id'),
                'room_reservations'=>DB::table('rental_requests')->where('id',$row['rental_request_id'])->value('workspace_id'),
                'invitations'=>$row['workspace_id'],
            };
            $url='/ui/'.$screen.'?context=personal';
            if($table==='invitations')$url=route('invitation.show',['invitation'=>$row['id']],false);
            if($table==='chat_messages')$url='/ui/conversations?'.http_build_query(['context'=>$row['sender_side']==='requester'?'workspace':'personal','id'=>$row['thread_id']]+($row['sender_side']==='requester'?['workspace_id'=>$noticeWorkspace]:[]));
            $at=CarbonImmutable::parse($row['created_at'])->addMinutes(1+intdiv($i-1,5)%17)->toDateTimeString();
            $this->put('user_notifications',$i,['user_id'=>$user,'title'=>$title,'body'=>$title.'. Bạn có thể mở hồ sơ liên quan để xem chi tiết.','read_at'=>$i%3?$at:null,'dedupe_key'=>self::DATASET.'-notice-'.$i,'action_url'=>$url,'action_label'=>'Xem chi tiết','priority'=>$i%5===0?1:0,'scope_kind'=>'property','workspace_id'=>$noticeWorkspace,'cloud_epoch'=>1,'created_at'=>$at]);
            $this->event('user_notifications',$i,'notification.created',null,null,$at);
        }
    }
    private function history(): void
    {
        if(count($this->events)!==10000)throw new RuntimeException('History must match exactly 10000 real imported actions, got '.count($this->events));
        $stamps=[]; foreach($this->ids['workspaces'] as $w) $stamps[$w]=app(\App\Modules\Subscription\PropertyCloud::class)->snapshot($w);
        foreach($this->events as $i=>$e){$this->put('domain_events',$i+1,['event_id'=>Uuid::uuid5(Uuid::NAMESPACE_URL,self::DATASET.':'.$e['table'].':'.$e['key'].':'.$e['action'])->toString(),'workspace_id'=>$e['workspace'],'source'=>'synthetic','event_type'=>$e['action'],'event_time'=>$e['at'],'ingest_time'=>$this->time(0),'provenance'=>'synthetic','payload'=>['dataset'=>self::DATASET,'resource_table'=>$e['table'],'resource_id'=>$this->id($e['table'],$e['key']),'actor_id'=>$e['actor'],'synthetic'=>true]+($e['workspace']?['cloud_entitlement'=>$stamps[$e['workspace']]]:[]),'created_at'=>$e['at']]);}
    }
    private function audit(): void
    {
        // Append through the existing tamper-evident model. Never manufacture hashes.
        foreach ([['memberships', 'membership.accepted', \App\Modules\Workspace\Models\Membership::class], ['subscription_orders', 'subscription.renewed', \App\Modules\Subscription\Models\SubscriptionOrder::class]] as [$table,$action,$model]) {
            foreach ($this->rows[$table] as $key=>$row) {
                if ($table==='subscription_orders' && $row['status']!=='confirmed') continue;
                $workspace=$this->rows['workspaces'][array_search($row['workspace_id'],$this->ids['workspaces'],true)];
                $entry=\App\Modules\DataPlatform\Models\AuditEntry::create(['actor_id'=>$row['confirmed_by']??$workspace['owner_id'],'workspace_id'=>$workspace['id'],'action'=>$action,'resource_type'=>$model,'resource_id'=>$row['id'],'changes'=>['dataset'=>self::DATASET,'historical_import'=>true,'synthetic'=>true,'summary'=>$table==='subscription_orders'?'Lịch sử xác nhận thủ công mô phỏng, không phải chứng cứ chuyển khoản thực tế.':'Lịch sử lời mời đã được chấp thuận theo phạm vi nhà đã giao.'],'created_at'=>$row['created_at'],'updated_at'=>$row['created_at']]);
                DB::table('demo_dataset_records')->insert(['dataset'=>self::DATASET,'table_name'=>'audit_entries','demo_key'=>$table.'-'.$key,'record_id'=>(string)$entry->id,'created_at'=>now()]);
            }
        }
    }
}
