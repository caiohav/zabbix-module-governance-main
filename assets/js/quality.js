/* Current-state quality audit: document first, one authenticated checkpoint at a time. */
(() => {
    const initialize = () => {
        const root = document.getElementById('gqp-dashboard');
        if (!root) return;
        const pt = root.dataset.lang === 'pt';
        const t = (a, b) => pt ? a : b;
        const el = id => document.getElementById('gqp-' + id);
        const message = text => { el('message').textContent = text; };
        const number = value => Number(value).toLocaleString(pt ? 'pt-BR' : 'en-GB', {maximumFractionDigits: 1});
        const status = score => score === null ? 'neutral' : score >= 90 ? 'good' : score >= 70 ? 'warning' : 'critical';
        const translateError = text => {
            if (typeof text !== 'string') return t('Resposta inválida do servidor.', 'Invalid server response.');
            const parts = text.split(' / ');
            return (pt && parts.length > 1 ? parts.slice(1).join(' / ') : parts[0]).slice(0, 800);
        };
        root.closest('main')?.classList.add('gqp-page');
        root.closest('.wrapper')?.classList.add('gqp-layout');
        const cards = new Map(Array.from(root.querySelectorAll('[data-card-id]')).map(node => [node.dataset.cardId, node]));
        const chartScores = new Map();
        const chartInstances = new Map();
        let config, endpoint, job = null, requestId = null, pending = false, leaving = false;
        let nextTimer = null, controller = null, chartsRequested = false, cardsRendered = false, retries = 0;
        const stopLoading = () => {
            el('progress-wrap').hidden = true;
            el('summary').setAttribute('aria-busy', 'false');
            el('cards').setAttribute('aria-busy', 'false');
            root.querySelectorAll('[aria-busy="true"]').forEach(node => node.setAttribute('aria-busy', 'false'));
            if (!cardsRendered) {
                cards.forEach(node => {
                    node.classList.remove('gqp-card-loading');
                    node.querySelector('.gov-card-score-sub').textContent = t('Análise não concluída', 'Analysis not completed');
                });
                el('score-help').textContent = t('O índice não está disponível. Tente novamente.', 'The score is unavailable. Try again.');
            }
            root.querySelectorAll('.gov-overview-hint').forEach(node => {
                if (node.textContent === t('Aguardando', 'Waiting')) node.textContent = t('Não concluído', 'Not completed');
            });
        };
        const fail = text => {
            stopLoading();
            el('message').classList.add('gqp-danger');
            message(text);
            el('retry').hidden = false;
            // A timed-out POST may still be running; only Retry (status) is safe in that case.
            el('refresh').disabled = Boolean(job && job.status === 'running') || Boolean(!job && requestId);
        };
        try {
            config = JSON.parse(el('input').textContent);
            endpoint = new URL(el('token').action, window.location.href);
            if (config.error) throw new Error(config.error);
            if (!window.fetch || !window.AbortController || !window.crypto?.getRandomValues
                    || endpoint.origin !== window.location.origin || endpoint.searchParams.get('action') !== 'governance.quality.run'
                    || !el('token').querySelector('[name="sid"]')?.value) {
                throw new Error(t('Sessão ou navegador incompatível. Entre novamente no Zabbix e recarregue a página.', 'Session or browser unsupported. Sign in to Zabbix again and reload the page.'));
            }
        }
        catch (error) {
            fail(error.message); el('retry').hidden = true; el('refresh').disabled = true; return;
        }

        const drawCharts = () => {
            if (leaving || !window.echarts) return;
            const style = getComputedStyle(root);
            const colors = {good: style.getPropertyValue('--gov-good').trim() || '#1f774b',
                warning: style.getPropertyValue('--gov-warning').trim() || '#89600b',
                critical: style.getPropertyValue('--gov-critical').trim() || '#be3c3c'};
            const track = style.getPropertyValue('--gov-chart-track').trim() || 'rgba(128,128,128,.2)';
            chartScores.forEach(({value: score, conformity}, container) => {
                if (chartInstances.has(container)) return;
                try {
                    container.textContent = '';
                    const chart = window.echarts.init(container);
                    chartInstances.set(container, chart);
                    chart.setOption({backgroundColor: 'transparent', series: [{type: 'gauge', min: 0, max: 100, startAngle: 90, endAngle: -270,
                        pointer: {show: false}, progress: {show: true, roundCap: true, itemStyle: {color: colors[status(conformity)]}},
                        axisLine: {lineStyle: {width: 7, color: [[1, track]]}}, splitLine: {show: false},
                        axisTick: {show: false}, axisLabel: {show: false}, data: [{value: score}],
                        detail: {formatter: () => number(score) + '%', fontSize: 14, color: colors[status(conformity)], offsetCenter: [0, 0]},
                        animationDuration: 300}]});
                }
                catch (error) {
                    chartInstances.get(container)?.dispose(); chartInstances.delete(container);
                    container.textContent = number(score) + '%';
                }
            });
        };
        const loadCharts = () => {
            if (window.echarts) { drawCharts(); return; }
            if (chartsRequested || !chartScores.size) return;
            chartsRequested = true;
            const script = document.createElement('script');
            script.src = root.dataset.echarts; script.async = true;
            script.addEventListener('load', drawCharts, {once: true});
            script.addEventListener('error', () => { chartsRequested = false; }, {once: true});
            // Numerical text remains visible even when ECharts cannot load.
            document.head.appendChild(script);
        };
        const disposeCharts = () => {
            chartInstances.forEach(chart => chart.dispose()); chartInstances.clear(); chartScores.clear();
        };
        const setMetric = (key, value, hint, state = 'complete') => {
            const node = el('metric-' + key);
            node.querySelector('.gov-overview-value').textContent = state === 'complete' ? number(value) : '—';
            node.querySelector('.gov-overview-hint').textContent = state === 'failed'
                ? t('Falha na consulta', 'Query failed') : state === 'pending' ? t('Aguardando', 'Waiting') : hint;
            node.setAttribute('aria-busy', state === 'pending' ? 'true' : 'false');
            const mood = state !== 'complete' ? 'neutral' : key === 'disabled' ? 'neutral'
                : key === 'monitored' || value === 0 ? 'good' : key === 'unsupported_items' ? 'warning' : 'critical';
            node.className = 'gov-overview-item gov-overview-' + mood;
        };
        const renderResult = result => {
            if (!result) return;
            if (!cardsRendered) {
                const total = result.total_hosts;
                el('score').textContent = result.overall_score === null ? '—' : number(result.overall_score) + '%';
                el('summary').className = 'gov-summary-banner gov-status-' + status(result.overall_score);
                el('summary').setAttribute('aria-busy', 'false'); el('cards').setAttribute('aria-busy', 'false');
                el('hosts').textContent = number(total) + ' / ' + number(result.overview.registered) + ' ' + t('hosts analisados', 'hosts analyzed');
                const help = !cards.size ? t('Adicione cards para calcular o índice.', 'Add cards to calculate the score.')
                    : !total ? t('Nenhum host monitorado encontrado para análise.', 'No monitored hosts found for analysis.')
                        : result.overall_score === null ? t('Nenhum card participante possui hosts no escopo.', 'No participating card has hosts in scope.')
                            : t('O índice considera apenas os cards participantes desta página.', 'The score includes only participating cards on this page.');
                el('score-help').textContent = help;
                if (!total) { el('cards').hidden = true; el('empty').hidden = false; el('empty').textContent = help; }
                result.kpis.forEach(kpi => {
                    const node = cards.get(kpi.id);
                    node.className = 'gov-kpi-card gov-card-status-' + status(kpi.score);
                    const chart = node.querySelector('.gov-card-chart');
                    const inverted = kpi.display_mode === 'non_conformity';
                    const value = kpi.score === null ? null : inverted ? 100 - kpi.score : kpi.score;
                    chart.textContent = value === null ? '—' : number(value) + '%';
                    chart.setAttribute('aria-label', node.querySelector('h3').textContent + ': ' + chart.textContent);
                    if (value !== null) chartScores.set(chart, {value, conformity: kpi.score});
                    node.querySelector('.gov-card-score-sub').textContent = !kpi.total_count ? t('Nenhum host no escopo', 'No hosts in scope')
                        : number(inverted ? kpi.total_count - kpi.valid_count : kpi.valid_count) + ' / ' + number(kpi.total_count) + ' '
                            + (inverted ? t('não conformes', 'non-compliant') : t('em conformidade', 'compliant'));
                    node.querySelector('.gov-card-score-missing').textContent = value === null ? ''
                        : number(100 - value) + '% ' + (inverted ? t('em conformidade', 'compliant') : t('não conformes', 'non-compliant'));
                    const exceptions = node.querySelector('.gov-card-exceptions');
                    exceptions.replaceChildren();
                    if (!kpi.total_count) { exceptions.textContent = t('Fora do índice desta página.', 'Excluded from this page score.'); }
                    else if (!kpi.non_compliant.length) { exceptions.textContent = t('100% de conformidade!', '100% compliant!'); }
                    else {
                        const details = document.createElement('details'), summary = document.createElement('summary'), list = document.createElement('ul');
                        list.className = 'gov-nc-list';
                        summary.textContent = number(kpi.total_count - kpi.valid_count) + ' ' + t('não conformes — ver amostra', 'non-compliant — view sample');
                        kpi.non_compliant.forEach(host => {
                            const li = document.createElement('li'), link = document.createElement('a');
                            link.href = 'zabbix.php?action=host.edit&hostid=' + encodeURIComponent(host.hostid);
                            link.textContent = host.name; link.target = '_blank'; link.rel = 'noopener'; li.appendChild(link); list.appendChild(li);
                        });
                        details.append(summary, list); exceptions.appendChild(details);
                    }
                });
                setMetric('monitored', total, number(result.overview.maintenance) + ' ' + t('em manutenção', 'in maintenance'));
                setMetric('disabled', result.overview.disabled, t('Desabilitados', 'Disabled'));
                setMetric('unavailable', result.overview.unavailable, t('Hosts afetados', 'Affected hosts'));
                cardsRendered = true;
                loadCharts();
            }
            setMetric('high_problems', result.metrics.high_problems.value, t('Abertos e não suprimidos', 'Open and unsuppressed'), result.metrics.high_problems.status);
            setMetric('unsupported_items', result.metrics.unsupported_items.value, t('Itens monitorados', 'Monitored items'), result.metrics.unsupported_items.status);
        };
        const validNumber = n => typeof n === 'number' && Number.isFinite(n) && n >= 0;
        const validResult = result => {
            if (result === null) return true;
            if (!result || !Number.isSafeInteger(result.total_hosts) || result.total_hosts < 0
                    || !(result.overall_score === null || (validNumber(result.overall_score) && result.overall_score <= 100))
                    || !Array.isArray(result.kpis) || result.kpis.length !== (result.total_hosts ? cards.size : 0)
                    || !result.overview || !result.metrics) return false;
            const seen = new Set();
            for (const kpi of result.kpis) {
                if (!cards.has(kpi.id) || seen.has(kpi.id)
                        || !(kpi.total_count === 0 ? kpi.score === null : validNumber(kpi.score) && kpi.score <= 100)
                        || !Number.isSafeInteger(kpi.valid_count) || kpi.valid_count < 0 || kpi.valid_count > kpi.total_count
                        || !Number.isSafeInteger(kpi.total_count) || kpi.total_count < 0 || kpi.total_count > result.total_hosts
                        || !Array.isArray(kpi.non_compliant) || kpi.non_compliant.length > Math.min(10, kpi.total_count - kpi.valid_count)) return false;
                seen.add(kpi.id);
                if (kpi.non_compliant.some(host => !/^[1-9][0-9]*$/.test(host.hostid) || typeof host.name !== 'string')) return false;
            }
            return ['registered', 'monitored', 'disabled', 'maintenance', 'unavailable'].every(key => validNumber(result.overview[key]))
                && ['high_problems', 'unsupported_items'].every(key => {
                    const metric = result.metrics[key];
                    return metric && ['pending', 'complete', 'failed'].includes(metric.status)
                        && (metric.status === 'complete' ? validNumber(metric.value) : metric.value === null);
                });
        };
        const accept = data => {
            if (!data || !/^[a-f0-9]{64}$/.test(data.job) || (job && job.job !== data.job)
                    || !Number.isSafeInteger(data.sequence) || data.sequence < (job?.sequence ?? 0)
                    || !['running', 'complete', 'failed', 'cancelled'].includes(data.status)
                    || data.page !== config.page || data.revision !== config.revision || !data.progress
                    || !validResult(data.result) || (data.status === 'complete' && !data.result)) {
                throw new Error(t('Resposta incompatível com esta página. Recarregue para tentar novamente.', 'Response does not match this page. Reload to retry.'));
            }
            job = data;
            renderResult(data.result);
            const progress = data.progress;
            const stages = {scope: t('Consultando hosts…', 'Reading hosts…'), hosts: t('Avaliando indicadores…', 'Evaluating indicators…'),
                problems: t('Consultando problemas…', 'Reading problems…'), unsupported: t('Consultando itens não suportados…', 'Reading unsupported items…')};
            message(stages[progress.stage] || t('Análise concluída.', 'Analysis completed.'));
            if (progress.stage === 'hosts' && progress.hosts_total > 0) {
                el('progress').max = progress.hosts_total; el('progress').value = progress.hosts_done;
                el('progress-text').textContent = number(progress.hosts_done) + ' / ' + number(progress.hosts_total) + ' hosts';
            }
            else { el('progress').removeAttribute('value'); el('progress-text').textContent = ''; }
            const formatTime = seconds => new Date(seconds * 1000).toLocaleString(pt ? 'pt-BR' : 'en-GB');
            el('timing').textContent = validNumber(data.started_at) ? t('Consulta iniciada: ', 'Query started: ') + formatTime(data.started_at)
                + (data.finished_at ? ' → ' + formatTime(data.finished_at) : '') + ' · ' + number(progress.calls || 0) + ' ' + t('chamadas à API', 'API calls') : '';
        };
        const post = async operation => {
            const body = new URLSearchParams({sid: el('token').querySelector('[name="sid"]').value, operation});
            if (operation === 'start') {
                body.set('page', config.page); body.set('revision', config.revision); body.set('request_id', requestId);
                config.groupids.forEach(id => body.append('groupids[]', String(id)));
            }
            else { body.set('job', job.job); body.set('sequence', String(job.sequence)); }
            controller = new AbortController();
            const timer = setTimeout(() => controller?.abort(), 25000);
            try {
                const response = await fetch(endpoint.href, {method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', Accept: 'application/json'},
                    body: body.toString(), signal: controller.signal});
                if (!response.ok) throw new Error(t('O servidor recusou a consulta. Confira a sessão e tente novamente.', 'The server rejected the query. Check your session and retry.'));
                return await response.json();
            }
            finally { clearTimeout(timer); controller = null; }
        };
        const drive = async operation => {
            if (pending || leaving) return;
            pending = true; el('retry').hidden = true; el('refresh').disabled = true;
            el('message').classList.remove('gqp-danger'); el('progress-wrap').hidden = false;
            let next = null, delay = 75;
            try {
                const data = await post(operation);
                if (leaving) return;
                if (data?.status === 'busy') {
                    if (++retries > 3) throw new Error(t('O servidor ainda está ocupado. Tente novamente em instantes.', 'The server is still busy. Retry shortly.'));
                    message(t('Uma etapa ainda está em andamento no servidor…', 'A stage is still running on the server…'));
                    next = job ? 'status' : 'start'; delay = 1500;
                }
                else if (data?.status === 'failed') {
                    // Failed starts may not have a confirmed page/revision. Never render their result.
                    if (job) job = Object.assign({}, job, {status: 'failed'});
                    requestId = null;
                    throw new Error(translateError(data.error));
                }
                else {
                    accept(data); retries = 0;
                    if (job.status === 'running') next = 'step';
                    else {
                        stopLoading(); el('refresh').disabled = false;
                        const partial = job.result && Object.values(job.result.metrics).some(metric => metric.status === 'failed');
                        message(partial ? t('Cards concluídos. Algumas métricas operacionais falharam; use Atualizar para tentar novamente.', 'Cards completed. Some operational metrics failed; use Refresh to retry.')
                            : job.status === 'cancelled' ? t('Análise cancelada.', 'Analysis cancelled.') : t('Indicadores atualizados.', 'Indicators updated.'));
                    }
                }
            }
            catch (error) {
                if (!leaving) fail(error.name === 'AbortError'
                    ? t('O tempo de espera terminou. A etapa pode continuar no servidor; tente novamente para consultar o andamento.', 'The wait timed out. The stage may still run on the server; retry to check progress.')
                    : error.name === 'SyntaxError' ? t('Resposta inválida. Confira sua sessão e tente novamente.', 'Invalid response. Check your session and retry.')
                        : error.name === 'TypeError' ? t('Falha de comunicação. Confira sua sessão e tente novamente.', 'Communication failed. Check your session and retry.') : error.message);
            }
            finally { pending = false; }
            if (next && !leaving) nextTimer = setTimeout(() => drive(next), delay);
        };
        const start = () => {
            if (pending || leaving) return;
            clearTimeout(nextTimer); disposeCharts(); cardsRendered = false; job = null; retries = 0;
            requestId = Array.from(window.crypto.getRandomValues(new Uint8Array(32)), n => n.toString(16).padStart(2, '0')).join('');
            el('cards').hidden = false; el('empty').hidden = Boolean(cards.size);
            el('summary').className = 'gov-summary-banner gov-status-neutral'; el('score').textContent = '—';
            el('hosts').textContent = t('Aguardando análise', 'Waiting for analysis'); el('timing').textContent = '';
            el('summary').setAttribute('aria-busy', 'true'); el('cards').setAttribute('aria-busy', 'true');
            el('score-help').textContent = t('O índice será exibido após concluir todos os cards participantes.', 'The score will appear after all participating cards are complete.');
            ['monitored', 'disabled', 'unavailable', 'high_problems', 'unsupported_items'].forEach(key => setMetric(key, null, '', 'pending'));
            cards.forEach(node => {
                node.className = 'gov-kpi-card gqp-card-loading';
                node.querySelector('.gov-card-chart').textContent = '—';
                node.querySelector('.gov-card-chart').setAttribute('aria-label', t('Aguardando resultado', 'Waiting for result'));
                node.querySelector('.gov-card-score-sub').textContent = t('Carregando…', 'Loading…');
                node.querySelector('.gov-card-score-missing').textContent = ''; node.querySelector('.gov-card-exceptions').replaceChildren();
            });
            message(t('Carregando indicadores… Você pode continuar navegando.', 'Loading indicators… You can continue navigating.'));
            drive('start');
        };
        el('refresh').addEventListener('click', () => { if (!el('refresh').disabled) start(); });
        el('retry').addEventListener('click', () => {
            if (pending) return;
            retries = 0;
            if (job?.status === 'running') drive('status');
            else if (!job && requestId) drive('start'); // reuse nonce if the first reply was lost
            else start();
        });
        window.addEventListener('resize', () => chartInstances.forEach(chart => chart.resize()));
        window.addEventListener('pagehide', () => { leaving = true; clearTimeout(nextTimer); controller?.abort(); disposeCharts(); });
        window.addEventListener('pageshow', event => { if (event.persisted) { leaving = false; start(); } });
        // Yield a paint before starting the automatic query. ECharts is loaded only after results exist.
        nextTimer = setTimeout(start, 0);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, {once: true});
    else initialize();
})();
