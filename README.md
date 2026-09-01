# Governance & Quality Audit — Zabbix 6.0 LTS

Módulo de governança e auditoria de qualidade de dados para o frontend do
Zabbix 6.0 LTS.

## Novidades 1.13.0 — Fallback conservador para trends

- Em meses encerrados, cada verificação tenta primeiro o histórico detalhado. Se
  sua cobertura ficar incompleta, o módulo consulta `trend.get` para o mês
  inteiro e usa as trends somente quando elas aumentarem a cobertura.
- Histórico e trends nunca são emendados na mesma fonte. Quando selecionadas, as
  trends substituem toda a série daquela verificação e ficam identificadas nos
  detalhes, gráficos e JSON.
- Hora com `min=1` e `max=1` é `UP`; hora com `min=0` e `max=0` é `DOWN`;
  hora mista (`min=0`, `max=1`) conta integralmente como `DOWN`; hora sem trend
  permanece `UNKNOWN`. `value_avg` não é usado para itens inteiros binários.
- O painel mostra quantas horas foram UP, DOWN, mistas e não classificáveis.
  A resolução conservadora é de uma hora e pode superestimar quedas curtas.
- O mês atual permanece somente no histórico detalhado, pois a hora corrente
  pode ainda não estar consolidada nas trends.
- O checkpoint interno passa ao formato 3; cálculos iniciados em versões
  anteriores precisam ser reiniciados para não misturar métodos.

## Novidades 1.12.0 — Evidência horária e diagnóstico por estado

- A validade automática de itens agora mantém uma amostra real por no mínimo uma hora. Isso alinha o ICMP frequente aos itens com descarte/heartbeat sem criar médias horárias: qualquer novo `0` ou `1` substitui o estado imediatamente.
- O PostgreSQL com heartbeat de 1h mantém sua margem automática (`heartbeat + duas coletas`), resultando em 3720s quando a coleta é de 1m. Sem nova evidência após a janela, o trecho passa a `UNKNOWN`.
- A configuração oferece janela automática, uma hora exata ou quantidade manual de segundos por item, explicando o efeito de cada opção.
- Os detalhes por fonte agora separam quantidade de amostras `UP`, `DOWN` e `UNKNOWN`, histórico vazio e valores que não correspondem às regras.
- A regra do host permanece explícita: todas as verificações em `UP` confirmam disponibilidade; qualquer `DOWN` prevalece; `UP + UNKNOWN` continua `UNKNOWN`.

## Novidades 1.11.0 — Gráficos coerentes e detalhe por host

Os gráficos da Disponibilidade agora usam o mesmo nível de agregação do
indicador exibido. Cada ponto diário recalcula hosts, tecnologias e pesos dentro
daquele dia; não divide tempos consolidados quando a regra é uma média de
indicadores. Isso corrige diferenças visuais quando os participantes têm
coberturas distintas.

- O gráfico diário mostra **disponibilidade**, **meta** e **cobertura**. A
  disponibilidade usa uma escala ampliada; a cobertura fica em uma faixa
  separada de 0–100%, evitando comparar alturas produzidas por eixos diferentes.
- A média simples dos pontos diários pode diferir do indicador mensal: cada dia
  tem duração e participantes próprios, enquanto o mês reaplica a regra sobre o
  período completo. Lacunas continuam desconhecidas e nunca viram 100%.
- Nos detalhes de uma tecnologia por itens, cada host ganhou um gráfico diário
  recolhido. Os 31 pontos compactos são carregados no relatório, mas o ECharts só
  é criado ao abrir o host. Abrir qualquer outro host descarta o canvas anterior;
  fechar a tecnologia também libera o gráfico.
- O comparativo mensal usa escala adaptativa para distinguir valores próximos de
  100%. **Meta do indicador** (configurada no módulo) e **SLO nativo** do Zabbix
  aparecem como referências separadas.
- O SLA nativo permanece mensal. `sla.getsli` não fornece uma série diária por
  host, portanto nenhum ponto ou disponibilidade é inventado para essa fonte.
- O checkpoint interno passa ao formato 2. Um cálculo iniciado antes da
  atualização é recusado como incompatível e deve ser iniciado novamente; isso
  impede combinar hosts processados por versões diferentes.

O JSON permanece no formato aditivo `governance-availability-v3`. Em fontes por
itens, `host.daily` contém pares posicionais `[score, coverage]`, alinhados aos
dias de `technology.daily`; `daily_indicator` e `host_daily_format` documentam
essa representação nas premissas exportadas.

## Novidades 1.10.0 — Disponibilidade observada por itens

O cálculo por itens agora oferece duas políticas explícitas em **Configurar
disponibilidade → Tratamento de dados ausentes**:

- **Exigir cobertura completa** (`data_policy: "strict"`): preserva o comportamento
  anterior. Um período com estado desconhecido não recebe um índice final.
- **Calcular sobre dados disponíveis** (`data_policy: "observed"`): calcula a
  disponibilidade observada, excluindo tempo sem estado conhecido do percentual.
  A cobertura e as exclusões continuam sendo apresentadas. **100% observado
  com cobertura parcial não certifica disponibilidade de 100% do mês inteiro.**

Configurações existentes continuam em `strict` até a escolha explícita da
nova política. Atualizar os arquivos não salva regras nem altera fontes, hosts,
itens, SLAs ou retenções no Zabbix.

### Usar os itens ICMP e PostgreSQL

1. Mantenha **Fonte do cálculo → Histórico de itens (24×7)**, os grupos desejados
   e o fuso que define seu mês, por exemplo `America/Cuiaba`.
2. Use as chaves exatas `icmpping` e
   `pgsql.ping["{$PG.URI}","{$PG.USER}","{$PG.PASSWORD}"]`, com disponível `= 1`.
   As macros da chave não são substituídas por credenciais; a chave é procurada
   como está cadastrada no item.
3. Selecione **Calcular sobre dados disponíveis** e salve. Inicie um novo cálculo;
   relatórios/checkpoints anteriores mantêm as regras capturadas no início.
4. Abra os detalhes da tecnologia para conferir cobertura, hosts com/sem estado,
   verificações não consultadas, validade, amostras e primeiro/último registro.

O modo automático reconhece intervalos flexíveis numéricos positivos,
como `30s;1m/6-7,00:00-24:00`. Antes, essa configuração era recusada e o histórico
não era consultado. O maior intervalo continua auditado, mas a janela automática
tem mínimo de 3600s. Com heartbeat de 1h e coleta de 60s, são 3720s.
Agendamentos, macros de intervalo, base zero e janelas que suspendem coleta
continuam exigindo validade manual: não se adivinha a cadência pelas amostras.

### Regras para não distorcer o indicador

- **Dentro de um host:** as verificações continuam obrigatórias. Uma queda
  confirmada no ICMP ou no serviço torna o host indisponível; todas as verificações
  precisam estar disponíveis para confirmar o host disponível. Uma chave ausente
  não é considerada sucesso, mesmo no modo observado.
- **Qualquer servidor fora:** união das quedas conhecidas, sem contar sobreposições
  duas vezes. Hosts sem estado conhecido são ignorados naquele instante. Se todos
  estiverem desconhecidos, o intervalo não entra no percentual.
- **Média dos servidores:** média dos percentuais observados dos hosts que têm
  estado conhecido. Um host com mais histórico não ganha peso adicional. Um host
  sem evidência fica sem indicador, não com 0% ou 100%.
- **Departamento:** média dos indicadores das tecnologias com os pesos configurados.
  Tecnologias sem indicador não entram nessa média; a quantidade participante,
  o peso participante/configurado e a participação efetiva ficam explícitos.
- **Cobertura:** nos itens, média do tempo com estado conhecido de TODOS os hosts,
  inclusive os sem dados. No departamento, considera TODOS os pesos. É separada
  da cobertura temporal da união de hosts: um host observado durante todo o mês
  não esconde que outro ficou sem dados.
- **Durações:** descrevem a linha do tempo consolidada e, nas médias, tempos
  equivalentes de todo o escopo. Não são o denominador das médias de percentuais
  observados quando as coberturas dos participantes são diferentes. A partir da
  versão 1.11, os pontos dos gráficos reaplicam a hierarquia de indicadores em
  cada dia e mantêm essas durações apenas como informação descritiva.

Exemplo: um host tem 90s disponível e 10s indisponível; outro não tem nenhum
estado conhecido nesses 100s. O modo observado produz 90%, cobertura de hosts
50% e 1/2 hosts com dados. O modo estrito continua inconclusivo. Se ninguém tiver
estado conhecido, ambos permanecem sem indicador.

A apuração prefere histórico bruto, validade finita e dados reais. Em mês
encerrado com cobertura incompleta, pode usar trends horárias conservadoras para
a fonte inteira; nunca estende uma amostra por todo o mês nem substitui itens por
SLA nativo. A fonte SLA opcional mantém seu calendário e suas restrições anteriores.
O JSON passa a `governance-availability-v3`: `data_policy` identifica a escolha,
`observation.score` contém o indicador observado, `observation.coverage` mantém
a cobertura do escopo, e `summary` preserva a consolidação estrita para auditoria.
Nos itens, `history_queried: false` distingue histórico não consultado de uma
consulta bem-sucedida sem amostras. Os dados de diagnóstico não incluem senhas.

## Novidades 1.9.0 — Fonte SLA nativo na Disponibilidade

Cada tecnologia pode escolher **Histórico de itens (24×7)** ou **SLA nativo
mensal**. A opção por itens e suas regras anteriores continuam sendo o padrão;
atualizar os arquivos não troca fontes nem modifica serviços, SLAs ou retenções.
Pesos, metas e hierarquia departamento → tecnologias são preservados.
Alternar a fonte preserva os dois rascunhos durante a edição. Ao salvar, apenas
os campos da fonte selecionada são guardados; mantenha uma cópia das regras
por itens se pretender voltar a elas depois de converter uma tecnologia para SLA.

### Configurar um indicador com o SLA existente

1. Abra **Governança → Configurar disponibilidade** e expanda a tecnologia.
2. Em **Fonte do cálculo**, selecione **SLA nativo mensal**.
3. Informe **SLA ID** e **Serviço ID**, ou cole o endereço do relatório nativo
   individual e clique em **Preencher IDs do endereço**. O endereço precisa
   conter `action=slareport.list`, `filter_slaid` e `filter_serviceid` do mesmo
   Zabbix. O link do dashboard/widget, sozinho, não contém esses dois IDs.
   O endereço colado não é acessado nem salvo; apenas os IDs são copiados.
4. Alinhe **Fuso horário do relatório** ao fuso do SLA para permitir a média
   departamental. Se o SLA mostrar `System default: UTC`, use `UTC` no módulo;
   o fuso do usuário não substitui o fuso do SLA.
5. Salve e calcule um **mês encerrado**, como julho/2026. Não é necessário
   informar grupos, chaves de itens ou validade de amostra para essa fonte.

O relatório identifica a fonte de cada tecnologia, mostra um comparativo mensal
em ECharts, os detalhes do SLA/serviço, a base de tempo após exclusões, o fuso,
a meta nativa e um link para conferência. A meta do módulo continua independente
da meta do SLA. O tema claro/escuro e português/inglês são mantidos.

### Critérios e limites da fonte SLA

- Consulta a API nativa `sla.getsli` para o serviço e mês exatos. Calcula os
  pesos sobre os tempos disponível/indisponível, sem reutilizar o percentual
  arredondado do widget. Respeita o calendário semanal e as exclusões nativas.
- Exige SLA ativo de período **Mensal**, vigente e com serviço criado antes
  do início do mês. O mês precisa estar encerrado no fuso do relatório e do SLA.
  O mês atual continua disponível na fonte por itens; a API de SLA não permite
  fixar seu resultado em andamento no instante de corte deste processamento.
- A média exige o mesmo período absoluto, calendário, exclusões e base de tempo
  em todas as tecnologias. SLAs com o mesmo calendário personalizado são aceitos.
  Para combinar SLA com itens, o SLA precisa ser 24×7, sem exclusões no mês.
  Fontes incompatíveis conservam seus resultados individuais, mas não geram
  índice departamental nem tempos equivalentes fictícios.
- **Cobertura SLA não é cobertura de amostras**: representa o tempo programado
  avaliado pelo SLA. Seu SLI segue os estados e regras dos serviços nativos,
  que podem ser diferentes das condições configuradas na fonte por itens.
- Não há fallback automático entre itens e SLA, nem preenchimento artificial de
  lacunas com 100%. Uma resposta inválida da API interrompe o processamento;
  ausência legítima de SLA/serviço/SLI recebe uma explicação de indisponibilidade
  do indicador, sem assumir disponibilidade ou queda.
- Um resumo mensal não reconstrói dias nem intervalos de queda. O comparativo
  mensal substitui o gráfico diário para fontes SLA; em departamentos mistos,
  os gráficos diários permanecem somente nas tecnologias por itens.
- O processamento continua em etapas privadas por usuário. A definição do SLA
  é conferida antes/depois da consulta; mudança detectada exige novo cálculo.
  Não é um snapshot transacional ou fechamento mensal imutável: alterações
  posteriores de regras, eventos ou calendário podem mudar nova apuração.
- A exportação passa a `governance-availability-v2`, incluindo fonte, calendário,
  comparabilidade, tempos nativos e disponibilidade ou não de série diária.
  Integrações que consomem o JSON devem verificar o campo `format`.

A fonte SLA permite aproveitar um indicador já preservado pelo Zabbix. Ela não
recupera o histórico bruto expirado dos itens. A partir da versão 1.13, a fonte
por itens pode usar trends horárias conservadoras quando elas existirem e tiverem
maior cobertura; lacunas restantes mantêm a política estrita inconclusiva. A
política observada permite apurar somente a parcela conhecida, sem certificar o
mês todo.

## Novidades 1.8.0 — Qualidade sem bloquear a página

A Qualidade agora entrega primeiro o cabeçalho, as abas e os espaços dos cards.
A análise começa automaticamente por POST, com progresso e lotes de até 100 hosts,
sem exigir um clique em Calcular. O GET inicial consulta somente a configuração.
Todos os cards da página compartilham a leitura de cada lote; percentuais e índice
só aparecem depois da avaliação completa dos hosts. Os critérios e o arredondamento
dos indicadores permanecem iguais.

As contagens de problemas e itens não suportados são etapas independentes.
Falha em uma dessas consultas não apaga cards concluídos nem aparece como zero.
**Tentar novamente** recupera o andamento após erro de comunicação; **Atualizar**
inicia uma análise nova após conclusão/falha confirmada. Não há atualização periódica.
Sair da página impede novas etapas, mas não cancela uma consulta PHP/SQL em execução.
O ECharts é carregado após os resultados; se a biblioteca falhar, os números continuam
visíveis. Tema claro/escuro e português/inglês são preservados.

### Limites e operação

- A descoberta inicial retorna apenas IDs/status, limitada a 50.000 hosts no escopo
  (incluindo desabilitados); acima disso há erro explícito, nunca truncamento silencioso.
- Itens não suportados são contados por lotes disjuntos. Problemas usam uma contagem
  única do escopo para não duplicar eventos de triggers associadas a vários hosts.
  Uma API individual ainda pode ser lenta: o novo fluxo não substitui o diagnóstico SQL.
- O escopo e as regras são capturados no início. Alterações detectadas de status/remoção
  de host durante a leitura impedem publicar um índice com denominador reduzido.
  A consulta é do estado atual ao longo da execução, não um snapshot transacional.
- Checkpoints da Qualidade são privados por usuário/instalação, separados da
  Disponibilidade, em `zabbix-governance-quality-*` no diretório temporário do PHP.
  Expiram após 5 minutos sem avanço ou 15 minutos absolutos. Limites: 8 por usuário,
  32 por instalação e 4 MiB por checkpoint. Trabalhos ativos não são expulsos;
  navegações rápidas abandonadas podem exigir aguardar a expiração. Resultados
  concluídos podem liberar vagas do próprio usuário. Não é um cache compartilhado.
- O usuário do PHP precisa de diretório temporário privado gravável, `flock()` e
  renomeação atômica. Com múltiplos frontends, usar afinidade de sessão ou armazenamento
  compatível; os arquivos locais não são replicados automaticamente.
- Nenhuma alteração de retenção, regras ou cálculo da Disponibilidade faz parte desta versão.

### Validação local da Qualidade

Execute `php tests/quality-pages.php`, `php tests/quality-calculation.php`,
`php tests/quality-jobs.php`, `php tests/quality-view.php`, `php tests/quality-scale.php`
e `node tests/quality-view.js`.
O PHP deve ter `mbstring`. Para revisão visual com dados simulados, execute
`php -S 127.0.0.1:8770 tests/quality-preview.php` e abra o endereço local.
Os links de demonstração exercitam carregamento lento, tema claro/inglês,
falha operacional e escopo vazio. Nenhum teste acessa o Zabbix de produção.
Os arquivos `tests/` e as notas locais não acompanham o ZIP de produção.

## Correção 1.7.1

Corrige o aviso `Undefined index: quality_json` ao abrir a configuração dos
cards e a mesma leitura insegura de campos opcionais no salvamento.
As páginas, cards, revisões de edição e regras de disponibilidade são preservados.
Os testes agora reproduzem o comportamento nativo do `getInput()` do Zabbix 6.0:
um valor padrão `null` não protege o acesso a um campo ausente.

## Funcionalidades

- Menu visível exclusivamente para usuários Super Admin.
- Interface automática em português ou inglês.
- Página **Configurar qualidade / Configure quality** totalmente traduzida conforme o
  idioma do usuário.
- Menu principal com ícone nativo e submenu para as páginas de governança.
- Cobertura de tag de departamento (`departamento`, `department` ou `dept`).
- Cobertura de tag de ambiente (`ambiente`, `environment` ou `env`).
- Cobertura de tag de responsável/equipe (`owner`, `responsavel`, `team`,
  `equipe` e equivalentes).
- Cobertura de inventário (sistema operacional ou número de série).
- Cobertura de vínculo com templates.
- Cobertura de interfaces com IP ou DNS configurado.
- Cobertura de associação a grupos de hosts definidos por nome ou ID.
- Lista de até dez hosts não conformes por indicador.
- Indicadores circulares renderizados com Apache ECharts 5.6.0.
- Cores adaptáveis aos temas claro e escuro do Zabbix 6.0.
- Cards translúcidos e canvas ECharts transparente, sem fallback branco.
- Cards configuráveis pelo frontend: nome, descrição, tipo de métrica, tags,
  valores aceitos e participação no índice da página.
- Inclusão e remoção dinâmica de cards sem editar arquivos PHP.
- Páginas nomeáveis na Qualidade, com abas e conjuntos independentes de cards.
- Acessos separados para configurar Qualidade e Disponibilidade no menu.
- Resumo operacional compacto com hosts monitorados/desabilitados, falhas de
  interface, problemas altos ou críticos e itens não suportados.
- Cards compactos e responsivos, com não conformidades recolhidas por padrão.
- Painel mensal de disponibilidade por departamento, com tecnologias ponderadas,
  metas, histórico diário em ECharts e memória de cálculo exportável.
- Cálculo em etapas, com progresso por host/item e retomada do último avanço
  confirmado, sem publicar indicadores de um escopo processado pela metade.

## Páginas de qualidade (v1.6.0)

Abra **Governança → Qualidade** para navegar pelas abas. Em
**Configurar qualidade**, adicione páginas, altere os nomes e configure os cards
de cada uma. Trocar de aba no editor preserva o rascunho; clique em **Salvar todas as páginas**
para aplicar as alterações de todas as páginas.

Os cards anteriores aparecem na primeira página, inicialmente chamada
**Qualidade / Quality**, sem perda de configuração. A migração só é
gravada ao salvar. Renomear uma página mantém seus cards e identificadores.

O índice usa apenas os cards participantes da página selecionada. Páginas sem
cards, sem hosts ou sem cards participantes não apresentam um índice de 100%.
Os totais operacionais no cabeçalho continuam representando o escopo de hosts
atual; trocar de página troca os indicadores, não o conjunto de hosts.

São permitidas até 12 páginas e 30 cards por página, respeitando também o
limite total de configuração do módulo no banco. O salvamento valida o JSON
completo (incluindo a disponibilidade), sem truncar dados. Rascunhos em
conflito com alterações de outra sessão não sobrescrevem a versão salva.
Essa proteção compara a revisão já gravada; não é uma trava transacional.
Evite salvar Qualidade e Disponibilidade simultaneamente em sessões diferentes,
pois a API nativa substitui o JSON compartilhado do módulo por inteiro.

## Disponibilidade mensal

Abra **Governança → Configurar disponibilidade** (ou **Configurar indicadores**
no próprio painel de disponibilidade). Cadastre um
departamento e suas tecnologias/serviços. Para reproduzir o exemplo do banco,
adicione PostgreSQL (peso 4), SQL Server (peso 2) e Qlik Sense (peso 1).
Os nomes e as metas são livres. O departamento usa a média ponderada das
tecnologias, sem arredondamento intermediário.

Cada tecnologia permite escolher a fonte do cálculo. Na fonte **Histórico de
itens**, é possível configurar:

- Grupos por nome (incluindo subgrupos) ou ID (apenas o grupo exato).
- Consolidação **qualquer servidor fora** ou **média dos servidores**.
- Uma ou mais chaves exatas de itens numéricos, presentes em cada host.
  Por exemplo, uma verificação de `icmpping` e outra do serviço. As chaves
  devem corresponder aos itens realmente cadastrados no seu Zabbix.
- Condição de disponibilidade: igual, diferente, maior/menor, maior/menor ou
  igual, ou intervalo inclusivo. A indisponibilidade pode ser qualquer outro
  valor válido ou uma condição explícita.
- Validade da última amostra **por item**, automática ou manual em segundos.
  Depois desse prazo, o estado passa a desconhecido.

### Validade automática e heartbeat

Cada verificação pode usar **Automática por item**. O módulo consulta o intervalo
de coleta e o pré-processamento, sem expandir macros de conexão ou senhas.
Para itens com intervalo simples, usa três intervalos de coleta como validade.
Com “Descartar inalterado com heartbeat”, usa o maior valor entre três intervalos
de coleta e `heartbeat + 2 × intervalo de coleta`.

Exemplo: `pgsql.ping` coletado a cada 60 s, com heartbeat de 1 h, recebe validade
de 3720 s. `icmpping` coletado a cada 60 s, sem esse pré-processamento, mantém
validade de 180 s. Assim, o heartbeat do PostgreSQL não estende a validade do ICMP.
As validades resolvidas e os avisos aparecem nos detalhes dos itens e no JSON.

O automático aceita intervalos flexíveis numéricos positivos e usa o maior
intervalo como base conservadora. **Não interpreta macros de intervalo,
agendamentos, base/intervalo zero, itens dependentes/trapper nem descarte
inalterado sem heartbeat**. Nessas situações,
é necessário definir uma validade manual por item; até isso ser feito, a
verificação fica desconhecida com aviso. A validade máxima é de 86400 s.
Uma política manual abaixo do heartbeat gera aviso, sem ser alterada silenciosamente.

Ao atualizar, as validades existentes são preservadas como manuais. Use o
automático somente quando o intervalo/pré-processamento forem suportados.
Por exemplo, `30s;1m/6-7,00:00-24:00` recebe validade automática de 180s.
Não aumente a validade apenas para eliminar lacunas: isso muda
a regra do indicador e pode esconder a falta de coleta. Copiar os arquivos não
muda as políticas já salvas nem a retenção de histórico do Zabbix.

O automático usa o intervalo/pré-processamento **atual**, não a configuração
histórica do item no mês consultado. Se essas regras mudaram, revise a validade
manual de forma consciente; a exportação registra a política usada. O módulo
não reconstrói o histórico de configurações nem infere heartbeat pelos dados.

Todas as verificações de um host são obrigatórias. Uma falha confirmada deixa
o host indisponível, mesmo se outra verificação estiver sem dados. No modo
**qualquer servidor fora**, o sistema une os intervalos: quedas simultâneas de
host e serviço, ou de vários servidores, não duplicam a duração. No modo
**média**, cada host tem peso igual. O peso da tecnologia é aplicado somente
ao consolidar o departamento.

Ausência de histórico, item ausente/não numérico, amostra expirada ou valor
que satisfaça ambas/nenhuma das condições explícitas gera tempo desconhecido.
Na política estrita, resultados com lacunas ficam **incompletos**, com cobertura
e faixa possível do índice. Na política observada, apenas estados conhecidos
formam o percentual e as exclusões ficam explícitas. Um grupo vazio não gera 100%.

O painel permite escolher mês e departamento, detalhar hosts/itens e intervalos,
baixar um JSON com regras e memória de cálculo e imprimir/salvar PDF pelo
navegador. As listas de intervalos, inclusive no JSON, mostram até os primeiros
200 por tecnologia; os totais consideram todos os intervalos processados.

O resumo mostra os departamentos no relatório, tecnologias, metas atendidas e
indicadores incompletos. A cobertura do departamento e os tempos equivalentes
são **ponderados**, não a contagem de hosts disponíveis nem a soma das quedas.
O mês em andamento é identificado como parcial, e metas atendidas são mostradas
como “Na meta até agora”. Fórmulas, gráficos diários e diagnóstico ficam em
seções recolhíveis. Não há um índice global misturando departamentos sem pesos definidos.

O editor resolve o tema efetivo do Zabbix (inclusive quando herdado do padrão),
estiliza também campos de texto e mês e adapta a largura somente nas páginas de
disponibilidade. Não muda o tema ou o layout das outras páginas do frontend.

### Processamento em etapas (v1.7.0)

Abrir **Governança → Disponibilidade** não consulta o histórico. Escolha a
competência/departamento e clique em **Calcular mês**. O painel mostra hosts
concluídos/total, verificações, etapa atual e amostras recebidas. O indicador,
gráficos e exportação aparecem somente quando todo o escopo terminar.

As regras e o fim do período são fixados no início; os grupos, hosts, itens e
validades são resolvidos antes da leitura do histórico. Uma alteração posterior
na configuração não modifica esse cálculo em andamento. A leitura não é uma
transação do banco: retenção, remoções e amostras atrasadas no Zabbix ainda podem
alterar o histórico disponível enquanto o trabalho é executado.

Cada solicitação executa poucas etapas, com páginas de até 5000 amostras mais
uma de confirmação. A paginação respeita valores com o mesmo timestamp e a
última amostra válida entre páginas. Repetir uma solicitação cuja resposta foi
perdida não reaplica uma etapa já salva. Não é necessário aumentar o timeout
do PHP para permitir que o cálculo inteiro dure vários minutos.

Use **Pausar** ou feche a aba para impedir novas etapas nessa aba. Uma consulta
já enviada pode terminar no servidor. Em caso de conexão interrompida, use
**Continuar cálculo**; o endereço que passa a conter `job` permite reabrir o
mesmo cálculo. Ele não continua em segundo plano sem uma aba conduzindo as
etapas. Outras abas abertas no mesmo cálculo podem continuar a avançá-lo.

Uma **falha de processamento** não é exibida como disponibilidade de 0% nem
como um mês inteiro sem histórico: não existe indicador final nesse caso.
Já uma execução concluída pode ter **dados incompletos** por item ausente,
retenção ou amostras expiradas. Nessa situação, a cobertura e os limites do
índice continuam visíveis, sem tratar as lacunas como disponibilidade.

Os detalhes de cada item mostram quantidade de amostras no período, primeiro
e último registro, maior intervalo entre registros e tempo sem dados. Esse
maior intervalo pode considerar a amostra anterior ao início do mês; não é
uma medida das lacunas sem registros nas extremidades. A cobertura calculada
é que considera todas as lacunas, incluindo o início/fim do período. O total
de amostras lidas inclui as amostras de apoio e releituras de fronteira.

O PHP precisa poder criar arquivos privados em seu diretório temporário.
Os checkpoints ficam em `zabbix-governance-availability-<id da instalação>`,
fora da pasta web e do JSON de configuração do módulo, com verificação de
usuário, SID nativo, locks e gravação atômica. Não são um arquivo mensal
permanente: expiram após 1 hora sem avanço ou 2 horas desde a criação.
São mantidos até 4 cálculos por usuário e 32 por instalação. Ao atingir a quota,
um novo cálculo pode descartar o resultado terminal mais antigo do próprio
usuário; cálculos em andamento não são descartados para abrir espaço.
Exporte o JSON de um relatório concluído se precisar conservá-lo.

Em instalações com vários frontends, as solicitações do cálculo precisam
chegar à mesma máquina (afinidade/sticky session); o armazenamento temporário
local não é uma fila distribuída. O filesystem precisa oferecer `flock` e
renomeação atômica. Não coloque essa pasta temporária em diretório público.

### Escopo e limites desta versão

- Fonte por itens: calendário 24×7, com fuso configurável; o mês atual considera
  apenas o tempo transcorrido. Manutenções não são excluídas automaticamente.
- A fonte por itens prefere **histórico bruto**, com resolução de um segundo. Em
  mês encerrado cuja cobertura detalhada esteja incompleta, consulta trends e
  pode substituir a série inteira por uma aproximação conservadora de uma hora.
  Trends não reconstroem duração ou sobreposição intrahorária: qualquer hora
  mista é integralmente DOWN e horas ausentes continuam UNKNOWN.
- A fonte por itens usa as regras e a composição de hosts/grupos **atuais**, inclusive hosts
  desabilitados que ainda tenham histórico. Alterações podem mudar resultados
  de meses passados. Não há fechamento mensal imutável nem histórico de membros.
- Hierarquia fixa: departamento → tecnologias → hosts → verificações para
  itens; departamento → tecnologias → SLA/serviço para a fonte nativa.
- A fonte SLA segue as condições e limitações específicas descritas na seção 1.9.0.
- Até 12 departamentos, 30 tecnologias no total, 6 verificações por tecnologia
  e 200 hosts por tecnologia. Consultas usam janelas de até 7 dias por item,
  paginadas em até 5000 amostras, com limite de 20 milhões de linhas lidas por
  cálculo. Mais de uma página inteira no mesmo segundo causa falha explícita,
  sem escolher arbitrariamente um valor de uma resposta truncada.
  Cada solicitação normalmente realiza até 4 operações e cede o processamento
  entre elas após 3 segundos. Uma chamada de API em andamento continua sujeita
  ao timeout do PHP/servidor; ela não pode ser interrompida por essa guarda.
- O orçamento de memória respeita o limite PHP (até um teto interno de 256 MiB),
  com reserva de 16 MiB. Consultas podem usar menos de 5000 amostras por página
  conforme a memória disponível. Consolidações têm limite de complexidade de
  200 mil intervalos e cada checkpoint tem no máximo 16 MiB. Há limites de
  cálculos temporários por usuário e instalação para proteger memória/disco.
- Limites ou erros de consulta interrompem a execução, preservando a contagem
  de hosts concluídos, mas sem publicar relatório parcial. Para falhas terminais,
  revise a causa e inicie um novo cálculo; reduza o escopo por departamento
  quando necessário. Uma perda de resposta/conexão permite retomar o checkpoint.
- Acesso restrito a Super Admin. Regras persistem no registro do módulo,
  preservando a configuração dos cards de qualidade.
- Conflitos entre abas/sessões não sobrescrevem regras salvas. O rascunho é
  preservado e continua bloqueado até recarregar a versão atual pelo link da tela.

### Testes locais

Execute `php tests/availability.php`, `php tests/availability-calculation.php`,
`php tests/availability-jobs.php`, `php tests/availability-scale.php`,
`php tests/availability-view.php`, `php tests/actions.php` e
`php tests/quality-pages.php` com a extensão
`mbstring` habilitada.
Os testes cobrem intervalos, sobreposições, lacunas, limiares, pesos e consultas
simuladas à API. Não substituem a validação no frontend Zabbix instalado.
Para a política de dados disponíveis, execute também
`php tests/availability-observed.php`, `php tests/availability-flexible.php`,
`php tests/availability-observed-config.php`,
`php tests/availability-observed-calculation.php`,
`php tests/availability-observed-view.php` e
`node tests/availability-observed-view.js`.
`node tests/availability-config.js` inclui a seleção, validação e persistência
da política no payload. A prévia visual local usa
`php -S 127.0.0.1:8772 tests/availability-observed-preview.php`, com dados fictícios
e POST que apenas valida, sem persistir. Nenhuma prévia acessa o Zabbix real.
Execute também `php tests/menu.php` para conferir os rótulos e destinos do menu.
`node tests/availability-view.js` verifica o fluxo do navegador com DOM/rede
simulados, incluindo SID, pausa, timeout, retomada, idempotência e relatórios antigos.

Para a fonte SLA, execute `php tests/availability-sla.php`,
`php tests/availability-sla-config.php`, `php tests/availability-sla-calculation.php`,
`php tests/availability-sla-actions.php`, `php tests/availability-sla-view.php`,
`node tests/availability-config.js` e `node tests/availability-sla-view.js`.
O teste de gráficos usa PHP pelo PATH ou pela variável `GOVERNANCE_PHP`;
`GOVERNANCE_PHP_EXT` pode indicar o diretório da extensão `mbstring`.
A prévia visual usa
`php -S 127.0.0.1:8771 tests/availability-sla-preview.php`, com os mesmos CSS
nativos opcionais descritos abaixo. Acesse `/?sample=1` para dados simulados e
`/zabbix.php?action=governance.availability.config` para testar o editor e a
importação de endereços. Nenhum desses testes acessa produção.

Para regressões de memória, execute `php tests/availability-memory.php technology`,
`php tests/availability-memory.php host` e `php tests/availability-memory.php department`.
Esses testes usam 128 MiB, histórico mensal e valores alternando a cada minuto.

No código-fonte há também `tests/browser-preview.php`, um ambiente de demonstração
para as views e os scripts reais, sem banco/API/credenciais do Zabbix. Somente
jobs fictícios temporários são persistidos para testar a retomada real.
Execute com `php -S 127.0.0.1:8768 tests/browser-preview.php` e abra o endereço local.
O arquivo não acompanha o pacote de produção e só aceita o servidor embutido do
PHP com cliente loopback. Para testar com CSS nativo, disponibilize `dark-theme.css`
e `blue-theme.css` oficiais em `governance-zabbix6-css` dentro do diretório temporário
do sistema. Teste idioma, temas, cadastro de verificações, macros, modo automático,
campos numéricos e larguras reduzidas. **Testar retomada** perde deliberadamente
uma resposta depois de salvar um checkpoint; use Continuar ou reabra seu endereço
para verificar a recuperação. `?preview_slow=1` simula consultas lentas para
testar a pausa. Esses parâmetros existem apenas no ambiente local de demonstração.
O botão de conferir rascunho mostra apenas
os dados fictícios do teste; salvar valida o JSON sem modificar qualquer módulo.

## Pré-requisitos

- Zabbix Frontend 6.0 LTS.
- PHP suportado pela versão instalada do Zabbix.

## Instalação

1. Copie a pasta inteira para o diretório `modules` do frontend. Dependendo do
   pacote, ele costuma ser `/usr/share/zabbix/ui/modules/` ou
   `/usr/share/zabbix/modules/`.
2. Confira se o arquivo ficou diretamente em
   `modules/Governance/manifest.json`, sem uma pasta duplicada no meio.
3. Garanta permissão de leitura para o usuário do Apache/Nginx/PHP-FPM.
4. No frontend, acesse **Administração → Geral → Módulos** e clique em
   **Examinar diretório**.
5. Habilite **Governance & Quality Audit**.
6. Entre como Super Admin e abra **Governança** no menu principal.

Para atualizar, faça backup dos arquivos e do banco do frontend e substitua os
arquivos na pasta existente do módulo. Mantenha o nome da pasta e o registro do
módulo no Zabbix; não o exclua para reinstalar, pois as configurações ficam nesse
registro. Recarregue a página com `Ctrl+F5` para renovar os arquivos de interface.

## Configuração dos cards

Abra **Governança → Configurar qualidade** e selecione a página. Cada card permite definir:

- Nome e descrição apresentados no painel.
- Tipo de métrica: tag personalizada, grupo de hosts, inventário, template ou
  interface.
- Um ou mais nomes/aliases de tag separados por vírgula.
- Um ou mais nomes ou IDs de grupos de hosts separados por vírgula. O host será
  considerado conforme quando pertencer a pelo menos um deles. Um grupo
  informado por nome também inclui seus subgrupos; por exemplo, `Equipes`
  inclui `Equipes/Banco de Dados` e `Equipes/Conectividade`.
- Valores aceitos para a tag, também separados por vírgula. Se ficar vazio,
  qualquer valor não vazio será considerado conforme.
- Se a métrica participa ou não do índice da página.

Use **Adicionar card** para criar novas métricas ou **Remover** para excluir um
card. A configuração é salva no próprio registro do módulo no banco do Zabbix,
sem criar tabelas adicionais.

## Diagnóstico

- Se o módulo não aparecer na lista, confira o caminho do `manifest.json` e
  execute novamente **Examinar diretório**.
- Se aparecer na lista, mas não no menu, confirme que está habilitado e que a
  conta é Super Admin.
- Em caso de tela em branco ou erro técnico, consulte o log do PHP e do servidor
  web. Erros de frontend não são gravados no log do `zabbix_server`.

## Compatibilidade

O manifesto usa a versão `1.0`, exigida pelo Zabbix 6.0. O formato `2.0` é de
gerações posteriores do frontend e faz o módulo ser ignorado pelo scanner do
6.0.

A view utiliza `CWidget`, classe disponível no frontend 6.0. `CHtmlPage` não
existe nessa versão e causa erro HTTP 500 ao abrir a ação.

As páginas GET utilizam `disableSIDvalidation()`, método do Zabbix 6.0.
As operações POST de cálculo mantêm a validação SID nativa e exigem Super Admin.
O método `disableCsrfValidation()` pertence a versões posteriores.
O endpoint de cálculo usa `layout.json`, necessário para emitir `main_block`
como JSON no frontend 6.0; `layout: null` não serve para essa resposta.

O Apache ECharts é distribuído junto com o módulo sob a licença Apache 2.0. A
cópia da licença está em `assets/js/ECHARTS-LICENSE.txt`.
