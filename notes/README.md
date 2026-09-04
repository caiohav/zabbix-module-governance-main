# Notas do projeto

## Estado atual

O roadmap funcional da versão 1.18.0 está concluído. O usuário vai revisar as
regras, tags e grupos antes de decidir o escopo de homologação; não alterar
essa configuração nem excluir hosts automaticamente.

- [Roadmap e histórico de execução](roadmap-regras-interface-2026-09-03.md)
- [Novo roadmap de padronização visual](roadmap-padronizacao-visual-zabbix-2026-09-03.md):
  lotes A/B/C/D implementados até a 1.22.0 e testados localmente. Próximo passo:
  publicar o pacote cumulativo e validar na instalação. Alto contraste pendente.
  Retomar por esse documento.
- [Validação real de julho/2026](validacao-julho-1.18.0.md)
- [Diagnósticos e propostas históricas](archive/): preservados para consulta.
  Os textos descrevem etapas antigas, não uma fila de tarefas pendentes.

## Limpeza da pasta de trabalho

- Onze pacotes antigos (1.11.0 até 1.17.0) foram movidos, sem apagar seu conteúdo,
  para `C:/Users/46027/Downloads/zabbix-governance-versoes-antigas-2026-09-03/`.
  Total retirado do projeto: 5.465.494 bytes (aproximadamente 5,2 MiB).
- O pacote da limpeza, `../dist/zabbix-module-governance-1.18.0.zip`, foi movido,
  não reconstruído, e permanece preservado. Após o novo pedido de implementação,
  o runtime passou à 1.22.0; seu pacote é `../dist/zabbix-module-governance-1.22.0.zip`.
  As versões 1.19.0, 1.20.0 e 1.21.0 também foram preservadas. Os pacotes novos são cumulativos.
- Os 12 ZIPs foram conferidos por SHA-256 antes e depois da movimentação.
- Para recuperar uma versão antiga, basta copiá-la da pasta de arquivo acima.
  Os ZIPs anteriormente versionados também permanecem no histórico do Git;
  nenhuma reescrita de histórico ou commit foi realizada pela limpeza.
- `dist/` e novos ZIPs na raiz são ignorados pelo Git. As remoções dos antigos
  caminhos rastreados aparecem no diff, aguardando revisão/commit do usuário.
- Quatro notas antigas foram movidas para `archive/` e receberam avisos de
  contexto histórico. Conteúdo anterior preservado.
- Código, manifesto, testes, fixtures, assets e licença do ECharts mantidos.
  Não havia arquivos idênticos duplicados nem caches/logs descartáveis fora de
  `.git`. A pasta `.git` não foi modificada pela limpeza.

Links antigos da conversa para ZIPs na raiz agora devem usar `dist/` para a
versão 1.18.0 ou a pasta de arquivo externa para as versões anteriores.

Verificação final: suítes PHP e JavaScript passaram após a organização;
`git diff --check` sem erros. Os 40 arquivos de runtime/manifesto/licença do
ZIP atual coincidem byte a byte com o projeto (README excluído da comparação,
pois recebeu a orientação sobre a nova organização). Nenhum runtime alterado.
