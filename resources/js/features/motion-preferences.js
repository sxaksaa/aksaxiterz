function shouldReduceMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
}

function usesTouchMotion() {
    return window.matchMedia?.('(hover: none) and (pointer: coarse)').matches === true;
}

export { shouldReduceMotion, usesTouchMotion };
