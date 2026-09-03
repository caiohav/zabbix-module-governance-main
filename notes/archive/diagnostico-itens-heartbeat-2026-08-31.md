# Diagnóstico para correção futura — disponibilidade por item e heartbeat

> Registro histórico da investigação. Não é uma lista de alterações a executar.
> O estado posterior está no [roadmap](../roadmap-regras-interface-2026-09-03.md)
> e na [validação de julho](../validacao-julho-1.18.0.md).

Data da análise: 31/08/2026 (`America/Cuiaba`).

Escopo desta etapa: análise e documentação. Nenhum código, configuração, item,
retenção ou cálculo de produção foi alterado ou iniciado.

## Conclusão executiva

Os valores mostrados nos dois históricos são válidos. Em especial, o item
PostgreSQL armazena `1` aproximadamente a cada hora porque usa **Discard
unchanged with heartbeat = 1h**. O cálculo deve manter cada valor real para a
frente até o próximo heartbeat esperado, com uma pequena tolerância de coleta.
Ele nunca deve preencher retroativamente o tempo anterior à primeira evidência
nem presumir disponibilidade depois que a evidência expirar.

O motor local já pretende aplicar exatamente essa regra: para o PostgreSQL
observado, intervalo efetivo de coleta de 60 s + heartbeat de 3600 s produz
validade automática de 3720 s. Assim, uma sequência horária de `1` não deveria
gerar lacunas. Antes de mudar a matemática, a futura correção deve descobrir por
que os metadados/amostras que chegam ao trabalho real não estão produzindo essa
política e separar isso da expiração do histórico solicitado.

Os links `history.php?action=showlatest` exibem somente as **500 entradas mais
recentes**. Eles comprovam que o item coleta atualmente, mas não comprovam que
todo o mês de julho ainda esteja consultável pela API:

| Item | Evidência observada em 31/08/2026 | O que ela comprova |
| --- | --- | --- |
| `104274` — `icmpping` | 500 pontos de `1`, aproximadamente a cada 30 s; janela visível de cerca de 4h10 | coleta atual e valores numéricos válidos |
| `104312` — `pgsql.ping["{$PG.URI}","{$PG.USER}","{$PG.PASSWORD}"]` | 500 pontos de `1`, aproximadamente a cada 1h; janela visível de 10/08 a 31/08 | heartbeat atual e valores numéricos válidos, mas nenhuma evidência de julho |

A reprodução anterior de julho feita pelo módulo em 31/08 retornou zero linhas
de `history.get` nas 50 verificações. Isso é compatível com histórico bruto de
julho já fora da retenção consultável; os links acima não contradizem esse
resultado. Se o relatório atual que aparece inválido for agosto, já deve existir
alguma cobertura recente e o diagnóstico por fonte abaixo permitirá identificar
uma falha real de resolução/consulta.

## Configuração confirmada do item 104312

- Host: `dbd-pje1-int01` (`hostid 10837`).
- Nome: `PostgreSQL: Ping`.
- Chave: `pgsql.ping["{$PG.URI}","{$PG.USER}","{$PG.PASSWORD}"]`.
- Tipo da informação: numérico sem sinal.
- Intervalo base: `1m`.
- Intervalo flexível: `50s`, período `1-7,00:00-24:00`.
- Histórico configurado no item: `7d`; estatísticas: `365d` (a retenção global
  pode sobrescrever esses campos e precisa ser registrada no diagnóstico).
- Pré-processamento: `Discard unchanged with heartbeat`, parâmetro `1h`.

O resolvedor atual toma o maior intervalo positivo entre base e flexíveis para
obter uma margem conservadora: `max(60, 50) = 60 s`. A validade resultante é:

```text
max(3 × intervalo, heartbeat + 2 × intervalo)
= max(180, 3600 + 120)
= 3720 segundos
```

A documentação oficial do Zabbix 6.0 confirma os códigos da API:

- `19` = Discard unchanged (sem heartbeat);
- `20` = Discard unchanged with heartbeat.

Portanto, os números usados atualmente em `AvailabilityFreshness.php` estão
corretos. Referências:

- https://www.zabbix.com/documentation/6.0/en/manual/config/items/preprocessing
- https://www.zabbix.com/documentation/6.0/en/manual/api/reference/item/object

## Semântica temporal que deve ser preservada

Para amostras ordenadas `S[i] = (clock, valor)`, a classificação deve ser
forward-fill, em intervalos semiabertos:

```text
estado de S[i] vale em
[S[i].clock, min(S[i+1].clock, S[i].clock + validade))
```

Regras obrigatórias:

1. `1` classificado como disponível continua disponível até a próxima amostra
   ou até expirar a validade. Com heartbeat de 1h e validade de 3720 s, pontos
   horários normais formam uma linha contínua.
2. `0` deve receber exatamente o mesmo forward-fill e representar
   indisponibilidade. Nunca aplicar a extensão somente a valores `1`.
3. Um valor não numérico, ou que não satisfaça unicamente a regra de disponível
   ou de indisponível, permanece desconhecido.
4. Se o próximo ponto chegar após 3720 s, o trecho entre a expiração e o novo
   ponto é desconhecido. Não estender o último `1` indefinidamente.
5. Uma amostra anterior ao início do mês pode semear o começo do relatório
   somente até `clock + validade`. Se ela já expirou, o início permanece
   desconhecido.
6. Não preencher para trás desde a primeira amostra encontrada. Um `1` às 10:00
   não prova o estado das 00:00 às 10:00.
7. `Discard unchanged` do tipo 19, sem heartbeat, não fornece limite seguro para
   inferência automática; deve exigir validade manual explícita.
8. A política “calcular sobre dados disponíveis” remove períodos desconhecidos
   do denominador, mas não transforma desconhecido em disponível. Se não houver
   nenhum segundo conhecido, o resultado deve continuar sem indicador.

## Diagnóstico que a implementação deve capturar antes de corrigir

Em uma execução futura autorizada, registrar no próprio relatório, para cada
item (começando por `104274` e `104312`), os dados brutos da descoberta e da
consulta. Não depender somente de mensagens agregadas da tecnologia.

### Resposta de `item.get`

Consultar e preservar, sem expor valores de macros/segredos:

```text
itemid, hostid, key_, value_type, status, state, error,
type, delay, history, trends,
preprocessing[].type, preprocessing[].params
```

Resultado esperado para `104312`:

```text
preprocessing type = 20
heartbeat_seconds = 3600
interval_seconds = 60
max_age = 3720
freshness_source = heartbeat_flexible_interval
```

Se isso não ocorrer, mostrar a etapa exata que falhou: item não encontrado,
tipo não numérico, metadado ausente, heartbeat com macro não resolvida, delay
não interpretável ou item sem periodicidade própria. Não usar apenas “inválido”.

### Resposta de `history.get`

Registrar por fonte e página:

- `history` enviado (deve coincidir com `value_type`: `3` para numérico sem
  sinal e `0` para float);
- `itemids`, `time_from`, `time_till`, quantidade retornada e limites real do
  primeiro/último `clock`;
- quantidade de páginas, linhas totais, amostra-semente anterior ao período,
  maior intervalo entre pontos e motivo de cobertura desconhecida;
- erro/permissão da API separado de resposta válida vazia;
- fuso e timestamps absolutos do mês usado no cálculo.

Executar uma consulta de diagnóstico pequena ao redor de um limite conhecido
de sete dias e comparar os clocks com `history.php`, porque o processamento é
paginado em janelas de sete dias. Uma resposta vazia deve informar explicitamente
“nenhuma linha retornada nesse intervalo”, não “valor inválido”.

### Retenção efetiva

Exibir a retenção efetiva aplicada pelo Zabbix, distinguindo:

- campo `history` do item;
- override global de housekeeping;
- período solicitado;
- instante mais antigo ainda consultável pela API.

O frontend `showlatest` limitado a 500 linhas não deve ser usado para concluir
que existe um mês inteiro. Estatísticas de 365 dias também não reconstroem com
fidelidade as transições: min/max/avg/count por hora não preservam a ordem das
quedas nem a união temporal entre hosts. Não fazer fallback silencioso para
trends no indicador oficial.

## Alterações candidatas — implementar somente após o diagnóstico

1. Substituir números mágicos de pré-processamento por constantes do Zabbix
   (`ZBX_PREPROC_DISCARD_UNCHANGED` e
   `ZBX_PREPROC_DISCARD_UNCHANGED_HEARTBEAT`) quando disponíveis, mantendo
   fallback documentado para 19/20 no Zabbix 6.0. Isso não muda o resultado
   esperado, mas reduz risco de incompatibilidade futura.
2. Tornar os diagnósticos acima parte persistida e visível do relatório por
   fonte. Mostrar “valor sem classificação”, “validade não resolvida”, “histórico
   vazio” e “histórico expirado/insuficiente” como causas diferentes.
3. Confirmar que `selectPreprocessing` realmente chega em cada resposta de
   produção antes de chamar o resolvedor; testar o formato numérico e string
   retornado pela versão instalada.
4. Preservar a busca de uma amostra-semente em `from - max_age` e o carry entre
   páginas. Auditar as fronteiras inclusivas de `time_from/time_till` para não
   perder nem duplicar a amostra exatamente no limite da página.
5. Não alterar a fórmula de 3720 s para “uma hora civil”. A coleta pode atrasar;
   heartbeat + duas coletas fornece a tolerância já planejada. Exibir essa
   tolerância ao usuário. Caso se deseje outra margem, torná-la política
   explícita e auditável.
6. Para meses cujo histórico bruto expirou, manter resultado inconclusivo ou
   usar uma fonte explicitamente diferente (SLA nativo/arquivo mensal). Nunca
   fabricar 100% a partir de uma amostra atual.
7. Para relatórios por item confiáveis no futuro, dimensionar/preservar histórico
   ou transições até o fechamento mensal. Aumentar retenção agora não recupera
   julho já removido.

## Testes mínimos de aceitação

1. Pontos `1` exatamente a cada hora durante todo o período, heartbeat 3600 s e
   coleta 60 s: disponibilidade 100%, cobertura 100%, zero lacunas.
2. Pontos `1` com atrasos menores ou iguais a 120 s: continuidade sem lacunas.
3. Atraso maior que 3720 s: trecho após a expiração é desconhecido.
4. `1` seguido de `0`: indisponibilidade começa no clock real do `0` e permanece
   até o próximo valor/expiração.
5. Amostra-semente válida antes do início: cobre apenas o início ainda válido.
6. Nenhuma semente e primeiro ponto posterior ao início: trecho anterior
   desconhecido; nenhuma extensão retroativa.
7. `type 20`, parâmetro `1h`, delay `1m;50s/1-7,00:00-24:00`: resolve 3600/60/3720.
8. `type 19`: automático não resolvido; manual funciona sem inferir heartbeat.
9. `value_type` 3 e 0 consultam a tabela correta de histórico.
10. Paginação exatamente no segundo de fronteira, inclusive vários valores com
    o mesmo `clock`, não perde nem duplica estado.
11. ICMP frequente + PostgreSQL horário, ambos obrigatórios: cada fonte usa sua
    própria validade; uma queda confirmada prevalece e lacunas não viram uptime.
12. Política observada com dados apenas em parte do mês: percentual calculado
    sobre tempo conhecido e cobertura parcial visível. Zero tempo conhecido:
    indicador ausente, não 0% nem 100%.
13. Julho fora da retenção: diagnóstico identifica histórico vazio/expirado.
    Agosto com pontos presentes: pelo menos a janela efetivamente preservada é
    classificada, permitindo separar retenção de erro do resolvedor.
14. Fuso `America/Cuiaba`, mudança de página a cada sete dias e mês atual parcial
    mantêm limites exatos sem inventar segundos.

## Critério para decidir a correção

- Se `item.get` produzir 20/`1h` e 60/3720, mas `history.get` devolver pontos que
  continuam classificados como inválidos, há defeito no caminho de transporte,
  paginação ou classificação e ele deve ser corrigido com uma fixture baseada
  nos clocks reais dos dois itens.
- Se `item.get` não entregar o pré-processamento, corrigir a consulta/permissão
  ou exigir validade manual com mensagem específica.
- Se `history.get` devolver vazio no mês, não há erro nos valores e nenhuma
  fórmula resolve a ausência. O painel deve explicar retenção e solicitar fonte
  preservada.
- Se houver pontos somente em parte do período, calcular apenas o trecho
  realmente sustentado por amostras, respeitando forward-fill e mostrando a
  cobertura. Isso atende à regra de ignorar ausência sem afirmar disponibilidade
  onde não há evidência.
