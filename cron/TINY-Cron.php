<?php

//Make sure this is run as a cron or manually by setting ?doing_cron to anything
if( false === isset( $_GET['doing_cron'] ) ) {
    die();
}

require_once '../vendor/autoload.php';
date_default_timezone_set('UTC');

//Load settings
require_once( "../config.php" );

//Connect to MySql
$client = new mysqli($config['db']['host'], $config['db']['username'], $config['db']['password'], $config['db']['database']);

if( $client->connect_error ) {
    die("Connection failed: " . $client->connect_error);
}

//Init GW2 API Client
$api = new \GW2Treasures\GW2Api\GW2Api();

//update Logs
foreach( $config['guilds'] as $guild ){

    // Get last ID in the DB
    //error_log("Running SQL: SELECT MAX(api_id) as id FROM `log` where guild_id = '{$guild['guild_id']}'");
    $last_id = $client->query( "SELECT MAX(api_id) as id FROM `log` where guild_id = '{$guild['guild_id']}'" )->fetch_object()->id;
    if( is_null( $last_id ) )
        $last_id = 0;

    $log = $api->guild()->logOf($guild['api_key'], $guild['guild_id'])->since( $last_id );

    // If the API call failed, or empty log. just keep going
    if( empty( $log ) ) {
        //error_log( "LogOf({$guild['guild_id']} failed" );
        continue;
    }

    foreach ( $log as $entry ) {
        if( $entry->id > $last_id ) { //Sanity check
            switch ($entry->type){
                //Roster Items
                case 'joined':
                case 'invited':
                case 'kick':
                case 'rank_change':
                    addRosterEvent($entry, $guild);
                    break;
                
                case 'treasury':
                    //addTreasuryEvent($entry, $guild);
                    break;
                
                case 'stash':
                    addStashEvent($entry, $guild);
                    break;
            }
        }
    }
}

function addRosterEvent($entry, $guild){
    if( $entry->type == "joined" ){
        $message = "{$entry->user} has joined the guild";
    } else if ( $entry->type == "invited" ) {
        $message = "{$entry->invited_by} invited {$entry->user}";
    } else if ( $entry->type == "kick" ) {
        if( $entry->user == $entry->kicked_by ) {    //User left the guild (kicked themselves)
            $message = "{$entry->user} has left the guild";
        } else {                                    //User was kicked from the guild
            $message = "{$entry->user} was kicked by {$entry->kicked_by}";
        }
    } else if ($entry->type == "rank_change" ) {
        $message = "{$entry->changed_by} changed the rank of {$entry->user} from {$entry->old_rank} to {$entry->new_rank}";
    }

    if( $message != "" ){
        addLogEvent($entry, $message, $guild);
    }
    
}

function addTreasuryEvent($entry, $guild){
    
    
    if( $message != "" ){
        addLogEvent($entry, $message, $guild);
    }
}

function addStashEvent($entry, $guild){
    global $api;
    
    //Deposits
    if($entry->operation == "deposit"){
        if($entry->coins > 0){
            $message = "{$entry->user} deposited {$entry->coins}";
        } else {
            $item = $api->items()->get($entry->item_id);
            $message = "{$entry->user} deposited {$entry->count} {$item->name}";
        }
    }

    //Withdrawls
    if($entry->operation == "withdraw"){
        if($entry->coins > 0){
            $message = "{$entry->user} withdrew {$entry->coins}";
        } else {
            $item = $api->items()->get($entry->item_id);
            $message = "{$entry->user} withdrew {$entry->count} {$item->name}";
        }
    }

    if( $message != "" ){
        addLogEvent($entry, $message, $guild);
    }
}

function addLogEvent($event, $message, $guild) {
    global $client;
    $client->query( "INSERT INTO `log`
                        (`api_id`, `guild_id`, `date`, `user`, `type`, `message`) 
                        VALUES ({$event->id}, '{$guild['guild_id']}', STR_TO_DATE('{$event->time}', '%Y-%m-%dT%H:%i:%s.000Z'), '{$event->user}', '{$event->type}', '{$message}') 
                    ");
}
//Update Memebrs 

// $members_list = [];
// foreach ( $guild_ids as $key => $guild ) {
//     //Load Guild Name
//     $guildInfo = $api->guild()->detailsOf($guild)->get();
    
//     //Load Members
//     $api_members = $api->guild()->membersOf( API_KEY, $guild )->get();
//     foreach( $api_members as $api_member ){
//         $members_list[$api_member->name]['guilds'][$guildInfo->name] = $api_member;
//     }
// }