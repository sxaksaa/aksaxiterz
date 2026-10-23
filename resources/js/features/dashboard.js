function dashboardChartPointFromEvent(event) {
    const target = event.target;

    return target instanceof Element ? target.closest('[data-chart-point]') : null;
}

function setDashboardChartTooltipText(tooltip, selector, value) {
    const element = tooltip.querySelector(selector);

    if (element) {
        element.textContent = value || '-';
    }
}

function dashboardChartPointPosition(point, frame) {
    const svg = point.ownerSVGElement;
    const matrix = svg?.getScreenCTM?.();
    const x = Number(point.dataset.x);
    const y = Number(point.dataset.y);

    if (svg && matrix && Number.isFinite(x) && Number.isFinite(y)) {
        const svgPoint = svg.createSVGPoint();
        svgPoint.x = x;
        svgPoint.y = y;

        const screenPoint = svgPoint.matrixTransform(matrix);
        const frameRect = frame.getBoundingClientRect();

        return {
            left: screenPoint.x - frameRect.left,
            top: screenPoint.y - frameRect.top,
        };
    }

    const pointRect = point.getBoundingClientRect();
    const frameRect = frame.getBoundingClientRect();

    return {
        left: pointRect.left + (pointRect.width / 2) - frameRect.left,
        top: pointRect.top + (pointRect.height / 2) - frameRect.top,
    };
}

function showDashboardChartTooltip(point) {
    const frame = point.closest('[data-dashboard-chart]');
    const tooltip = frame?.querySelector('[data-dashboard-chart-tooltip]');

    if (!frame || !tooltip) return;

    setDashboardChartTooltipText(tooltip, '[data-chart-tooltip-title]', point.dataset.label || point.dataset.shortLabel);
    setDashboardChartTooltipText(tooltip, '[data-chart-tooltip-orders]', point.dataset.orders);
    setDashboardChartTooltipText(tooltip, '[data-chart-tooltip-line]', point.dataset.line);
    setDashboardChartTooltipText(tooltip, '[data-chart-tooltip-idr]', point.dataset.idr);
    setDashboardChartTooltipText(tooltip, '[data-chart-tooltip-crypto]', point.dataset.crypto);

    tooltip.classList.remove('is-below');
    tooltip.setAttribute('aria-hidden', 'false');

    const frameRect = frame.getBoundingClientRect();
    const position = dashboardChartPointPosition(point, frame);
    const tooltipWidth = tooltip.offsetWidth || 192;
    const tooltipHeight = tooltip.offsetHeight || 110;
    const minLeft = Math.min((tooltipWidth / 2) + 12, Math.max(12, frameRect.width / 2));
    const maxLeft = Math.max(minLeft, frameRect.width - (tooltipWidth / 2) - 12);
    const left = Math.min(maxLeft, Math.max(minLeft, position.left));
    const top = Math.max(16, position.top);

    tooltip.style.left = `${left}px`;
    tooltip.style.top = `${top}px`;
    tooltip.classList.toggle('is-below', top < tooltipHeight + 18);
    tooltip.classList.add('is-visible');

    if (tooltip.id) {
        point.setAttribute('aria-describedby', tooltip.id);
    }
}

function hideDashboardChartTooltip(point = null) {
    const frame = point?.closest('[data-dashboard-chart]') || document.querySelector('[data-dashboard-chart]');
    const tooltip = frame?.querySelector('[data-dashboard-chart-tooltip]');

    if (!tooltip) return;

    tooltip.classList.remove('is-visible');
    tooltip.setAttribute('aria-hidden', 'true');
    point?.removeAttribute('aria-describedby');
}

export { dashboardChartPointFromEvent, showDashboardChartTooltip, hideDashboardChartTooltip };
