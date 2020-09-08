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

        //Create GW2 API client
        $this->api = new \GW2Treasures\GW2Api\GW2Api();
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
            $this->_log("==Starting CronTask==");
            $quag = $gw2api->quaggans()->get('cheer');
            print_r( $quag );
        }

        private function _log ($message)
        {
            echo __CLASS__. ": {$message}<br />";
        }
    }
}