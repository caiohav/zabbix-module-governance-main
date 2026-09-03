(() => {
    const initJobs = (root, t, hasReport) => {
        const form = document.getElementById('gav-filters');
        const tokenForm = document.getElementById('gav-job-token');
        const panel = document.getElementById('gav-job');
        if (!form || !tokenForm || !panel) return;
        const month = form.querySelector('[name="month"]');
        const department = form.querySelector('[name="department"]');
        const calculate = document.getElementById('gav-calculate');
        const pause = document.getElementById('gav-job-pause');
        const resume = document.getElementById('gav-job-resume');
        const newCalculation = document.getElementById('gav-job-new');
        const progressBar = document.getElementById('gav-job-progress');
        const locale = root.dataset.lang === 'pt' ? 'pt-BR' : 'en-GB';
        const validId = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
        const terminal = status => ['complete', 'failed', 'cancelled'].includes(status);
        const configured = department.options.length > 1;
        let job = null, startInput = null, clientSnapshot = null;
        let active = false, pending = false, leaving = false, nextTimer = null, phase = 'idle', notice = '', busyRetries = 0;
        const endpoint = new URL(tokenForm.action, window.location.href);
        const supported = typeof window.fetch === 'function' && typeof window.AbortController === 'function'
            && window.crypto && typeof window.crypto.getRandomValues === 'function'
            && endpoint.origin === window.location.origin && endpoint.searchParams.get('action') === 'governance.availability.run'
            && tokenForm.querySelector('input[name="sid"]');
        const setText = (id, value) => {
            const node = document.getElementById(id);
            if (node && node.textContent !== value) node.textContent = value;
        };
        const numeric = value => value !== null && value !== undefined && Number.isFinite(Number(value));
        const number = value => numeric(value) ? Number(value).toLocaleString(locale, {maximumFractionDigits: 0}) : '—';
        const errorText = value => {
            let message = typeof value === 'string' ? value : '';
            if (value && Array.isArray(value.messages)) message = value.messages.filter(item => typeof item === 'string').join(' ');
            const separator = message.indexOf(' / ');
            if (separator !== -1) message = root.dataset.lang === 'pt' ? message.slice(separator + 3) : message.slice(0, separator);
            return message.slice(0, 800);
        };
        const requestError = message => {
            const error = new Error(message);
            error.userMessage = message;
            return error;
        };
        const clock = (value, timezone) => {
            if (!numeric(value)) return '';
            try {
                return new Intl.DateTimeFormat(locale, {timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit', second: '2-digit'}).format(new Date(Number(value) * 1000));
            }
            catch (error) { return ''; }
        };
        const rememberUrl = snapshot => {
            const url = new URL(window.location.href);
            url.searchParams.set('action', 'governance.availability.view');
            url.searchParams.delete('sid');
            if (job && validId(job.job)) url.searchParams.set('job', job.job);
            else url.searchParams.delete('job');
            if (snapshot && /^\d{4}-\d{2}$/.test(snapshot.month)) url.searchParams.set('month', snapshot.month);
            if (snapshot && /^-?\d+$/.test(String(snapshot.department))) url.searchParams.set('department', snapshot.department);
            try { window.history.replaceState(null, '', url.href); }
            catch (error) { /* The calculation also remains usable when history updates are unavailable. */ }
        };
        const hideReport = () => {
            root.dataset.reportStale = '1';
            const report = document.getElementById('gav-report');
            const actions = document.getElementById('gav-filter-actions');
            if (report) report.hidden = true;
            if (actions) actions.hidden = true;
            ['gav-export', 'gav-print'].forEach(id => {
                const button = document.getElementById(id);
                if (button) button.disabled = true;
            });
        };
        const render = () => {
            const resumable = (job && !terminal(job.status)) || (!job && startInput && phase !== 'failed');
            const locked = Boolean(pending || active || resumable);
            const reportVisible = hasReport && root.dataset.reportStale !== '1';
            month.disabled = locked;
            department.disabled = locked;
            calculate.disabled = !supported || !configured || locked;
            calculate.textContent = active || pending ? t('Calculando…', 'Calculating…') : t('Calcular mês', 'Calculate month');
            panel.hidden = phase === 'idle' || (phase === 'complete' && reportVisible);
            panel.classList.toggle('gav-job-has-error', phase === 'retry');
            panel.classList.toggle('gav-job-failed', phase === 'failed');
            pause.hidden = !active;
            resume.hidden = !supported || !['paused', 'retry', 'complete'].includes(phase) || (phase === 'complete' && reportVisible);
            resume.disabled = pending;
            resume.textContent = phase === 'complete' ? t('Abrir relatório', 'Open report')
                : job ? t('Continuar cálculo', 'Resume calculation') : t('Tentar novamente', 'Retry');
            newCalculation.hidden = active || pending || phase === 'idle' || (phase === 'complete' && reportVisible);
            const states = {
                starting: t('Iniciando', 'Starting'), running: t('Em processamento', 'Processing'), pausing: t('Pausa solicitada', 'Pause requested'),
                paused: t('Pausado nesta aba', 'Paused in this tab'), retry: t('Confirmação pendente', 'Confirmation pending'),
                failed: t('Falha no processamento', 'Processing failed'), cancelled: t('Cancelado', 'Cancelled'), complete: t('Concluído', 'Completed')
            };
            setText('gav-job-state', states[phase] || '');
            setText('gav-job-message', notice || t('Consultando as fontes em etapas. O relatório será exibido somente após a conclusão.', 'Querying sources in stages. The report will appear only after completion.'));
            const progress = job && job.progress ? job.progress : {};
            const stages = {
                groups: t('Resolvendo grupos', 'Resolving groups'), scope_hosts: t('Identificando hosts', 'Identifying hosts'),
                scope_items: t('Identificando itens', 'Identifying items'), check: t('Preparando verificação', 'Preparing check'),
                scope_sla: t('Consultando definição do SLA', 'Reading SLA definition'),
                scope_sla_service: t('Validando serviço do SLA', 'Validating SLA service'),
                sla: t('Consultando SLI mensal', 'Reading monthly SLI'),
                sla_verify: t('Conferindo a definição do SLA', 'Verifying SLA definition'),
                history: t('Lendo histórico', 'Reading history'), trend: t('Lendo trends horárias', 'Reading hourly trends'),
                host: t('Consolidando host', 'Aggregating host'),
                technology: t('Consolidando tecnologia', 'Aggregating technology'), department: t('Consolidando departamento', 'Aggregating department'),
                finish: t('Preparando relatório completo', 'Preparing complete report'), complete: t('Processamento concluído', 'Processing completed')
            };
            setText('gav-job-stage', stages[progress.stage] || t('Preparando o cálculo', 'Preparing calculation'));
            if (numeric(progress.percent)) {
                const value = Math.max(0, Math.min(100, Number(progress.percent)));
                progressBar.value = value;
                setText('gav-job-percent', value.toLocaleString(locale, {maximumFractionDigits: 1}) + '% ' + t('processado', 'processed'));
            }
            else {
                progressBar.removeAttribute('value');
                setText('gav-job-percent', '—');
            }
            setText('gav-job-hosts', number(progress.hosts_done) + ' / ' + number(progress.hosts_total));
            setText('gav-job-checks', number(progress.checks_done) + ' / ' + number(progress.checks_total));
            setText('gav-job-slas', number(progress.slas_done) + ' / ' + number(progress.slas_total));
            const slaCount = document.getElementById('gav-job-slas-count');
            if (slaCount) slaCount.hidden = !(Number(progress.slas_total) > 0);
            setText('gav-job-rows', number(progress.rows));
            setText('gav-job-calls', number(progress.calls));
            const context = [
                [t('Departamento', 'Department'), progress.department], [t('Tecnologia', 'Technology'), progress.technology], ['Host', progress.host]
            ].filter(entry => typeof entry[1] === 'string' && entry[1]).map(entry => entry[0] + ': ' + entry[1]);
            setText('gav-job-context', context.join(' · '));
            const snapshot = (job && job.snapshot) || clientSnapshot || {};
            const departmentName = snapshot.department_name || (Number(snapshot.department) === -1 ? t('Todos os departamentos', 'All departments') : '');
            const knownMonth = /^\d{4}-\d{2}$/.test(snapshot.month);
            setText('gav-job-snapshot', knownMonth ? [snapshot.month, departmentName].filter(Boolean).join(' · ')
                : phase === 'failed' ? t('Período não confirmado', 'Period not confirmed')
                    : t('Aguardando confirmação do período', 'Waiting for period confirmation'));
            const timezone = snapshot.timezone || '';
            const from = clock(snapshot.from, timezone), to = clock(snapshot.to, timezone);
            const frozen = clock(snapshot.generated_at, timezone);
            const policy = snapshot.data_policy === 'observed' ? t('Dados disponíveis', 'Available data')
                : snapshot.data_policy === 'strict' ? t('Cobertura completa exigida', 'Complete coverage required') : '';
            setText('gav-job-period', [from && to ? from + ' → ' + to : '', timezone,
                frozen ? t('Recorte fixado em ', 'Cutoff fixed at ') + frozen : '', knownMonth ? policy : ''].filter(Boolean).join(' · '));
            setText('gav-job-snapshot-note', job && job.snapshot && knownMonth && numeric(snapshot.from) && numeric(snapshot.to)
                ? t('Regras e período fixados ao iniciar. Nenhum resultado parcial será publicado.', 'Rules and period are fixed at the start. No partial result will be published.')
                : phase === 'failed' ? t('Não há relatório concluído deste cálculo. Ajuste os filtros ou escolha outro período.', 'There is no completed report for this calculation. Adjust the filters or choose another period.')
                    : t('Aguardando confirmação do início. O período escolhido será preservado ao tentar novamente.', 'Waiting for start confirmation. The selected period will be preserved when retrying.'));
            const idleHelp = document.getElementById('gav-idle-help');
            if (idleHelp && phase !== 'idle') idleHelp.hidden = true;
        };
        const accept = projection => {
            if (!projection || typeof projection !== 'object' || Array.isArray(projection)
                || !validId(projection.job) || !Number.isSafeInteger(Number(projection.sequence)) || Number(projection.sequence) < 0
                || !['running', 'complete', 'failed', 'cancelled'].includes(projection.status)
                || (job && job.job !== projection.job) || (job && Number(projection.sequence) < job.sequence)) {
                throw requestError(t('A resposta não confirmou o estado deste cálculo.', 'The response did not confirm this calculation’s state.'));
            }
            job = Object.assign({}, projection, {sequence: Number(projection.sequence)});
            if (job.snapshot) {
                if (/^\d{4}-\d{2}$/.test(job.snapshot.month)) month.value = job.snapshot.month;
                const departmentId = String(job.snapshot.department);
                if (/^-?\d+$/.test(departmentId)) {
                    if (!Array.from(department.options).some(option => option.value === departmentId) && job.snapshot.department_name) {
                        const option = document.createElement('option');
                        option.value = departmentId;
                        option.textContent = job.snapshot.department_name;
                        department.add(option);
                    }
                    department.value = departmentId;
                }
            }
            rememberUrl(job.snapshot || clientSnapshot);
        };
        const openResult = () => {
            try {
                if (!job || job.status !== 'complete' || typeof job.result_url !== 'string') throw new Error();
                const url = new URL(job.result_url, window.location.href);
                if (url.origin !== window.location.origin || url.pathname !== endpoint.pathname || url.username || url.password
                    || url.searchParams.getAll('action').length !== 1 || url.searchParams.get('action') !== 'governance.availability.view'
                    || url.searchParams.getAll('job').length !== 1 || url.searchParams.get('job') !== job.job) throw new Error();
                window.location.assign(url.href);
            }
            catch (error) {
                notice = t('O cálculo terminou, mas o endereço do relatório não pôde ser validado. Nenhum resultado foi aberto.', 'The calculation finished, but the report address could not be validated. No result was opened.');
                render();
            }
        };
        const post = async operation => {
            const body = new URLSearchParams();
            const sid = tokenForm.querySelector('input[name="sid"]');
            if (!sid || !sid.value) throw requestError(t('A sessão não forneceu a validação necessária. Recarregue a página após entrar no Zabbix.', 'The session did not provide the required validation. Reload this page after signing in to Zabbix.'));
            body.set('sid', sid.value);
            body.set('operation', operation);
            if (operation === 'start') {
                body.set('month', startInput.month);
                body.set('department', startInput.department);
                body.set('request_id', startInput.request_id);
            }
            else {
                body.set('job', job.job);
                body.set('sequence', job.sequence);
            }
            const controller = new AbortController();
            let timedOut = false;
            const timeout = setTimeout(() => { timedOut = true; controller.abort(); }, 25000);
            try {
                const response = await fetch(endpoint.href, {method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', Accept: 'application/json'},
                    body: body.toString(), signal: controller.signal});
                let payload;
                try { payload = await response.json(); }
                catch (error) { throw requestError(t('O servidor não devolveu uma confirmação JSON. Verifique a sessão antes de continuar.', 'The server did not return a JSON confirmation. Check the session before resuming.')); }
                if (!payload || typeof payload !== 'object' || Array.isArray(payload)) throw requestError(t('Resposta de processamento inválida.', 'Invalid processing response.'));
                if (!['running', 'complete', 'failed', 'cancelled', 'busy'].includes(payload.status)) {
                    throw requestError(errorText(payload.error) || t('O servidor não confirmou a solicitação.', 'The server did not confirm the request.'));
                }
                return payload;
            }
            catch (error) {
                if (timedOut) throw requestError(t('O tempo de espera desta etapa terminou; a consulta pode continuar no servidor.', 'This stage’s wait timed out; the query may still be running on the server.'));
                throw error;
            }
            finally { clearTimeout(timeout); }
        };
        const drive = async operation => {
            if (!active || pending) return;
            pending = true;
            phase = operation === 'start' ? 'starting' : 'running';
            render();
            let next = null, delay = 100, completed = false;
            try {
                const payload = await post(operation);
                if (payload.status === 'busy' && payload.retryable === true) {
                    // A timeout does not stop PHP. Busy responses never replace the last confirmed checkpoint.
                    busyRetries++;
                    if (active && busyRetries <= 3) {
                        notice = t('Uma etapa ainda está em andamento no servidor. Aguardando sua confirmação…', 'A stage is still running on the server. Waiting for its confirmation…');
                        next = job ? 'status' : 'start';
                        delay = 1500;
                    }
                    else {
                        active = false;
                        phase = 'retry';
                        notice = t('O servidor ainda está ocupado. O avanço confirmado foi preservado; use Continuar para consultar novamente.', 'The server is still busy. Confirmed progress was preserved; use Resume to check again.');
                    }
                }
                else if (payload.status === 'failed' && payload.retryable === false && !payload.progress) {
                    // Invalid input or an unavailable/expired job has no checkpoint projection to replace our counters.
                    if (job && payload.job && payload.job !== job.job) throw requestError(t('A resposta pertence a outro cálculo.', 'The response belongs to another calculation.'));
                    if (job) job = Object.assign({}, job, {status: 'failed', error: payload.error});
                    startInput = null;
                    active = false;
                    phase = 'failed';
                    notice = t('Falha no processamento. Nenhum índice foi publicado. ', 'Processing failed. No index was published. ') + errorText(payload.error);
                }
                else {
                    accept(payload);
                    busyRetries = 0;
                    if (terminal(job.status)) {
                        active = false;
                        phase = job.status;
                        completed = job.status === 'complete';
                        notice = completed ? t('Cálculo concluído. Abrindo o relatório completo…', 'Calculation completed. Opening the full report…')
                            : job.status === 'failed' ? t('Falha no processamento. Nenhum índice foi publicado. ', 'Processing failed. No index was published. ') + errorText(job.error)
                                : t('Cálculo cancelado. Nenhum resultado parcial foi publicado.', 'Calculation cancelled. No partial result was published.');
                    }
                    else {
                        phase = active ? 'running' : 'paused';
                        notice = active ? '' : t('Pausado nesta aba. O último avanço confirmado foi salvo; use Continuar para retomar.', 'Paused in this tab. The last confirmed progress was saved; use Resume to continue.');
                        if (active) next = 'step';
                    }
                }
            }
            catch (error) {
                active = false;
                phase = 'retry';
                const detail = error.userMessage || t('Não foi possível confirmar a comunicação com o servidor.', 'Communication with the server could not be confirmed.');
                notice = detail + ' ' + t('O avanço confirmado foi preservado. Use Continuar ou Tentar novamente; esta falha não é um resultado de disponibilidade.', 'Confirmed progress was preserved. Use Resume or Retry; this failure is not an availability result.');
            }
            finally {
                pending = false;
                if (!active && phase === 'running') phase = 'paused';
                render();
            }
            if (completed && !leaving) openResult();
            else if (next && active) nextTimer = setTimeout(() => { nextTimer = null; drive(next); }, delay);
        };
        form.addEventListener('submit', event => {
            event.preventDefault();
            if (calculate.disabled || active || pending || !form.reportValidity()) return;
            // Capture fields before disabling them, and keep this nonce when a start acknowledgement is lost.
            const bytes = new Uint8Array(32);
            window.crypto.getRandomValues(bytes);
            startInput = {month: month.value, department: department.value,
                request_id: Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('')};
            clientSnapshot = {month: month.value, department: department.value,
                department_name: department.options[department.selectedIndex].textContent, timezone: root.dataset.timezone};
            job = null;
            busyRetries = 0;
            active = true;
            notice = '';
            hideReport();
            const pageError = document.getElementById('gav-page-error');
            if (pageError) pageError.hidden = true;
            rememberUrl(clientSnapshot);
            drive('start');
        });
        pause.addEventListener('click', () => {
            active = false;
            clearTimeout(nextTimer);
            nextTimer = null;
            phase = pending ? 'pausing' : 'paused';
            notice = pending
                ? t('Pausa solicitada. A consulta enviada pode terminar no servidor; nenhuma nova etapa será iniciada nesta aba.', 'Pause requested. The submitted query may finish on the server; no new stage will start in this tab.')
                : t('Pausado nesta aba. Use Continuar para retomar o mesmo cálculo.', 'Paused in this tab. Use Resume to continue the same calculation.');
            render();
        });
        resume.addEventListener('click', () => {
            if (pending || active) return;
            if (job && job.status === 'complete') { openResult(); return; }
            if (!job && !startInput) return;
            active = true;
            notice = '';
            busyRetries = 0;
            drive(job ? 'status' : 'start');
        });
        window.addEventListener('pagehide', () => {
            leaving = true;
            active = false;
            clearTimeout(nextTimer);
            nextTimer = null;
            if (job && !terminal(job.status)) {
                phase = pending ? 'pausing' : 'paused';
                notice = t('Pausado nesta aba. Use Continuar para retomar o mesmo cálculo.', 'Paused in this tab. Use Resume to continue the same calculation.');
                render();
            }
        });
        window.addEventListener('pageshow', () => {
            leaving = false;
        });
        try {
            const node = document.getElementById('gav-job-data');
            const saved = node ? JSON.parse(node.textContent) : null;
            if (saved && saved.status === 'busy' && saved.retryable === true && validId(saved.job)) {
                // A GET during a locked checkpoint may know only the owned ID. Query status before any step.
                job = {job: saved.job, sequence: 0, status: 'running', progress: {}};
                phase = 'retry';
                notice = t('Uma etapa está em andamento no servidor. Use Continuar para confirmar o período e o avanço salvo.', 'A stage is running on the server. Use Resume to confirm the period and saved progress.');
                hideReport();
                rememberUrl(null);
            }
            else if (saved) {
                accept(saved);
                phase = terminal(job.status) ? job.status : 'paused';
                if (phase !== 'complete') hideReport();
                notice = phase === 'paused' ? t('Cálculo salvo. Clique em Continuar para retomar do último avanço confirmado.', 'Saved calculation. Click Resume to continue from the last confirmed progress.')
                    : phase === 'failed' ? t('Falha no processamento. Nenhum índice foi publicado. ', 'Processing failed. No index was published. ') + errorText(job.error)
                        : phase === 'cancelled' ? t('Cálculo cancelado. Nenhum resultado parcial foi publicado.', 'Calculation cancelled. No partial result was published.')
                            : t('O processamento já terminou. Abra o relatório completo.', 'Processing has already finished. Open the complete report.');
            }
        }
        catch (error) {
            phase = 'failed';
            notice = t('Não foi possível carregar o cálculo salvo. Nenhum índice foi publicado; inicie um novo cálculo.', 'The saved calculation could not be loaded. No index was published; start a new calculation.');
        }
        if (!supported) {
            phase = 'failed';
            notice = t('O cálculo requer JavaScript, um navegador compatível e uma sessão válida do Zabbix. Recarregue após entrar novamente.', 'The calculation requires JavaScript, a compatible browser and a valid Zabbix session. Reload after signing in again.');
        }
        render();
    };
    const init = () => {
        const root = document.getElementById('gav-dashboard');
        const dataNode = document.getElementById('gav-report-data');
        if (!root) return;
        const page = root.closest('main');
        const layout = root.closest('.wrapper');
        if (page) page.classList.add('gav-page');
        if (layout) layout.classList.add('gav-layout');
        if (root.dataset.initialized) return;
        root.dataset.initialized = '1';
        const pt = root.dataset.lang === 'pt';
        const t = (a, b) => pt ? a : b;
        initJobs(root, t, Boolean(dataNode));
        if (!dataNode) return;
        let report;
        try { report = JSON.parse(dataNode.textContent); }
        catch (error) { return; }
        const observedPolicy = report.data_policy === 'observed';
        const metric = node => observedPolicy && node.observation
            ? {...node.summary, ...(node.observation.summary || {}), score: node.observation.score, coverage: node.observation.coverage}
            : node.summary;
        const daily = (node, labels = null) => {
            const rows = observedPolicy && node?.observation?.daily ? node.observation.daily : node?.daily;
            if (!Array.isArray(rows)) return rows;
            if (!rows.length || !Array.isArray(rows[0])) return rows;
            return rows.map((point, index) => ({day: labels?.[index]?.day || String(index + 1),
                score: point.length ? point[0] : null, coverage: point.length > 1 ? point[1] : 0, summary: {}}));
        };
        const dailyMetric = day => {
            if (Array.isArray(day)) return {score: day.length ? day[0] : null, coverage: day.length > 1 ? day[1] : 0};
            const summary = day?.summary || {};
            return {score: Object.prototype.hasOwnProperty.call(day || {}, 'score') ? day.score
                : observedPolicy ? summary.observed ?? null : summary.score ?? null,
            coverage: Object.prototype.hasOwnProperty.call(day || {}, 'coverage') ? day.coverage : summary.coverage ?? 0};
        };
        const entries = [];
        const percent = value => value === null || value === undefined || !Number.isFinite(Number(value)) ? '—'
            : (value < 100 && value > 99.999999 ? '<100%'
                : Number(value).toLocaleString(pt ? 'pt-BR' : 'en-GB', {maximumFractionDigits: 6}) + '%');
        const draw = entry => {
            if (!entry.node.clientWidth || !entry.details.open || typeof echarts === 'undefined') return;
            const dept = report.departments[entry.index];
            if (!dept) return;
            const selected = entry.kind === 'daily' ? Number(entry.select.value) : null;
            const technology = entry.kind === 'host' ? dept.technologies[entry.technology] : null;
            const host = technology && entry.kind === 'host' ? technology.hosts[entry.host] : null;
            const source = entry.kind === 'host' ? host : (entry.kind === 'daily'
                ? (selected < 0 ? dept : dept.technologies[selected]) : null);
            const data = entry.kind === 'monthly' ? null : daily(source,
                entry.kind === 'host' ? daily(technology) : null);
            if (entry.kind !== 'monthly' && (!Array.isArray(data) || !data.length)) {
                if (entry.chart) { entry.chart.dispose(); entry.chart = null; }
                entry.node.textContent = t('Esta fonte não fornece distribuição diária. Consulte o resumo mensal.', 'This source does not provide a daily distribution. Check the monthly summary.');
                return;
            }
            if (!entry.chart) {
                entry.node.textContent = '';
                entry.chart = echarts.init(entry.node, null, {renderer: 'canvas'});
            }
            const style = getComputedStyle(root);
            const muted = style.getPropertyValue('--gov-muted').trim();
            if (entry.kind === 'monthly') {
                const nativeSlos = dept.technologies.map(tech => (tech.source || 'items') === 'sla'
                    && Number.isFinite(Number(tech.native_sla?.slo)) ? Number(tech.native_sla.slo) : null);
                entry.chart.setOption({backgroundColor: 'transparent', animation: false, color: ['#60aa87', '#d99d40', '#9c86d8'],
                    textStyle: {fontFamily: style.fontFamily},
                    tooltip: {trigger: 'axis', renderMode: 'richText', backgroundColor: style.getPropertyValue('--gav-panel').trim(),
                        textStyle: {color: style.color}, valueFormatter: value => percent(Array.isArray(value) ? value[0] : value)},
                    legend: {top: 0, textStyle: {color: muted}},
                    grid: {left: 16, right: 78, top: 38, bottom: 22, containLabel: true},
                    xAxis: {type: 'value', min: 0, max: 100, interval: 20, axisLabel: {color: muted, formatter: '{value}%'},
                        splitLine: {lineStyle: {color: 'rgba(128,128,128,.15)'}}},
                    yAxis: {type: 'category', inverse: true, data: dept.technologies.map(tech => tech.name),
                        axisLabel: {color: muted, width: 180, overflow: 'truncate', formatter: (name, index) =>
                            name + (metric(dept.technologies[index]).score === null ? ' —' : '')}},
                    series: [{name: t('Disponibilidade', 'Availability'), type: 'bar', barMaxWidth: 23,
                        data: dept.technologies.map(tech => ({value: metric(tech).score,
                            itemStyle: {color: metric(tech).score === null ? '#8c9baa'
                                : (metric(tech).score >= tech.target ? '#60aa87' : '#df6969')}})),
                        label: {show: true, position: 'right', color: style.color, formatter: info => percent(info.value)}},
                    {name: t('Meta do indicador', 'Indicator target'), type: 'scatter', symbol: 'rect', symbolSize: [3, 23],
                        itemStyle: {color: '#d99d40'}, data: dept.technologies.map((tech, index) => [tech.target, index])},
                    {name: t('SLO nativo', 'Native SLO'), type: 'scatter', symbol: 'diamond', symbolSize: 9,
                        itemStyle: {color: '#9c86d8'}, data: nativeSlos.flatMap((slo, index) => slo === null ? [] : [[slo, index]])}]
                }, true);
                entry.chart.resize();
                return;
            }
            const target = Number(entry.kind === 'host' ? technology.target
                : selected < 0 ? dept.target : dept.technologies[selected].target);
            const values = data.map(dailyMetric);
            if (entry.context) entry.context.textContent = t(
                'Cada ponto reaplica o critério do indicador ao respectivo dia; cobertura usa eixo separado e lacunas não viram disponibilidade. A média simples dos dias pode diferir do indicador mensal.',
                'Each point reapplies the indicator criterion to that day; coverage uses a separate axis and gaps never become availability. A simple mean of the days may differ from the monthly indicator.');
            entry.chart.setOption({backgroundColor: 'transparent', animation: false,
                textStyle: {fontFamily: style.fontFamily}, color: ['#60aa87', '#d99d40', '#71b6df'],
                tooltip: {trigger: 'axis', renderMode: 'richText', backgroundColor: style.getPropertyValue('--gav-panel').trim(),
                    textStyle: {color: style.color}, formatter: params => {
                        const index = params?.[0]?.dataIndex;
                        if (!Number.isInteger(index) || !data[index]) return '';
                        return [data[index].day,
                            t('Disponibilidade: ', 'Availability: ') + percent(values[index].score),
                            t('Cobertura: ', 'Coverage: ') + percent(values[index].coverage),
                            t('Meta: ', 'Target: ') + percent(target)].join('\n');
                    }},
                legend: {top: 0, textStyle: {color: muted}},
                axisPointer: {link: [{xAxisIndex: 'all'}]},
                grid: [{left: 58, right: 22, top: 48, height: '52%'},
                    {left: 58, right: 22, top: '78%', height: '10%'}],
                xAxis: [{type: 'category', data: data.map(day => day.day), gridIndex: 0,
                    axisLabel: {show: false}, axisTick: {show: false}},
                {type: 'category', data: data.map(day => day.day), gridIndex: 1, axisLabel: {color: muted,
                    formatter: value => String(value).length >= 10 ? String(value).slice(8) : String(value)}}],
                yAxis: [{type: 'value', name: t('Disponibilidade', 'Availability'), min: 0, max: 100, interval: 20, gridIndex: 0,
                    axisLabel: {color: muted, formatter: '{value}%'}, nameTextStyle: {color: muted}, splitLine: {lineStyle: {color: 'rgba(128,128,128,.15)'}}},
                {type: 'value', min: 0, max: 100, interval: 100, gridIndex: 1,
                    axisLabel: {color: muted, formatter: '{value}%'}, nameTextStyle: {color: muted}, splitLine: {show: false}}],
                series: [{name: t('Disponibilidade', 'Availability'), type: 'bar', barMaxWidth: 23,
                    data: values.map(value => value.score)},
                {name: t('Meta', 'Target'), type: 'line', showSymbol: false, silent: true,
                    data: data.map(() => target), lineStyle: {width: 1, type: 'dotted'}},
                {name: t('Cobertura', 'Coverage'), type: 'bar', xAxisIndex: 1, yAxisIndex: 1, barMaxWidth: 23,
                    data: values.map(value => value.coverage)}]
            }, true);
            entry.chart.resize();
        };
        root.querySelectorAll('.gav-chart').forEach(node => {
            const index = Number(node.dataset.department);
            const entry = {node, index, kind: 'daily', chart: null, details: node.closest('.gav-chart-details'),
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
        root.querySelectorAll('.gav-monthly-chart').forEach(node => {
            const entry = {node, index: Number(node.dataset.department), kind: 'monthly', chart: null,
                details: node.closest('.gav-monthly-details')};
            entries.push(entry);
            node.style.height = Math.max(185, Math.min(1200, 85 + 34 * report.departments[entry.index].technologies.length)) + 'px';
            entry.details.addEventListener('toggle', () => draw(entry));
            if (typeof ResizeObserver !== 'undefined') new ResizeObserver(() => {
                if (!entry.chart) draw(entry);
                else if (entry.details.open) entry.chart.resize();
            }).observe(node);
            draw(entry);
        });
        root.querySelectorAll('.gav-host-chart').forEach(node => {
            const entry = {node, index: Number(node.dataset.department), technology: Number(node.dataset.technology),
                host: Number(node.dataset.host), kind: 'host', chart: null, details: node.closest('.gav-host-chart-details'),
                technologyDetails: node.closest('.gav-tech-detail'), select: null, context: null};
            entries.push(entry);
            entry.details.addEventListener('toggle', () => {
                if (entry.details.open) {
                    entries.filter(peer => peer !== entry && peer.kind === 'host').forEach(peer => {
                            if (peer.details.open) peer.details.open = false;
                            if (peer.chart) peer.chart.dispose();
                            peer.chart = null;
                            peer.node.textContent = t('Abra para carregar o gráfico deste host.', 'Open to load this host chart.');
                        });
                    draw(entry);
                }
                else {
                    if (entry.chart) entry.chart.dispose();
                    entry.chart = null;
                    entry.node.textContent = t('Abra para carregar o gráfico deste host.', 'Open to load this host chart.');
                }
            });
        });
        root.querySelectorAll('.gav-tech-detail').forEach(details => details.addEventListener('toggle', () => {
            const hosts = entries.filter(entry => entry.kind === 'host' && entry.technologyDetails === details);
            if (details.open) hosts.filter(entry => entry.details.open).forEach(draw);
            else hosts.forEach(entry => {
                if (entry.chart) entry.chart.dispose();
                entry.chart = null;
                entry.node.textContent = t('Abra para carregar o gráfico deste host.', 'Open to load this host chart.');
            });
        }));
        window.addEventListener('resize', () => entries.forEach(entry => { if (entry.chart && entry.details.open) entry.chart.resize(); }));
        root.querySelectorAll('.gav-open-tech').forEach(link => link.addEventListener('click', () => {
            const details = document.getElementById(link.hash.slice(1));
            if (details) details.open = true;
        }));
        const exportButton = document.getElementById('gav-export');
        exportButton.disabled = root.dataset.reportStale === '1';
        exportButton.addEventListener('click', () => {
            if (root.dataset.reportStale === '1') return;
            const payload = {format: 'governance-availability-v3', module_version: '1.13.3',
                assumptions: {aggregation: 'weighted mean only for matching periods, schedules and exclusions',
                    data_policy: observedPolicy ? 'observed' : 'strict',
                    items: {schedule: '24x7', membership: 'current', maintenance_excluded: false,
                        unknown_policy: observedPolicy
                            ? 'ignore unknown intervals and hosts, never presume them up; checks inside each host remain required'
                            : 'no final score when unknown time exists',
                        reported_score: observedPolicy ? 'observation.score' : 'summary.score',
                        observed_aggregation: 'mean of host percentages for mean mode; union of known outages for any_down; weighted technology percentages for departments; exclude null indicators from score, not coverage',
                        observed_coverage: 'known-state time averaged over ALL scoped hosts, then ALL configured technology weights',
                        strict_summary_preserved: true, resolution_seconds: 1,
                        daily_indicator: 'each civil day reapplies the same host and technology hierarchy; a simple mean of daily points need not equal the monthly indicator',
                        host_daily_format: '[score, coverage] per civil day, positionally aligned with the parent technology daily calendar',
                        sample_validity: 'per item; resolved values are listed in host sources', interval_list_limit: 200},
                    sla: {method: 'Zabbix sla.getsli', period: 'monthly; closed months only',
                        schedule: 'native SLA calendar, timezone and exclusions', daily_timeline_available: false},
                    automatic_source_fallback: false, immutable_close: false}, report};
            const url = URL.createObjectURL(new Blob([JSON.stringify(payload, null, 2)], {type: 'application/json'}));
            const link = document.createElement('a');
            link.href = url;
            link.download = `governance-availability-${report.month}.json`;
            link.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        });
        let printState = null;
        window.addEventListener('beforeprint', () => {
            if (printState || root.dataset.reportStale === '1') return;
            const printable = entries.filter(entry => entry.kind !== 'host');
            printState = printable.map(entry => ({entry, open: entry.details.open}));
            printable.forEach(entry => { entry.details.open = true; draw(entry); });
        });
        window.addEventListener('afterprint', () => {
            for (const state of printState || []) { state.entry.details.open = state.open; draw(state.entry); }
            printState = null;
        });
        const printButton = document.getElementById('gav-print');
        printButton.disabled = root.dataset.reportStale === '1';
        printButton.addEventListener('click', () => { if (root.dataset.reportStale !== '1') window.print(); });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
    else init();
})();
