<?php

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
        $this->db = new MysqliDb($config['db']['host'], $config['db']['username'], $config['db']['password'], $config['db']['database']);

        //Create GW2 API client
        $this->api = new \GW2Treasures\GW2Api\GW2Api();

        //Redis Cache
        global $cache;
        $this->cache = $cache;
    }

    /**
     * Get lottery status
     *
     * @url GET /status
     * @noAuth
     */
    public function getSatatus()
    {
        if (Date("l") == "Wednesday" && (Date("G") >= 0 && Date("G") < 3)) {
            return "Lottery in progress";
        } else {
            return new DateTime("Next Wednesday 3:00", );
        }
    }

    /**
     * Get the pot for the current weeks lottery
     *
     * @url GET /pot
     * @noAuth
     */
    public function getPot()
    {
        $pot = $this->_getLotteryPot();

        if ($pot) {
            return [
                'pot' => (int) $pot,
                'first' => round($pot * 0.25),
                'second' => round($pot * 0.25),
                'third' => round($pot * 0.25),
                'guild' => round($pot * 0.25),
            ];
        } else {
            return [
                'pot' => 0,
                'first' => 0,
                'second' => 0,
                'third' => 0,
                'guild' => 0,
            ];
        }
    }

    /**
     * Generate pot image
     *
     * @url GET /pot/image
     * @noAuth
     */

    public function getImage()
    {
        header("Content-type: image/jpeg");

        $pot = round(($this->_getLotteryPot() * 0.25)/10000);
        $im = imagecreatefromjpeg("public/images/gold.jpg");
        $orange = imagecolorallocate($im, 210, 200, 60);

        imagettftext($im, 72, 0, 50, 100, $orange, "public/fonts/gw2chat.ttf", $pot);
        imagettftext($im, 24, 0, 50, 134, $orange, "public/fonts/gw2chat.ttf", "Gold Each");
        
        imagejpeg($im);
        imagedestroy($im);
    }

    /**
     * Return entries for a specific account
     *
     * @url GET /$account/entries
     * @noAuth
     */
    public function listEntries(string $account)
    {
        $currentLotteryStart = date("Y-m-d H:i:s", strtotime("Last Wednesday"));

        $this->db->where('user', $account);
        $this->db->where('time', $currentLotteryStart, '>=');
        $this->db->orderBy('time', 'DESC');
        $result = $this->db->getValue('lottery_entries', 'sum(coins)');

        if ($result) {

            $tickets = floor($result / 10000);
            if ($tickets > 10) {
                $tickets = 10;
            }

            return ['tickets' => $tickets];

            //seperate current lottery entries
            // $current_lottery = array_filter($result, function ($entry) {
            //     $time = new DateTime($entry['time']);
            //     $currentLottery = new DateTime("Last Wednesday");
            //     return $time >= $currentLottery;
            // });
            // return [
            //     'current_lottery' => $current_lottery,
            //     'history' => $result,
            // ];
        } else {
            //return empty array
            return ['tickets' => 0];

            // return [
            //     'current_lottery' => [],
            //     'history' => [],
            // ];
        }
    }

    /**
     * Return entries for a specific account
     * for use on https://tinyarmy.org/lottery/
     * for now.
     * 
     * @url GET /listEntries/$account
     * @noAuth
     */
    public function listEntriesOld(string $account)
    {
        global $db;

        $db->where('user', $account);
        $db->orderBy('time', 'desc');
        $result = $db->get('lottery_entries', null, 'id as lottery_id, api_id as log_id, time, user, coins, guild_id as guild');
        return $result;
        //$result = $this->conn->query( "SELECT * FROM `{$this->config['db_table']}` WHERE `user`='{$account}' ORDER BY `time` DESC" )->fetch_all( MYSQLI_ASSOC );
        
    }

    private function _getLotteryPot()
    {
        //check if it is Wednesday
        if (date('N') == 3) {
            $lotteryStart = date("Y-m-d H:i:s", strtotime("Today Midnight"));
        } else {
            $lotteryStart = date("Y-m-d H:i:s", strtotime("Last Wednesday"));
        }
        $this->db->where('time', $lotteryStart, '>=');
        return $this->db->getValue('lottery_entries', 'sum(coins)');
    }
}

if (interface_exists('ICronTask')) {
    class LotteryCron implements ICronTask
    {

        public function run($config, $db, $cache, $gw2api)
        {
            CronTask::Log("==Starting CronTask==");
            foreach ($config['guilds'] as $guild) {
                CronTask::Log("Checking for entries in {$guild['name']}");
                // Get last ID in the DB
                $db->where('guild_id', $guild['guild_id']);
                $last_id = $db->getValue('lottery_entries', 'max(api_id)');

                if (is_null($last_id)) {
                    $last_id = 0;
                }

                // Call the GW2 API and fetch the log
                $log = $gw2api->guild()->logOf($guild['api_key'], $guild['guild_id'])->since($last_id);

                // If the API call failed, or empty log. just move on
                if (empty($log)) {
                    continue;
                }

                foreach ($log as $entry) {
                    if ($entry->type == 'stash' && $entry->operation == 'deposit' && $entry->coins > 0) {
                        $ticket = [
                            'api_id' => $entry->id,
                            'time' => $db->func('STR_TO_DATE(?, ?)', [$entry->time, '%Y-%m-%dT%H:%i:%s.000Z']),
                            'user' => $entry->user,
                            'coins' => $entry->coins,
                            'guild_id' => $guild['guild_id'],
                        ];

                        $id = $db->insert('lottery_entries', $ticket);
                        if ($id) {
                            CronTask::Log("Ticket was created. ID={$id}");
                        } else {
                            CronTask::Log("Ticket creation failed: " . $db->getLastError());
                        }
                    }
                }
            }
        }
    }
}
