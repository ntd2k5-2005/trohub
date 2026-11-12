<?php

namespace App\Modules\DataPlatform;

use App\Modules\DataPlatform\Models\DomainEvent;
use App\Modules\Workspace\Models\Workspace;
use App\Support\ProductPresenter;
use Illuminate\Support\Carbon;

final class Presenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        if ($this->request->input('section') === 'activity') { $this->activity(); return; }
        if ($this->access->global($this->user, 'data.view')) $this->vm['links'][] = $this->link('Lịch sử hoạt động', 'data-platform', ['section' => 'activity']);
        $fields = [];
        foreach (config('runtime_settings') as $key => $definition) {
            $fields[] = $this->field($key, $definition['name'], 'number', value: config($definition['config']));
        }
        $this->vm['forms'][] = $this->form('Cấu hình vận hành', 'system.settings', $fields);
        $this->vm['forms'][] = $this->form('Đồng bộ dữ liệu đang chờ', 'data.project', [$this->field('limit', 'Số sự kiện tối đa', 'number', value: 100)]);
        $this->vm['forms'][] = $this->form('Khôi phục dữ liệu dẫn xuất', 'data.replay', [
            $this->field('target_workspace_id', 'Nguồn dữ liệu', 'select', [0 => 'Dữ liệu dùng chung'] + Workspace::whereNull('purged_at')->pluck('name', 'id')->all()),
            $this->field('from', 'Từ thời điểm', 'datetime-local'), $this->field('until', 'Đến thời điểm', 'datetime-local'),
        ]);
        if (! $this->access->global($this->user, 'data.view')) {
            return;
        }
        $this->vm['forms'][] = $this->form('Kiểm tra kết nối', 'data.health');
        $health = app(DataStoreHealth::class)->latest();
        foreach (['mongodb' => 'MongoDB', 'redis' => 'Redis', 'cassandra' => 'Cassandra', 'neo4j' => 'Neo4j', 'meilisearch' => 'Meilisearch'] as $key => $name) {
            $latest = $health[$key] ?? null;
            $this->vm['records'][] = $this->card($name, '', ['Cấu hình' => config('data_stores.'.$key.'.enabled') ? 'Đã bật' : 'Chưa bật', 'Kết nối thực tế' => $latest ? (['disabled' => 'Chưa bật', 'connected' => 'Đã kết nối', 'unavailable' => 'Chưa kết nối được'][$latest['status']] ?? 'Chưa kiểm tra') : 'Chưa kiểm tra', 'Lần kiểm tra gần nhất' => $latest ? Carbon::parse($latest['checked_at'])->format('H:i:s d/m/Y') : 'Chưa có']);
        } $this->vm['stats'] = ['Sự kiện chờ đồng bộ' => DomainEvent::whereNull('projected_at')->count(), 'Lần đồng bộ gặp lỗi' => DomainEvent::whereNotNull('last_error')->count()];
    }
    private function activity(): void
    {
        abort_unless($this->access->global($this->user, 'data.view'), 403);
        $input = $this->request->validate(['history_workspace_id' => 'nullable|integer|min:0', 'history_from' => 'nullable|date_format:Y-m-d', 'history_until' => 'nullable|date_format:Y-m-d']);
        $workspace = (int) ($input['history_workspace_id'] ?? DomainEvent::whereIn('source', ['business', 'web'])->latest('event_time')->value('workspace_id') ?? 0);
        if ($workspace) Workspace::whereNull('purged_at')->findOrFail($workspace);
        $from = Carbon::parse($input['history_from'] ?? now()->subDays(7)->toDateString())->startOfDay();
        $until = Carbon::parse($input['history_until'] ?? today()->toDateString())->endOfDay();
        $history = app(BusinessHistoryRepository::class)->read($this->user, $workspace, $from, $until);
        $this->request->attributes->set('history.engine', $history['engine']);
        $this->vm['activity_filter'] = ['workspaces' => [0 => 'Hoạt động dùng chung'] + Workspace::whereNull('purged_at')->pluck('name', 'id')->all(), 'selected' => $workspace, 'from' => $from->toDateString(), 'until' => $until->toDateString()];
        $this->vm['notice'] = 'Lịch sử hoạt động gần nhất trong khoảng thời gian đã chọn (tối đa 31 ngày, 100 hoạt động). Khi kho lịch sử chưa sẵn sàng, dữ liệu vẫn được đọc từ hồ sơ chính.';
        $this->vm['stats'] = ['Hoạt động' => count($history['events']), 'Đã đối chiếu kho lịch sử' => $history['native_count']];
        $this->vm['links'][] = $this->link('Quay lại vận hành', 'data-platform');
        foreach ($history['events'] as $event) $this->vm['records'][] = $this->card((config('activity_labels')[$event['type']] ?? 'Cập nhật hoạt động'), $event['time']->format('d/m/Y H:i:s'), ['Nguồn đọc' => $event['native'] ? 'Kho lịch sử đã đối chiếu' : 'Hồ sơ chính']);
    }

}
