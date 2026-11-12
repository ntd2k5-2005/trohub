export function registerWorkflow(Alpine) {
  Alpine.data('roomPreview', (previews, selected) => ({
    previews, selected: selected ? String(selected) : '',
    get room() { return this.previews[this.selected] || null; },
    fill() { if (!this.room) return; const form = this.$el.closest('form'); const title = form.elements.namedItem('title'); const description = form.elements.namedItem('description'); if (title && !title.value) title.value = this.room.name + ' tại ' + this.room.property; if (description && !description.value) description.value = [this.room.area + ' m², tối đa ' + this.room.capacity + ' người.', this.room.amenities, this.room.rules].filter(Boolean).join('\n'); },
  }));
  Alpine.data('formDialog', initial => ({
    dirty: false,
    init() { this.$el.addEventListener('input', () => { this.dirty = true; }); if (initial) this.$nextTick(() => this.open()); },
    open() { if(this.$refs.dialog.open)return; this.trigger=document.activeElement; this.previousOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden'; this.$refs.dialog.showModal(); },
    close() { if (!this.dirty || window.confirm('Bạn đang có thông tin chưa lưu. Đóng biểu mẫu?')) { this.$refs.dialog.close(); document.body.style.overflow = this.previousOverflow || ''; this.trigger?.focus(); } },
  }));
  Alpine.data('workflowForm', (steps, initial) => ({
    sending: false, step: initial, steps, form: null,
    init() { this.form = this.$el; this.form.addEventListener('input', event => event.target.setCustomValidity?.('')); this.form.addEventListener('change', event => event.target.setCustomValidity?.('')); },
    go(index) {
      if (index > this.step) {
        const invalid = [...this.form.querySelectorAll(`[data-step="${this.step}"] input, [data-step="${this.step}"] select, [data-step="${this.step}"] textarea`)].find(input => !input.checkValidity());
        if (invalid) { invalid.reportValidity(); return; }
      }
      this.step = index;
      this.$nextTick(() => this.form.querySelector(`[data-step="${index}"] input:not([type=hidden]), [data-step="${index}"] select, [data-step="${index}"] textarea`)?.focus());
    },
    invalid(event) {
      const input = event.target, group = input.closest('[data-step]');
      if (group) this.step = Number(group.dataset.step);
      const label = input.labels?.[0]?.textContent?.replace(/\*/g, '').trim().toLocaleLowerCase('vi') || 'thông tin này';
      if (input.validity.valueMissing) input.setCustomValidity('Vui lòng ' + (input.type === 'checkbox' ? 'xác nhận ' : input.tagName === 'SELECT' ? 'chọn ' : 'nhập ') + label + '.');
      else if (input.validity.typeMismatch) input.setCustomValidity('Vui lòng nhập ' + label + ' hợp lệ.');
      else input.setCustomValidity('Vui lòng kiểm tra ' + label + '.');
      this.sending = false;
    },
    submit(event) {
      if (this.sending) return;
      const invalid = [...event.target.elements].find(input => input.willValidate && !input.checkValidity());
      if (invalid) { this.invalid({target: invalid}); this.$nextTick(() => invalid.reportValidity()); return; }
      this.sending = true; event.target.submit();
    },
  }));
  Alpine.data('amenityPicker', (selected, custom, limit, maxLength) => ({
    selected, custom, other: custom.length > 0, text: '', error: '',
    add() { const value = this.text.trim(); this.error = ''; if (!value) return; if (value.length > maxLength) { this.error = `Mỗi tiện nghi tối đa ${maxLength} ký tự.`; return; } if (this.custom.some(item => item.toLocaleLowerCase('vi') === value.toLocaleLowerCase('vi'))) { this.error = 'Tiện nghi này đã có trong danh sách.'; return; } if (this.custom.length >= limit) { this.error = `Tối đa ${limit} tiện nghi khác.`; return; } this.custom.push(value); this.text = ''; },
  }));
  Alpine.data('searchSelect', (options, selected) => ({
    options, selected: selected == null ? '' : String(selected), query: '',
    get matches() { return Object.entries(this.options).filter(([id, label]) => id === this.selected || label.toLocaleLowerCase('vi').includes(this.query.toLocaleLowerCase('vi'))); },
  }));
  Alpine.data('paymentCard', () => ({
    message: '', qrFailed: false,
    async copy(value) { try { await navigator.clipboard.writeText(value); this.message = 'Đã sao chép.'; } catch { this.message = 'Chưa sao chép được. Bạn có thể chọn và sao chép trực tiếp thông tin.'; } },
  }));
  Alpine.data('galleryUpload', () => ({
    photos: [],
    select(event) { this.photos.forEach(p => URL.revokeObjectURL(p.url)); this.photos = [...event.target.files].map(file => ({file, url: URL.createObjectURL(file)})); },
    drop(event) { this.$refs.files.files = event.dataTransfer.files; this.select({target: this.$refs.files}); },
    move(index, offset) { const next = index + offset; if (next < 0 || next >= this.photos.length) return; [this.photos[index], this.photos[next]] = [this.photos[next], this.photos[index]]; this.sync(); },
    remove(index) { URL.revokeObjectURL(this.photos[index].url); this.photos.splice(index,1); this.sync(); },
    sync() { const data = new DataTransfer(); this.photos.forEach(photo => data.items.add(photo.file)); this.$refs.files.files = data.files; },
    destroy() { this.photos.forEach(p => URL.revokeObjectURL(p.url)); },
  }));
}
