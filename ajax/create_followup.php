<?php

error_reporting(E_ERROR);
global $CFG_GLPI;

use GlpiPlugin\Openrouter\Config;
use ITILFollowup;
use Ticket;

header("Content-Type: application/json");

if (!isset($_POST['ticket_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing ticket_id']);
    exit;
}

$ticket_id = $_POST['ticket_id'];

global $DB;
$table_name = 'glpi_plugin_openrouter_disabled_tickets';
$query = "SELECT `tickets_id` FROM `$table_name` WHERE `tickets_id` = '$ticket_id'";
$result = $DB->query($query);

if ($DB->numrows($result) > 0) {
    // Bot is disabled for this ticket
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Bot is disabled for this ticket.']);
    exit;
}

$config = Config::getConfig();

if(isset($config['openrouter_max_api_usage_count']) && isset($config['openrouter_api_usage_count']) && isset($config['openrouter_api_reset_day']))
{
    $now = new DateTime();
    $reset_day = new DateTime($config['openrouter_api_reset_day']);

    if($now >= $reset_day)
    {
        $config['openrouter_api_usage_count'] = 0;
        // Set next reset day to the same time tomorrow
        $new = (new DateTime('now'))->add(new DateInterval('P1D'));
        $old = new DateTime($config['openrouter_api_reset_day']); // reconvertir en DateTime

        $new->setTime(
            (int)$old->format('H'),
            (int)$old->format('i'),
            (int)$old->format('s')
        );
        $config['openrouter_api_reset_day'] = $new->format('Y-m-d H:i:s');
        Config::setConfig($config);
    }
    if($config['openrouter_api_usage_count'] >= $config['openrouter_max_api_usage_count'])
    {
        http_response_code(429);
        echo json_encode(['error' => 'API usage limit reached for today']);
        exit;
    }
    else
    {
        // Increment usage count
        $config['openrouter_api_usage_count']++;
        Config::setConfig($config);
    }
}
else
{
    // Initialize usage count and reset day if not set
    if(!isset($config['openrouter_api_usage_count']))
    {
        $config['openrouter_api_usage_count'] = 1;
    }
    if(!isset($config['openrouter_api_reset_day']))
    {
        $newval = (new DateTime('now'))->add(new DateInterval('P1D'));
        $config['openrouter_api_reset_day'] = $newval->format('Y-m-d H:i:s');
    }
    Config::setConfig($config);
}

$api_key = $config['openrouter_api_key'] ?? '';
$model_name = $config['openrouter_model_name'] ?? '';
$bot_user_id = $config['openrouter_bot_user_id'] ?? 0;
$system_prompt_config = $config['openrouter_system_prompt'] ?? '';

if (empty($api_key) || empty($model_name) || empty($bot_user_id)) {
    http_response_code(500);
    echo json_encode(['error' => 'Plugin not configured']);
    exit;
}

$ticket = new Ticket();
if (!$ticket->getFromDB($ticket_id)) {
    http_response_code(404);
    echo json_encode(['error' => 'Ticket not found']);
    exit;
}

// Get the last user message as content
$content = $ticket->fields['content']; // Fallback to initial content
$timeline = $ticket->getTimelineItems();
foreach ($timeline as $item) {
    if ($item->fields['itemtype'] == 'TicketFollowup' && $item->fields['users_id'] != $bot_user_id) {
        $content = $item->fields['content']; // Get the latest user followup
    }
}


$system_prompt = "You are an AI assistant acting as a Level 1 IT support technician for the company. Your name is 'OpenRouter Bot'. You must be professional and courteous in all your responses. Your primary goal is to resolve common user issues based on the provided ticket information.\n\nWhen responding to a user, please follow these guidelines:\n1.  Analyze the user's request carefully.\n2.  If the request is clear and you can provide a solution, offer a step-by-step guide.\n3.  If the request is unclear, ask for more information. Be specific about what you need.\n4.  If the issue is complex or requires administrative privileges you don't have, you must escalate the ticket. To do so, respond with the following exact phrase and nothing else: 'I am unable to resolve this issue and have escalated it to a system administrator.'\n5.  Do not invent solutions or provide information you are not sure about.\n6.  Always sign your responses with your name, 'OpenRouter Bot'.";

if (!empty($system_prompt_config)) {
    $system_prompt = $system_prompt_config;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://openrouter.ai/api/v1/chat/completions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
$postdata = [
    'model' => $model_name,
    'messages' => [
        ['role' => 'system', 'content' => $system_prompt],
        ['role' => 'user', 'content' => $content]
    ]
];
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postdata));

$headers = [
    'Authorization: Bearer ' . $api_key,
    'Content-Type: application/json'
];
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$result = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if (curl_errno($ch) || $http_code != 200) {
    $error_message = "OpenRouter API error: " . curl_error($ch) . " (HTTP code: " . $http_code . "). Response: " . $result;
    curl_close($ch);
    http_response_code(500);
    echo json_encode(['error' => $error_message]);
    exit;
}
curl_close($ch);

$response = json_decode($result, true);
$response_content = $response['choices'][0]['message']['content'] ?? '';

if (!empty($response_content)) {
    $followUp = new ITILFollowup();
    $toAdd = [
        'type' => 'new',
        'items_id' => $ticket_id,
        'itemtype' => 'Ticket',
        'content' => $response_content . "\n\n<!-- openrouter_bot_response -->",
        'users_id' => $bot_user_id
    ];
    if ($followUp->add($toAdd)) {
        echo json_encode(['success' => true, 'message' => 'Followup added.']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to add followup.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No response from bot.']);
}
