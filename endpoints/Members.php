<?php

include 'classes/Discord.php';
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
     * Update Member data
     *
     * @url POST /update
     */
    public function updateMember($data)
    {
        if (empty($data) || empty($data->account)) {
            throw new RestException(400, "An account is required");
        }

        global $db;

        // $data = [
        //     'account' => 'NullValue.1234',
        //     'fields' => [
        //         'field_name' => 'value'
        //     ]
        // ];
        //error_log("Processing: " . print_r($data->fields->discord, true));

        //Try and fetch User Data
        if (property_exists($data->fields, 'discord')) {
            if (empty($data->fields->discord)) {
                //error_log("Deleating data for User: {$data->account}");
                //Delete entries
                $db->where('account', $data->account);
                $db->delete('members_discord');

            } else {
                //error_log("Saving data for User: {$data->account}");
                $apidata = Discord::GetUserData($data->fields->discord);
                //API error
                if ($apidata['info']['http_code'] != 200) {
                    throw new RestException(500, $apidata['data']['message']);
                } else {
                    //error_log("Discord Data: " . print_r($apidata['data'], true));
                    $dbData = [
                        'account' => $data->account,
                        'id' => $apidata['data']['id'],
                        'username' => $apidata['data']['username'],
                        'discriminator' => $apidata['data']['discriminator'],
                        'avatar' => $apidata['data']['avatar'],
                    ];
                    $db->onDuplicate($dbData);
                    $db->insert('members_discord', $dbData);
                }
            }
        }

        $db->where('account', $data->account);
        if ($db->update('members', (array) $data->fields)) {
            return true;
        } else {
            throw new RestException(500, 'Update failed: ' . $db->getLastError());
        }
    }

    /**
     * Get discord info
     *
     * @url GET /$account/discord
     */
    public function getDiscord($account)
    {
        global $db;
        // Get Discord info
        $db->where('account', $account);
        $data = $db->getOne('members_discord');

        if ($data) {
            return $data;
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
