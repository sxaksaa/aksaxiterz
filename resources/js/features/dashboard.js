let activeDashboardTooltip = null;

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
    if (activeDashboardTooltip && activeDashboardTooltip.frame !== frame) hideDashboardChartTooltip();
    const tooltip = activeDashboardTooltip?.tooltip || frame?.querySelector('[data-dashboard-chart-tooltip]');

    if (!frame || !tooltip) return;

    activeDashboardTooltip?.point.removeAttribute('aria-describedby');
    activeDashboardTooltip = { frame, tooltip, point };
    tooltip.classList.add('is-floating');
    if (tooltip.parentElement !== document.body) document.body.append(tooltip);

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
    const anchorX = frameRect.left + position.left;
    const anchorY = frameRect.top + position.top;
    const left = Math.max(12, Math.min(window.innerWidth - tooltipWidth - 12, anchorX - tooltipWidth / 2));
    const below = anchorY - tooltipHeight - 16 < 12;
    const preferredTop = below ? anchorY + 16 : anchorY - tooltipHeight - 16;
    const top = Math.max(12, Math.min(window.innerHeight - tooltipHeight - 12, preferredTop));

    tooltip.style.left = `${left}px`;
    tooltip.style.top = `${top}px`;
    tooltip.style.setProperty('--dashboard-tooltip-arrow', `${Math.max(12, Math.min(tooltipWidth - 12, anchorX - left))}px`);
    tooltip.classList.toggle('is-below', below);
    tooltip.classList.add('is-visible');

    if (tooltip.id) {
        point.setAttribute('aria-describedby', tooltip.id);
    }
}

function hideDashboardChartTooltip(point = null) {
    if (activeDashboardTooltip) {
        if (point && point !== activeDashboardTooltip.point) return;
        const { frame, tooltip, point: activePoint } = activeDashboardTooltip;
        tooltip.classList.remove('is-visible', 'is-floating', 'is-below');
        tooltip.setAttribute('aria-hidden', 'true');
        tooltip.style.removeProperty('left');
        tooltip.style.removeProperty('top');
        tooltip.style.removeProperty('--dashboard-tooltip-arrow');
        activePoint.removeAttribute('aria-describedby');
        frame.prepend(tooltip);
        activeDashboardTooltip = null;
        return;
    }
    const frame = point?.closest('[data-dashboard-chart]') || document.querySelector('[data-dashboard-chart]');
    const tooltip = frame?.querySelector('[data-dashboard-chart-tooltip]');

    if (!tooltip) return;

    tooltip.classList.remove('is-visible');
    tooltip.setAttribute('aria-hidden', 'true');
    point?.removeAttribute('aria-describedby');
}

export { dashboardChartPointFromEvent, showDashboardChartTooltip, hideDashboardChartTooltip };
