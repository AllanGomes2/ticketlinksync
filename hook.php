<?php

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access directly to this file');
}

/**
 * Retorna os IDs dos chamados PAI de um chamado filho, usando o vinculo
 * nativo do GLPI (tabela glpi_tickets_tickets).
 *
 * O GLPI pode gravar a relacao em qualquer uma das duas orientacoes,
 * dependendo de qual lado/tipo de vinculo foi escolhido na tela "Chamados
 * vinculados":
 *   - tickets_id_1 = filho, tickets_id_2 = pai, link = SON_OF
 *   - tickets_id_1 = pai,   tickets_id_2 = filho, link = PARENT_OF
 * Por isso checamos as duas possibilidades em vez de assumir uma unica
 * normalizacao.
 */
function plugin_ticketlinksync_get_parent_ids(int $child_tickets_id): array
{
    global $DB;

    static $cache = [];

    if (isset($cache[$child_tickets_id])) {
        return $cache[$child_tickets_id];
    }

    $parents = [];

    $iterator = $DB->request([
        'SELECT' => ['tickets_id_1', 'tickets_id_2', 'link'],
        'FROM'   => Ticket_Ticket::getTable(),
        'WHERE'  => [
            'OR' => [
                [
                    'tickets_id_1' => $child_tickets_id,
                    'link'         => Ticket_Ticket::SON_OF,
                ],
                [
                    'tickets_id_2' => $child_tickets_id,
                    'link'         => Ticket_Ticket::PARENT_OF,
                ],
            ],
        ],
    ]);

    foreach ($iterator as $row) {
        $link = (int) $row['link'];

        if ($link === Ticket_Ticket::SON_OF && (int) $row['tickets_id_1'] === $child_tickets_id) {
            $parents[] = (int) $row['tickets_id_2'];
        } elseif ($link === Ticket_Ticket::PARENT_OF && (int) $row['tickets_id_2'] === $child_tickets_id) {
            $parents[] = (int) $row['tickets_id_1'];
        }
    }

    $parents = array_values(array_unique($parents));

    $cache[$child_tickets_id] = $parents;

    return $parents;
}

/**
 * Monta o texto padrao usado em todas as notas automaticas, sempre citando
 * o numero do chamado filho de origem.
 */
function plugin_ticketlinksync_build_message(int $child_id, string $summary, string $details = ''): string
{
    $message = sprintf(__('[Sincronizado do chamado filho #%d] %s'), $child_id, $summary);

    if ($details !== '') {
        $message .= "\n\n" . $details;
    }

    return $message;
}

/**
 * Cria o followup no(s) chamado(s) pai. Usa um guarda estatico por ID de
 * pai para nao entrar em recursao infinita caso exista um vinculo
 * pai/filho circular malformado na base.
 */
function plugin_ticketlinksync_add_parent_followup(int $child_tickets_id, string $message, int $is_private = 1): void
{
    static $processing = [];

    $child_ticket = new Ticket();

    if (!$child_ticket->getFromDB($child_tickets_id)) {
        return;
    }

    $parent_ids = plugin_ticketlinksync_get_parent_ids($child_tickets_id);

    foreach ($parent_ids as $parent_id) {
        if (isset($processing[$parent_id])) {
            continue;
        }

        $parent_ticket = new Ticket();

        if (!$parent_ticket->getFromDB($parent_id)) {
            continue;
        }

        // Guarda de seguranca multi-entidade: o hook roda com privilegios do
        // core do GLPI, sem passar pelo ACL normal de quem editou o filho.
        // Para evitar que informacao vaze de um chamado filho para um pai em
        // outra entidade (ex.: empresas/departamentos diferentes dentro do
        // mesmo GLPI), a propagacao só ocorre quando pai e filho estao na
        // mesma entidade. Vinculos legitimos entre entidades diferentes nao
        // sao replicados; fica registrado no log do plugin para auditoria.
        if ((int) $parent_ticket->fields['entities_id'] !== (int) $child_ticket->fields['entities_id']) {
            Toolbox::logInFile('ticketlinksync', sprintf(
                "Propagacao bloqueada: chamado filho #%d (entidade %d) e chamado pai #%d (entidade %d) pertencem a entidades diferentes.\n",
                $child_tickets_id,
                (int) $child_ticket->fields['entities_id'],
                $parent_id,
                (int) $parent_ticket->fields['entities_id']
            ));
            continue;
        }

        $processing[$parent_id] = true;

        $followup = new ITILFollowup();
        $followup->add([
            'itemtype'   => Ticket::class,
            'items_id'   => $parent_id,
            'content'    => $message,
            'is_private' => $is_private,
            'users_id'   => Session::getLoginUserID() ?: 0,
        ]);

        unset($processing[$parent_id]);
    }
}

function plugin_ticketlinksync_excerpt(string $html, int $max_length = 300): string
{
    $text = trim(strip_tags($html));

    if (mb_strlen($text) > $max_length) {
        $text = mb_substr($text, 0, $max_length) . '...';
    }

    return $text;
}

// ---------------------------------------------------------------------
// Followups (acompanhamentos)
// ---------------------------------------------------------------------

function plugin_ticketlinksync_followup_add(ITILFollowup $item)
{
    if ($item->fields['itemtype'] !== Ticket::class) {
        return;
    }

    $child_id = (int) $item->fields['items_id'];
    $author   = getUserName((int) $item->fields['users_id']);
    $excerpt  = plugin_ticketlinksync_excerpt($item->fields['content']);

    $summary = sprintf(__('novo acompanhamento adicionado por %s'), $author);
    $message = plugin_ticketlinksync_build_message($child_id, $summary, $excerpt);

    // Sempre privado no pai: mesmo que o acompanhamento seja publico no
    // filho (visivel ao solicitante do filho), quem abriu o chamado pai
    // nao tem relacao com esse solicitante e nao deve ver esse conteudo.
    plugin_ticketlinksync_add_parent_followup($child_id, $message, 1);
}

// ---------------------------------------------------------------------
// Tarefas
// ---------------------------------------------------------------------

function plugin_ticketlinksync_task_add(TicketTask $item)
{
    $child_id = (int) $item->fields['tickets_id'];
    $author   = getUserName((int) $item->fields['users_id']);
    $excerpt  = plugin_ticketlinksync_excerpt($item->fields['content']);

    $summary = sprintf(__('nova tarefa registrada por %s'), $author);
    $message = plugin_ticketlinksync_build_message($child_id, $summary, $excerpt);

    plugin_ticketlinksync_add_parent_followup($child_id, $message, 1);
}

// ---------------------------------------------------------------------
// Solucoes
// ---------------------------------------------------------------------

function plugin_ticketlinksync_solution_add(ITILSolution $item)
{
    if ($item->fields['itemtype'] !== Ticket::class) {
        return;
    }

    $child_id = (int) $item->fields['items_id'];
    $excerpt  = plugin_ticketlinksync_excerpt($item->fields['content']);

    $summary = __('solucao proposta');
    $message = plugin_ticketlinksync_build_message($child_id, $summary, $excerpt);

    plugin_ticketlinksync_add_parent_followup($child_id, $message, 1);
}

// ---------------------------------------------------------------------
// Alteracoes de campos do chamado (status, prioridade, urgencia, impacto,
// categoria)
// ---------------------------------------------------------------------

function plugin_ticketlinksync_format_field_value(string $type, $value, string $dropdown_itemtype = '')
{
    if ($value === null || $value === '') {
        return __('(vazio)');
    }

    switch ($type) {
        case 'status':
            return Ticket::getStatus((int) $value);
        case 'priority':
            return CommonITILObject::getPriorityName((int) $value);
        case 'urgency':
            return CommonITILObject::getUrgencyName((int) $value);
        case 'impact':
            return CommonITILObject::getImpactName((int) $value);
        case 'dropdown':
            return Dropdown::getDropdownName(getTableForItemType($dropdown_itemtype), (int) $value);
        default:
            return (string) $value;
    }
}

function plugin_ticketlinksync_ticket_update(Ticket $item)
{
    if (empty($item->oldvalues)) {
        return;
    }

    $tracked_fields = [
        'status'            => ['label' => __('Status'),     'type' => 'status'],
        'priority'          => ['label' => __('Prioridade'), 'type' => 'priority'],
        'urgency'           => ['label' => __('Urgencia'),   'type' => 'urgency'],
        'impact'            => ['label' => __('Impacto'),    'type' => 'impact'],
        'itilcategories_id' => ['label' => __('Categoria'),  'type' => 'dropdown', 'itemtype' => 'ITILCategory'],
    ];

    $lines = [];

    foreach ($tracked_fields as $field => $meta) {
        if (!array_key_exists($field, $item->oldvalues)) {
            continue;
        }

        $old = $item->oldvalues[$field];
        $new = $item->fields[$field] ?? null;

        if ($old == $new) {
            continue;
        }

        $lines[] = sprintf(
            '- %s: %s -> %s',
            $meta['label'],
            plugin_ticketlinksync_format_field_value($meta['type'], $old, $meta['itemtype'] ?? ''),
            plugin_ticketlinksync_format_field_value($meta['type'], $new, $meta['itemtype'] ?? '')
        );
    }

    if (empty($lines)) {
        return;
    }

    $child_id = (int) $item->fields['id'];
    $summary  = __('chamado filho foi atualizado');
    $message  = plugin_ticketlinksync_build_message($child_id, $summary, implode("\n", $lines));

    plugin_ticketlinksync_add_parent_followup($child_id, $message, 1);
}

// ---------------------------------------------------------------------
// Tecnico / grupo atribuido (atores do tipo ASSIGN)
// ---------------------------------------------------------------------

function plugin_ticketlinksync_handle_actor_change($item, bool $added): void
{
    $type = (int) ($item->fields['type'] ?? 0);

    if ($type !== CommonITILActor::ASSIGN) {
        return;
    }

    $child_id = (int) ($item->fields['tickets_id'] ?? 0);

    if (!$child_id) {
        return;
    }

    if ($item instanceof Ticket_User) {
        $label = __('Tecnico');
        $name  = getUserName((int) $item->fields['users_id']);
    } elseif ($item instanceof Group_Ticket) {
        $label = __('Grupo');
        $name  = Dropdown::getDropdownName(Group::getTable(), (int) $item->fields['groups_id']);
    } else {
        return;
    }

    $verb    = $added ? __('atribuido') : __('removido');
    $summary = sprintf(__('%s "%s" %s no chamado'), $label, $name, $verb);
    $message = plugin_ticketlinksync_build_message($child_id, $summary);

    plugin_ticketlinksync_add_parent_followup($child_id, $message, 1);
}

function plugin_ticketlinksync_actor_add($item)
{
    plugin_ticketlinksync_handle_actor_change($item, true);
}

function plugin_ticketlinksync_actor_delete($item)
{
    plugin_ticketlinksync_handle_actor_change($item, false);
}
