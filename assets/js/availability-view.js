(() => {
    const init = () => {
        const root = document.getElementById('gav-dashboard');
        const dataNode = document.getElementById('gav-report-data');
        if (!root) return;
        const page = root.closest('main');
        const layout = root.closest('.wrapper');
        if (page) page.classList.add('gav-page');
        if (layout) layout.classList.add('gav-layout');
        if (!dataNode || root.dataset.initialized) return;
        root.dataset.initialized = '1';
        const pt = root.dataset.lang === 'pt';
        const t = (a, b) => pt ? a : b;
        let report;
        try { report = JSON.parse(dataNode.textContent); }
        catch (error) { return; }
        const entries = [];
        const draw = entry => {
            if (!entry.node.clientWidth || !entry.details.open || typeof echarts === 'undefined') return;
            if (!entry.chart) {
                entry.node.textContent = '';
                entry.chart = echarts.init(entry.node, null, {renderer: 'canvas'});
            }
            const dept = report.departments[entry.index];
            const selected = Number(entry.select.value);
            const data = selected < 0 ? dept.daily : dept.technologies[selected].daily;
            const weighted = selected < 0 || dept.technologies[selected].mode === 'mean';
            const noExceptions = data.every(day => day.summary.down === 0 && day.summary.unknown === 0);
            entry.context.textContent = (weighted ? t('Minutos equivalentes (média ponderada ou por host).', 'Equivalent minutes (weighted mean or mean per host).') : t('Minutos de queda ou lacuna, sem duplicar sobreposições.', 'Minutes of downtime or gaps, without double counting overlaps.'))
                + (noExceptions ? ' ' + t('Nenhuma queda ou lacuna no período.', 'No downtime or gaps in this period.') : '');
            const style = getComputedStyle(root);
            const muted = style.getPropertyValue('--gov-muted').trim();
            entry.chart.setOption({backgroundColor: 'transparent', animation: false,
                textStyle: {fontFamily: style.fontFamily}, color: ['#df6969', '#8c9baa'],
                tooltip: {trigger: 'axis', renderMode: 'richText', backgroundColor: style.getPropertyValue('--gav-panel').trim(),
                    textStyle: {color: style.color}, valueFormatter: value => Number(value).toLocaleString(pt ? 'pt-BR' : 'en-GB', {maximumFractionDigits: 3}) + ' min'},
                legend: {top: 0, textStyle: {color: muted}},
                grid: {left: 55, right: 18, top: 48, bottom: 28},
                xAxis: {type: 'category', data: data.map(day => day.day), axisLabel: {color: muted, formatter: value => value.slice(8)}},
                yAxis: {type: 'value', name: 'min', min: 0, axisLabel: {color: muted}, nameTextStyle: {color: muted}, splitLine: {lineStyle: {color: 'rgba(128,128,128,.15)'}}},
                series: [{name: t('Indisponível', 'Down'), type: 'bar', stack: 'minutes', data: data.map(day => day.summary.down / 60), barMaxWidth: 25},
                    {name: t('Sem dados', 'Unknown'), type: 'bar', stack: 'minutes', data: data.map(day => day.summary.unknown / 60), barMaxWidth: 25}]
            }, true);
            entry.chart.resize();
        };
        root.querySelectorAll('.gav-chart').forEach(node => {
            const index = Number(node.dataset.department);
            const entry = {node, index, chart: null, details: node.closest('.gav-chart-details'),
                select: root.querySelector(`.gav-chart-selection[data-department="${index}"]`),
                context: root.querySelector(`.gav-chart-context[data-department="${index}"]`)};
            entries.push(entry);
            entry.select.addEventListener('change', () => draw(entry));
            entry.details.addEventListener('toggle', () => draw(entry));
            if (typeof ResizeObserver !== 'undefined') new ResizeObserver(() => {
                if (!entry.chart) draw(entry);
                else if (entry.details.open) entry.chart.resize();
            }).observe(node);
            draw(entry);
        });
        window.addEventListener('resize', () => entries.forEach(entry => { if (entry.chart && entry.details.open) entry.chart.resize(); }));
        root.querySelectorAll('.gav-open-tech').forEach(link => link.addEventListener('click', () => {
            const details = document.getElementById(link.hash.slice(1));
            if (details) details.open = true;
        }));
        const exportButton = document.getElementById('gav-export');
        exportButton.disabled = false;
        exportButton.addEventListener('click', () => {
            const payload = {format: 'governance-availability-v1', module_version: '1.5.1',
                assumptions: {schedule: '24x7', membership: 'current', maintenance_excluded: false,
                    unknown_policy: 'no final score when unknown time exists', resolution_seconds: 1,
                    sample_validity: 'per item; resolved values are listed in host sources',
                    interval_list_limit: 200, immutable_close: false}, report};
            const url = URL.createObjectURL(new Blob([JSON.stringify(payload, null, 2)], {type: 'application/json'}));
            const link = document.createElement('a');
            link.href = url;
            link.download = `governance-availability-${report.month}.json`;
            link.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        });
        let printState = null;
        window.addEventListener('beforeprint', () => {
            if (printState) return;
            printState = entries.map(entry => entry.details.open);
            entries.forEach(entry => { entry.details.open = true; draw(entry); });
        });
        window.addEventListener('afterprint', () => {
            entries.forEach((entry, index) => { entry.details.open = printState ? printState[index] : entry.details.open; draw(entry); });
            printState = null;
        });
        const printButton = document.getElementById('gav-print');
        printButton.disabled = false;
        printButton.addEventListener('click', () => window.print());
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
    else init();
})();
