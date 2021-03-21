<?php

use \Jacwright\RestServer\RestException;

class GuildController
{

    //Reference to the GW2 API
    private $api;

    private $cache;
    private $db;
    private $config;

    public function __construct()
    {
        global $config;
        $this->config = $config;

        //Connect to SQL
        $this->db = new MysqliDb($config['db']['host'], $config['db']['username'], $config['db']['password'], $config['db']['database']);

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
     */
    public function allLogs($guild = null)
    {
        $type = $_GET['type'] ?? null;
        $page = (int) ($_GET['page'] ?? 1);
        $limit = (int) ($_GET['limit'] ?? 20);
        $account = $_GET['account'] ?? null;

        $valid_types = ['stash', 'rank_change', 'kick', 'joined', 'invited'];

        //Add guild filter
        if ($guild) {
            $this->db->where('guild_id', $guild);
        }

        //Add account filter
        if ($account) {
            $this->db->where('user', $account);
        }

        //Add type filter
        if ($type) {
            if (in_array($type, $valid_types)) {
                $this->db->where('type', $type);
            } else {
                throw new RestException(400, "Argument `type` must be one of (" . implode(', ', $valid_types) . ")");
            }
        }

        $this->db->pageLimit = $limit;
        $this->db->orderBy('date', 'Desc');
        $log = $this->db->arraybuilder()->withTotalCount()->paginate('log', $page);
        if ($this->db->getLastErrno() === 0) {
            header("X-Page-Size: {$limit}");
            header("X-Result-Count: {$this->db->count}");
            header("X-Page-Total: {$this->db->totalPages}");
            header("X-Result-Total: {$this->db->totalCount}");
            return [
                'PageTotal' => $this->db->totalPages,
                'PageSize' => $limit,
                'ResultCount' => $this->db->count,
                'ResultTotal' => (int) $this->db->totalCount,
                'logs' => $log,
            ];
        } else {
            //return $this->db->getLastQuery();
            throw new RestException(400, "Great! Ya blew it!");
        }

    }

    /**
     * Sync guild members
     *
     * @url GET /syncMembers
     * @noAuth
     */
    public function syncMembers()
    {
        if (!isset($_GET['pass']) || $_GET['pass'] != "ThisisthePassWord235") {
            return "No!";
        }
        global $config;

        $log = [];

        foreach ($config['guilds'] as $guild) {

            $members = $this->api->guild()->membersOf(API_KEY, $guild['guild_id'])->get();

            foreach ($members as $member) {

                $log[] = "Setting up {$member->name}";

                $data = [
                    'account' => $member->name,
                ];
                $this->db->onDuplicate(['account']);
                $this->db->insert('members', $data);
                $log[] = $this->db->getLastQuery();

                $member_guild = [
                    'account' => $member->name,
                    'guild' => $guild['guild_id'],
                    'guild_rank' => $member->rank,
                    'date_joined' => $this->db->func('STR_TO_DATE(?, ?)', [$member->joined, '%Y-%m-%dT%H:%i:%s.000Z']),
                ];

                $mgid = $this->db->insert('members_guild', $member_guild);
                $log[] = $this->db->getLastQuery();
            }
        }
        return $log;

    }

    /**
     * Get Members Count
     *
     * @url GET /members/count
     */
    public function membersCount()
    {
        $this->db->orderBy('time', 'DESC');
        $members = $this->db->getValue('guild_stats', 'members', 1);
        if ($members) {
            return $members;
        } else {
            return 0;
        }

    }

    /**
     * Get guild memebrs
     *
     * @url GET /members
     * @url GET /$guild/members
     */
    public function members($guild = null)
    {
        $page = (int) ($_GET['page'] ?? 1);
        $limit = (int) ($_GET['limit'] ?? 20);
        $order_by = $_GET['order_by'] ?? 'DESC';
        $sort_by = $_GET['sort_by'] ?? 'date_joined';

        $valid_sort_by = ['account', 'guild_rank', 'date_joined'];

        //Validate Order By
        if (!in_array($sort_by, $valid_sort_by)) {
            throw new RestException(400, "Argument `orderBy` must be one of (" . implode(', ', $valid_sort_by) . ")");
        }

        $this->db->pageLimit = $limit;
        $this->db->orderBy($sort_by, $order_by);

        //Add guild filter
        if ($guild) {
            $this->db->where('guild_guid', $guild);
        }

        $members = $this->db->arraybuilder()->withTotalCount()->paginate('v_members', $page);
        if ($this->db->getLastErrno() === 0) {
            header("X-Result-Count: {$this->db->count}");
            header("X-Page-Total: {$this->db->totalPages}");
            header("X-Result-Total: {$this->db->totalCount}");
            return [
                'PageTotal' => $this->db->totalPages,
                'PageSize' => $limit,
                'ResultCount' => $this->db->count,
                'ResultTotal' => (int) $this->db->totalCount,
                'members' => $members,
            ];
        } else {
            //return $this->db->getLastQuery();
            throw new RestException(400, "Great! Ya blew it!");
        }

        //Check if cached

        //request new data
        //$guildMemebers = $this->api->guild()->membersOf(API_KEY, $id)->get();

        //store in cache
        //$this->cache->set("guild:{$id}:members", $guildMemebers);
    }

    /**
     * Get guild stats
     *
     * @url GET /stats
     */
    public function guildStats()
    {
        $this->db->orderBy('time', 'DESC');
        $stats = $this->db->get('guild_stats', 15);
        $current = $stats[0];
        return [
            'current' => $current,
            'historical' => $stats,
        ];
    }

    /**
     * Get guild info
     *
     * @url GET /$id
     */
    public function guild($id)
    {
        if (empty($id)) {
            throw new RestException(400, "Missing guild ID");
        } else {
            return $this->api->guild()->detailsOf($id, API_KEY)->get();
        }
    }
}

if (interface_exists('ICronTask')) {
    class GuildCron implements ICronTask
    {

        private $config;
        private $db;
        private $cache;
        private $api;

        public function run($config, $db, $cache, $api)
        {
            $this->config = $config;
            $this->db = $db;
            $this->cache = $cache;
            $this->api = $api;

            CronTask::Log("==Starting CronTask==");

            //Build guild stats every day
            if (date("H") == 0 && date("i") == 2) {
                $this->getStats();
            }

            $this->parseLogs();
        }

        /**
         * Buid up guild stats
         *
         * @return void
         */
        private function getStats()
        {
            $gold = 0;
            $allMembers = [];

            CronTask::Log("=== Starting Stats ===");

            foreach ($this->config['guilds'] as $guild) {

                CronTask::Log("Getting Stats for {$guild['name']}");

                // Load bank
                $stash = $this->api->guild()->stashOf($guild['api_key'], $guild['guild_id'])->get();

                // Loop bank tabs and add coins to $gold
                foreach ($stash as $tab) {
                    $gold = $gold + $tab->coins;
                }

                // Load members
                $members = $this->api->guild()->membersOf($guild['api_key'], $guild['guild_id'])->get();

                // Loop members
                foreach ($members as $member) {
                    $allMembers[] = $member->name;
                }

            }

            // Store in `guild_stats` table
            $data = [
                'gold' => $gold,
                'members' => count(array_unique($allMembers)),
            ];

            CronTask::Log("Saving to DB");
            $this->db->insert('guild_stats', $data);
        }

        /**
         * Gather the logs from each guild and call the proper method to
         * generate a generic log message and save it based on the `$entry->type`
         *
         * @return void
         */
        private function parseLogs()
        {

            // Fetch last ID's processed for all guilds
            $profile_start = hrtime(true);
            $last_ids = $this->getLastIds();
            CronTask::Log("getLastIds took: " . (float) (hrtime(true) - $profile_start) / 1e+9 . " seconds to run");

            foreach ($this->config['guilds'] as $guild) {

                CronTask::Log("Getting logs for {$guild['name']}");

                // Fetch last log ID
                $last_id = $last_ids[$guild['guild_id']] ?? 0;
                // Fetch the logs from API
                $profile_start = hrtime(true);
                $log = $this->api->guild()->logOf($guild['api_key'], $guild['guild_id'])->since($last_id);
                CronTask::Log("API call took: " . (float) (hrtime(true) - $profile_start) / 1e+9 . " seconds to run");
                // If log is empty, nothing new, move on
                if (empty($log)) {
                    continue;
                }

                // Reverse log so we work on entries in the right order
                $log = array_reverse($log);

                foreach ($log as $entry) {
                    switch ($entry->type) {
                        //Roster Items
                        case 'joined':
                        case 'invited':
                        case 'kick':
                        case 'rank_change':
                            $this->addRosterEvent($entry, $guild);
                            break;

                        case 'treasury':
                            $this->addTreasuryEvent($entry, $guild);
                            break;

                        case 'stash':
                            $this->addStashEvent($entry, $guild);
                            break;

                        case 'upgrade':
                            //not dealing with this for now as its broken in my mind
                            break;

                        default:
                            CronTask::Log("Unknown entry type: {$entry->type}");
                    }
                }
            }
        }

        /**
         * Parse the __Roster__ log events saves them to the database
         * These include: `joined`, `invited`, `kick`, `rank_change`
         *
         * This method handles the parsing of different actions of `$entry->type`
         * and creates formatted message strings for each type
         *
         * @param object $entry
         * @param array $guild
         *
         * @return void
         */
        private function addRosterEvent(object $entry, array $guild): void
        {
            switch ($entry->type) {
                case 'joined':
                    $message = "{$entry->user} has joined the guild";
                    break;

                case 'invited':
                    $message = "{$entry->invited_by} invited {$entry->user}";
                    //$this->addMember($entry->user, $guild['guild_id']);
                    break;

                case 'kick':
                    if ($entry->user == $entry->kicked_by) {
                        //User left the guild (kicked themselves)
                        $message = "{$entry->user} has left the guild";
                    } else {
                        //User was kicked from the guild
                        $message = "{$entry->user} was kicked by {$entry->kicked_by}";
                    }

                    //Remove from `guild`
                    //$this->removeMember($entry->user, $guild['guild_id']);
                    break;

                case 'rank_change':
                    $message = "{$entry->changed_by} changed the rank of {$entry->user} from {$entry->old_rank} to {$entry->new_rank}";
                    break;

                default:
                    $message = "UNKNOWN Type: {$entry->type}";
                    CronTask::Log($message);
                    break;
            }

            $this->addLogEvent($message, $entry, $guild);
        }

        /**
         * Parse the __Treasury__ log events and saves them to the database
         *
         * This method handles the parsing of the `treasury` action
         * and creates formatted message string for it.
         *
         * @param object $entry
         * @param array $guild
         *
         * @return void
         */
        private function addTreasuryEvent(object $entry, array $guild): void
        {
            // Get item name fom API
            $item = $this->api->items()->get($entry->item_id);
            $this->addLogEvent("{$entry->user} deposited {$entry->count} {$item->name}", $entry, $guild);
        }

        /**
         * Parse the __Stash__ log events saves them to the database
         *
         * This method handles the parsing of different types of `$entry->operation`
         * and creates formatted message strings for each type
         *
         * @param object $entry The raw API entry
         * @param array $guild The guild array from $config
         *
         * @return void
         */
        private function addStashEvent(object $entry, array $guild): void
        {

            switch ($entry->operation) {
                case 'deposit':
                    if ($entry->coins > 0) {
                        $message = "{$entry->user} deposited {$entry->coins}";
                    } else {
                        $item = $this->api->items()->get($entry->item_id);
                        $message = "{$entry->user} deposited {$entry->count} {$item->name}";
                    }
                    break;

                case 'withdraw':
                    if ($entry->coins > 0) {
                        $message = "{$entry->user} withdrew {$entry->coins}";
                    } else {
                        $item = $this->api->items()->get($entry->item_id);
                        $message = "{$entry->user} withdrew {$entry->count} {$item->name}";
                    }
                    break;

                case 'move':
                    $item = $this->api->items()->get($entry->item_id);
                    $message = "{$entry->user} moved {$entry->count} {$item->name} somewhere ¯\_(ツ)_/¯";
                    break;

                default:
                    $message = "UNKNOWN operation: {$entry->operation}";
                    CronTask::Log($message);
                    break;
            }

            $this->addLogEvent($message, $entry, $guild);
        }

        /**
         * Adds the log message to the Database
         *
         * @param string $message The formatted message
         * @param object $entry The raw API entry
         * @param array $guild The guild array from $config
         *
         * @return void
         */
        private function addLogEvent(string $message, object $entry, array $guild): void
        {
            $raw = json_encode($entry);
            $data = [
                'api_id' => $entry->id,
                'guild_id' => $guild['guild_id'],
                'date' => $this->db->func('STR_TO_DATE(?, ?)', [$entry->time, '%Y-%m-%dT%H:%i:%s.000Z']),
                'user' => $entry->user,
                'type' => $entry->type,
                'message' => $message,
                'raw' => $raw,
            ];

            $id = $this->db->insert('log', $data);
            if (!$id) {
                CronTask::Log($this->db->getLastQuery());
                CronTask::Log($this->db->getLastError());
                CronTask::Log("Error Saving log entry: {$raw}");
            }
        }

        /**
         * Add a user to the `members` and `members_guild` tables
         *
         * @param object $entry The GW2 log entry
         * @param string $guild The Guild to add them to
         *
         * @return void
         */
        private function addMember(object $entry, string $guild): void
        {
            $data = [
                'account' => $entry->user,
            ];
            $this->db->onDuplicate(['account'], 'id');
            $this->db->insert('members', $data);
            $id = $this->db->rawQueryValue("SELECT LAST_INSERT_ID() limit 1");

            $member_guild = [
                'account_id' => $id,
                'guild_id' => $guild,
                'guild_rank' => 'Almost Tiny',
                'date_joined' => $this->db->func('STR_TO_DATE(?, ?)', [$entry->time, '%Y-%m-%dT%H:%i:%s.000Z']),
            ];
            $this->db->insert('members_guild', $member_guild);
        }

        /**
         * Remove user from a guild
         *
         * @param object $enrty The GW2 log entry
         * @param string $guild The Guild to remove them from
         *
         * @return void
         */
        private function removeMemebr(object $entry, string $guild): void
        {
            $this->db->rawQuery("CALL remove_member('NullValue.4956', '4EC8BEAF-B669-EB11-81AC-95DFE50946EB')");
        }

        /**
         * Fetch and format the last IDs for all guilds
         *
         * @return array
         */
        private function getLastIds(): array
        {
            $this->db->groupBy('guild_id');
            $last_ids_raw = $this->db->get('log', null, ['max(api_id) as api_id', 'guild_id']);
            $last_ids = [];

            foreach ($last_ids_raw as $data) {
                $last_ids[$data['guild_id']] = $data['api_id'];
            }

            return $last_ids;
        }
    }
}
