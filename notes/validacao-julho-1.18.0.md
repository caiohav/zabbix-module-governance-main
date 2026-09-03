# Validação de julho/2026 — versão 1.18.0

## Execução real

Calculado pelo botão Calculate month na instância zabbix.tjmt.jus.br, sem editar
ou salvar regras. Período: 01/07/2026 00:00 até 01/08/2026 00:00 (fim exclusivo),
America/Cuiaba. Fonte por itens, modo any_down, política observed; grupo
DABD/PostgreSQL, 25 hosts e 50 verificações. Corte informado na tela:
03/09/2026 17:13:10. O processamento concluiu e mostrou relatório, não UNKNOWN.

## Resultado apresentado

- DABD/PostgreSQL: 99,462366%, abaixo da meta de 99,9%.
- Indisponibilidade conservadora: 4 horas; desconhecido agregado: zero.
- Cobertura ponderada dos hosts: 75,758065%; 19 de 25 com estado conhecido.
- A política observed ignora hosts desconhecidos em cada instante; por isso
  cobertura abaixo de 100% pode coexistir com zero desconhecido agregado.
  Não significa que todos os hosts têm dados nem que ausência virou UP.
- Conferência aritmética: (744 - 4) / 744 * 100 = 99,46236559%.

## Origem das horas segundo os detalhes do relatório

| Host | Tempo DOWN contabilizado | Fontes |
| --- | --- | --- |
| dbd-pgsql-p-02 | 1 hora | ICMP 88643: 744 horas de trends, 743 UP e 1 mista; PostgreSQL 88681: 744 UP. |
| precatoriohomologacao-rhel.pjmt.local | 3 horas | ICMP 75586: 743 UP e 1 mista; PostgreSQL 187929: 742 horas, 739 UP, 2 DOWN e 1 mista. Sobreposições entre verificações não são somadas. |

Intervalos DOWN exibidos na tabela agregada:

- 10/07, 05:00–06:00;
- 10/07, 07:00–08:00;
- 10/07, 10:00–11:00;
- 20/07, 10:00–11:00.

O gráfico individual de dbd-pgsql-p-02 abriu corretamente, com barras, escala
0–100%, cobertura separada e redução em 20/07. Inspeção visual no tema escuro.

## Itens anteriormente questionados

dbd-pje1-int01 aparece com 100% de disponibilidade observada e 99,865591% de
cobertura. ICMP 104274 tem 744 horas UP; PostgreSQL 104312 tem 743 horas UP e
uma hora ausente. Não há DOWN nesses dois itens segundo este relatório.
Eles não são a origem das quatro horas agregadas.

## Ausência de dados

Seis hosts aparecem sem estado conhecido e atualmente desabilitados:
dbd-pje1-backup, dbd-pje1-master-p, dbd-pje2-master-p, pje2-logs, pje2-slave4 e
pje2-slave5. Nos dois master-p, a verificação PostgreSQL também aparece como
ausente/não numérica, sem consulta de histórico. Isso está explicitamente
sinalizado no relatório. Não remover esses hosts automaticamente: sua inclusão
histórica depende do escopo desejado pelo usuário.

## Interpretação e limites

AvailabilityCalculation::trendFallback consultado no código local: trends
substituem o histórico quando aumentam sua cobertura; não há mistura de fontes
na mesma verificação. O modo conservador classifica integralmente como DOWN uma
hora mista com extremidade DOWN. Logo, quatro horas contabilizadas não provam
quatro horas contínuas/reais de queda; sem histórico detalhado, a duração exata
e a simultaneidade dentro da hora não são recuperáveis por esse cálculo.

As evidências de origem acima são as apresentadas pelo módulo; não foi feita
uma segunda consulta independente às linhas brutas de trends. Não se detectou
inconsistência entre o detalhamento e o resultado agregado desta execução.

O grupo inclui um host cujo nome indica homologação. Confirmar com o usuário
se ele deve participar do indicador antes de propor mudar escopo/regras.
Não alterar o modo conservador apenas para aproximar o resultado de 100%.

## Estado ao encerrar

Nenhum código de runtime, regra salva ou dado de monitoramento foi alterado.
Apenas esta nota e o roadmap foram atualizados. Agosto não foi recalculado
nesta rodada; a validação focou a falha antiga de julho.
