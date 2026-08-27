(() => {
    const init = () => {
        const root = document.getElementById('gav-dashboard');
        const dataNode = document.getElementById('gav-report-data');
        if (!root || !dataNode || root.dataset.initialized) return;
        root.dataset.initialized = '1';
        const pt = root.dataset.lang === 'pt';
        const t = (a, b) => pt ? a : b;
        const report = JSON.parse(dataNode.textContent);
        const charts = [];
        const chartData = index => {
            const dept = report.departments[index];
            const selected = Number(root.querySelector(`.gav-chart-selection[data-department="${index}"]`).value);
            return selected < 0 ? dept.daily : dept.technologies[selected].daily;
        };
        const draw = (chart, index) => {
            const data = chartData(index);
            const muted = getComputedStyle(root).getPropertyValue('--gov-muted').trim();
            chart.setOption({backgroundColor: 'transparent', animation: false,
                color: ['#df6969', '#8c9baa'],
                tooltip: {trigger: 'axis', renderMode: 'richText', valueFormatter: value => Number(value).toLocaleString(pt ? 'pt-BR' : 'en-GB', {maximumFractionDigits: 3}) + ' min'},
                legend: {top: 0, textStyle: {color: muted}},
                grid: {left: 55, right: 18, top: 48, bottom: 28},
                xAxis: {type: 'category', data: data.map(day => day.day.slice(8)), axisLabel: {color: muted}},
                yAxis: {type: 'value', name: 'min', axisLabel: {color: muted}, nameTextStyle: {color: muted}, splitLine: {lineStyle: {color: 'rgba(128,128,128,.15)'}}},
                series: [{name: t('Indisponível', 'Down'), type: 'bar', stack: 'minutes', data: data.map(day => day.summary.down / 60), barMaxWidth: 25},
                    {name: t('Desconhecido', 'Unknown'), type: 'bar', stack: 'minutes', data: data.map(day => day.summary.unknown / 60), barMaxWidth: 25}]
            }, true);
        };
        if (typeof echarts !== 'undefined') {
            root.querySelectorAll('.gav-chart').forEach(node => {
                node.textContent = '';
                const index = Number(node.dataset.department);
                const chart = echarts.init(node, null, {renderer: 'canvas'});
                charts.push(chart);
                draw(chart, index);
                root.querySelector(`.gav-chart-selection[data-department="${index}"]`).addEventListener('change', () => draw(chart, index));
                if (typeof ResizeObserver !== 'undefined') new ResizeObserver(() => chart.resize()).observe(node);
            });
            window.addEventListener('resize', () => charts.forEach(chart => chart.resize()));
        }
        root.querySelectorAll('.gav-open-tech').forEach(link => link.addEventListener('click', () => {
            const details = document.getElementById(link.hash.slice(1));
            if (details) details.open = true;
        }));
        const exportButton = document.getElementById('gav-export');
        exportButton.disabled = false;
        exportButton.addEventListener('click', () => {
            const payload = {format: 'governance-availability-v1', module_version: '1.5.0',
                assumptions: {schedule: '24x7', membership: 'current', maintenance_excluded: false,
                    unknown_policy: 'no final score when unknown time exists', resolution_seconds: 1,
                    interval_list_limit: 200, immutable_close: false}, report};
            const url = URL.createObjectURL(new Blob([JSON.stringify(payload, null, 2)], {type: 'application/json'}));
            const link = document.createElement('a');
            link.href = url;
            link.download = `governance-availability-${report.month}.json`;
            link.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        });
        const printButton = document.getElementById('gav-print');
        printButton.disabled = false;
        printButton.addEventListener('click', () => window.print());
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
    else init();
})();
