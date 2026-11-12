<form method="get" class="app-card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Lọc lịch sử hoạt động">
    <input type="hidden" name="context" value="{{ $context }}">
    <input type="hidden" name="section" value="activity">
    <div><label for="history-workspace">Khu quản lý</label><select id="history-workspace" name="history_workspace_id" class="app-input mt-1">@foreach($screen['activity_filter']['workspaces'] as $key=>$label)<option value="{{ $key }}" @selected($key === $screen['activity_filter']['selected'])>{{ $label }}</option>@endforeach</select></div>
    <div><label for="history-from">Từ ngày</label><input id="history-from" name="history_from" type="date" class="app-input mt-1" value="{{ $screen['activity_filter']['from'] }}" required></div>
    <div><label for="history-until">Đến ngày</label><input id="history-until" name="history_until" type="date" class="app-input mt-1" value="{{ $screen['activity_filter']['until'] }}" required></div>
    <div class="flex items-end"><button class="app-button-primary">Xem lịch sử</button></div>
</form>
