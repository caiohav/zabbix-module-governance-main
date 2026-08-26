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
- Tipo de métrica: tag personalizada, inventário, template ou interface.
- Um ou mais nomes/aliases de tag separados por vírgula.
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
