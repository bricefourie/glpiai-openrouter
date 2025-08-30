<?php

require_once __DIR__ . '/src/Config.php';
use Config;
use GlpiPlugin\Openrouter\Config as OpenrouterConfig;
use Toolbox;
use ITILFollowup;

function plugin_openrouter_install()
{
    global $DB;

    $table_name = 'glpi_plugin_openrouter_disabled_tickets';
    if (!$DB->tableExists($table_name)) {
        $query = "CREATE TABLE `$table_name` (
                      `tickets_id` INT(11) NOT NULL,
                      PRIMARY KEY  (`tickets_id`)
                   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $DB->queryOrDie($query, $DB->error());
    }

    Config::setConfigurationValues('plugin:openrouter', [
        'openrouter_api_key'     => '',
        'openrouter_model_name'  => '',
        'openrouter_bot_user_id' => 2,
        'openrouter_system_prompt' => ''
    ]);
    return true;
}

function plugin_openrouter_uninstall()
{
    global $DB;

    $table_name = 'glpi_plugin_openrouter_disabled_tickets';
    if ($DB->tableExists($table_name)) {
        $query = "DROP TABLE `$table_name`";
        $DB->queryOrDie($query, $DB->error());
    }

    $config = new Config();
    $config->deleteByCriteria(['context' => 'plugin:openrouter']);
    return true;
}

function plugin_openrouter_save_disabled_state($ticket)
{
    global $DB;
    $ticket_id = $ticket->getID();

    if ($ticket_id > 0) {
        $table_name = 'glpi_plugin_openrouter_disabled_tickets';

        if (isset($_POST['openrouter_bot_disabled']) && $_POST['openrouter_bot_disabled'] == '1') {
            // Checkbox is checked, so add to disabled table
            $query = "INSERT IGNORE INTO `$table_name` (`tickets_id`) VALUES ('$ticket_id')";
            $DB->query($query);
        } else {
            // Checkbox is not checked, so remove from disabled table
            $query = "DELETE FROM `$table_name` WHERE `tickets_id` = '$ticket_id'";
            $DB->query($query);
        }
    }
}

function plugin_openrouter_display_ticket_form($params) {
    global $DB;
    $ticket = $params['item'];
    if (is_null($ticket) || !$ticket->getID()) {
        return;
    }
    $ticket_id = $ticket->getID();

    if ($ticket_id > 0) {
        $table_name = 'glpi_plugin_openrouter_disabled_tickets';
        $query = "SELECT `tickets_id` FROM `$table_name` WHERE `tickets_id` = '$ticket_id'";
        $result = $DB->query($query);
        $is_disabled = $DB->numrows($result) > 0;

        echo '<tr>';
        echo '<th>' . __('Disable AI Bot', 'openrouter') . '</th>';
        echo '<td>';
        echo '<input type="checkbox" name="openrouter_bot_disabled" value="1" ' . ($is_disabled ? 'checked' : '') . ' id="openrouter_bot_disabled_checkbox">';
        echo '</td>';
        echo '</tr>';
    }
}
