<?php

namespace App\Modules\Analytics;

use App\Modules\DataPlatform\Models\DomainEvent;
use App\Modules\DataPlatform\Models\QuarantinedEvent;
use App\Support\ProductPresenter;

final class Presenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        if (! $this->workspace) {
            if ($slug === 'analytics-lineage') {
                $events = DomainEvent::query();
                $this->vm['stats'] = ['Sự kiện hợp lệ' => (clone $events)->count(), 'Sự kiện đến muộn' => (clone $events)->where('late', true)->count(), 'Đang chờ đồng bộ' => (clone $events)->whereNull('projected_at')->count(), 'Cần kiểm tra' => QuarantinedEvent::count()];
                foreach (['production' => 'Dữ liệu vận hành', 'trial' => 'Dữ liệu dùng thử', 'synthetic' => 'Dữ liệu nhập sẵn', 'external' => 'Dữ liệu tham chiếu'] as $key => $label) {
                    $this->vm['records'][] = $this->card($label, '', ['Số sự kiện' => (clone $events)->where('provenance', $key)->count(), 'Cập nhật gần nhất' => (clone $events)->where('provenance', $key)->max('ingest_time') ?? 'Chưa có dữ liệu']);
                }

                return;
            }
            $this->vm['notice'] = 'Chọn một phạm vi nhà trọ được cấp quyền để xem phân tích.';

            return;
        }
        $service = app(AnalyticsService::class);
        $data = $service->aggregate($this->workspace->id, now()->subMonth()->toDateTimeString(), now()->toDateTimeString(), $this->workspace->provenance);
        $this->vm['stats'] = ['Sự kiện trong 30 ngày' => array_sum($data['counts']), 'Lượt xem tin' => $data['counts']['listing.viewed'] ?? 0, 'Khách liên hệ' => $data['counts']['connection.created'] ?? 0, 'Cập nhật lúc' => now()->format('d/m/Y H:i')];
        if ($slug === 'analytics-forecast') {
            $forecast = $service->forecast($this->workspace->id, today()->toDateTimeString(), today()->addDay()->toDateTimeString());
            if ($forecast['status'] === 'ready') {
                $this->vm['records'][] = $this->card('Mức quan tâm dự kiến mỗi ngày', 'Tham khảo từ lịch sử; không tự thay đổi quyết định cho thuê.', ['Lượt quan tâm' => $forecast['value'], 'Sai số kiểm tra trung bình' => $forecast['validation_mae'], 'Dữ liệu đến' => today()->format('d/m/Y')]);
            } else {
                $this->vm['notice'] = 'Chưa đủ lịch sử để đưa ra dự báo đáng tin cậy.';
            }
        }
    }
}
