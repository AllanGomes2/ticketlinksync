<?php

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access directly to this file');
}

define('PLUGIN_TICKETLINKSYNC_VERSION', '1.4.0');
define('PLUGIN_TICKETLINKSYNC_MIN_GLPI', '10.0.0');
define('PLUGIN_TICKETLINKSYNC_MAX_GLPI', '11.9.99');

/**
 * Registra os hooks do plugin nos itens do GLPI que precisam ser observados
 * no chamado FILHO para replicar a informacao no chamado PAI.
 */
function plugin_init_ticketlinksync()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['ticketlinksync'] = true;

    // Ticket_User/Group_Ticket (tecnico/grupo atribuido) nao sao mais
    // registrados aqui a partir da v1.4.0 - mudavam com frequencia alta
    // demais e geravam ruido sem valor de acompanhamento real.
    $PLUGIN_HOOKS['item_add']['ticketlinksync'] = [
        'ITILFollowup' => 'plugin_ticketlinksync_followup_add',
        'TicketTask'   => 'plugin_ticketlinksync_task_add',
        'ITILSolution' => 'plugin_ticketlinksync_solution_add',
    ];

    $PLUGIN_HOOKS['item_update']['ticketlinksync'] = [
        'Ticket' => 'plugin_ticketlinksync_ticket_update',
    ];
}

function plugin_version_ticketlinksync()
{
    return [
        'name'           => 'Sincronismo de Chamados Pai/Filho',
        'version'        => PLUGIN_TICKETLINKSYNC_VERSION,
        'author'         => 'Allan Silva',
        'license'        => 'GPLv3+',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_TICKETLINKSYNC_MIN_GLPI,
                'max' => PLUGIN_TICKETLINKSYNC_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_ticketlinksync_check_prerequisites()
{
    return true;
}

function plugin_ticketlinksync_check_config($verbose = false)
{
    return true;
}

// Este plugin nao cria tabelas proprias, apenas registra hooks.
function plugin_ticketlinksync_install()
{
    return true;
}

function plugin_ticketlinksync_uninstall()
{
    return true;
}
