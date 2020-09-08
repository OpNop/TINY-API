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

    public function _cronTask()
    {
        //Pull down API

        //Store new entries into the DB
        
    }
}