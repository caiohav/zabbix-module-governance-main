# Diagnóstico de disponibilidade — julho/2026

Data da avaliação: 28/08/2026, America/Cuiaba.

Status: somente diagnóstico; implementação pendente de solicitação do usuário.

## Escopo e procedimento

Solicitação: testar julho no navegador, avaliar a relação entre retenção/estatísticas e o resultado sem dados, sem modificar a aplicação ou as configurações. Este arquivo é a única alteração local desta avaliação.

Foi utilizado o navegador autenticado em `https://zabbix.tjmt.jus.br`. Não foram alterados itens, grupos, regras, retenção, pré-processamento ou código; nenhum pacote foi implantado. Foram usados filtros de consulta, abertos detalhes e iniciado um único cálculo de julho pelo botão normal do painel. O cálculo gera seu checkpoint temporário habitual, não um fechamento mensal permanente.

Reprodução:

1. Abrir Governança → Disponibilidade (`zabbix.php?action=governance.availability.view`).
2. Selecionar competência `2026-07`, todos os departamentos, e Calcular mês.
3. Abrir “PostgreSQL · 25 hosts avaliados”.
4. Consultar Configurar disponibilidade, Administração → Geral → Limpeza de dados e o item PostgreSQL: Ping de `dbd-apolo-p`, sem salvar.

Ambiente observado: Zabbix 6.0.36. Código local: manifest 1.7.1. A versão implantada do módulo não foi conferida na lista de módulos; a interface em produção apresenta o cálculo em etapas e os diagnósticos por fonte.

## Resultado efetivamente observado

- Departamento DABD; tecnologia PostgreSQL; peso 1; meta 99,9%; grupo `DABD/PostgreSQL`.
- Consolidação: indisponível se qualquer host cair (`any_down`).
- Duas verificações obrigatórias: `icmpping` e `pgsql.ping["{$PG.URI}","{$PG.USER}","{$PG.PASSWORD}"]`.
- Ambas: disponível se valor = 1; outro valor válido = indisponível; validade manual 3600 segundos.
- Período: 01/07/2026 00:00:00 até 01/08/2026 00:00:00, fim exclusivo, calendário 24×7.
- Início: 28/08/2026 11:13:39. Conclusão: 11:14:02.
- 25 hosts avaliados; 50 verificações previstas; 262 chamadas à API; 139.733 linhas lidas, incluindo possíveis repetições da paginação; 23 segundos totais e 3 segundos ativos informados pelo painel.
- Índice final: inconclusivo, “Dados incompletos”; cobertura consolidada 0%; tempo desconhecido 744h; indisponibilidade confirmada 0h; faixa possível 0%–100%.

O processamento terminou normalmente. Não houve erro 500 ou interrupção observada neste teste. Zero horas de queda confirmada NÃO comprova disponibilidade de 100%: faltam evidências para classificar o mês.

## Causa 1: retenção efetiva de histórico insuficiente

Configurações observadas na limpeza de dados:

| Configuração | Valor observado |
| --- | --- |
| Limpeza interna do histórico | Ativada |
| Substituir o período de histórico do item | Ativado |
| Retenção global de histórico | 30d |
| Limpeza interna de estatísticas | Ativada |
| Substituir a retenção das estatísticas | Ativado |
| Retenção global de estatísticas | 365d |
| Compressão de histórico e tendências | Ativada; registros mais antigos que 7d |

O item PostgreSQL: Ping de `dbd-apolo-p` (host 21622, item 1929954) mostra histórico 7d, estatísticas 365d, coleta 1m, tipo numérico inteiro sem sinal e descarte de inalterados com heartbeat 1h. Porém, a substituição global faz prevalecer 30d sobre os 7d desse item. A documentação explica a prioridade global e a exceção para itens configurados para não armazenar histórico/estatísticas. [Zabbix 6.0 — Housekeeper](https://www.zabbix.com/documentation/6.0/en/manual/web_interface/frontend_sections/administration/general#housekeeper)

Os 7d da compressão são outra configuração; não representam conversão do histórico em estatísticas. Não há evidência de falha de leitura por compressão neste teste. [Zabbix 6.0 — TimescaleDB](https://www.zabbix.com/documentation/6.0/en/manual/appendix/install/timescaledb)

O código oficial da API `history.get`, no caminho SQL da versão 6.0.36, limita `time_from` a `max(time_from, time() - hk_history + 1)` quando a substituição global está ativada. Portanto, não basta que alguma partição antiga ainda exista fisicamente: a API aplica o corte. [CHistory.php, linhas 144–148](https://github.com/zabbix/zabbix/blob/6.0.36/ui/include/classes/api/services/CHistory.php#L144-L148)

Em 28/08, 30 dias alcançam aproximadamente 29/07 às 11:13. Isso coincide com as primeiras amostras retornadas. Exemplo diretamente do relatório:

| Fonte de dbd-apolo-p | Amostras em julho | Primeira amostra | Última amostra | Cobertura da fonte |
| --- | ---: | --- | --- | ---: |
| icmpping, ID 1929914 | 7292 | 29/07 11:14:08 | 31/07 23:59:38 | 8,167264% |
| pgsql.ping, ID 1929954 | 61 | 29/07 11:27:54 | 31/07 23:27:54 | 8,136425% |

Conclusão: a retenção impede calcular julho inteiro com o histórico consultável. Não foi feita inspeção direta do banco, nem de backups, portanto não afirmar que todo dado anterior foi fisicamente eliminado. Aumentar retenção agora não recria registros já removidos.

## Causa 2: hosts sem evidência no escopo explicam a cobertura de 0%

Dos 25 hosts, 19 apresentaram amostras do fim de julho. Seis apresentaram zero amostras nas duas verificações e aviso de host atualmente desabilitado:

| Host | ICMP (ID) | PostgreSQL (ID) |
| --- | --- | --- |
| dbd-pje1-backup | 182061 | 184956 |
| dbd-pje1-master-p | 2465009 | Não localizado |
| dbd-pje2-master-p | 2482615 | Não localizado |
| pje2-logs | 95154 | 95192 |
| pje2-slave4 | 107368 | 107406 |
| pje2-slave5 | 102889 | 102927 |

Nos dois masters, a interface informa “Item ausente ou não numérico” e ID vazio para a chave PostgreSQL.

O motor inclui hosts atualmente desabilitados para não descartar automaticamente seu possível histórico. Em `any_down`, uma queda confirmada prevalece; sem queda confirmada, uma fonte obrigatória desconhecida impede afirmar que todos estão disponíveis. Assim, esses hosts tornam todo o mês desconhecido na consolidação, mesmo havendo leituras nos demais.

Não está demonstrado quando esses hosts foram desabilitados, nem se deveriam compor o serviço em julho. A composição é a atual, não uma composição histórica versionada. Excluir desabilitados automaticamente pode apagar participantes válidos de um mês passado. Mudar para média também não recupera os dados ausentes e altera o significado do indicador.

## Causa 3: validade manual e heartbeat

A validade manual de 3600s é igual ao heartbeat. O relatório alerta que a estimativa automática seria 3720s nos itens PostgreSQL compatíveis (heartbeat + duas coletas de 60s). Foram observados intervalos de 1h01m20s em `dbd-dpf-p-01` e 1h01m11s em `srv-dbd-pgdiversos`.

Pequenos atrasos podem criar lacunas dentro do trecho ainda retido. Este fator é secundário: não explica sozinho a ausência de quase todo julho e não resolve os seis hosts sem evidência. Não aumentar arbitrariamente a validade nem prolongar a última leitura por semanas para obter 100%.

## Por que não substituir automaticamente por estatísticas

O Zabbix gera estatísticas horárias com mínimo, máximo, média e quantidade; elas não são uma conversão tardia aos sete dias. Além disso, a documentação 6.0 registra perda de precisão da média em itens inteiros sem sinal, inclusive valores 0/1. [Zabbix 6.0 — History and trends](https://www.zabbix.com/documentation/6.0/en/manual/config/items/history_and_trends)

Inferência para este painel: esses agregados não preservam os instantes e a sobreposição entre quedas de hosts/itens. Com descarte de inalterados, média de amostras tampouco equivale necessariamente a tempo disponível. Portanto, `trendavg × 100` não substitui fielmente este cálculo temporal, nem permite recompor a união das quedas em `any_down`.

Não foi verificada a existência de estatísticas de julho para cada item: retenção configurada de 365d não comprova que elas foram coletadas. Uma eventual análise aproximada deve ser separada do índice oficial, com método e limitações explícitos.

## Proposta para implementação futura — não executada

### 1. Diagnóstico e clareza do painel

- Consultar retenção efetiva (global versus item), respeitando permissões e macros não resolvidas; nunca presumir retenção quando indisponível.
- Registrar no cálculo a política consultada, instante do diagnóstico e limites estimados. A retenção continua avançando enquanto um trabalho fica pausado; não prometer congelamento físico do histórico.
- Mostrar alerta de período anterior ao histórico consultável, distinguindo política de retenção, item inexistente, host desabilitado, ausência sem causa determinada e expiração por validade.
- Exibir “estado consolidado desconhecido” e explicar por que pode coexistir com milhares de amostras. Separar cobertura por fonte da cobertura do serviço; destacar os hosts que impedem a conclusão.
- Manter índice oficial inconclusivo com lacunas; preservar quedas confirmadas e limites possíveis. Falha de processamento deve continuar separada de ausência de dados.
- Não bloquear todo processamento só pelo alerta de retenção: ainda pode haver informação parcial útil.

### 2. Composição e validade com política explícita

- Oferecer prévia do escopo: grupos/subgrupos, hosts incluídos, atualmente desabilitados e verificações ausentes.
- Definir com o usuário seleção/exclusões auditáveis e, idealmente, vigência de participação por host. Não usar o status atual como prova do estado em julho.
- Permitir revisão consciente da validade automática/manual e mostrar o impacto, sem mudança silenciosa de regras.

### 3. Preservação para relatórios mensais confiáveis

- Dimensionar retenção necessária pelo início do mês a apurar, prazo de fechamento/revisão e leitura anterior necessária para estabelecer o estado inicial. Trinta dias não garantem sequer um mês de 31 dias ao fechar no mês seguinte.
- Avaliar preservação dedicada das transições/intervalos usados pelos indicadores, com coleta incremental, lacunas, recuperação de interrupções, regras e composição versionadas; fechar e armazenar relatórios auditáveis antes da expiração das fontes.
- Checkpoints atuais são temporários e não substituem arquivo mensal. Guardar apenas percentuais individuais também não permite refazer a união temporal de quedas.
- Não ampliar retenção global indiscriminadamente. Neste ambiente há override e compressão: mudar apenas o item para retenção maior não basta; desabilitar override sem avaliar TimescaleDB pode afetar a limpeza. Decidir com a administração uma arquitetura de armazenamento compatível e dimensionada.
- Para recuperar julho, verificar posteriormente, com autorização, se há backup/exportação confiável com histórico bruto suficiente. Sem isso, manter o mês inconclusivo; não inventar resultados a partir das estatísticas.

### Pontos do código a revisar quando solicitado

- `AvailabilityCalculation.php`: `scopeHosts()` (linha 137), `scopeItems()` (174), `historyPage()` (237), diagnósticos e metadados do relatório.
- `AvailabilityEngine.php`: `combine()` (84) e `summary()` (134); preservar a semântica de desconhecido e a precedência de queda confirmada.
- `AvailabilityFreshness.php`: `resolve()` (9); compatibilidade com heartbeat/intervalos e validade manual.
- `views/governance.availability.view.php` e `assets/js/availability-view.js`: alertas, cobertura e explicações.
- `AvailabilityConfig.php`, `views/governance.availability.config.php`, `assets/js/availability-config.js`: eventual política explícita de composição/validade.
- `AvailabilityJobStore.php`: distinguir a vida do checkpoint de um futuro armazenamento mensal persistente.

### Testes mínimos para a futura alteração

1. Item 7d com override 30d, override ausente, retenção zero, macro não resolvida e acesso restrito.
2. Mês inteiro expirado, corte no meio do mês e margem para amostra anterior ao início.
3. Reproduzir julho: 19 hosts parcialmente cobertos + seis sem evidência; explicar o 0% consolidado sem atribuir indisponibilidade de 100%.
4. Host desabilitado hoje com histórico válido no mês passado; exclusões somente explícitas/versionadas.
5. Queda confirmada em um host com outro desconhecido; sobreposição entre ICMP e PostgreSQL sem contagem duplicada.
6. Heartbeat 3600s com atrasos, regras manuais preservadas, ausência prolongada nunca preenchida artificialmente.
7. Trends 0/1 inteiras, amostragem irregular e quedas simultâneas versus alternadas: nenhuma substituição silenciosa por média horária.
8. Limite de retenção avançando durante pausa, paginação, retomada e fechamento imutável/versionado se implementado.

Nenhuma dessas propostas foi implementada nesta avaliação. Antes de alterar políticas de escopo ou armazenamento, confirmar os critérios com o usuário.

## Reavaliação após a versão 1.8.0 — comparação com o SLA nativo

Nova consulta em 28/08/2026, a partir das 15:43, America/Cuiaba. A versão 1.8.0
implementou o carregamento assíncrono da Qualidade; não alterou o motor nem a
retenção da Disponibilidade. Esta reavaliação é um diagnóstico: nenhum código,
configuração de produção, serviço, SLA, regra, item ou retenção foi modificado.
Foi iniciado um único cálculo pelo botão normal do painel e consultadas telas
de configuração sem salvar. Este complemento é a única alteração local.

### Resultado do painel indicado pelo usuário

No [dashboard 477 — DABD, SLA por Tecnologia](https://zabbix.tjmt.jus.br/zabbix.php?action=dashboard.view&dashboardid=477),
o quadro mensal apresenta para julho/2026:

| Tecnologia | SLI exibido em julho | SLA mensal / serviço |
| --- | --- | --- |
| PostgreSQL | 100% | 113 / 2316 |
| Qlik Sense | 100% | 113 / 2320 |
| SQL Server | 99,8779% | 113 / 2321 |

O [relatório mensal individual do PostgreSQL](https://zabbix.tjmt.jus.br/zabbix.php?action=slareport.list&filter_slaid=113&filter_serviceid=2316&filter_set=1)
confirma SLI 100, uptime exibido como `1M 1d`, downtime zero e nenhum período
excluído exibido na linha de julho. Portanto, o resultado nativo existe; não é
uma expectativa inferida de leituras atuais. Isso não comprova que o histórico
bruto dos itens esteja preservado nem que os dois modelos avaliem exatamente
as mesmas condições.

Na lista de SLAs, `[DABD][M] - Produção` está ativo, mensal, 24x7, meta 99%,
vigente desde 30/10/2025 e fuso `System default: (UTC+00:00) UTC`.
O módulo está em `America/Cuiaba`, meta 99,9%, grupo `DABD/PostgreSQL`,
`any_down`, duas chaves e validade manual 3600s, como no diagnóstico anterior.
O início/fim do mês diferem quatro horas entre esses fusos. A meta altera a
avaliação de cumprimento, não o percentual de disponibilidade calculado.

A árvore do serviço observado é:

`[DABD] - Produção` → `PostgreSQL` → `PostgreSQL - Disponibilidade` (2316,
tag de serviço `service_sla: dabd_prod`) → `PostgreSQL - Disponibilidade - Serviço`
(2317). Não foram auditadas todas as problem tags, expressões de triggers ou
regras de propagação dessa árvore; não afirmar equivalência com as duas chaves
do módulo. O grupo do módulo inclui nomes como `srv-postgresql-hom-dev` e
`precatoriohomologacao-rhel.pjmt.local`; os nomes sugerem outros ambientes,
mas a classificação e a participação histórica precisam ser confirmadas.

### Retenções reconfirmadas na interface

| Fonte/configuração | Valor |
| --- | --- |
| Histórico de itens, com override global ativo | 30d |
| Estatísticas de itens, com override global ativo | 365d |
| Services → Data storage period | 365d |
| Events and alerts → Trigger data storage period | 365d |
| Events and alerts → Service data storage period | 7d |
| Compressão de histórico/estatísticas | Registros mais antigos que 7d |

As seções **Services** e **Events and alerts → Service** são configurações
distintas; não confundir os 7d de eventos de serviço com os 365d da seção
Services, nem os 7d da compressão com uma conversão de histórico em tendências.

### Nova reprodução do módulo

- Execução: 15:43:38–15:44:02; 24s totais, 2s ativos informados.
- 25 hosts, 50 verificações, 262 chamadas à API e 129.382 linhas lidas,
  incluindo possíveis repetições da paginação.
- Conclusão normal, sem erro de processamento; índice inconclusivo, cobertura
  consolidada 0%, 744h desconhecidas e zero horas de queda confirmada.
- Os mesmos seis hosts listados anteriormente continuam sem amostras; os dois
  masters continuam sem item PostgreSQL localizado.
- Em `dbd-apolo-p`, `icmpping` tem 6.752 amostras; a primeira é
  29/07/2026 15:44:08, e a última 31/07/2026 23:59:38. Cobertura da fonte:
  7,562425%.
- O `pgsql.ping` desse host tem 56 amostras, de 29/07/2026 16:27:54 a
  31/07/2026 23:27:54. Cobertura da fonte: 7,464382%.

O corte avançou junto com o relógio desde a primeira avaliação, de modo
compatível com `history.get` e o override de 30d. Não houve inspeção direta de
partições ou backups: a conclusão é ausência de histórico **consultável pela
API no período completo**, não prova de eliminação física de todos os dados.

### Revisão local e decisão necessária

O caminho ativo em `AvailabilityCalculation.php` consulta somente `History.get`.
Não há consulta de SLA, serviços ou tendências nesse cálculo. Os testes locais
de cálculo e engine/adapter passaram (1.937 + 629 assertivas). Uma simulação
em memória de julho confirma: 25 hosts disponíveis durante todo o mês produzem
100%; 19 disponíveis e seis desconhecidos produzem 744h desconhecidas em
`any_down`. Não foi encontrado erro matemático que explique uma perda de
disponibilidade com evidência completa nesse cenário.

Para preservar o cálculo correto:

1. Manter `Unknown` no método por itens enquanto faltarem evidências, explicando
   retenção e fontes impeditivas. Retomar as melhorias de diagnóstico e de escopo
   propostas acima; não excluir hosts silenciosamente.
2. Se o usuário quiser reaproveitar o indicador já existente, oferecer uma fonte
   explícita **SLA nativo** por tecnologia, além da fonte **Itens**, preservando
   pesos e hierarquia. Não fazer fallback automático nem apresentar SLI nativo
   como se fosse histórico bruto reconstruído.
3. Para a fonte nativa, consultar o SLA/serviço selecionado pela API oficial
   `sla.getsli`, respeitando período retornado, fuso, calendário, vigência,
   exclusões e permissões. Mapear o resultado por `serviceids` retornados, cuja
   ordem não é garantida. Validar comparabilidade dos períodos antes da média
   ponderada; não inventar gráfico diário ou sobreposição temporal a partir de
   um percentual mensal. Esses cuidados são requisitos de uma implementação
   futura, não funcionalidades presentes.
4. Se o usuário mantiver exclusivamente itens, julho depende de recuperação de
   histórico suficiente em fonte autorizada; a solução prospectiva exige
   retenção/preservação compatível com o prazo de fechamento. Aumentar retenção
   agora, isoladamente, não recria o que já foi removido.

Usar o SLA nativo muda a fonte e os critérios do indicador originalmente baseado
em itens. Confirmar essa escolha com o usuário antes de implementar ou mudar
regras. Nada foi preenchido com 100% artificialmente.

Referências primárias consultadas nesta reavaliação:
[API de SLI do Zabbix 6.0](https://www.zabbix.com/documentation/6.0/en/manual/api/reference/sla/getsli),
[SLA e calendário](https://www.zabbix.com/documentation/6.0/en/manual/it_services/sla),
[mapeamento de problemas e árvore de serviços](https://www.zabbix.com/documentation/6.0/en/manual/it_services/service_tree)
e [corte de histórico no código oficial 6.0.36](https://github.com/zabbix/zabbix/blob/6.0.36/ui/include/classes/api/services/CHistory.php#L144-L148).

## Implementação autorizada — versão 1.9.0

Após o pedido “Implemente”, foi adicionada **localmente** a fonte opcional
SLA nativo mensal por tecnologia. As análises acima descrevem o estado anterior
e foram preservadas. Não houve implantação nem salvamento de configuração em
produção nesta etapa.

- Tecnologias anteriores continuam por itens. Grupos, chaves, validade,
  pesos e metas não são migrados silenciosamente para SLA.
- A nova opção recebe SLA ID + Serviço ID, ou extrai esses IDs de um link
  individual do relatório nativo. Não consulta nem persiste o endereço colado.
- O processamento consulta definição, associação do serviço e SLI mensal;
  confere novamente a definição antes de aceitar o resultado. Respostas
  inválidas são falhas operacionais, não meses de indisponibilidade/Unknown.
- Os pesos usam os tempos nativos sem arredondamento intermediário.
  A consolidação exige período absoluto, calendário, exclusões e base de tempo
  compatíveis. O resultado individual é preservado quando o agregado é bloqueado.
- Nesta versão, SLA deve ser mensal e o mês precisa estar encerrado. Não se
  produz histórico diário ou lista de quedas a partir do resumo mensal.
  O painel oferece um comparativo mensal ECharts, detalhes auditáveis e JSON v2.
- Temas claro/escuro, idiomas PT/EN e o cálculo por itens permanecem disponíveis.

### Aplicação sugerida ao caso observado

Depois de atualizar os arquivos do módulo existente, em Configurar disponibilidade:

1. Ajustar o fuso do relatório para **UTC**, correspondente ao SLA 113 observado.
2. No PostgreSQL, escolher **SLA nativo mensal**, SLA **113**, serviço **2316**.
3. Se desejar incluir Qlik Sense e SQL Server, usar respectivamente os serviços
   **2320** e **2321** do mesmo SLA, mantendo os pesos escolhidos pelo usuário.
4. Salvar e calcular **2026-07**, conferindo os resultados com o relatório nativo.

Esses IDs são uma referência da inspeção anterior, não valores embutidos no
código ou uma configuração aplicada automaticamente. PostgreSQL e Qlik exibiam
100%; SQL Server exibia 99,8779%, portanto não afirmar que todos os indicadores
ou o departamento inteiro deveriam resultar em 100%.

### Verificações realizadas

Testes locais com API sintética cobrem calendário 24×7/personalizado, exclusões,
mudanças de fuso/DST, períodos/serviços ausentes, sentinela nativa sem SLI,
resposta inválida, IDs longos, pesos extremos, edição concorrente do SLA e fontes
mistas. Também foi corrigida a tolerância de ponto flutuante na agregação mensal
de médias de muitos hosts, sem arredondar ou apagar lacunas reais.

A suíte anterior de itens/Qualidade e os testes de memória passaram. A interface
foi exercitada no navegador local com CSS nativo do Zabbix: seleção de fonte,
rascunhos preservados, importação de IDs, envio validado sem persistência,
gráficos transparentes, tema claro/escuro e conclusão/falha em etapas. Essa
validação não substitui a conferência após a atualização no frontend instalado.

Os diagnósticos futuros de retenção por item e fechamento mensal permanente
continuam fora desta implementação. A fonte por itens ainda precisa de histórico
bruto suficiente para julho; nenhum dado faltante foi preenchido artificialmente.

## Revalidação em produção — 31/08/2026

A configuração foi inspecionada sem alterações: fuso `America/Cuiaba`, política
**Calcular sobre dados disponíveis**, tecnologia PostgreSQL pela fonte de itens,
modo **qualquer servidor fora**, 25 hosts e duas verificações obrigatórias por
host. Portanto, não faltava selecionar a política observada nem salvar a fonte.

Um novo processamento de julho/2026 terminou com 50 verificações, **0 linhas de
histórico lidas**, 243 chamadas à API e 25/25 hosts sem estado conhecido. O
resultado `Unknown`, cobertura 0% e 744 horas desconhecidas é coerente com essa
evidência: na data da revalidação, o histórico bruto necessário já não estava
mais acessível pela API. A política observada ignora lacunas no denominador, mas
não pode formar um percentual quando nenhuma amostra classificável permanece.
Ela também não autoriza presumir 100%.

Essa execução separa dois problemas:

1. **Dados de julho:** sem linhas recuperáveis, nenhum ajuste de gráfico consegue
   reconstruir o mês. É necessário um SLA/fechamento preservado ou outra fonte
   autorizada que ainda contenha as evidências.
2. **Representação diária:** havia uma inconsistência real nas médias. Os pontos
   eram derivados de durações temporais combinadas, que podem dar peso maior a
   quem possui mais cobertura, enquanto o indicador mensal usa uma participação
   por host e pesos por tecnologia. A versão 1.11 reaplica a hierarquia correta em
   cada dia, separa disponibilidade de cobertura e adiciona gráficos lazy por host.

Nenhuma configuração foi salva ou modificada no Zabbix durante essa validação.
