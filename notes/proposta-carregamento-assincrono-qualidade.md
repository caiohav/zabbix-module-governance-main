# Qualidade: proposta de carregamento assíncrono

Data: 28/08/2026. Base local: módulo 1.7.1.

Atualização: implementação principal concluída na versão local 1.8.0, após solicitação do usuário.
Inclui GET leve, início automático, lotes de 100 hosts, checkpoints exclusivos da Qualidade,
contadores independentes, erros/retomada, ECharts desacoplado e testes. Cache compartilhado
e alterações de infraestrutura não foram implementados. O texto abaixo preserva a análise
original; os limites e o comportamento entregue estão documentados no README.

## Conclusão

É viável carregar primeiro o menu lateral, o cabeçalho, as páginas e a estrutura dos cards, exibindo “Carregando indicadores…” enquanto os dados são consultados. Recomenda-se carregamento automático com requisições separadas e, onde necessário, processamento por lotes. O usuário não precisa clicar em “Calcular” a cada visita.

Usar os princípios da Disponibilidade — página inicial leve, progresso, tratamento de falhas e etapas curtas — sem copiar seu motor de histórico mensal. Qualidade consulta o estado atual; não precisa reconstruir séries temporais.

Este arquivo é a única alteração deste trabalho. A nota anterior sobre julho foi preservada. Não houve alteração de código, configuração, implantação ou consulta ao ambiente produtivo nesta avaliação.

## Evidências encontradas no código

Em `actions/QualityView.php`, todo o trabalho ocorre em `doAction()` antes de `setResponse()`:

1. `loadPages()` consulta a configuração do módulo e seleciona a página.
2. `Host.get` carrega todos os hosts monitorados do escopo, sem limite ou lotes, com interfaces e os relacionamentos necessários aos cards.
3. Outra consulta conta os hosts desabilitados.
4. Percorre hosts para manutenção e estado das interfaces.
5. Para cada card, percorre novamente todos os hosts, calcula conformidade e guarda até dez exemplos de não conformidade.
6. `Problem.get` conta problemas altos/críticos abertos e não suprimidos.
7. `Item.get` conta itens monitorados não suportados.
8. Somente então devolve os dados para a view e o layout do Zabbix.

São cinco chamadas de API no caminho com hosts: módulo, hosts, hosts desabilitados, problemas e itens. As seleções de relacionamentos podem envolver consultas internas adicionais. A quantidade de chamadas de alto nível, sozinha, não mede seu custo.

`assets/js/quality.js` apenas desenha os gráficos a partir dos resultados já presentes no HTML. Não busca métricas nem acompanha processamento. Assim, colocar um spinner nessa view, sem mudar o controlador, só mostraria o aviso depois do trabalho pesado.

As abas de Qualidade são links para uma nova navegação: mudar de página repete as consultas, inclusive o resumo operacional que depende do escopo, e não dos cards.

O código já possui otimizações que devem ser preservadas:

- Calcula somente a página selecionada, não as 12 páginas possíveis.
- Seleciona tags, inventário, templates e grupos somente quando necessários aos cards ativos.
- Usa contagem para problemas e itens; não baixa todos esses objetos.
- Limita a amostra de não conformes a dez hosts por card.
- Evita consulta global de problemas/itens quando o escopo monitorado está vazio.

## O que pode estar lento, e o que ainda não foi medido

Candidatos: leitura de todos os hosts e seus relacionamentos, contagem de itens não suportados, consulta de problemas e trabalho PHP proporcional ao número de hosts × cards, além das tags/grupos/interfaces examinados em cada avaliação. `splitList()` e normalizações das mesmas regras são repetidos para cada host, aumentando trabalho evitável.

A biblioteca ECharts local tem 1.034.102 bytes antes de compressão HTTP e o JavaScript configura animação de 800 ms. Isso pode influenciar o carregamento/renderização após a resposta, mas não demonstra ser o gargalo do processamento no servidor.

Não foram medidos nesta avaliação o tempo de resposta em produção, a duração individual de cada API, as consultas SQL nem o consumo de memória real. Não atribuir a lentidão exclusivamente a uma consulta ou prometer um tempo fixo sem essas medições. A dependência entre resposta inicial e conclusão do cálculo, porém, está comprovada no controlador.

Foi executada a suíte existente `tests/quality-pages.php`: 133 verificações passaram. Ela usa APIs simuladas; valida comportamento, não desempenho produtivo.

## Alternativas

| Alternativa | Benefício | Limitação |
| --- | --- | --- |
| Aviso no fluxo atual | Mudança pequena | Não resolve a espera pela resposta inicial; não recomendado isoladamente |
| Página leve + uma consulta assíncrona | Libera navegação e permite aviso de carregamento | A consulta continua longa, com o mesmo risco de timeout/memória |
| Página leve + etapas/lotes | Libera navegação e reduz trabalho acumulado por requisição | Exige estado, consistência, tratamento de retomada e testes adicionais |

Recomendação: terceira opção com escopo enxuto. Uma entrega inicial pode separar os blocos e medir suas durações; lotes devem ser aplicados aos blocos que possam exceder o orçamento de execução. Não criar uma consulta completa independente para cada card: isso multiplicaria a leitura dos mesmos hosts.

## Experiência proposta

1. GET inicial carrega apenas configuração e estrutura: menu nativo, título, abas, botão de configurar/atualizar e espaços reservados. Nenhum `Host.get`, `Problem.get` ou `Item.get` pesado nesse caminho.
2. Após a estrutura estar disponível, iniciar automaticamente uma atualização da página selecionada. Mostrar “Carregando indicadores de qualidade… Você pode continuar navegando.”
3. Informar fases reais: “Consultando hosts”, “Avaliando indicadores”, “Consultando problemas” e “Consultando itens não suportados”. Exibir progresso numérico apenas quando houver denominador conhecido.
4. Publicar os cards quando todo o lote de hosts necessário tiver sido processado. Não apresentar percentuais de uma parcela dos hosts como resultado final.
5. O índice da página só aparece quando todos os cards participantes tiverem terminado. As métricas operacionais, independentes desse índice, podem terminar depois e mostrar carregamento/erro individualmente.
6. Ao terminar, mostrar instante/intervalo da consulta e botão “Atualizar”. Não implementar atualização periódica automática neste escopo.
7. Se falhar, mostrar erro útil e “Tentar novamente”. Não deixar spinner eterno nem converter falha em zero, 100% ou “Nenhum host”.
8. Trocar de página ou sair interrompe o envio de novas etapas. Ignorar respostas atrasadas da página anterior. Cancelar a espera no navegador não garante cancelamento da consulta PHP/SQL já enviada.

Manter tema claro/escuro, português/inglês, navegação por teclado, `aria-busy`, região de status acessível e espaços estáveis para evitar saltos de layout. Sem JavaScript, explicar a necessidade de ativá-lo; não voltar silenciosamente a executar toda a análise no GET.

## Arquitetura sugerida

### Controlador leve e serviço de cálculo separado

- Manter `QualityView` responsável pela configuração, seleção da página e layout inicial.
- Extrair critérios de conformidade e agregação para um serviço testável, por exemplo `QualityCalculation`/`QualityMetrics`; nomes propostos, arquivos ainda inexistentes.
- Criar endpoint autenticado de dados/etapas, por exemplo `governance.quality.run`, com resposta JSON e `layout.json` no manifest. Revalidar compatibilidade com Zabbix 6.0 na implementação.
- Buscar regras no servidor. Não confiar em resultados, contagens ou regras enviados pelo navegador.
- Capturar página, grupos e revisão da configuração no início. Identificar cada atualização para impedir mistura de respostas.
- Atualizar o conteúdo no próprio painel, sem recarregar toda a página ao finalizar.

### Leitura compartilhada e lotes corretos

- Normalizar nomes/valores de tags e grupos uma vez por regra, fora do laço de hosts; normalizar dados do host uma vez por lote quando útil.
- Consultar os campos necessários uma vez por lote e atualizar todos os cards da página com esses dados. Persistir principalmente contadores, cursor e amostras limitadas, não a coleção integral de hosts com todos os relacionamentos.
- Definir e testar descoberta de IDs e paginação completas. A API `host.get` documenta `limit` e ordenação, mas não um `offset` genérico; não inventar paginação incompatível. Uma consulta inicial somente de IDs pode reduzir volume, mas continua sem limite se não for dimensionada. [Zabbix 6.0 — host.get](https://www.zabbix.com/documentation/6.0/en/manual/api/reference/host/get)
- Nunca truncar o escopo silenciosamente para parecer rápido. Se não for possível concluí-lo, apresentar limite/falha e orientar filtro de grupos.
- Verificar uso de contagem de templates quando somente sua existência interessa. Não aplicar indiscriminadamente `limitSelects=1`: outras seleções, como interfaces, são necessárias para avaliar corretamente o host.
- Processar contadores operacionais em fases próprias. Começar com concorrência limitada, preferencialmente uma requisição por vez; não disparar dezenas de consultas simultâneas.
- Não somar cegamente contagens de problemas por lotes de hosts. Uma trigger envolvendo vários hosts pode fazer o mesmo evento aparecer em mais de um lote. Manter uma consulta global exata do escopo ou deduplicar por `eventid` com paginação adequada. A API oferece filtros por hosts/eventos e limites por ID de evento. [Zabbix 6.0 — problem.get](https://www.zabbix.com/documentation/6.0/en/manual/api/reference/problem/get)
- Uma etapa curta no PHP não interrompe uma única API lenta. Medir também cada chamada e verificar limites do frontend/proxy e eventual serialização da sessão. Liberar a sessão, se necessário, exige revisão específica do ciclo do Zabbix; não fazer isso indiscriminadamente.

### Consistência dos resultados

- Preservar a semântica atual: cards sobre hosts monitorados, desabilitados apenas no resumo, nomes/valores de tags com as normalizações existentes, grupos por ID/nome e descendentes conforme a regra atual.
- Não confundir o filtro externo `groupids` com o critério de descendência de um card; o controlador atual encaminha o filtro diretamente à API.
- Preservar arredondamento atual: uma casa por card e média dos cards participantes. Eventual mudança matemática deve ser tratada separadamente.
- Escopo vazio, página sem cards, nenhum card participante e falha de API são estados diferentes.
- Qualidade é uma leitura atual distribuída no tempo, não um snapshot transacional do Zabbix. Registrar início/fim. Se host desaparecer ou mudar de estado durante os lotes, detectar e aplicar política explícita (reiniciar ou sinalizar resultado inconsistente), sem alterar silenciosamente o denominador.
- Se regras mudarem durante a execução, não misturar revisões: finalizar como retrato da revisão iniciada com aviso ou exigir nova atualização.

### Reutilização da Disponibilidade e segurança

Reutilizar padrões de POST com SID, usuário proprietário, sequência/idempotência, proteção contra concorrência, limites de armazenamento, erros sanitizados e expiração. Não reutilizar diretamente `AvailabilityCalculation`: ele trata meses e histórico.

`AvailabilityJobStore` também não é genérico: diretório, projeção, URL de resultado e redução do estado em falhas estão ligados à Disponibilidade. Caso se aproveite sua infraestrutura, separar tipos/namespaces e cotas, com regressão completa da funcionalidade existente. Atualizações automáticas da Qualidade não devem consumir ou expulsar trabalhos de Disponibilidade.

Revalidar permissões a cada requisição. Não expor tokens, macros ou configurações sensíveis no progresso/JSON/logs. Renderizar títulos, descrições e nomes como texto seguro. Manter estado temporário fora da pasta pública, privado por usuário/instalação. Frontends múltiplos exigem armazenamento adequado ou afinidade de sessão, caso usem checkpoints locais.

### Cache opcional, após medição

Um cache curto pode evitar repetir o resumo operacional ao trocar de aba. Não é necessário para a primeira entrega. Se adotado, incluir instalação, usuário/permissões, filtros canônicos e revisão das regras nas chaves pertinentes, com validade explícita e horário visível. Não compartilhar resultados entre escopos ou apresentar cache antigo como recém-calculado. “Atualizar” deve ter comportamento definido, sem iniciar trabalhos duplicados.

### ECharts

Separar carregamento de dados da biblioteca de gráficos. Um erro ao carregar ECharts não deve impedir a consulta nem esconder os valores textuais. Criar gráficos somente para resultados válidos; nunca inicializar com zero como substituto de “carregando”. Usar instâncias e listeners gerenciados, descartando-os ao substituir cards; preservar fundo transparente e tema. Considerar renderização sob demanda apenas se medições apontarem custo relevante.

## Arquivos envolvidos futuramente

- `actions/QualityView.php`: separar trabalho pesado da resposta inicial.
- Novos controlador e serviço de cálculo da Qualidade; eventual armazenamento temporário próprio.
- `views/governance.quality.view.php`: estrutura inicial, estados e resultado incremental por bloco.
- `assets/js/quality.js` e `views/js/governance.quality.view.js.php`: transporte, ciclo de atualização e gráficos desacoplados.
- `assets/css/quality-pages.css` e, se necessário, `assets/css/governance.css`: espaços reservados e mensagens temáticas.
- `manifest.json`: registrar endpoint e atualizar versão somente na implementação.
- `tests/quality-pages.php`: preservar critérios atuais; adicionar testes de API/etapas e testes JavaScript.

## Validação e critérios de aceite futuros

1. GET inicial não consulta hosts/problemas/itens e retorna menus/abas antes da análise. Medir resposta inicial separadamente do tempo total; definir meta após conhecer o ambiente.
2. Resultado final equivalente ao controlador atual para os mesmos dados: cinco tipos de card, grupos/subgrupos, filtros, manutenção, interfaces, contagens e índice.
3. Testar zero hosts, zero cards, todos os cards fora do índice, primeira página removida e nomes em português/inglês.
4. Escopo grande e 30 cards: consumo limitado por lote, contadores completos e amostra determinística de até dez não conformes, sem duplicação de hosts.
5. Problema associado a múltiplos hosts deve contar uma única vez. Escopo vazio jamais dispara consulta global por acidente.
6. Falhas, sessão expirada, JSON inválido, HTTP 500, timeout, duplo clique, duas abas e respostas fora de ordem não publicam índices falsos nem criam repetição infinita.
7. Troca de página durante consulta mantém filtros e não mostra resultado antigo na nova página. Nenhuma navegação deve esperar artificialmente por todas as métricas.
8. Falha de um contador operacional não invalida cards independentes já concluídos; falha em card participante impede publicar o índice geral como completo.
9. Tema escuro/claro, ausência de ECharts, teclado e leitores de tela; sem fundo branco ou gráficos de 0% durante espera.
10. Testar mudanças de regras/hosts durante etapas, expiração de estado, cotas e isolamento entre usuários e entre Qualidade/Disponibilidade.
11. Medir duração por fase/API, hosts processados, tamanho de respostas, memória de pico e tempo de renderização. Logs sem conteúdo sensível.

Não há alteração de regras de qualidade nem implementação autorizada neste momento. Este documento serve como base para a próxima solicitação.
