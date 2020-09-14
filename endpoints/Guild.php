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
    public function allLogs($guild = null)
    {
        $type = $_GET['type'] ?? null;
        $page = $_GET['page'] ?? 1;
        $limit = $_GET['limit'] ?? 20;
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
        if ($log) {
            header("X-Page-Size: {$limit}");
            header("X-Result-Count: {$this->db->count}");
            header("X-Page-Total: {$this->db->totalPages}");
            header("X-Result-Total: {$this->db->totalCount}");
            return $log;
        } else {
            throw new RestException(400, "Great! Ya blew it!");
        }

    }

    /**
     * Get guild info
     *
     * @url GET /$id
     * @noAuth
     */
    public function guild($id)
    {
        if (empty($id)) {
            throw new RestException(400, "Missing guild ID");
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
    public function members($id)
    {
        if (empty($id)) {
            throw new RestException(400, "Missing guild ID");
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
            $this->parseLogs();
        }

        /**
         * Gather the logs from each guild and call the proper method to
         * generate a generic log message and save it based on the `$entry->type`
         *
         * @return void
         */
        private function parseLogs()
        {
            foreach ($this->config['guilds'] as $guild) {

                CronTask::Log("Getting logs for {$guild['name']}");

                // Fetch last log ID
                $last_id = $this->getLastId($guild);
                // Fetch the logs from API
                $log = $this->api->guild()->logOf($guild['api_key'], $guild['guild_id'])->since($last_id);

                // If log is empty, nothing new, move on
                if (empty($log)) {
                    continue;
                }

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
                    break;

                case 'kick':
                    if ($entry->user == $entry->kicked_by) {
                        //User left the guild (kicked themselves)
                        $message = "{$entry->user} has left the guild";
                    } else {
                        //User was kicked from the guild
                        $message = "{$entry->user} was kicked by {$entry->kicked_by}";
                    }
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
                CronTask::Log("Error Saving log entry: {$raw}");
            }
        }

        /**
         * Get last log ID from the database
         *
         * @since 1.0
         *
         * @param array $guild
         *
         * @return int The last ID in the DB or 0 if nothing found
         */
        private function getLastId(array $guild): int
        {
            $this->db->where('guild_id', $guild['guild_id']);
            $last_id = $this->db->getValue('log', 'max(api_id)');

            if (is_null($last_id)) {
                $last_id = 0;
            }

            return $last_id;
        }
    }
}
