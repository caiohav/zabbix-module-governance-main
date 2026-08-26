# Governance & Quality Audit — Zabbix 6.0 LTS

Módulo de governança e auditoria de qualidade de dados para o frontend do
Zabbix 6.0 LTS.

## Funcionalidades

- Menu visível exclusivamente para usuários Super Admin.
- Interface automática em português ou inglês.
- Cobertura de tag de departamento (`departamento`, `department` ou `dept`).
- Cobertura de inventário (sistema operacional ou número de série).
- Cobertura de vínculo com templates.
- Lista de até dez hosts não conformes por indicador.
- Indicadores circulares sem bibliotecas externas.

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
