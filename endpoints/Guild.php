<?php

use \Jacwright\RestServer\RestException;

class GuildController
{

    //Reference to the GW2 API
    private $api;

    private $cache;
    private $db;

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
     * Test Endpoint
     * 
     * @url GET /test
     * @noAuth
     */
    public function test()
    {
        return "(Guild) Hello Tiny!";
    }

    /**
     * Get all guild logs
     * 
     * @url GET /logs
     * @url GET /$guild/log
     * @noAuth
     */
    public function allLogs( $guild = null ) {
        $type   = $_GET['type'] ?? null;
        $page   = $_GET['page'] ?? 1;
        $limit  = $_GET['limit'] ?? 20;

        $valid_types = ['stash', 'rank_change', 'kick', 'joined', 'invited'];

        //Add guild filter
        if( false === is_null( $guild ) ) {
            $this->db->where( 'guild_id', $guild );
        }

        //Add type filter
        if( false === is_null( $type ) ) {
            if( in_array( $type, $valid_types ) ) {
                $this->db->where( 'type', $type );
            } else {
                throw new RestException( 400, "Argument `type` must be one of (".implode(', ', $valid_types).")" );
            }
        }

        $this->db->pageLimit = $limit;
        $this->db->orderBy( 'date', 'Desc' );
        $log = $this->db->arraybuilder()->withTotalCount()->paginate( 'log', $page );
        if( $log ){
            header("X-Page-Size: {$limit}");
            header("X-Result-Count: {$this->db->count}");
            header("X-Page-Total: {$this->db->totalPages}");
            header("X-Result-Total: {$this->db->totalCount}");
            return $log;
        } else {
            throw new RestException( 400, "Great! Ya blew it!" );
        }

    }

    /**
     * Get guild info
     * 
     * @url GET /$id
     * @noAuth
     */
    public function guild( $id )
    {
        if( empty( $id ) ) {
            throw new RestException( 400, "Missing guild ID" );
        } else {
            return $this->api->guild()->detailsOf($id, API_KEY)->get();
        }
    }

    /**
     * Get guild memebrs
     * 
     * @url GET /$id/members
     * @noAuth
     */
    public function members( $id )
    {
        if( empty( $id ) ) {
            throw new RestException( 400, "Missing guild ID" );
        } else {
            //Check if cached

            //request new data
            $guildMemebers = $this->api->guild()->membersOf(API_KEY, $id)->get();

            //store in cache
            //$this->cache->set("guild:{$id}:members", $guildMemebers);

            return $guildMemebers;
            
        }
    }
}

if (interface_exists( 'iConTask' ) )
{
    class LotteryCron implements iCronTask
    {

    }
}