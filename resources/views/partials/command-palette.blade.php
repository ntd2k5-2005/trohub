@php($commandItems = $shell['items'] ?? $navigation->map(fn($item) => ['label'=>$item['label'] ?? $item['title'], 'url'=>route('ui.show',['slug'=>$item['slug'],'context'=>$context]), 'icon'=>config('navigation.icons.'.$item['slug'],'grid')]))
<div x-cloak x-show="commandOpen" x-transition.opacity class="shell-command-overlay" @click.self="closeCommands()">
    <section role="dialog" aria-modal="true" aria-labelledby="command-title" class="shell-command" @keydown.tab="trapFocus($event, $el, commandOpen)">
        <div class="shell-command-heading"><h2 id="command-title">Bạn muốn làm gì?</h2><button type="button" class="shell-icon-button" @click="closeCommands()" aria-label="Đóng tìm kiếm"><x-ui.icon name="close"/></button></div>
        <div class="shell-command-input"><x-ui.icon name="search"/><input x-ref="commandInput" x-model="commandQuery" x-effect="if(commandOpen) $nextTick(() => $refs.commandInput.focus())" aria-label="Tìm chức năng" placeholder="Nhập tên chức năng…" autocomplete="off"></div>
        <div class="shell-command-results">
            @foreach($commandItems as $item)
                <a href="{{ $item['url'] }}" data-command-item x-show="matchesCommand(@js($item['label']))"><x-ui.icon :name="$item['icon']"/><span>{{ $item['label'] }}</span><x-ui.icon name="arrow"/></a>
            @endforeach
            <p x-cloak x-show="!@js($commandItems->pluck('label')->values()).some(label => matchesCommand(label))" class="shell-command-empty">Không tìm thấy chức năng phù hợp. Hãy thử từ khóa khác.</p>
        </div>
    </section>
</div>
