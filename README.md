# Governance & Quality Audit — Zabbix 6.0 LTS

Módulo de governança e auditoria de qualidade de dados para o frontend do
Zabbix 6.0 LTS.

## Funcionalidades

- Menu visível exclusivamente para usuários Super Admin.
- Interface automática em português ou inglês.
- Página **Regras e cards / Rules and cards** totalmente traduzida conforme o
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
  valores aceitos e participação no score geral.
- Inclusão e remoção dinâmica de cards sem editar arquivos PHP.
- Resumo operacional compacto com hosts monitorados/desabilitados, falhas de
  interface, problemas altos ou críticos e itens não suportados.
- Cards compactos e responsivos, com não conformidades recolhidas por padrão.
- Painel mensal de disponibilidade por departamento, com tecnologias ponderadas,
  metas, histórico diário em ECharts e memória de cálculo exportável.

## Disponibilidade mensal (v1.5.0)

Abra **Governança → Disponibilidade → Configurar indicadores**. Cadastre um
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
- Validade da última amostra em segundos: use uma margem acima do intervalo
  normal de coleta. Depois desse prazo, o estado passa a desconhecido.

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
  e 200 hosts por tecnologia. Consultas usam blocos de até 7 dias por item,
  com limite de 60 mil amostras por bloco e 3 milhões por relatório.
  Há uma guarda de tempo de 18 segundos entre etapas; uma chamada de API em
  andamento continua sujeita ao timeout do PHP/servidor.
- Limites ou erros de consulta tornam a tecnologia incompleta e geram aviso;
  não se apresenta um resultado truncado como completo. Reduza o escopo pelo
  filtro de departamento quando necessário.
- Acesso restrito a Super Admin. Regras persistem no registro do módulo,
  preservando a configuração dos cards de qualidade.

### Testes locais

Execute `php tests/availability.php` e `php tests/actions.php` com a extensão
`mbstring` habilitada.
Os testes cobrem intervalos, sobreposições, lacunas, limiares, pesos e consultas
simuladas à API. Não substituem a validação no frontend Zabbix instalado.

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

## Configuração dos cards

Abra **Governança → Regras e cards**. Cada card permite definir:

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
- Se a métrica participa ou não do índice geral de governança.

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

O controller utiliza `disableSIDvalidation()`, que é o método existente no
Zabbix 6.0. O método `disableCsrfValidation()` pertence a versões posteriores.

O Apache ECharts é distribuído junto com o módulo sob a licença Apache 2.0. A
cópia da licença está em `assets/js/ECHARTS-LICENSE.txt`.
