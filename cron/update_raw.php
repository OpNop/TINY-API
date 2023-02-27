<?php
die('nope.avi');
//Make sure this is run as a cron or manually by setting ?doing_cron to anything
// if( false === isset( $_GET['doing_cron'] ) ) {
//     die();
// }

date_default_timezone_set('UTC');

//Load classes
require_once '../vendor/autoload.php';

//Load settings
require_once "../config.php";

//Open log file
$logFile = fopen('update.log', 'a');

//Connect to MySQL
$db = new MysqliDb($config['db']);

//Setup API
$gw2api = new \GW2Treasures\GW2Api\GW2Api();

//Check if API is up
try {
    WriteLog("API " . $gw2api->build()->get());
} catch (Exception $e) {
    WriteLog("API Error");
    die();
}

function WriteLog($message)
{
    $now = date("Y-m-d H:i:s");
    $trace = debug_backtrace();

    if (isset($trace[1])) {
        //Write to log gile
        global $logFile;
        fwrite($logFile, "{$now} - {$trace[1]['class']}::{$trace[1]['function']}: {$message}\n");
        echo ("{$now} - <strong>{$trace[1]['class']}::{$trace[1]['function']}:</strong> {$message}<br>");
    }
}

//Load 20 log entries to update
$entries = $db->rawQuery('SELECT * FROM log WHERE JSON_EXTRACT(raw, "$.type") = "treasury" AND JSON_EXTRACT(raw, "$.item_id") <> 0 AND JSON_EXTRACT(raw, "$.item_name") IS NULL LIMIT 500');

foreach ($entries as $entry) {
    // Parse the json
    $jEnrty = json_decode($entry['raw']);
    // var_dump($entry['raw']);
    // echo '<br>---------------------<br>';
    // var_dump($jEnrty);

    // Get the item name from API
    $item = $gw2api->items()->get($jEnrty->item_id);
    $jEnrty->item_name = $item->name;

    // echo '<br>---------------------<br>';
    // var_dump($jEnrty);

    // Encode json
    $sEntry = json_encode($jEnrty);
    //echo '<br>---------------------<br>';
    //var_dump($sEntry);

    // Update the DB
    $db->where('api_id', $entry['api_id']);
    $db->where('guild_id', $entry['guild_id']);
    if ($db->update('log', ['raw' => $sEntry])) {
        echo "Updated {$entry['user']} {$item->name}<br>";
    } else {
        echo 'update failed: ' . $db->getLastError() . '<br>';
    }
}
