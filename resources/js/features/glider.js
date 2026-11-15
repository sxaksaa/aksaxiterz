// Geometry is shared; callers retain their own hover, focus and selection rules.
export function positionGlider(glider, target) {
    if (!glider || !target) {
        glider?.classList.remove('is-visible');
        return;
    }

    const firstPosition = glider.dataset.ready !== 'true';
    if (firstPosition) glider.style.transition = 'none';
    glider.style.width = `${target.offsetWidth}px`;
    glider.style.transform = `translate3d(${target.offsetLeft}px, 0, 0)`;
    glider.classList.add('is-visible');

    if (firstPosition) {
        glider.dataset.ready = 'true';
        glider.getBoundingClientRect();
        requestAnimationFrame(() => glider.style.removeProperty('transition'));
    }
}
