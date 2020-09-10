<?php

use \Jacwright\RestServer\RestException;

class LotteryController
{
    /**
     * GW2 API client
     */
    private $api;

    public function __construct()
    {
        global $config;

        //Connect to SQL
        $this->db = new MysqliDb( $config['db']['host'], $config['db']['username'], $config['db']['password'], $config['db']['database'] );

        //Create GW2 API client
        $this->api = new \GW2Treasures\GW2Api\GW2Api();
        
        //Redis Cache
        global $cache;
        $this->cache = $cache;
    }

    /**
     * Get the pot for the current weeks lottery
     * 
     * @url GET /pot
     * @noAuth
     */
    public function getPot()
    {
        $lotteryStart = date( "Y-m-d H:i:s", strtotime( "Last Wednesday" ) );
        $this->db->where( 'time', $lotteryStart, '>=' );
        $pot = $this->db->getValue( 'lottery_entries', 'sum(coins)' );
        if( $pot ){
            return [
                'pot'   => $pot,
                '1st'   => round($pot * 0.4),
                '2nd'   => round($pot * 0.3),
                '3rd'   => round($pot * 0.2),
                'guild' => round($pot * 0.1)
            ];
        } else {
            return (int)0;
        }
    }

    /**
     * Return entries for a specific account
     * 
     * @url GET /$account/entries
     * @noAuth
     */
    public function listEntries( string $account )
    {
        throw new RestException( 501 );
    }
}

if (interface_exists( 'ICronTask' ) )
{
    class LotteryCron implements ICronTask
    {

        public function run( $config, $db, $cache, $gw2api )
        {
            CronTask::Log("==Starting CronTask==");
            foreach( $config['guilds'] as $guild )
            {
                CronTask::Log("Checking for entries in {$guild['name']}");
                // Get last ID in the DB
                $db->where( 'guild_id', $guild['guild_id'] );
                $last_id = $db->getValue( 'lottery_entries', 'max(api_id)' );

                if( is_null( $last_id ) )
                    $last_id = 0;

                // Call the GW2 API and fetch the log
                $log = $gw2api->guild()->logOf( $guild['api_key'], $guild['guild_id'] )->since( $last_id );

                // If the API call failed, or empty log. just move on
                if( empty( $log ) )
                    continue;
                
                foreach ( $log as $entry ) {
                    if( $entry->id > $last_id ) {
                        if( $entry->type == 'stash' && $entry->operation == 'deposit' && $entry->coins > 0 ) {
                            $ticket = [
                                'api_id'    => $entry->id,
                                'time'      => $db->func( 'STR_TO_DATE(?, ?)', [$entry->time, '%Y-%m-%dT%H:%i:%s.000Z'] ),
                                'user'      => $entry->user,
                                'coins'     => $entry->coins,
                                'guild_id'  => $guild['guild_id']
                            ];

                            $id = $db->insert( 'lottery_entries', $ticket );
                            if ($id) {
                                CronTask::Log( "Ticket was created. ID={$id}");
                            } else {
                                CronTask::Log( "Ticket creation failed: ".$db->getLastError() );
                            } 
                        }
                    }
                }
            }
        }
    }
}