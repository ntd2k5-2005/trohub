export function registerProduct(Alpine) {
  Alpine.data('permissionPicker', (categories, selected) => ({
    categories, selected, query: '',
    matches(label) {
      const normalize = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/gi, 'd').toLowerCase();
      return normalize(label).includes(normalize(this.query.trim()));
    },
    visibleGroup(index) { return this.categories[index].permissions.some(p => this.matches(p.name)); },
    groupCount(index) { return this.categories[index].permissions.filter(p => this.selected.includes(p.code)).length; },
    allSelected(index) { return this.groupCount(index) === this.categories[index].permissions.length; },
    partiallySelected(index) { return this.groupCount(index) > 0 && !this.allSelected(index); },
    toggleGroup(index, checked) {
      const codes = this.categories[index].permissions.map(p => p.code);
      this.selected = checked ? [...new Set([...this.selected, ...codes])] : this.selected.filter(code => !codes.includes(code));
    },
  }));

  // Keep DOM/keyboard order, while letting each card occupy only its own height.
  Alpine.data('independentCards', () => ({
    observer: null, frame: null,
    init() {
      if (!window.ResizeObserver) return;
      this.$el.classList.add('is-ready');
      const layout = () => {
        cancelAnimationFrame(this.frame);
        this.frame = requestAnimationFrame(() => {
          for (const card of this.$el.children) {
            const span = `span ${Math.ceil(card.getBoundingClientRect().height) + 16}`;
            if (card.style.gridRowEnd !== span) card.style.gridRowEnd = span;
          }
        });
      };
      this.observer = new ResizeObserver(layout);
      this.observer.observe(this.$el);
      for (const card of this.$el.children) this.observer.observe(card);
      layout();
    },
    destroy() { this.observer?.disconnect(); cancelAnimationFrame(this.frame); },
  }));
}
