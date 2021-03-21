<?php

use \Jacwright\RestServer\RestException;

class MemberController
{
    public function __construct()
    {

    }

    /**
     * Test Endpoint
     *
     * @url GET /test
     */
    public function test()
    {
        global $db, $config, $api;
        unset($config['db']);
        return [
            'message'       => '(Member) Hello Tiny!',
            'config'        => $config,
            'dbVersion'     => $db->rawQueryValue('SELECT VERSION() LIMIT 1'),
            'gw2Version'   => $api->build()->get()
        ];
    }

    /**
     * Get Ban List
     * 
     * @url GET /banned
     */
    public function banList()
    {
        global $db;

        $db->orderBy('account', 'ASC');
        $list = $db->get('ban_list');
        if($list){
            return $list;
        } else {
            return [];
        }
    }

    /**
     * Get account information
     * 
     * @url GET /$account
     */
    public function memberInfo($account = null)
    {
        if($account == null){
            throw new RestException(400, "An account is required");
        }

        global $db;

        // Get basic Info
        $db->where('account', $account);
        $user = $db->getOne('members', "account, created");
        
        if(!$user){
            throw new RestException(404, "Account not found");
        }
        
        // Get guilds of user
        $db->where('account', $account);
        $user['guilds'] = $db->get('v_members');

        return $user;
    }
}
