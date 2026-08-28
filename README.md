# Governance & Quality Audit — Zabbix 6.0 LTS

Módulo de governança e auditoria de qualidade de dados para o frontend do
Zabbix 6.0 LTS.

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

Cada tecnologia permite configurar:

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

O automático **não interpreta macros de intervalo, agendamentos personalizados,
itens dependentes/trapper nem descarte inalterado sem heartbeat**. Nessas situações,
é necessário definir uma validade manual por item; até isso ser feito, a
verificação fica desconhecida com aviso. A validade máxima é de 86400 s.
Uma política manual abaixo do heartbeat gera aviso, sem ser alterada silenciosamente.

Ao atualizar, as validades existentes são preservadas como manuais. Use o
automático somente quando o intervalo/pré-processamento forem suportados.
Por exemplo, um ICMP com intervalo flexível por dia da semana ainda exige uma
validade manual. Não aumente a validade apenas para eliminar lacunas: isso muda
a regra do indicador e pode esconder a falta de coleta. Copiar os arquivos não
muda as políticas já salvas nem a retenção de histórico do Zabbix.

Todas as verificações de um host são obrigatórias. Uma falha confirmada deixa
o host indisponível, mesmo se outra verificação estiver sem dados. No modo
**qualquer servidor fora**, o sistema une os intervalos: quedas simultâneas de
host e serviço, ou de vários servidores, não duplicam a duração. No modo
**média**, cada host tem peso igual. O peso da tecnologia é aplicado somente
ao consolidar o departamento.

Ausência de histórico, item ausente/não numérico, amostra expirada ou valor
que satisfaça ambas/nenhuma das condições explícitas gera tempo desconhecido.
Resultados com lacunas ficam **incompletos**, com cobertura e faixa possível
do índice, sem assumir disponibilidade. Um grupo vazio também não gera 100%.

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

- Calendário 24×7, com fuso configurável; o mês atual considera apenas o tempo
  transcorrido. Manutenções não são excluídas automaticamente.
- Usa **histórico bruto** dos itens, com resolução de um segundo. Trends
  agregadas não permitem reconstruir quedas e não são usadas como substituto.
  Configure retenção de histórico compatível com os meses que deseja consultar.
- Usa as regras e a composição de hosts/grupos **atuais**, inclusive hosts
  desabilitados que ainda tenham histórico. Alterações podem mudar resultados
  de meses passados. Não há fechamento mensal imutável nem histórico de membros.
- Hierarquia fixa: departamento → tecnologias → hosts → verificações.
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
Execute também `php tests/menu.php` para conferir os rótulos e destinos do menu.
`node tests/availability-view.js` verifica o fluxo do navegador com DOM/rede
simulados, incluindo SID, pausa, timeout, retomada, idempotência e relatórios antigos.

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
