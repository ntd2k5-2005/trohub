export function registerHousing(Alpine) {
  Alpine.data('housingGallery', images => ({
    images, active: 0, fallback: document.documentElement.dataset.housingFallback,
    imageError(event) { if (event.target.src !== new URL(this.fallback, location.href).href) event.target.src = this.fallback; },
    move(step) { this.active = (this.active + step + this.images.length) % this.images.length; },
  }));
}
