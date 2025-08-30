<?php

use Glpi\Plugin\Hooks;

define('PLUGIN_OPENROUTER_VERSION', '1.2.1');

// Minimal GLPI version, inclusive
define("PLUGIN_OPENROUTER_MIN_GLPI_VERSION", "11.0.0");

// Maximum GLPI version, exclusive
define("PLUGIN_OPENROUTER_MAX_GLPI_VERSION", "11.1.0");
require_once __DIR__ . '/src/Config.php';

function plugin_init_openrouter() {
   global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['config_page']['openrouter'] = 'front/config.form.php';

    $PLUGIN_HOOKS['item_add']['openrouter'] = [
        'Ticket' => 'plugin_openrouter_save_disabled_state',
    ];

    $PLUGIN_HOOKS['item_update']['openrouter'] = [
        'Ticket' => 'plugin_openrouter_save_disabled_state'
    ];

    $PLUGIN_HOOKS['post_item_form']['openrouter'] = [
        'Ticket' => 'plugin_openrouter_display_ticket_form'
    ];

    $PLUGIN_HOOKS['post_init']['openrouter'] = 'plugin_openrouter_post_init';
}

function plugin_openrouter_post_init() {
    global $CFG_GLPI;

if (strpos($_SERVER['REQUEST_URI'], '/front/ticket.form.php') !== false) {
echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    // Check if we are on a ticket page

//    const ticketId = form.querySelector("input[name=\'id\']").value;
    const urlParams = new URLSearchParams(window.location.search);
    const ticketId = urlParams.get(\'id\');
    if (!ticketId) {
        return;
    }

setTimeout(() => { 
    const timeline = document.querySelectorAll("div[class=\'rich_text_container\']");
    const len = timeline.length;
    console.log(len);
    if (timeline) {
        const lastMessage = timeline[len-1];
        if (lastMessage && lastMessage.innerText.includes("<!-- openrouter_bot_response -->")) {
            // Last message is from the bot, do nothing.
                return;
        }
        else
        {
            const disableBotCheckbox = document.getElementById('openrouter_bot_disabled_checkbox');
            if (disableBotCheckbox && disableBotCheckbox.checked) {
                // Bot is disabled, do nothing.
                return;
            }
            setTimeout(() => {
        const formData = new FormData();
	const csrfToken = document.querySelector("input[name=\'_glpi_csrf_token\']").getAttribute("value");
	formData.append("_glpi_csrf_token", csrfToken);
        formData.append("ticket_id", ticketId);

        fetch("../plugins/openrouter/ajax/create_followup.php", {
            method: "POST",
            body: formData,
            credentials: "same-origin"
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Reload the page to show the new followup
                location.reload();
            } else {
                console.error("Error creating followup:", data.error);
            }
        })
        .catch(error => {
            console.error("Error creating followup:", error);
        });
    }, 1000);
        }
    }
   },3000);

});
</script>';
    }

}

function plugin_version_openrouter() {
    return [
        'name'           => 'OpenRouter',
        'version'        => PLUGIN_OPENROUTER_VERSION,
        'author'         => 'Brice FOURIE',
        'license'        => 'Apache 2.0',
        'homepage'       => 'https://github.com/bricefourie/glpiai-openrouter',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_OPENROUTER_MIN_GLPI_VERSION,
                'max' => PLUGIN_OPENROUTER_MAX_GLPI_VERSION,
            ]
    ]
   ];
}

function plugin_openrouter_check_config($verbose = false) {
    $config = \GlpiPlugin\Openrouter\Config::getConfig();
    $api_key = $config['openrouter_api_key'] ?? '';
    $model_name = $config['openrouter_model_name'] ?? '';
    $bot_user_id = $config['openrouter_bot_user_id'] ?? 0;

    if (!empty($api_key) && !empty($model_name) && !empty($bot_user_id)) {
        return true;
    }

    if ($verbose) {
        _e('Plugin not configured. Please provide API key, model name and bot user ID.', 'openrouter');
    }
    return false;
}
