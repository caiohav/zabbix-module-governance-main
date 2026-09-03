# Roadmap: regras personalizadas e interface nativa

## Pedido e escopo

Usuário considera confusa a interface 1.14.0. Quer selecionar os hosts por regras
personalizadas (ex.: tag X E grupo XX) e medir quantos possuem template ZYX.
Quer prévia dos hosts selecionados, somente ao clicar em um botão. Usar como
referência a configuração nativa das ações de trigger do Zabbix 6.0:
https://zabbix.tjmt.jus.br/actionconf.php?eventsource=0&form=Create+action
Padronizar também as outras telas do módulo, sem alterar cálculos de disponibilidade.
Não salvar/criar ações reais nem mudar configurações de produção ao inspecionar.

## Estado inicial

- Base: módulo 1.14.0; árvore limpa no início deste pedido.
- Qualidade já tem scope_tag_name/value, scope_group_names, flags de subgrupos,
  template_names, template_mode any/all, inventory_field e display_mode.
- Escopo atual é tag E grupos; lista de grupos usa OU; só hosts monitorados.
- Templates diretos por nome/ID; não percorre herança. Tags herdadas excluídas.
- Card usa denominador próprio; vazio => score null e fora do índice.
- QualityCalculation executa em etapas e tem API injetável; QualityJobStore
  guarda checkpoints privados; QualityRun tem proteção de sessão/permissões.
- Editor atual assets/js/config.js gera muitos campos dentro de details.
- Não reintroduzir fundo branco, cabeçalhos repetidos nem consultas no carregamento.

## Plano de execução e critérios

1. Inspecionar visualmente a referência autenticada. Abrir condições/adicionar
   sem salvar. Registrar disposição, tabela de condições, operadores e combinação.
   Se sessão indisponível, pedir login e usar documentação/código oficial como apoio.
2. Separar claramente Identificação, Seleção de hosts e Indicador. Condições em
   linhas identificadas A/B/C, tipo, operador e valor, com adicionar/remover.
   Suportar múltiplas tags/grupos/templates/campos de inventário para seleção,
   combinações todas (E) e qualquer (OU); só oferecer fórmula personalizada se
   houver parser seguro/testado (nunca eval). Explicar diferenças ante ações nativas.
3. Esquema versionado e validador compartilhado: limites de condições/texto,
   operadores permitidos por tipo, IDs estáveis. Migrar regras 1.14 sem persistir
   automaticamente e sem alterar sua semântica (incluindo grupos OU dentro de E).
   Preservar cards/pages/índice, conflitos de revisão e quota de module.config.
4. Motor compartilhado de seleção e medição: aplicação idêntica no painel e prévia;
   buscar relações somente necessárias; lotes limitados; validação explícita de
   API incompleta. Não confundir população com regra: tag X E grupo XX seleciona,
   template ZYX mede. Exibir X de N, com opção conformes/não conformes.
5. Prévia sob demanda de rascunho não salvo: POST autenticado com token nativo,
   mesma permissão do editor, validação rigorosa; nenhum salvamento de config.
   Reutilizar pipeline em etapas/checkpoint privado se grande. Total exato e
   amostra limitada/lista paginada, avisar limite; não mostrar amostra como total.
   Mostrar nome/link e resultado da métrica; cancelar/inutilizar resposta antiga
   ao editar regra/trocar card; erro explícito e possibilidade de tentar de novo.
   Não executar nenhuma consulta de hosts automaticamente no editor.
6. UI alinhada com nativo: abas, labels, tabela compacta, botões Adicionar/Remover,
   formulário de uma coluna e ajuda recolhida; cores herdadas light/dark. Aplicar
   mesma linguagem visual à configuração de disponibilidade e cabeçalhos dos
   painéis sem reescrever formulários/cálculos funcionais desnecessariamente.
7. Testes: migração/roundtrip, AND/OR, exclusões, subgrupos/prefixos, templates
   any/all, inventário vazio, zero hosts, cinco casos originais, equivalência
   prévia/painel, autenticação/token, inválidos, limites, resposta obsoleta e
   nenhuma chamada automática. Regressões PHP+JS existentes e escala.
   Verificação visual PT/EN light/dark e tamanho de tela quando possível.
8. Atualizar README e versão/cache assets; gerar ZIP somente runtime (estrutura
   Governance/, sem testes/notas), conferir manifesto/arquivos; não implantar
   no servidor sem solicitação. Entregar ZIP e limitações/testes realmente feitos.

## Arquivos-chave e ferramentas

- GovernanceConfig.php, QualityCalculation.php, QualityJobStore.php
- actions/QualityConfig.php, QualityConfigUpdate.php, QualityRun.php
- views/governance.quality.config.php, governance.quality.view.php, views/js/*
- assets/js/config.js, quality.js; assets/css/quality-pages.css, governance.css
- views/governance.availability.config.php; assets/css/availability.css
- tests/quality-*.php, tests/quality-config.js, tests/quality-view.js
- PHP: C:/Users/46027/AppData/Local/Temp/codex-php83/php.exe
- Node: C:/Users/46027/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe
- JS suites que executam PHP precisam dessa pasta PHP no PATH do processo.
- Editar usando apply_patch. Sem subagentes (não autorizado pelas instruções).

## Progresso / retomada

- [x] Roadmap salvo antes da implementação.
- [ ] Referência visual autenticada: bloqueada pelo controle de navegador no turno anterior; não insistir automaticamente. Referência documental oficial consultada: https://www.zabbix.com/documentation/6.0/en/manual/config/notifications/action.
- [x] Modelo/compatibilidade e motor: QualityConditions.php, selection v1, até 20 condições; all/any. Migração de campos antigos preserva listas de grupos OU em uma linha sob E.
- [x] Editor e prévia: tabela A–T, seleção separada da medição; preview_start em QualityRun, SID/permissões originais; rascunho validado, checkpoints privados; mesmo motor, 50 amostras, totais exatos, sem contadores operacionais.
- [x] Camada visual comum native-layout.css nas quatro telas. Qualidade em uma coluna; condições em tabela. Disponibilidade recebeu ajustes visuais leves, não uma reestruturação funcional completa.
- [x] Testes PHP e JS existentes passaram; novos testes de regras, equivalência da prévia, permissão/rascunho, migração e resposta obsoleta. Escala local: 12.001 hosts/30 cards, 244 chamadas, 8 MiB (API simulada).
- [x] Pacote zabbix-module-governance-1.15.0.zip gerado e conferido: 41 entradas, manifesto 1.15.0, classe QualityConditions e CSS/JS/ação novos incluídos; testes/notas excluídos. Sintaxe PHP e git diff --check passaram. Não instalado no servidor.

### Continuação 1.16.0 — expressões personalizadas

- Implementada seleção custom com formula: `(A or B) and C`, and/or/not, parênteses, precedência not > and > or. Limites: 512 bytes, 256 tokens, 20 condições; exige uso de todos os rótulos existentes.
- Parser shunting-yard limitado em QualityConditions, sem eval e sem recursão. Programa compilado internamente em QualityCalculation::create; `_program` enviado pelo cliente é descartado pela validação e reconstruído no servidor.
- Editor mostra campo somente no modo custom; valida sintaxe/rótulos antes de salvar/testar. Adicionar ou remover linha limpa a expressão para prevenir troca silenciosa de referências. E/OU antigos preservados.
- Testes de tabela-verdade e integração adicionados em quality-conditions.php; testes de formulário em quality-config.js; browser test agora exercita `(A or B) and C` e a prévia em etapas.
- Navegador local passou PT/EN e claro/escuro em 1440/700 px. Screenshot inspecionado: C:/Users/46027/AppData/Local/Temp/governance-editor-qa-nkfoqn/quality-dark.png. Depois da inspeção, ocultada repetição da expressão abaixo do campo custom.
- Escala custom passou: 12.001 hosts/30 cards, 244 chamadas, 8 MiB, 0,78 s com API simulada. Regressões PHP/JS, sintaxe PHP e diff check passaram. Pacote zabbix-module-governance-1.16.0.zip gerado e conferido (41 entradas, manifesto 1.16.0). Servidor de teste local encerrado. Não houve acesso/escrita na instância real.

### Decisões e pendências posteriores

### Continuação 1.15.1

- Adicionada expressão visual A E B/A OU B e Cancelar prévia. Respostas de prévia validam total, cardinalidade/ID do KPI, amostra limitada e IDs duplicados antes de mostrar resultados.
- Configuração de Disponibilidade: campos alinhados no padrão de formulário; explicações gerais recolhidas em details. Nenhuma alteração de cálculo.
- QA local real realizado com Playwright + Edge headless em perfil isolado, rede limitada a 127.0.0.1, servidor PHP do harness em 8771. Sem usar sessão de produção.
- Teste tests/editor-browser.cjs cobre PT/EN, light/dark, 1440/700 px, ausência de requests automáticos, prévia real sobre fixtures (251 hosts, 50 amostras), descarte após editar e ausência de erros JS/overflow da página.
- Screenshots locais da última passagem: C:/Users/46027/AppData/Local/Temp/governance-editor-qa-zO3RjF. Inspeção visual de disponibilidade dark e qualidade light/mobile realizada na passagem anterior hzK3g7, de mesmo layout.
- Harness de testes corrigido para não carregar classes de renderização durante POST de prévia; suporta preview_start/cancel. Isso não altera endpoints de produção.
- Pacote desta continuação: zabbix-module-governance-1.15.1.zip gerado e conferido (41 entradas, manifesto 1.15.1). Regressões PHP/JS passaram. Instalação/validação no servidor ainda pendente.

- Fórmula personalizada disponível desde 1.16.0. Modo automático misto E/OU por tipo não implementado; usar fórmula explícita quando necessário.
- A prévia ignora o filtro global de grupos do painel, com aviso explícito. Só hosts monitorados acessíveis; limite de 50.000 hosts antes da seleção. Não há pushdown dos filtros nem paginação de amostra.
- Templates e tags continuam diretos, não herdados. Nomes/IDs digitados ou seleção assistida de grupos/templates disponível desde 1.17.0.
- QA visual local foi realizado na continuação 1.15.1 (ver acima). Ainda não há QA autenticado no servidor; não afirmar reprodução fiel da tela de ações específica do usuário.
- Teste da prévia no servidor e adaptação mais profunda da configuração de Disponibilidade permanecem próximos passos, se solicitados.
- Nenhuma alteração no servidor ou ação nativa do Zabbix foi realizada.

Ao retomar: ler este arquivo, git status e diffs atuais; não refazer trabalho
marcado concluído. Atualizar esta seção com decisões, testes e pendências reais.

### Continuação 1.17.0 — Catálogo sob demanda

- QualityCatalog.php: consulta read-only HostGroup/Template, mínimo 2 bytes, máximo 255, sem controles; busca parcial literal (nome visível e técnico de templates), sortfield name compatível com 6.0. Limite 21 para indicar mais resultados, resposta com até 20 IDs como strings e nomes. Nenhuma varredura de hosts ou relações.
- QualityRun aceita lookup com tipo/query, mantendo POST, Super Admin e SID nativo. Executa antes de abrir jobStore; consulta não depende de cache ou permissão de escrita temporária. protected catalogGet permite testar sem API real.
- Editor: Selecionar… ao lado dos grupos/templates nas condições e na medição. Dialog HTML nativo, busca explícita/Enter, PT/EN/temas. Aborta ao fechar/editar busca, descarta respostas obsoletas e valida resposta. Não grava nem calcula automaticamente.
- Selecionar adiciona NOME e preserva subgrupos/listas anteriores; deduplica nomes, não trunca acima de 255. Vírgulas não são representáveis no formato legado: resultado fica desabilitado, instrução para ID manual/grupo exato. Renomeações exigem revisar regras por nome. Não mudamos o esquema de configuração.
- Testes de API limitada, resposta inválida, precisão de IDs, controlador sem jobStore, permissões/GET e nenhuma gravação. Regressões PHP/JS passaram. Browser local passou seleção de grupos/templates + prévia com fixtures, PT/EN, claro/escuro, 1440/700 px. Screenshot inspecionado: C:/Users/46027/AppData/Local/Temp/governance-editor-qa-GPOyFM/quality-dark-catalog.png.
- Referências oficiais consultadas: https://www.zabbix.com/documentation/6.0/en/manual/api/reference/hostgroup/get e https://www.zabbix.com/documentation/6.0/en/manual/api/reference/template/get.
- Não houve acesso/alteração no servidor. QA autenticado e eventual refinamento da configuração de disponibilidade continuam pendentes.
- Verificação final: navegador passou também deduplicação, descarte ao editar busca e fechamento por Escape. Últimas capturas: C:/Users/46027/AppData/Local/Temp/governance-editor-qa-a9xTw9. Sintaxe PHP e diff check passaram. Pacote zabbix-module-governance-1.17.0.zip gerado/conferido com manifesto 1.17.0 e QualityCatalog incluído; servidor PHP local encerrado.
