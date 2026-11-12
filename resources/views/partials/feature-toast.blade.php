<div class="feature-toast" aria-live="polite" aria-atomic="true" @feature-unavailable.window="notifyFeature(@js(config('availability.message')), @js(config('availability.toast_duration')))">
    <div x-cloak x-show="toastVisible" x-transition.opacity class="feature-toast-card" @mouseenter="pauseToast()" @mouseleave="resumeToast()" @focusin="pauseToast()" @focusout="resumeToast()">
        <span class="feature-toast-icon"><x-ui.icon name="info"/></span>
        <p class="min-w-0 flex-1 text-sm leading-6" x-text="toastMessage"></p>
        <button type="button" class="app-button-ghost shrink-0 px-2" @click="dismissToast()" aria-label="Đóng thông báo"><x-ui.icon name="close"/></button>
    </div>
</div>
