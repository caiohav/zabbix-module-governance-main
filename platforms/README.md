# Variantes do frontend

Cada subpasta contém somente os arquivos que mudam entre as gerações do
frontend. O restante do módulo é compartilhado na raiz do projeto.

- `zabbix-6.0`: manifesto 1.0, `Core\CModule`, `CWidget` e SID.
- `zabbix-7.0`: manifesto 2.0, `Zabbix\Core\CModule`, `CHtmlPage` e CSRF por ação.

Não copie apenas esta pasta para o servidor. Gere as distribuições completas
com `scripts/build-packages.ps1` ou use os ZIPs correspondentes em `dist/`.
