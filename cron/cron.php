<?php

//Make sure this is run as a cron or manually by setting ?doing_cron to anything
// if( false === isset( $_GET['doing_cron'] ) ) {
//     die();
// }

date_default_timezone_set('UTC');

//Load classes
require_once '../vendor/autoload.php';

//Require all endpoints
foreach(glob("../endpoints/v1/*.php") as $endpoint){
    require_once $endpoint;
}

//Load settings
require_once "../config.php";

//Open log file
$logFile = fopen('log.log', 'a');

class CronTask 
{
    private $db;

    private $cache;

    private $config;

    private $gw2api;

    private $cronTasks;

    public function __construct()
    {
        global $config;
        $this->config = $config;

        //Connect to MySQL
        //$this->db = new MysqliDb($config['db']['host'], $config['db']['username'], $config['db']['password'], $config['db']['database']);
        $this->db = new MysqliDb($config['db']);

        //Connect to Redis
        $this->cache = new Predis\Client();

        //Setup API
        $this->gw2api = new \GW2Treasures\GW2Api\GW2Api();

        //Check if API is up 
        try{
            $this->gw2api->build()->get();
        } catch (Exception $e) {
            CronTask::Log("API Error");
            return false;
        }

        //Find CronTasks
        $this->cronTasks = $this->_findCronTasks();

        //Start Tasks
        $this->_runCronTasks();
    }

    private function _runCronTasks()
    {
        if( is_array( $this->cronTasks ) ) {
            foreach( $this->cronTasks as $cronTask ) {
                $task = new $cronTask;
                $task->run( $this->config, $this->db, $this->cache, $this->gw2api );
            }
        } else {
            echo "No tasks found";
        }
    }

    private function _findCronTasks() : ?array
    {
        if (interface_exists("ICronTask")) {
            return array_filter(get_declared_classes(), function($className) { return in_array("ICronTask", class_implements("$className"));});
        }
        else {
            return null;
        }
    }

    //Hmm, this feels strange but meh?
    public static function Log($message)
    {
        $now = date("Y-m-d H:i:s");
        $trace = debug_backtrace();
        
        if (isset($trace[1])) {
            //Write to log gile
            global $logFile;
            fwrite($logFile, "{$now} - {$trace[1]['class']}::{$trace[1]['function']}: {$message}\n");

            if( isset( $_GET['output'] ) ){
                echo( "{$now} - <strong>{$trace[1]['class']}::{$trace[1]['function']}:</strong> {$message}<br>" );
            }
        }
    }
}

interface ICronTask
{
    public function run ( $config, $db, $cache, $gw2api );
}

new CronTask();