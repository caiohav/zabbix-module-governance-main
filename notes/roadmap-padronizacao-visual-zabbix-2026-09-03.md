# Roadmap de padronização visual — Zabbix 6.0

## Estado e autorização

- Pedido: inspecionar páginas reais do Zabbix, comparar botões, cores, menus e
  formulários com o módulo e deixar um plano suficiente para implementação futura.
- O diagnóstico inicial era somente documentação. O usuário autorizou a execução
  com **“comece a corrigir”** e autorizou continuar sem publicação em 04/09.
  Lotes A/B/C/D implementados localmente até a **1.22.0**; validação instalada pendente.
  Nenhuma implantação ou alteração de regras reais.
- Base inspecionada: módulo 1.18.0; instância Zabbix **6.0.36**, em inglês e tema
  escuro, navegador integrado autenticado, em 03/09/2026.
- Árvore limpa no início do diagnóstico; as notas desse diagnóstico foram
  preservadas durante a implementação. Pacote local atual 1.22.0; servidor intocado.
- Este é um roadmap novo de refinamento visual. O roadmap funcional anterior
  continua concluído; não reabrir seus diagnósticos históricos como pendências.
- Usuário está revisando regras, tags, grupos e inclusão de homologação por conta
  própria. Não modificar escopo dos indicadores nesta padronização.

## Referências realmente inspecionadas

Base das URLs abaixo: `https://zabbix.tjmt.jus.br/`.

| Referência | Página/estado observado | Padrões úteis |
| --- | --- | --- |
| Dashboard nativo | `zabbix.php?action=dashboard.view`, dashboard existente com várias páginas; menu Actions aberto e fechado | Ação principal no cabeçalho; ações secundárias em menu; páginas compactas; widgets de superfícies planas |
| Grupos de hosts | `hostgroups.php`, com filtro que já estava salvo | Criar no cabeçalho; Filter, Apply e Reset; tabela compacta; ações em massa abaixo |
| Ações de trigger | `actionconf.php?eventsource=0` | Mesma hierarquia de cabeçalho, filtro, tabela e ações |
| Criar ação | `actionconf.php?eventsource=0&form=Create+action` | Abas Action/Operations; rótulos à esquerda alinhados à direita; campos à direita; tabela Label/Name/Action |
| Nova condição | Add da tabela Conditions na criação de ação | Janela New condition; Type, Operator, seleção; Add/Cancel no rodapé à direita; fechar no topo |
| SLA nativo | `zabbix.php?action=sla.list`, filtro existente preservado | Tabela, status, filtro por tags; Add/Remove como ações de texto; ajuda discreta junto ao dado |
| Novo SLA | Create SLA, somente abertura e cancelamento | Janela com abas SLA/Excluded downtimes; tamanhos de campo proporcionais; Time zone; Required com asterisco; Add/Cancel |
| Qualidade | `zabbix.php?action=governance.quality.view`, página Geral, durante carregamento | Botão denunciado pelo usuário, abas, resumo, cards e mensagens |
| Configurar qualidade | `zabbix.php?action=governance.quality.config&page=main`, páginas Geral e DABD; seletor de grupo aberto/fechado | Formulários, condições cruzadas existentes, seletor sob demanda, ações de página/card |
| Disponibilidade | Relatório de julho já aberto no início e, ao final, `zabbix.php?action=governance.availability.view` sem cálculo | Relatório existente lido pelo DOM/acessibilidade; estado inicial e filtros conferidos visualmente |
| Configurar disponibilidade | `zabbix.php?action=governance.availability.config`, departamento e tecnologia expandidos | Configurações globais, hierarquia, campos, verificações recolhidas e rodapé |

Limites da inspeção:

- Capturas foram vistas na conversa, não exportadas para arquivos. Este documento
  registra as medidas e referências para não depender das capturas temporárias.
- Não salvar/criar ação, condição ou SLA; as duas janelas de criação foram
  canceladas. Nenhuma regra do módulo foi editada ou salva.
- A abertura de Qualidade iniciou seu carregamento normal em etapas; não houve
  clique adicional em Refresh/Test. Não foi disparado cálculo de disponibilidade.
- Menu lateral observado recolhido e expandido, depois restaurado recolhido.
  Navegador deixado na seleção de período de Disponibilidade.
- Não houve troca do tema/idioma do perfil. Claro, alto contraste, PT e variações
  de largura precisam de QA próprio durante implementação; não afirmar que foram
  conferidos nesta auditoria.
- Não confundir a tradução de rótulos do sistema com títulos/descrições de cards
  escritos pelo usuário em português: esses textos devem ser preservados.

## Medidas e padrões observados no tema escuro

Valores são evidência da instalação, **não uma paleta para copiar como valores
fixos em todos os temas**. Preferir componentes/classes do frontend instalado.

| Elemento | Referência nativa observada | Módulo atual |
| --- | --- | --- |
| Fonte de controles | Arial/Tahoma/Verdana, 12 px | Parte herda; parte usa rem e escalas próprias |
| Botão principal | 24 px; padding 0 11 px; borda 1 px; raio 2 px | Disponibilidade força mínimo 34 px e padding 7 12 px |
| Botão secundário | BUTTON com `btn-alt`, transparente, borda e estados nativos | Qualidade usa BUTTON nativo em parte; Disponibilidade redefine `btn-alt` |
| Ação pequena em linha | BUTTON `btn-link`, Add/Remove, altura medida 15 px | Algumas corretas; regra genérica de Disponibilidade também aumenta btn-link |
| Selecionar entidade | BUTTON `btn-grey` no seletor de condição | `btn-link gqp-catalog-open` no módulo |
| Input de texto | 24 px; padding 0 5 px; fundo RGB(56,56,56) | Qualidade ~31,5 px; Disponibilidade 30 px no editor e 34 px no painel; fundo RGB(32,37,40) |
| Botão primário escuro | Fundo/borda RGB(105,128,141), texto RGB(242,242,242) | Parte preservada, mas dimensões e secundários destoam |
| Superfície de formulário | RGB(43,43,43), plana | Vários tons próprios, painéis dentro de painéis |
| Cabeçalho nativo | `.header-title`, altura observada 46 px, padding horizontal 10 px | Título nativo existe, mas ações ficam em cabeçalho próprio abaixo |
| Tabela de listagem | `.list-table`, células de cabeçalho padding 6 5 px; texto discreto | Disponibilidade usa `.gav-table`, padding 11 13 px, fundos e tipografia próprios |
| Abas | Dashboard navegação 26 px; formulários por volta de 30 px | `.gqp-page-link` mínimo 38 px, padding 8 13 px e estilo de destaque próprio |
| Janela nativa | `.overlay-dialogue.modal`, cabeçalho, body, footer; botões à direita | `dialog.gqp-catalog-dialog`, padding 20 px, fundo próprio, Close à esquerda, sem X |

O nativo também exibia rolagem horizontal em algumas telas na largura da sessão.
Não copiar esse comportamento como objetivo nem atribuir toda rolagem ao módulo.
Medir overflow do conteúdo da Governança separadamente da estrutura global.

## Diagnóstico e lista priorizada

### P1-01 — Corrigir ações de navegação e cabeçalhos

Problema confirmado de `Configure pages and cards`:

- `views/governance.quality.view.php`, atualmente linha 31:
  `<a class="btn-alt gqp-config-link" ...>`.
- Medida real: altura 16,8 px, padding zero, borda zero. Visualmente é um link,
  não o botão desejado. A regra nativa `.btn-alt` observada define variante de cor
  e estados; não fornece sozinha a estrutura de BUTTON para uma âncora.
- `quality-pages.css` dá ao `.gqp-config-link` apenas comportamento de layout;
  as regras de links em `.gqp-heading` não o abrangem.
- Todas as quatro views usam CWidget para o título, mas não seus controles de
  cabeçalho. Há segundas barras próprias com ações em posições diferentes.

Implementação proposta:

1. Usar a área de controles do CWidget da versão 6.0 para ações da página.
   Conferir assinatura de `setControls` e componentes disponíveis no código
   oficial/instalado **antes de usá-los**; não assumir API de Zabbix 7.x.
2. Na Qualidade, colocar `Configurar páginas e cards / Configure pages and cards`
   no canto direito do cabeçalho, com estrutura e aparência de botão nativo.
   Manter o nome completo inicialmente; encurtar só se necessário e com contexto.
3. Preservar a semântica de navegação e o endereço da página: preferir um link
   com classe-base apropriada suportada na 6.0; se não existir, criar adaptador
   estritamente local para link-botão. Não aninhar BUTTON dentro de A, nem usar
   `href="#"`, nem depender de JS para uma navegação simples.
4. Na Disponibilidade, Configure no cabeçalho; Export JSON e Print / PDF são
   funções **já existentes**, a preservar. Podem permanecer como secundárias
   compactas ou ir ao menu Actions após validar acessibilidade e estados.
5. Nos editores, Back to dashboard no cabeçalho. Retirar os títulos internos
   repetitivos Pages and cards/Indicator rules e mover introduções para ajuda.
6. Conferir title da aba do navegador: módulo mostra apenas prefixo do sistema
   com dois-pontos, enquanto páginas nativas incluem seu nome. Inspecionar
   CControllerResponseData/`setTitle` da 6.0; não substituir só por JS.

Critério: ação identificável como botão, mesma altura/estados dos pares nativos,
sem duplicar título, funcionando antes/durante/depois de carregamento e sem JS.

### P1-02 — Unificar botões e controles entre Qualidade e Disponibilidade

- `assets/css/availability.css:36` (`.gav button, .gav a.btn-alt`) aumenta todos
  os botões, inclusive ações de texto. `.gav .btn-alt` substitui cor/fundo/borda.
- `assets/css/native-layout.css` apenas diminui alguns inputs, não os botões;
  continuam convivendo alturas 24, 28, 30, 31,5 e 34 px.
- Remover overrides genéricos aos poucos; usar botão principal nativo, `btn-alt`
  para secundários, `btn-link` para Add/Remove de linhas, `btn-grey` para Select.
- Não tratar qualquer ação de adicionar/remover como primária. Save é a ação
  persistente; Add card/department altera somente o rascunho.
- Manter `type="button"` em tudo que não salva. Não trocar IDs/data-action
  utilizados pelos listeners; não reintroduzir o antigo defeito do Add card.
- Respeitar disabled, hover, focus-visible, active e teclado. Não aplicar opacity
  extra em um controle já desabilitado pelo tema sem verificar legibilidade.
- Campos curtos (peso, meta, valores UP/DOWN, segundos) não devem ocupar 540 px;
  nome/chaves podem ser largos, descrição pode ser multiline.
- Digitação de mês e timezone precisam de adaptação específica: a proteção
  contra fundo branco não deve ser removida sem substituição testada.

### P1-03 — Editor de Qualidade mais próximo de Trigger actions

Referência realmente vista agora: Conditions é uma tabela Label/Name/Action,
com Add em forma de link; a condição é preenchida numa janela separada.
No módulo atual, a tabela contém vários selects e inputs permanentemente abertos
e ocupa toda a largura, mesmo para duas condições simples.

Alvo proposto, sem alterar o significado dos indicadores:

1. Manter a separação explícita entre **Selecionar hosts** (denominador) e
   **Medir indicador** (conformidade dos selecionados).
2. Listar condições como `Rótulo | Condição | Ações` com descrição legível:
   `A | Tag Departamento é igual a DBD | Editar Remover`;
   `B | Grupo DABD/PostgreSQL (inclui subgrupos) | Editar Remover`.
3. Add/Edit abre formulário compacto com tipo, operador, nome/valor e subgrupos.
   Cancelar descarta apenas o rascunho da condição; aplicar altera o card em
   memória, não salva as páginas no servidor.
4. Não renumerar silenciosamente as fórmulas. Edição conserva rótulo; inserção e
   remoção mantêm a política atual explícita de invalidação/limpeza da expressão.
   Preserve all/any/custom, parser seguro, limite de 20 condições e compatibilidade.
5. Manter cálculo E/OU e expressão A AND B visíveis junto à tabela. **Não copiar
   o And/Or automático das ações nativas**: o motor do módulo não tem essa mesma
   semântica. Não criar modo novo disfarçado de alteração visual.
6. Test selection and indicator continua sob demanda e usa o mesmo motor;
   total exato/amostra até 50 hosts continuam claramente distinguidos.
7. Fazer esta reestruturação depois da padronização básica, pois exige testes de
   estado de formulário, validação e respostas obsoletas. Não é só uma troca CSS.

### P1-04 — Simplificar formulários, títulos e rodapés

- Usar disposição equivalente a `table-forms`/form-grid da 6.0: coluna de rótulos
  consistente, alinhada à direita no desktop, controles à esquerda. No celular,
  empilhar rótulo e campo. Nativo Actions medido com coluna ~177 px e nome 453 px;
  são referências, não larguras obrigatórias para todas as telas.
- Consolidar timezone e política de dados em uma seção Settings/Configurações,
  em vez de duas caixas largas isoladas. A política permanece visível.
- Conservar departamentos/tecnologias/verificações recolhíveis, sem aumentar a
  quantidade de níveis. Usar bordas e recuos discretos, ações por linha.
- Padronizar rodapé: salvar com destaque e retorno/cancelamento próximo; criação
  de card perto da lista de cards, criação de departamento perto da lista própria.
- `Save all pages` precisa continuar explícito: trocar de aba não limita o save.
  Qualquer Cancel deve explicar descarte de rascunhos e manter conflitos/revisões.
- Required com marca visual e rótulo acessível, além da validação existente.
- Não transformar toda a edição de disponibilidade em outra página/modal no
  primeiro lote; seu funcionamento e edição recolhida já foram validados.

### P1-05 — Tema, cores e estilos compartilhados

- Há três camadas sobrepostas: governance.css, quality-pages.css/availability.css
  e native-layout.css. Uma adiciona bordas/raios, outra desfaz alguns; evitar nova
  camada geral de correções ao final sem retirar conflitos na origem.
- Cores próprias diferentes: links `--gov-link` versus `--gav-accent`; fundos
  `--gqp-input-bg`/`--gav-control-bg`; verdes de Qualidade diferentes de Disponibilidade.
- Botões/inputs/tabelas devem herdar o tema nativo onde possível. Adaptadores e
  tokens do módulo só para o que o nativo não cobre (gráficos, input month etc.).
- Não presumir que Zabbix 6 expõe variáveis CSS de versões novas. Levantar classes
  e a forma de tematização suportada na versão antes de desenhar a solução.
- Auditar alto contraste: detecção atual por string contendo `dark` não garante
  todos os temas. Não usar somente `prefers-color-scheme` do sistema operacional.
- Qualidade usa verde #2e7d32 em CSS e JS mesmo no escuro. Medir contraste no fundo
  real, escolher estado semântico do tema e preservar distinção textual de status.
- Cores dos gráficos vêm de arrays próprios em quality.js e availability-view.js.
  Harmonizar legenda, barras, texto, meta e cobertura; fundo deve continuar
  transparente. Não confundir UNKNOWN com DOWN, nem severidade de trigger com KPI.
- Remover sombras e raios grandes de superfícies administrativas; cards de KPI
  podem continuar visuais, mas devem compartilhar tipografia e bordas do dashboard.
- Não alterar regras globais `button`, `a`, `input`, `.btn-alt`, `body` ou menus:
  qualquer complemento precisa estar restrito ao módulo.

### P2-06 — Abas, páginas e navegação

- Abas atuais de 38 px destoam da navegação de dashboard de 26 px. Usar navegação
  compacta para páginas de indicadores e abas de formulário para seções de edição.
- Separar ações de página (renomear/remover) de ações de card. Menu de página pode
  seguir o dashboard, mas somente se houver itens suficientes para justificá-lo.
- Manter IDs/URLs estáveis, truncamento com acesso ao nome completo, rolagem das
  abas em listas longas e sem rolagem horizontal global.
- Preservar teclado já implementado no editor: setas, Home/End, roving tabindex,
  aria-selected e aria-controls. Não substituir por abas só visuais.
- Achado concreto: abrir editor com `page=main`, trocar para DABD e observar Back
  to dashboard; href continua `page=main`. PHP monta a URL inicial e selectPage
  não atualiza o link. Planejar retorno à página atualmente selecionada se ela já
  existe no servidor; página nova não salva precisa de fallback e aviso de rascunho.
- Não introduzir salvamento automático ao navegar ou renomear.

### P2-07 — Seletores e janelas

- Janela própria atual tem fundo #202528, título grande, padding 20 px e Close à
  esquerda. Nativo tem `.overlay-dialogue`, corpo e rodapé separados, fechamento
  superior e ações à direita; adaptar catálogo/condições à mesma composição.
- Conferir API nativa de overlay da **6.0** antes de reutilizar. Se exigir acoplamento
  excessivo, conservar HTML dialog e reproduzir composição, teclado e tema com
  estilos locais; não combinar duas pilhas modais sem gestão de foco.
- Select junto a grupo/template deve parecer ação de seleção, não link auxiliar.
- Preservar consulta explícita, mínimo 2 caracteres, máximo 20 resultados e
  informação de limite. Não implantar autocomplete pesado nesta tarefa.
- Preservar lista por nomes, inclusão de subgrupos, IDs exatos e nomes com vírgula.
  Um multiselect visual que converta nomes em IDs mudaria o comportamento: não fazer.
- Garantir Escape, X, Close/Cancel, foco inicial, foco de retorno, Tab/Shift+Tab,
  label visível da busca, erro/sem resultados e descarte de respostas atrasadas.
- Campos Type/Operator das condições não têm nome acessível específico por linha
  no estado observado; adicionar rótulos como “Tipo da condição A”.

### P2-08 — Painéis, tabelas e diagnóstico sem poluição

- Qualidade: faixa de progresso compacta; recolher dados de duração/chamadas de API
  em detalhes técnicos; resumo operacional e cards permanecem disponíveis em etapas.
- Cards: reduzir variação de fontes/letter-spacing; títulos completos acessíveis
  mesmo truncados; descrições não devem dominar. Preservar exceções e denominador.
- Disponibilidade: filtros com a mesma escala dos controles nativos. Calcular mês
  é execução explícita, não um Apply que inicia cálculo ao mudar mês/departamento.
- Relatório: tabela de tecnologias/hosts próxima a `.list-table`, valores numéricos
  alinhados e datas/durações legíveis. Não colocar todas as informações de itens
  abertas na célula Items/notes: resumo da fonte/estado e Details por host/item.
- No relatório de julho já aberto havia longos blocos repetidos de amostras,
  janela, heartbeat e motivo de trends. Recolher repetição; **não esconder** alertas
  acionáveis (item ausente, validade não resolvida, sem dados, cobertura parcial).
- Na fonte trends, não destacar “Samples in period: 0” do history como se a fonte
  efetivamente usada não tivesse dados. Manter história da tentativa em diagnóstico.
- Gráficos ECharts e escala 0–100% devem ser preservados. Gráfico por host continua
  lazy, liberando a instância anterior. Não inserir cálculo/API ao abrir ajuda.
- Não trocar gauge de Qualidade por barras nesta tarefa sem novo pedido: a auditoria
  visual não altera a escolha funcional de gráfico que já existe em cada painel.

### P2-09 — Ajuda, mensagens, acessibilidade e tradução

- Unificar ajuda curta junto ao campo via botão de informação compatível com o
  nativo; explicação extensa em details ou janela. Evitar várias caixas introdutórias.
- Mensagens de conflito, erro e falha de API têm prioridade e não ficam escondidas.
  Seguir aparência de mensagens nativas após conferir markup/classes da versão;
  estados de falha não foram provocados no servidor nesta auditoria.
- Não depender de cor apenas; status textual, aria-live sem repetir toda a tabela,
  aria-busy e foco de erro preservados.
- Ao alterar layout, manter ajuda associada por aria-describedby e não quebrar
  validação de campo dentro de aba/details fechado.
- Terminologia uniforme: Qualidade, Disponibilidade, Configurar, Selecionar,
  Adicionar, Remover, Salvar; equivalentes EN. Valores/nome de entidades não traduzir.
- Input month exibiu mês em português embora frontend esteja em inglês, por locale
  do navegador. Registrar como comportamento de controle nativo, não erro de cálculo.
  Se for substituído, preservar valor YYYY-MM, acessibilidade e limites do mês.
- Avaliar proteção de saída com alterações não salvas como melhoria de usabilidade;
  não confundir com alteração de escopo do save nem com diálogos de confirmação já existentes.

### P3-10 — Menu lateral: preservar o que já está correto

- Inspeção expandida/recolhida confirmou `CMenu`/`CMenuItem`, icon-dashboard e
  classes nativas `has-submenu`, `is-selected`, `is-expanded`.
- Ícone escolhido pelo usuário está correto. Não substituí-lo nem desenhar outro.
- Quatro entradas (Quality, Availability, Configure quality, Configure availability)
  estão alinhadas. Não há defeito visual que exija reescrever o menu.
- Possível simplificação futura: configurações só nas respectivas páginas. Exige
  decisão explícita do usuário; **não remover entradas nesta padronização**.
- Manter restrição Super Admin e ação selecionada corretas; CSS do conteúdo não
  pode afetar menu nativo, hover, expansão ou atalhos.

## Mapa dos arquivos para implementação

| Área | Arquivos |
| --- | --- |
| Títulos, controles de página, estrutura dos quatro painéis | `views/governance.quality.view.php`, `views/governance.quality.config.php`, `views/governance.availability.view.php`, `views/governance.availability.config.php` |
| Título do navegador e metadados do tema | `actions/QualityView.php`, `actions/QualityConfig.php`, `actions/AvailabilityView.php`, `actions/AvailabilityConfigView.php` |
| Botões, formulários, abas, tabelas, janelas | `assets/css/governance.css`, `assets/css/quality-pages.css`, `assets/css/availability.css`, `assets/css/native-layout.css` |
| Condições, catálogo, abas e rascunho | `assets/js/config.js`: `catalogControl`, `makeSelection`, `readSelection`, `selectPage`, validação/serialização e handlers |
| Editor hierárquico e eventos | `assets/js/availability-config.js` |
| Progresso, ECharts, paleta, mensagens | `assets/js/quality.js`, `assets/js/availability-view.js` |
| Inclusão dos assets e cache | `views/js/*.js.php` e addCssFile das views; URLs atuais usam versões diferentes por asset; revisar referências de cada arquivo efetivamente alterado |
| Menu (somente regressão salvo decisão nova) | `Module.php`, `tests/menu.php` |
| Testes visuais e de interação | `tests/editor-browser.cjs`, `tests/browser-preview.php`, fixtures/renderers em `tests/` |
| Regressões do editor | `tests/quality-config.js`, `tests/availability-config.js`, `tests/quality-preview*.php`, `tests/quality-catalog.php`, `tests/quality-conditions.php` |
| Regressões dos painéis | `tests/quality-view.*`, `tests/availability-view.*`, `tests/availability-observed-view.*`, `tests/availability-sla-view.*` |

## Ordem de execução futura

1. **Baseline e compatibilidade:** ler este documento, git status e versões; conferir
   APIs de componentes da 6.0; guardar capturas comparáveis e fixtures de JSON antes.
   Não presumir que o harness local atual reproduz integralmente o CSS nativo.
2. **Lote A — ganho imediato:** cabeçalhos, Configure pages and cards, retorno,
   título da aba, dimensões/variantes de botões e campos; reduzir duplicações visuais.
3. **Lote B — consistência visual:** cores/tema compartilhados, formulários,
   abas, rodapés, tabelas e ajuda. Consolidar CSS retirando overrides redundantes.
4. **Lote C — condições e catálogo:** tabela legível + edição de condição, janelas
   coerentes, foco e rascunhos. Preservar seleção/medição e endpoints atuais.
5. **Lote D — relatório e acabamento:** diagnóstico recolhido, progresso compacto,
   contraste dos gráficos e estados vazios/erro. Sem alteração do cálculo.
6. **QA/entrega:** testes abaixo, manifesto/cache somente após implementar,
   ZIP runtime em `dist/` (sem tests/notes/.git); implantação somente pelo usuário
   ou com pedido explícito. Validar no servidor sem salvar regras reais de teste.

## Critérios de aceite e testes obrigatórios

- Quatro páginas comparadas lado a lado com referência nativa no mesmo tema,
  idioma, zoom e viewport; desktop ~1440, intermediário ~1024, estreito ~700 px.
- Claro e escuro; PT/EN; alto contraste caso anunciado como suportado. Verificar
  campos preenchidos, vazios, disabled, focus, hover, dropdown, autofill e input month.
- Cabeçalhos sem repetição e Configure pages and cards com aparência de botão.
  Navegação preserva a página selecionada, inclusive fallback de página não salva.
- Menu lateral expandido/recolhido, sem regressão de ícone/permissões/destaque.
- Telas nativas visitadas depois do módulo não recebem CSS ou efeitos do módulo.
- JSON antes/depois de abrir/fechar abas, ajuda, catálogo e condição deve ser
  equivalente quando não há edição. Cancelar uma condição não muda fórmula/card.
- IDs de páginas/cards, limites, all/any/custom e migração antigos preservados.
  Mesma seleção e mesmo denominador na prévia e no painel para as regras originais.
- Add page/card/department/technology/check, Remove, Save e validação testados
  **localmente**, incluindo erro em seção recolhida, conflito de revisão e rascunho.
- Sem consultas de hosts no editor ao abrir, trocar aba ou ajuda; catálogo só sob
  demanda; prévia preserva cancelamento/obsolescência. Painel Qualidade continua
  carregando em etapas. Disponibilidade só calcula após comando explícito.
- Tab/Shift+Tab, setas/Home/End das abas, Enter, Escape e retorno de foco em janelas.
  Labels identificam tipo/operador da condição e valores de disponibilidade.
- Progresso, erro HTTP, sessão expirada, zero hosts, dados parciais, UNKNOWN e
  fonte trends são distinguíveis. Não provocar falhas reais em produção para testar.
- Gráficos sem fundo branco; barras de Disponibilidade sempre 0–100%; tooltip,
  legenda e estados legíveis; preservação do ciclo de vida lazy por host.
- Export JSON e Print/PDF continuam funcionando; não esconder metadados essenciais
  na impressão nem alterar o JSON por mudanças cosméticas.
- Regressões PHP/JS completas, sintaxe PHP, `git diff --check`, revisão do pacote.
  Recalcular fixtures antes/depois deve dar resultados numéricos idênticos.

Ferramentas locais já usadas no projeto (conferir existência antes de executar):

- PHP: `C:/Users/46027/AppData/Local/Temp/codex-php83/php.exe`.
- Node: `C:/Users/46027/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe`.
- NODE_PATH: `C:/Users/46027/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules`.
- Colocar diretório do PHP no PATH do processo para testes JS que o invocam.
- Navegador de produção: sessão autenticada via ferramenta de navegador; não
  copiar cookies/tokens, usar HTTP por fora da sessão ou registrar segredos em notas.

## Execução do lote A — 1.19.0

Implementado em 03/09/2026:

- Quatro views usam `CWidget::setControls()` para navegação no cabeçalho nativo.
  Exportar/Imprimir também foram movidos, preservando os IDs e a invalidação ao
  iniciar outro cálculo. Configurar continua acessível durante essa invalidação.
- `gov-page-actions` e `gov-action-link` adaptam links reais (funcionam sem JS)
  ao tamanho de botão secundário. Atenção: `a:link/a:visited` nativos sobrepõem
  a cor de `.btn-alt`; as cores/estados do adaptador são explícitos, com escopo
  restrito, usando os valores Blue/Dark 6.0. Não extrapolar para alto contraste.
- Removidos H2/introdução repetidos dos dois editores. Quatro actions agora
  chamam `CControllerResponseData::setTitle()` para o título da aba.
- Campos e botões de Disponibilidade voltaram a 24 px; retirados overrides que
  impunham 34 px e preenchimento a todos os botões secundários. Campos de
  Qualidade também compactados; dark inputs usam #383838 e texto #f2f2f2.
- Rótulos dos formulários em duas colunas alinham à direita; números de meta/peso
  têm largura curta. Abas compactadas e Selecionar do catálogo usa `btn-grey`.
- Retorno de Qualidade acompanha a página selecionada. `saved_page_ids` é
  capturado ANTES de aplicar um rascunho devolvido por conflito/erro; páginas
  novas retornam à primeira salva. Fallback aplicado no PHP e no JavaScript.
  Não salva rascunho; a proteção existente de alterações pendentes é preservada.
- Manifesto e caches dos arquivos alterados atualizados. Sem alteração no
  formato salvo, permissões, endpoints ou arquivos de cálculo.

Compatibilidade conferida no código oficial 6.0.36:

- https://raw.githubusercontent.com/zabbix/zabbix/6.0.36/ui/include/classes/html/widget/CWidget.php
- https://raw.githubusercontent.com/zabbix/zabbix/6.0.36/ui/include/classes/mvc/CControllerResponseData.php
- `setTitle()` da resposta não é fluente: chamar separado de `setResponse()`.
  Não transportar controles para dentro do formulário por engano: o cabeçalho
  fica fora de `main`, por isso controles de salvar continuam nos formulários.

QA local:

- Regressões PHP/JS passaram, incluindo páginas/rascunhos, condições, catálogo,
  cálculo de Qualidade e Disponibilidade, tendências, observação e SLA.
- `tests/widget-fixture.php` centraliza o contrato mínimo do CWidget com header
  e controles fora de main; não replica todo o frontend do Zabbix.
- `tests/editor-browser.cjs`: dois editores, PT/EN, claro/escuro, adição/edição,
  preview explícito, catálogo, validações, campos compactos e controles ativos.
- `tests/native-layout-browser.cjs`: quatro cabeçalhos, 24 px, cores normal/hover,
  retorno entre páginas salvas/novas, download JSON real e chamada de impressão,
  invalidação dos botões ao iniciar outro cálculo. Nenhuma conexão externa.
- Capturas finais em `C:/Users/46027/AppData/Local/Temp/governance-native-qa-KhuPEe/`
  e `C:/Users/46027/AppData/Local/Temp/governance-editor-qa-mKjApf/`.
  São temporárias, não entram no pacote; reproduzir pelos testes se desaparecerem.
- Viewports 1440/1024/700 capturados. **Limitação nativa:** CSS do Zabbix 6 impõe
  `min-width:1200px` ao header; o painel de Qualidade herda isso. A assertiva de
  ausência de overflow global é só para desktop 1440. Não anunciar suporte
  mobile pleno. Resolver futuramente somente com escopo do módulo, sem mexer
  globalmente no cabeçalho de outras páginas.
- Validação instalada ainda não realizada; após usuário instalar, conferir os
  quatro títulos, alinhamento na sidebar real, tema do perfil e caches. Não salvar
  regras reais de teste nem mudar grupos/tags/política de cálculo.
- Pacote: `dist/zabbix-module-governance-1.19.0.zip`, 512.917 bytes,
  41 arquivos sob `Governance/`, sem tests/notes/.git; cada arquivo conferido
  por SHA-256 contra o runtime local. SHA-256 do ZIP:
  `E222A7EAA9323C66B460CD1C30D1DAAF029B292652F8BAFC049F260252DE8AC5`.
  Pacote 1.18.0 preservado. Servidor local de testes encerrado após QA.

## Execução do lote B — 1.20.0, 04/09/2026

- Usuário permitiu continuar quando não dependesse de instalação. Todo o trabalho
  e testes desta etapa são locais, com dados sintéticos. Não exige instalar a
  1.19.0 primeiro: ZIP 1.20.0 é cumulativo.
- `governance.css` centraliza tokens de campos. `quality-pages.css` e
  `availability.css` passam a usar aliases desses tokens, sem paletas duplicadas.
  Superfícies administrativas compartilhadas ficam restritas a `.gqp-editor` e
  `#gav-config`; cores semânticas de gráficos/status não foram reformuladas.
- Timezone e política de dados reunidos em uma seção General settings/Configurações
  gerais. Nenhum campo escondido e nenhuma alteração de política padrão.
- Add card e Add department saem do rodapé de salvar e ficam após suas listas.
  Os IDs, `type=button` e listeners são preservados. Footer usa Save primeiro,
  seguido do estado do rascunho e legenda de obrigatoriedade; sem overlay sticky.
  Não foi criado Cancel: o retorno existente e proteção de rascunhos continuam,
  evitando uma nova ação ambígua de descarte de todas as páginas.
- Remover página/card/tecnologia/departamento e adicionar tecnologia/verificação
  usam `btn-link`; criação de página/card/departamento mantém `btn-alt` e consulta
  de catálogo mantém `btn-grey`. Nenhuma confirmação ou regra de remoção mudou.
- Asterisco segue `:required:enabled` nos rótulos. É realce progressivo por CSS
  `:has()`; atributos required e validação existentes continuam funcionando em
  navegadores sem esse seletor. Campos inativos não ganham marca indevida.
- Abas com tipografia normal; ajuda com indicador de abertura; fundos planos;
  retirados overrides redundantes de cards, grid, rodapés e títulos extintos.
  Textarea de Disponibilidade explicitamente multiline, não forçado a 24 px.
- Manifesto e caches dos CSS e JS de edição atualizados a 1.20.0. Nenhuma alteração
  nos engines, nas consultas, nos endpoints ou na estrutura dos payloads salvos.

Validação:

- Suítes PHP/JS de regressão passaram; teste de preview de regras executado
  separadamente. Logs de falha de API em fixtures são cenários esperados.
- Browser QA em PT/EN, Blue/Dark, 1440 e 700 px; os quatro cabeçalhos também em
  1024 px, respeitando a limitação nativa descrita no lote A.
- Asserções novas: uma seção global, política junto ao fuso, superfícies iguais,
  campos required marcados, só Save no footer, Add junto à lista e footer estático.
  Catálogo, preview sob demanda, rascunhos, validações e troca itens/SLA passaram.
- Export JSON, impressão e invalidação de ações continuam passando. O teste
  intercepta `window.print`, não imprime em dispositivo físico.
- Capturas inspecionadas: `C:/Users/46027/AppData/Local/Temp/governance-editor-qa-cOZEG0/`
  e `C:/Users/46027/AppData/Local/Temp/governance-native-qa-qYSO2m/`.
  Para reproduzir, usar os mesmos testes/harness do lote A, sem acesso externo.
- Não anunciar validação instalada ou alto contraste: continuam não verificados.
- Execução final após caches/limpeza de CSS também passou; capturas em
  `C:/Users/46027/AppData/Local/Temp/governance-editor-qa-BeOpSC/`.
- Pacote 1.20.0: 513.479 bytes, 41 arquivos runtime conferidos por SHA-256;
  ZIP `544A693DD32F4EBB4A505E8063604716318C0E6500729459DF077F9F0DC263FA`.
  Versões 1.18.0/1.19.0 preservadas. Sintaxe PHP/JS e `git diff --check` aprovados.

## Execução do lote C — 1.21.0, 04/09/2026

- Pedido “proximo”: implementados tabela compacta e editor em janela para condições,
  e padronizada a janela do catálogo. Continua sem acesso ao servidor de produção.
- `assets/js/config.js`: linhas guardam `conditionData` (objeto simples do rascunho)
  e mostram Rótulo/Condição/Ações. `readSelection` serializa os mesmos campos do
  formato versão 1. Nada de campos editáveis escondidos no relatório da condição.
- `editCondition` cria controles temporários, isolando eventos input/change do
  formulário principal. Só Adicionar/Aplicar válido substitui a condição. Abrir,
  digitar, cancelar, X e Escape não alteram payload, fórmula ou prévia já aplicada.
  Aplicar sem mudança também não invalida a prévia. Parent submit é bloqueado
  enquanto uma condição está aberta; Enter no campo aplica somente a condição.
- Condições inválidas devolvidas no rascunho impedem salvar/testar e abrem a linha
  problemática. Validação cliente replica os limites/tipos/campos de
  `QualityConditions::validate`; a validação PHP continua sendo a autoridade.
- Todos/Qualquer/Expressão preservados; edição mantém rótulo/fórmula. Só inserção
  efetivamente aplicada e remoção limpam a fórmula, seguindo o comportamento
  anterior. Cancelar uma inserção não limpa nada. Limite de 20 preservado.
- Descrições usam somente textContent; valor exato vazio de tag é explícito.
  Lista de grupo/template indica OU; nome+subgrupos e ID exato continuam distintos.
  Ajuda diferencia métricas por aliases de tags de condições com comparação exata.
- `modal` compartilha cabeçalho/body/footer, X e navegação Tab/Shift+Tab. Mantido
  HTML dialog, já usado no catálogo: não depende de APIs de overlay de outras
  versões do Zabbix nem mistura bibliotecas de modal. Sem suporte a showModal,
  exibe aviso para atualizar navegador e não deixa o formulário bloqueado.
- Catálogo pode abrir acima da condição, ambos na mesma pilha nativa: Escape fecha
  só o superior, foco volta ao seletor, depois ao Editar/Adicionar da tabela.
  Busca continua explícita, com os mesmos limites (2 caracteres, 20 resultados).
  Fechar cancela a consulta e invalida respostas atrasadas. Nenhum autocomplete.
- `native-layout.css`: três colunas, descrições quebram linhas, ações compactas;
  diálogos responsivos com corpo rolável e footer visível. Testados até 390 px.
  Não anunciar responsividade plena de toda a aplicação: limite do header nativo
  descrito no lote A permanece fora deste trabalho.
- Manifesto 1.21.0; cache do JS de Qualidade e do CSS compartilhado atualizado nas
  quatro views. Motores, endpoints, permissões e regras do servidor não mudaram.

QA concluído localmente:

- Suítes PHP/JS passaram, inclusive preview-actions, seleção cruzada, parser,
  serialização, regras antigas, cálculos de disponibilidade e itens/trends/SLA.
- `tests/quality-config.js`: mock DOM adaptado a dialogs; cancelamento, isolamento,
  validação de nome vazio/lista de vírgulas, rascunho inválido, fórmulas e prévia.
- `tests/editor-browser.cjs`: fluxo real adaptado às novas janelas, catálogo dentro
  de condição e catálogo da métrica, amostra de hosts, preview sem salvar, temas/idiomas.
- Novo `tests/conditions-browser.cjs`: JSON inalterado durante edição/cancelamento,
  Enter sem submit, labels estáveis, fórmula limpa só quando necessário, X/Escape,
  foco inicial/retorno/trap, validação, conteúdo HTML como texto, 20 condições,
  responsividade 1440/700/390 e resposta atrasada após fechar catálogo/condição.
  Só a busca explicitamente acionada consulta o endpoint sintético.
- Capturas inspecionadas em `C:/Users/46027/AppData/Local/Temp/governance-conditions-qa-0FQrO2/`;
  execução com teste de resposta atrasada em `.../governance-conditions-qa-p8YRS6/`.
- `tests/native-layout-browser.cjs`: cabeçalhos, navegação de páginas e exportação/
  impressão continuam aprovados. Validação instalada e alto contraste não realizados.
- Execução final dos editores após atualização de versão passou; capturas em
  `C:/Users/46027/AppData/Local/Temp/governance-editor-qa-9vzBjb/`.
- ZIP 1.21.0: 516.621 bytes, 41 arquivos sob Governance/, sem testes/notas.
  SHA-256: `96CD5A54B9CCD5359CA4CD7EFD44E954E2EC6A94E2E97404AB04DFEC52F1A7AD`.
  Cada entrada conferida contra o runtime. Versões anteriores preservadas.

Próximo passo registrado na entrega C: lote D (P2-08/P2-09). Executado abaixo.

## Execução do lote D — 1.22.0, 04/09/2026

- Pedido “Proximo”: acabamento local de Qualidade/Disponibilidade, sem acessar ou
  modificar produção. Engines, seleção, regras, API e formatos salvos preservados.
- Qualidade: painel de progresso compacto e detalhes da consulta fechados por
  padrão. Horários, chamadas à API e explicação da consulta ficam nesse bloco;
  estado, progresso, Atualizar/Tentar novamente, cards e indicadores ficam visíveis.
  IDs e regiões aria-live/aria-busy preservados, sem novas consultas ao expandir.
- Cards: superfícies planas, cabeçalho de 13 px sem espaçamento entre letras,
  até duas linhas; title contém nome/descrição completos. Corrigida a precedência
  de `.gqp h3`, que sobrepunha o tamanho compacto. Denominador e exceções mantidos.
- Disponibilidade: resumo do período/snapshot, status, progresso e ações permanecem
  fora dos detalhes do processamento. Contagens, contexto e explicação da pausa
  recolhidos; a descrição acessível da barra aponta para a mensagem visível.
- Tabelas de tecnologias/hosts usam `.list-table`, fontes compactas e números à
  direita. Fonte ativa, cobertura, tempo desconhecido e alertas continuam visíveis.
  Janela, heartbeat, amostras, timestamps e tentativas ficam em Detalhes da fonte.
- Trends conservadoras: não chamar contagem vazia de history de ausência de dados
  da fonte ativa. A tentativa continua integralmente consultável nos detalhes.
  Histórico não consultado, ausência real de amostras e valores não classificáveis
  continuam explícitos em history. Amostra anterior válida não gera falso alerta
  de ausência. Warnings do motor e cobertura parcial não foram suprimidos.
- Cores semânticas compartilhadas por CSS e ECharts, com variantes claro/escuro.
  Melhorado contraste de textos de status e textos secundários. Corrigida a
  precedência que deixava avisos de fonte na cor secundária da tabela. Restaurada
  `.gqp-danger` usada pelo JavaScript em erros de consulta, sem afetar botões Remover.
- Gauges de Qualidade e barras de Disponibilidade permanecem transparentes e
  0–100%. Gráficos por host seguem lazy e descartam a instância anterior.
- Impressão abre os detalhes técnicos das fontes, restaura o estado anterior ao
  terminar e usa paleta clara. Não abre todos os hosts nem instancia seus gráficos;
  metadados ficam imprimíveis dentro das seções de tecnologia que o usuário abriu.
- Caches dos CSS compartilhados e scripts dos dois relatórios atualizados.
  Exportação conserva seu contrato/versão de formato anterior, sem alterar dados.
- Proteção de saída com rascunho não salvo: avaliada como melhoria opcional,
  adiada para não introduzir novos bloqueios de navegação nesta padronização.
  Não é pendência de cálculo. Input month continua seguindo locale do navegador.

QA local:

- Todas as suítes PHP/JS passaram: regras antigas e cruzadas, configuração,
  jobs/preview, freshness/heartbeat, history/trends, políticas estrita/observada,
  SLA, gráficos, exportação e impressão. Dois testes de symlink por suite de jobs
  permanecem indisponíveis neste Windows; não declarar que foram executados.
- `tests/availability-observed-view.php`: seletor da tabela atualizado para as
  classes nativas; 670 verificações renderizadas passaram. Expectativas de cor
  desconhecida nos testes JS agora seguem a cor secundária do tema.
- Novo `tests/reports-browser.cjs`: escuro/PT e claro/EN; detalhes fechados, sem consultas
  extras; fonte trends, falta de dados, histórico não consultado e seed válido;
  restauração da impressão, escala 0–100%, transparência e estados vazio/falha.
  Contraste de pelo menos 4,5:1 das cores semânticas/secundárias do relatório
  contra as superfícies de teste #eceeef e #2b2b2b. Não equivale a auditoria WCAG.
- Capturas dos relatórios inspecionadas em
  `C:/Users/46027/AppData/Local/Temp/governance-reports-qa-ZURNOd/`.
- Cabeçalhos/navegação/exportação: `tests/native-layout-browser.cjs`, capturas em
  `C:/Users/46027/AppData/Local/Temp/governance-native-qa-HKKwDB/`.
- Editores: `tests/editor-browser.cjs`, capturas em
  `C:/Users/46027/AppData/Local/Temp/governance-editor-qa-FI33ig/`.
- Condições e janelas: `tests/conditions-browser.cjs`, isolamento, validação,
  teclado e largura de 390 px; capturas em
  `C:/Users/46027/AppData/Local/Temp/governance-conditions-qa-dKlOHX/`.
- Verificação adicional de falha de comunicação: erro e Tentar novamente visíveis
  com detalhes fechados e cor de erro do tema escuro. Nova execução completa de
  relatórios aprovada: `C:/Users/46027/AppData/Local/Temp/governance-reports-qa-qx1uCH/`.
- Sintaxe de todos os arquivos PHP/JS e `git diff --check` aprovados.
- ZIP 1.22.0: 517.805 bytes, 41 entradas conferidas por SHA-256 contra o runtime.
  SHA-256: `9F4F531D0CB7098A2ACE3249A1447D13BE9F9966E51DF031B9197D155D4A074E`.
  Comparação com 1.21.0 confirmou que apenas manifesto/README, views, CSS e os
  scripts de apresentação dos dois relatórios mudaram. Engines/actions intactos.
  Versões anteriores preservadas; testes/notas não entram no ZIP.

Próximo passo: usuário publicar o ZIP cumulativo 1.22.0; conferir as quatro telas
na instalação real, com regras escolhidas pelo usuário. Validar claro/escuro,
menus, temas de alto contraste e impressão real. Não pedir publicação de todas
as versões intermediárias. Não reabrir julho nem alterar políticas de ausência
de dados, pesos, grupos/tags ou fontes do usuário. Não declarar homologação feita.

## Checklist de retomada

- [x] Páginas nativas inspecionadas em sessão real, inclusive Create action/condição.
- [x] Quatro telas do módulo auditadas; causa do botão confirmada no DOM/CSS.
- [x] Plano, arquivos, prioridades, preservações e critérios registrados.
- [x] Usuário solicitar implementação da padronização.
- [x] Lote A — 1.19.0, código e testes locais.
- [x] Lote B — base dos editores Blue/Dark e formulários, 1.20.0.
- [x] Lote C — condições e catálogo, 1.21.0.
- [x] Lote D — relatórios, detalhes e contraste, 1.22.0.
- [x] QA e pacote local do lote A.
- [ ] Validação instalada do lote A.
- [x] QA local e pacote cumulativo do lote B.
- [ ] Validação instalada do lote B.
- [x] QA local e pacote cumulativo do lote C.
- [ ] Validação instalada do lote C.
- [x] QA local do lote D.
- [x] Pacote cumulativo 1.22.0 conferido.
- [ ] Validação instalada do lote D e temas de alto contraste.

Retomar por aqui, não pelas notas arquivadas. Não alegar que uma proposta já foi
implementada. Marcar cada lote somente depois de código e testes concluídos.
