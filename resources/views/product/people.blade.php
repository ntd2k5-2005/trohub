<div class="people-page">
    <header class="people-heading"><div><p class="people-eyebrow">{{ $context === 'workspace' ? $currentWorkspace->name : $contexts[$context]['label'] }}</p><h1>{{ $page['title'] }}</h1><p>{{ $screen['people']['description'] }}</p></div></header>
    @if(session('status'))<div role="status" class="people-feedback">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="people-feedback is-error"><strong>Vui lòng kiểm tra lại thông tin.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if(session('invitation_url'))<div class="app-card p-5" x-data="{ copied: false, async copyInvitation() { try { await navigator.clipboard.writeText(this.$refs.inviteLink.value); this.copied = true; } catch { this.$refs.inviteLink.focus(); this.$refs.inviteLink.select(); } } }"><label for="invitation-url" class="block font-semibold">Liên kết mời đã sẵn sàng</label><p class="mt-1 text-sm text-slate-500">Gửi liên kết này cho người được mời để họ xác nhận tham gia.</p><div class="mt-3 flex flex-wrap gap-2"><input id="invitation-url" x-ref="inviteLink" class="app-input min-w-0 flex-1" readonly value="{{ session('invitation_url') }}" @focus="$el.select()"><button type="button" class="app-button-muted" @click="copyInvitation()"><span x-text="copied ? 'Đã sao chép' : 'Sao chép liên kết'">Sao chép liên kết</span></button></div></div>@endif
    @if($screen['stats'])<div class="people-metrics">@foreach($screen['stats'] as $label=>$value)<div><span>{{ $label }}</span><strong>{{ $value }}</strong></div>@endforeach</div>@endif
    <nav class="people-tabs" aria-label="Các mục quản lý">@foreach($screen['people']['tabs'] as $key=>$tab)<a href="{{ $tab['url'] }}" @if($screen['people']['section']===$key) aria-current="page" @endif>{{ $tab['label'] }}</a>@endforeach</nav>
    @if(array_key_exists('social_accounts', $screen))
        @include('product.social-accounts')
    @endif
    @if(!empty($screen['people']['search']))
    <form method="get" class="people-filters">
        <input type="hidden" name="context" value="{{ $context }}"><input type="hidden" name="section" value="{{ $screen['people']['section'] }}">
        @if($context === 'workspace')<input type="hidden" name="workspace_id" value="{{ $currentWorkspace->id }}">@endif
        <div class="people-search"><label for="people-search">Tìm kiếm</label><input id="people-search" name="q" class="app-input" placeholder="Nhập tên hoặc email…" value="{{ request('q') }}"></div>
        @if($screen['people']['filters'])<div><label for="people-status">Trạng thái</label><select id="people-status" name="status" class="app-input"><option value="">Tất cả trạng thái</option>@foreach($screen['people']['filters'] as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select></div>@endif
        <button class="app-button-primary">Tìm kiếm</button><a class="app-button-muted" href="{{ $screen['people']['tabs'][$screen['people']['section']]['url'] }}">Đặt lại</a>
    </form>
    @endif
    @if($screen['forms'])<div class="flex flex-wrap gap-3">@foreach($screen['forms'] as $form)@include('product.form-dialog')@endforeach</div>@endif
    @if(isset($screen['record_table']))@include('product.record-table')
    @elseif($screen['records'])<section class="independent-cards" x-data="independentCards">
    @foreach($screen['records'] as $record)@include('product.people-record')@endforeach
    </section>@elseif(!$screen['forms'] || !empty($screen['people']['search']))<div class="app-card people-empty"><x-ui.icon name="users"/><h2>Chưa có thông tin phù hợp</h2><p>{{ $screen['people']['empty'] }}</p></div>@endif
    @if($screen['pagination']){{ $screen['pagination']->links() }}@endif
</div>
