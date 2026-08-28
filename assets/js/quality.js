/**
 * Governance & Quality Audit Module
 * ECharts Card Gauge/Ring Charts Renderer
 */

(() => {
    const initializeCharts = () => {
    const root = document.querySelector('.gov-container.gqp');
    const main = root && root.closest('main');
    const layout = root && root.closest('.wrapper');
    if (main) main.classList.add('gqp-page');
    if (layout) layout.classList.add('gqp-layout');
    // Valida se a biblioteca Apache ECharts foi carregada
    if (typeof echarts === 'undefined') {
        console.error('[Governance Module] ECharts library not loaded.');
        return;
    }

    const colorMap = {
        good: '#2e7d32',
        warning: '#f57c00',
        critical: '#d32f2f'
    };

    const governanceContainer = document.querySelector('.gov-container');
    const stylesheetDark = Array.from(document.styleSheets).some((stylesheet) =>
        stylesheet.href && stylesheet.href.includes('dark-theme')
    );
    const bodyColorParts = getComputedStyle(document.body).backgroundColor.match(/[\d.]+/g) || [];
    const bodyLuminance = bodyColorParts.length >= 3
        ? (0.2126 * Number(bodyColorParts[0]))
            + (0.7152 * Number(bodyColorParts[1]))
            + (0.0722 * Number(bodyColorParts[2]))
        : 255;
    const isDark = governanceContainer
        && (governanceContainer.classList.contains('gov-theme-dark')
            || stylesheetDark
            || bodyLuminance < 140);

    if (governanceContainer && isDark) {
        governanceContainer.classList.add('gov-theme-dark');
    }

    // A cor vem do CSS do container e acompanha o tema sem pintar o canvas.
    const trackColor = governanceContainer
        ? getComputedStyle(governanceContainer).getPropertyValue('--gov-chart-track').trim()
            || 'rgba(128, 128, 128, 0.2)'
        : 'rgba(128, 128, 128, 0.2)';

    const chartContainers = document.querySelectorAll('.gov-card-chart');
    const chartInstances = [];

    chartContainers.forEach((container) => {
        const score = parseFloat(container.getAttribute('data-score')) || 0;
        const status = container.getAttribute('data-status') || 'critical';
        const activeColor = colorMap[status] || colorMap.critical;

        // Inicializa o elemento ECharts
        const existingChart = echarts.getInstanceByDom(container);
        const chart = existingChart || echarts.init(container);

        const option = {
            backgroundColor: 'transparent',
            series: [{
                type: 'gauge',
                startAngle: 90,
                endAngle: -270,
                pointer: { 
                    show: false 
                },
                progress: {
                    show: true,
                    overlap: false,
                    roundCap: true,
                    clip: false,
                    itemStyle: {
                        color: activeColor
                    }
                },
                axisLine: {
                    lineStyle: {
                        width: 7,
                        color: [[1, trackColor]]
                    }
                },
                splitLine: { show: false },
                axisTick: { show: false },
                axisLabel: { show: false },
                data: [{
                    value: score
                }],
                detail: { 
                    show: true,
                    offsetCenter: [0, 0],
                    formatter: '{value}%',
                    color: activeColor,
                    fontSize: 14,
                    fontWeight: 'bold'
                },
                animationDuration: 800,
                animationEasing: 'cubicOut'
            }]
        };

        chart.setOption(option);
        chartInstances.push(chart);
    });

    // Redimensionamento automático dos gráficos ao alterar o tamanho da janela
    window.addEventListener('resize', () => {
        chartInstances.forEach((chart) => {
            if (chart && typeof chart.resize === 'function') {
                chart.resize();
            }
        });
    });

    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeCharts, { once: true });
    } else {
        initializeCharts();
    }
})();
