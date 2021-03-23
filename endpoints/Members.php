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
            'message' => '(Member) Hello Tiny!',
            'config' => $config,
            'dbVersion' => $db->rawQueryValue('SELECT VERSION() LIMIT 1'),
            'gw2Version' => $api->build()->get(),
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
        if ($list) {
            return $list;
        } else {
            return [];
        }
    }

    /**
     * Search for member
     *
     * @url GET /search
     */
    public function memberSearch()
    {
        $account = $_GET['account'] ?? '';

        if ($account == '') {
            return [];
        }

        global $db;

        $db->where("account", "{$account}%", 'like');
        return $db->get('members');

    }

    /**
     * Get account information
     * @noAuth
     * @url GET /$account
     */
    public function memberInfo($account = null)
    {
        if ($account == null) {
            throw new RestException(400, "An account is required");
        }

        global $db;

        // Get basic Info
        $db->where('account', $account);
        $user = $db->getOne('members', "account, discord, created, is_banned");

        if (!$user) {
            throw new RestException(404, "Account not found");
        }

        // Get guilds of user
        $db->where('account', $account);
        $db->orderBy('date_joined', 'ASC');
        $user['guilds'] = $db->get('v_members');

        // If they are banned, pull the reason
        if ($user['is_banned'] === 1) {
            $db->where('account', $account);
            $user['ban_reason'] = $db->getOne('ban_list', 'date_added, reason');
        }

        return $user;
    }
}
