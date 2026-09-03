# Especificação futura — estado do host por ICMP e PostgreSQL em janela de uma hora

> Especificação histórica. Não reaplicar automaticamente as instruções desta
> etapa; consulte o [roadmap atual](../roadmap-regras-interface-2026-09-03.md)
> e a [validação no servidor](../validacao-julho-1.18.0.md).

Data: 01/09/2026.

Esta é apenas uma especificação para alteração futura. Nenhum código ou
configuração do Zabbix foi modificado nesta etapa.

## Regra desejada

Cada host possui duas verificações obrigatórias:

1. `icmpping`;
2. `pgsql.ping["{$PG.URI}","{$PG.USER}","{$PG.PASSWORD}"]`.

Para cada verificação:

- valor numérico `1` = `UP`;
- valor numérico `0` = `DOWN`;
- ausência de uma amostra ainda válida = `UNKNOWN`;
- valor inválido/não numérico = `UNKNOWN`.

O estado consolidado do host deve seguir esta tabela:

| ICMP | PostgreSQL | Host |
| --- | --- | --- |
| UP | UP | UP |
| DOWN | UP | DOWN |
| UP | DOWN | DOWN |
| DOWN | DOWN | DOWN |
| DOWN | UNKNOWN | DOWN |
| UNKNOWN | DOWN | DOWN |
| UP | UNKNOWN | UNKNOWN |
| UNKNOWN | UP | UNKNOWN |
| UNKNOWN | UNKNOWN | UNKNOWN |

Consequentemente:

- o host só é `UP` quando **as duas verificações estão UP**;
- qualquer `DOWN` confirmado torna o host `DOWN`, mesmo que a outra verificação
  esteja sem dados;
- `UP + UNKNOWN` não prova disponibilidade e permanece `UNKNOWN`;
- nenhuma ausência de dados pode ser convertida em `UP` ou `DOWN`.

## O que o código já faz

O motor atual já classifica `1` como disponível e `0` como indisponível quando
as regras configuradas são `UP = 1` e “qualquer outro valor válido = DOWN”.

Também já combina as verificações obrigatórias com a tabela acima. A consolidação
interna do host usa `any_down`: queda confirmada prevalece; sem queda, todas as
verificações precisam estar disponíveis para o host ser `UP`.

Essas partes não devem ser alteradas.

## Divergência encontrada

As duas verificações não usam atualmente a mesma janela de validade:

- PostgreSQL com heartbeat de `1h` e coleta de `1m`: validade automática de
  `3720 s` (`3600 + 2 × 60`). Assim, cada valor armazenado continua representando
  seu estado até o próximo heartbeat esperado, com 120 s de tolerância.
- ICMP sem descarte/heartbeat: validade automática de três coletas. Se a coleta
  ocorre a cada 30 s, são `90 s`; se ocorre a cada 60 s, são `180 s`.

Portanto, uma lacuna de poucos minutos no ICMP torna essa fonte `UNKNOWN`, mesmo
que exista um `1` dentro da última hora. Isso não atende ao pedido de analisar
também o ICMP com horizonte de uma hora.

## Alteração necessária

Usar para o ICMP uma validade explícita de uma hora, sem criar agregação por
média e sem arredondar o relatório em blocos de relógio.

Semântica recomendada:

```text
amostra ICMP em T vale em [T, T + 3600 s)
ou até a próxima amostra, que substitui imediatamente o estado anterior
```

Exemplos:

- `1` às 10:00 e nenhuma outra amostra: `UP` até 10:59:59; `UNKNOWN` a partir
  das 11:00;
- `1` às 10:00 e `0` às 10:12: `UP` até 10:11:59 e `DOWN` desde 10:12;
- `0` às 10:12 e `1` às 10:13: somente o minuto confirmado entre esses pontos
  fica `DOWN`; o valor `1` posterior substitui o anterior;
- primeira amostra do relatório às 10:00, sem semente anterior válida: o trecho
  antes das 10:00 permanece `UNKNOWN`.

Isso interpreta “há dado dentro da última hora” como evidência ainda válida,
mas não ignora mudanças intermediárias. Não se deve calcular média dos valores
da hora: qualquer transição `0` precisa preservar exatamente o período de queda.

## Forma mais simples de aplicar futuramente

O formato de configuração já possui validade manual por verificação (`max_age`).
A mudança mínima é configurar a verificação `icmpping` com:

```text
max_age = 3600
```

O PostgreSQL pode continuar automático, resultando em `3720 s`, pois a margem
de 120 s protege atrasos normais do heartbeat. Se for requisito que ambas as
fontes expirem exatamente após uma hora, definir manualmente `3600 s` também no
PostgreSQL, sabendo que pequenos atrasos do heartbeat poderão criar lacunas.

A opção recomendada é:

- ICMP: manual `3600 s`;
- PostgreSQL: automático `3720 s`;
- interface: exibir claramente “janela de evidência” para cada item e o valor
  efetivamente resolvido.

Não codificar uma exceção baseada no texto da chave `icmpping`. Como o painel
aceita itens customizados, a janela deve continuar configurável por verificação.

## Melhorias de interface para evitar interpretação incorreta

1. Renomear ou complementar “validade” com a explicação “por quanto tempo a
   última amostra representa o estado”.
2. Mostrar no relatório, por fonte:
   - regra de UP/DOWN;
   - janela efetiva em segundos e formato humano;
   - origem automática, heartbeat ou manual;
   - primeiro e último ponto;
   - tempo UP, DOWN e UNKNOWN.
3. Exibir a tabela de consolidação de forma resumida na configuração:
   “todas UP = host UP; qualquer DOWN = host DOWN; caso contrário = UNKNOWN”.
4. Não chamar uma fonte de “inválida” quando o problema for apenas ausência ou
   expiração da janela. Mostrar a causa específica.

## Testes de aceitação necessários

1. ICMP e PostgreSQL sempre `1`: host 100% `UP` no trecho coberto.
2. ICMP `0`, PostgreSQL `1`: host `DOWN` durante o estado zero.
3. ICMP `1`, PostgreSQL `0`: host `DOWN` durante o estado zero.
4. ICMP `1`, PostgreSQL sem dado válido: host `UNKNOWN`.
5. ICMP sem dado válido, PostgreSQL `1`: host `UNKNOWN`.
6. Uma fonte `DOWN` e outra `UNKNOWN`: host `DOWN`.
7. ICMP `1` seguido de 59 minutos sem amostra: continua `UP`.
8. ICMP `1` sem nova amostra por mais de 3600 s: passa a `UNKNOWN` exatamente
   após a validade.
9. Um `0` dentro da hora substitui imediatamente o `1`; nunca usar “maioria dos
   valores” nem média horária.
10. PostgreSQL com amostras `1` a cada hora e validade automática de 3720 s:
    nenhuma lacuna artificial.
11. Ausência no começo do mês sem amostra-semente: início `UNKNOWN`, sem
    preenchimento retroativo.
12. Gráficos diário, mensal e por host usam a mesma série consolidada e exibem
    os mesmos tempos UP/DOWN/UNKNOWN do contador.

## Arquivos envolvidos quando a alteração for autorizada

- `AvailabilityConfig.php`: manter/validar a validade manual por verificação.
- `assets/js/availability-config.js` e
  `views/governance.availability.config.php`: explicar e exibir a janela efetiva.
- `AvailabilityFreshness.php`: não precisa mudar a fórmula do PostgreSQL; apenas
  garantir que o override manual do ICMP seja preservado.
- `AvailabilityEngine.php`: a tabela de estados já está correta; não alterar sua
  precedência.
- `AvailabilityCalculation.php`: garantir que gráfico, resumo e diagnóstico
  consumam a mesma série por host.
- `tests/availability.php`, `tests/availability-calculation.php` e
  `tests/availability-flexible.php`: adicionar os cenários acima.
