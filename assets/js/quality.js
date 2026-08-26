/**
 * Governance & Quality Audit Module
 * ECharts Card Gauge/Ring Charts Renderer
 */

(() => {
    const initializeCharts = () => {
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

    // Cor do trilho de fundo com opacidade para compatibilidade com temas claro e escuro
    const trackColor = 'rgba(128, 128, 128, 0.18)';

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
                        width: 9,
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
                    fontSize: 18,
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
