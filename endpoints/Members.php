<?php

include '/var/www/api.tinyarmy.org/htdocs/classes/Discord.php';
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

        return [
            'message' => '(Member) Hello Tiny!',
            'dbVersion' => $db->rawQueryValue('SELECT VERSION() LIMIT 1'),
            'gw2Version' => $api->build()->get(),
            'server' => $this->server->authHandler->token
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
     * Add API key to a member
     *
     * @url POST /$account/key
     */
    public function setKey($account, $data)
    {
        if (empty($account) || empty($data->key)) {
            throw new RestException(400, "A GW2 Account and API key are required");
        }

        //make sure key is proper, this whould already
        //have been done by the discord bot, but meh?
        if (!preg_match('/([A-Z0-9]{8}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{20}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{12})/', $data->key)) {
            throw new RestException(403, "Invalid API key");
        }

        global $db, $api;

        $db->where('account', $account);
        if ($db->update('members', ['api_key' => $data->key])) {
            //Load Character info
            $characters = $api->characters($data->key)->all();
            $characterData = [];

            foreach ($characters as $character) {
                $characterData[] = [
                    "account" => $account,
                    "name" => $character->name,
                    "race" => $character->race,
                    "created" => $db->func('STR_TO_DATE(?, ?)', [$character->created, '%Y-%m-%dT%H:%i:00Z']),
                ];
            }
            //error_log(print_r($characterData, true));

            $ids = $db->insertMulti('members_character', $characterData);
            if (!$ids) {
                return 'insert failed: ' . $db->getLastError();
            }
            return true;

        } else {
            throw new RestException(500, "Error saving API key");
        }
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
     * Save discord info
     *
     * @url POST /$account/discord
     */
    public function setDiscord($account, $data)
    {
        if (empty($account) || empty($data)) {
            throw new RestException(400, "A GW2 Account and Discord Account are required");
        }

        global $db;

        if (empty($data->discord)) {
            //error_log("Deleating data for User: {$data->account}");
            //Delete entries
            $db->where('account', $data->account);
            $db->delete('members_discord');

        } else {
            //error_log("Saving data for User: {$data->account}");
            $apidata = Discord::GetUserData($data->discord);
            //API error
            if ($apidata['info']['http_code'] != 200) {
                throw new RestException(500, $apidata['data']['message']);
            } else {
                //error_log("Discord Data: " . print_r($apidata['data'], true));
                $dbData = [
                    'account' => $account,
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

    /**
     * Load user notes
     *
     * @url GET /notes
     * @url GET /$account/notes
     */
    public function getNotes($account = null)
    {
        global $db;

        $limit = $_GET['limit'] ?? null;

        if( !is_null($account) ){
            $db->where('account', $account);
        }

        $db->orderBy('date_created', 'desc');

        $notes = $db->get('v_member_notes', $limit);
        
        if ($db->count > 0) {
            return $notes;
        } else {
            return [];
        }

    }

    /**
     * Add note to user
     *
     * @url POST /$account/notes
     */
    public function addNote($account, $data)
    {
        if(!$this->server->authHandler->token->data->account){
            throw new RestException(400, "Old auth token format, please log out and try again");
        }

        global $db;

        $data = [
            'account' => $account,
            'creator' => $this->server->authHandler->token->data->account,
            'message' => $data->message
        ];

        $db->insert('members_note', $data);
        return $this->getNotes($account);
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

        // Load characters
        $db->where('account', $account);
        $db->orderBy('name', 'ASC');
        $user['characters'] = $db->get('members_character');

        return $user;
    }
}

if (interface_exists('ICronTask')) {
    class MemberCron implements ICronTask
    {
        public function run($config, $db, $cache, $gw2api)
        {
            CronTask::Log("== Starting Members CronTask ==");
            //Update discord avatar
            $db->where('last_update < DATE_SUB(NOW(), INTERVAL 24 HOUR)');
            $db->orderBy('last_update', 'ASC');
            $user = $db->getOne('members_discord');
            if (!$user) {
                return;
            }

            //make call to Discord
            CronTask::Log("Checking info on {$user['account']}");
            $apidata = Discord::GetUserData($user['id']);
            //API error
            if ($apidata['info']['http_code'] != 200) {
                //throw new RestException(500, $apidata['data']['message']);
                CronTask::Log("Error fetching Discord data for {$user['account']}");
            } else {
                CronTask::Log("Updating Discord data for {$user['account']}");
                //error_log("Discord Data: " . print_r($apidata['data'], true));
                $dbData = [
                    'id' => $apidata['data']['id'],
                    'username' => $apidata['data']['username'],
                    'discriminator' => $apidata['data']['discriminator'],
                    'avatar' => $apidata['data']['avatar'],
                    'last_update' => $db->now(),
                ];
                //$db->onDuplicate($dbData);
                $db->where('account', $user['account']);
                if (!$db->update('members_discord', $dbData)) {
                    CronTask::Log("Error Updating {$user['account']}: {$db->getLastError()}");
                }
            }
        }
    }
}
