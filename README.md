# ticketlinksync — Sincronismo de Chamados Pai/Filho (GLPI)

Plugin para GLPI 10.x/11.x. Sempre que um chamado **filho** (vinculado via
"Filho de" / "Pai de", recurso nativo do GLPI) recebe uma alteração, uma
nota automática é criada no chamado **pai** correspondente.

**Status:** testado com sucesso em ambiente de homologação (GLPI 11) em
06/08/2026 — cenário validado: alteração de status no chamado filho gerou a
nota automática esperada no chamado pai. Ainda pendente de avaliação para
uso em produção (ver riscos abaixo).

## O que é replicado do filho para o pai

- Novo acompanhamento (followup).
- Nova tarefa.
- Nova solução proposta.
- Mudança de status, prioridade, urgência ou impacto.

A partir da v1.4.0, mudança de **categoria** e atribuição/remoção de
**técnico ou grupo** deixaram de gerar nota — mudavam com frequência alta
demais no dia a dia e o volume de notas geradas não tinha valor real de
acompanhamento (ver Versões).

Todas as notas geradas no pai são sempre marcadas como **privadas**,
independente da visibilidade do conteúdo original no chamado filho — o
solicitante do chamado pai não tem relação com o solicitante do filho, então
mesmo um acompanhamento público no filho é tratado como informação interna
ao ser replicado no pai. Todas as notas citam
`[Sincronizado do chamado filho #N]` para deixar clara a origem.

## Limitações e decisões de projeto

- Propagação de apenas **um nível** (filho → pai direto). Se o pai também
  for filho de outro chamado (avô), a nota sobe em cascata automaticamente
  porque a própria nota criada no pai também dispara o hook de followup —
  há uma proteção contra recursão infinita em caso de vínculo circular
  malformado, mas cascatas legítimas de vários níveis funcionam.
- Um chamado filho pode ter mais de um pai vinculado; a nota é replicada
  para todos eles.
- O plugin não verifica se o usuário que alterou o filho tem permissão
  individual de visualizar o chamado pai — isso é intencional, já que o
  objetivo é justamente dar visibilidade entre técnicos diferentes atuando
  em pai e filho. A partir da v1.1.0, porém, há uma checagem de **entidade**:
  se pai e filho pertencerem a entidades diferentes do GLPI, a propagação é
  bloqueada e registrada no log do plugin, para evitar vazamento de
  informação entre empresas/departamentos diferentes num GLPI multi-entidade.
- Ruído em chamados pai com muitos filhos ativos é esperado por design: cada
  nota carrega o prefixo `[Sincronizado do chamado filho #N]`, o que permite
  localizar/filtrar rapidamente as notas de um filho específico; mudanças de
  múltiplos campos num mesmo evento (status, prioridade, urgência, impacto)
  já são agrupadas em uma única nota, em vez de uma por campo. Categoria e
  atribuição de técnico/grupo foram removidas da propagação em v1.4.0 por
  gerarem ruído desproporcional ao valor da informação.
- Não propaga na direção pai → filho (evita loop e não foi solicitado).
- A partir da v1.3.0, assim que o chamado **pai** chega a **Solucionado**
  (o que também cobre **Fechado**, que vem depois no fluxo do GLPI), a
  propagação é bloqueada e registrada no log. A partir desse momento o
  chamado pai só volta a receber atualização se o usuário reabri-lo
  manualmente — evita que uma nota automática o reabra indevidamente e
  preserva as estatísticas/SLA a partir da solução. Não há checagem
  equivalente sobre o status do **filho**: qualquer evento nele, mesmo
  tardio, ainda dispara a tentativa de propagação.

## Instalação

1. Copie a pasta `ticketlinksync` inteira para dentro de `plugins/` na
   instalação do GLPI (o caminho final deve ser
   `plugins/ticketlinksync/setup.php`).
2. No GLPI, acesse **Configurar > Plugins**.
3. Localize "Sincronismo de Chamados Pai/Filho" e clique em **Instalar**,
   depois em **Ativar**.

## Como testar

1. Crie o chamado A (pai) e o chamado B (filho).
2. Em B, na aba "Chamados vinculados", adicione um vínculo com A do tipo
   "Filho de".
3. Em B, adicione um acompanhamento, ou altere status/prioridade/urgência/
   impacto.
4. Volte no chamado A e confira se a nota automática apareceu.

Se nada aparecer, verifique o log de erros do GLPI (normalmente em
`files/_log/php-errors.log` dentro da instalação) para mensagens de erro
do PHP. Bloqueios pela checagem de entidade (v1.1.0+) ou de status do pai
(v1.3.0+) aparecem em `files/_log/ticketlinksync.log`.

## Versões

- **1.4.0** — remove a propagação de mudança de **categoria** e de
  **atribuição/remoção de técnico ou grupo** — geravam volume alto de notas
  sem valor real de acompanhamento. Continuam sendo propagados: novo
  acompanhamento, tarefa, solução e mudança de status/prioridade/urgência/
  impacto.
- **1.3.0** — o corte de status do pai passa a ser em **Solucionado** (em
  vez de só Fechado), já que a partir daí o chamado só recebe atualização
  se for reaberto manualmente.
- **1.2.0** — não propaga mais para um chamado pai já Fechado (evita
  reabertura indevida e preserva SLA/estatísticas de chamados encerrados).
- **1.1.0** — adiciona checagem de entidade antes de propagar a nota (evita
  vazamento entre entidades diferentes do GLPI).
- **1.0.0** — versão inicial, testada com sucesso em homologação (GLPI 11)
  em 06/08/2026.
