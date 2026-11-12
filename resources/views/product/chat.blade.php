<div class="messenger" x-data="messenger(@js($screen['chat']))" :class="{'has-conversation': selected}">
<aside class="messenger-inbox" aria-label="Danh sách trò chuyện">
<header class="messenger-inbox-header"><div class="flex items-center justify-between gap-3"><h1>Trò chuyện</h1><span class="messenger-total" x-text="inbox.total"></span></div>
<label class="messenger-search"><x-ui.icon name="search" class="h-4 w-4"/><span class="sr-only">Tìm theo tên tài khoản</span><input type="search" placeholder="Tìm theo tên tài khoản…" x-model="query" @input.debounce.400ms="loadInbox(1)" maxlength="150"></label>
</header>
<div class="messenger-thread-list">
<p x-cloak x-show="error && !selected" x-text="error" role="status" class="messenger-error"></p>
<template x-for="item in inbox.items" :key="item.id"><a :href="item.url" class="messenger-thread" :class="{'is-selected': selected?.id === item.id, 'is-unread': item.unread > 0}" @click.prevent="open(item)" :aria-current="selected?.id === item.id ? 'page' : null">
<span class="messenger-avatar" x-text="item.initial"></span><span class="min-w-0 flex-1"><span class="flex items-center justify-between gap-2"><strong class="truncate" x-text="item.name"></strong><small class="shrink-0" x-text="item.time"></small></span><span class="messenger-thread-subtitle" x-text="item.subtitle"></span><span class="messenger-preview"><span class="truncate" x-text="(item.mine ? 'Bạn: ' : '') + item.preview"></span><b x-show="item.unread" class="messenger-unread" x-text="item.unread > 99 ? '99+' : item.unread"></b></span><small x-show="item.state" class="messenger-state" x-text="item.state"></small></span>
</a></template>
<div x-show="!inbox.items.length" class="messenger-empty-list"><p class="font-semibold" x-text="query ? 'Không tìm thấy cuộc trò chuyện' : 'Chưa có cuộc trò chuyện'"></p><p class="mt-2 text-sm leading-6">Các cuộc trò chuyện của bạn sẽ được lưu tại đây.</p></div>
</div>
<footer x-show="inbox.pages > 1" class="messenger-list-pages"><button type="button" @click="loadInbox(inbox.page - 1)" :disabled="inbox.page <= 1">← Trước</button><span x-text="inbox.page + ' / ' + inbox.pages"></span><button type="button" @click="loadInbox(inbox.page + 1)" :disabled="inbox.page >= inbox.pages">Sau →</button></footer>
</aside>
<section class="messenger-main" aria-label="Nội dung trò chuyện">
<div x-show="!selected" class="messenger-welcome"><span class="messenger-welcome-icon"><x-ui.icon name="users" class="h-10 w-10"/></span><h2>Những cuộc trò chuyện của bạn</h2><p>Chọn một người để tiếp tục trao đổi.</p></div>
<template x-if="selected"><div class="messenger-conversation">
@if(session('status'))<p class="messenger-notice" role="status" x-data="{visible:true}" x-show="visible" x-init="setTimeout(() => visible=false, 6000)">{{ session('status') }}</p>@endif
<header class="messenger-conversation-header"><button type="button" class="messenger-back" @click="backToList()" aria-label="Quay lại danh sách trò chuyện">←</button><span class="messenger-avatar" x-text="selected.name.slice(0,1)"></span><div class="min-w-0 flex-1"><h2 class="truncate" x-text="selected.name"></h2><p class="truncate" x-text="selected.subtitle"></p></div><a :href="selected.workflow_url" class="messenger-workflow" x-show="selected.workflow_url" x-text="selected.workflow_label"></a></header>
<div class="messenger-messages" x-ref="messages" role="log" aria-label="Tin nhắn" aria-live="polite" @scroll.debounce.180ms="acknowledge()">
<button type="button" class="messenger-older" x-show="hasOlder" :disabled="loadingOlder" @click="older()">Xem tin nhắn trước</button>
<template x-for="(message,index) in messages" :key="message.id"><div class="messenger-message-block">
<div class="messenger-date" x-show="index === 0 || messages[index - 1]?.date !== message.date"><span x-text="message.date"></span></div>
<div class="messenger-message" :class="{'is-mine':message.mine}"><p class="messenger-sender" x-show="!message.mine" x-text="message.name"></p>
<template x-if="message.kind === 'context' && message.card"><div class="messenger-context-card messenger-room-card"><a :href="message.card.url || null" :aria-disabled="!message.card.url"><img :src="message.card.image" :alt="message.card.image_alt || message.card.title" referrerpolicy="no-referrer" x-on:error.once="$el.src = document.documentElement.dataset.housingFallback"><div><span class="messenger-card-label" x-text="message.card.label"></span><h3 x-text="message.card.title"></h3><p x-text="message.card.subtitle"></p><strong x-text="message.card.price"></strong><span class="messenger-card-link" x-text="message.card.url ? message.card.action_label : message.card.unavailable"></span></div></a></div></template>
<p class="messenger-bubble" x-show="message.kind === 'text'" x-text="message.body"></p><div class="messenger-message-meta"><time x-text="message.time"></time><span x-show="message.mine" x-text="message.id <= peerRead ? '· Đã xem' : '· Đã gửi'"></span></div>
</div></div></template>
<div x-show="pending" class="messenger-message is-mine"><p class="messenger-bubble" x-text="pending?.body"></p><div class="messenger-message-meta"><span x-text="sending ? 'Đang gửi…' : 'Chưa gửi được'"></span><button x-show="!sending && selected.can_send" type="button" @click="retry()">Thử lại</button></div></div>
</div>
<p x-show="error" x-cloak x-text="error" role="status" class="messenger-error"></p>
<form class="messenger-composer" @submit.prevent="send()" x-show="selected.can_send"><label class="sr-only" for="messenger-body">Tin nhắn</label><textarea id="messenger-body" :disabled="sending" x-model="body" @input="saveDraft()" @keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); send(); }" rows="2" maxlength="2000" placeholder="Nhập tin nhắn…"></textarea><button type="submit" :disabled="sending || !body.trim()" aria-label="Gửi tin nhắn"><span x-text="sending ? '…' : '➤'"></span></button></form>
<p x-show="!selected.can_send" class="messenger-closed">Cuộc trò chuyện đã kết thúc. Bạn vẫn có thể đọc lại lịch sử.</p>
</div></template>
</section>
<noscript><p>Vui lòng bật JavaScript để sử dụng trò chuyện.</p></noscript>
</div>
