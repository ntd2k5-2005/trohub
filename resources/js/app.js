import './bootstrap';
import Alpine from 'alpinejs';
import { registerProduct } from './product';
import { registerHousing } from './housing';
import { registerChat } from './chat';
import { registerWorkflow } from './workflow';

registerProduct(Alpine);
registerHousing(Alpine);
registerChat(Alpine);
registerWorkflow(Alpine);

window.Alpine = Alpine;

Alpine.data('appShell', () => ({
  theme: localStorage.getItem('theme') || document.documentElement.dataset.defaultTheme || 'system',
  sidebarOpen: false,
  commandOpen: false,
  commandQuery: '',
  actionOpen: false,
  accountOpen: false,
  mobileViewport: window.innerWidth < 1024,
  commandTrigger: null,

  openSidebar() {
    this.sidebarOpen = true;
    this.$nextTick(() => document.querySelector('#app-sidebar button')?.focus());
  },
  closeSidebar() {
    this.sidebarOpen = false;
    this.$nextTick(() => this.$refs.menuTrigger?.focus());
  },
  openCommands() { this.commandOpen = true; },
  closeCommands() {
    this.commandOpen = false;
    this.commandTrigger?.focus();
  },
  escapeShell() {
    if (this.commandOpen) this.closeCommands();
    else if (this.sidebarOpen) this.closeSidebar();
    else this.accountOpen = false;
  },
  matchesCommand(label) {
    const normalize = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase();
    return normalize(label).includes(normalize(this.commandQuery.trim()));
  },
  trapFocus(event, container, enabled) {
    if (!enabled) return;
    const nodes = [...container.querySelectorAll('a[href],button:not([disabled]),summary,input,select,[tabindex="0"]')].filter(el => el.getClientRects().length);
    const first = nodes[0], last = nodes[nodes.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
  },
  toastVisible: false,
  toastMessage: '',
  toastTimer: null,
  toastDuration: 5000,

  notifyFeature(message, duration) {
    this.toastMessage = message;
    this.toastDuration = duration;
    this.toastVisible = true;
    this.resumeToast();
  },
  pauseToast() { clearTimeout(this.toastTimer); },
  resumeToast() {
    this.pauseToast();
    this.toastTimer = setTimeout(() => this.toastVisible = false, this.toastDuration);
  },
  dismissToast() { this.pauseToast(); this.toastVisible = false; },

  init() {
    this.applyTheme();
    const viewport = window.matchMedia('(min-width: 1024px)');
    viewport.addEventListener('change', event => { this.mobileViewport = !event.matches; if (event.matches) this.sidebarOpen = false; });
    const lockScroll = () => document.body.style.overflow = this.commandOpen || (this.mobileViewport && this.sidebarOpen) ? 'hidden' : '';
    this.$watch('sidebarOpen', lockScroll);
    this.$watch('commandOpen', open => {
      if (open) { this.commandTrigger = document.activeElement; this.commandQuery = ''; this.accountOpen = false; }
      lockScroll();
    });
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    media.addEventListener?.('change', () => {
      if (this.theme === 'system') this.applyTheme();
    });
  },

  applyTheme() {
    const dark = this.theme === 'dark'
      || (this.theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

    document.documentElement.classList.toggle('dark', dark);
    localStorage.setItem('theme', this.theme);
  },

  cycleTheme() {
    this.theme = this.theme === 'system' ? 'light' : this.theme === 'light' ? 'dark' : 'system';
    this.applyTheme();
  },

  themeLabel() {
    return this.theme === 'light' ? 'Sáng' : this.theme === 'dark' ? 'Tối' : 'Theo hệ thống';
  },


}));

Alpine.data('identityForm', () => ({
  sending: false,
  showPassword: false,
  showConfirmation: false,
  init() {
    this.$nextTick(() => this.$el.querySelector('[aria-invalid="true"]')?.focus());
  },
}));

Alpine.start();

// Mỗi module tự sở hữu JS/CSS của nó. Core chỉ tải module đang được hiển thị.
const activeModule = document.documentElement.dataset.uiModule;
const moduleLoaders = import.meta.glob('./modules/*/index.js');
const loader = activeModule ? moduleLoaders[`./modules/${activeModule}/index.js`] : null;

if (loader) {
  loader().catch((error) => console.error(`Không thể tải module giao diện ${activeModule}`, error));
}
