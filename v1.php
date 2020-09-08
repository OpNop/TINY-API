<?php 
spl_autoload_register(); // don't load our classes unless we use them

//API Key to be used for guild tasks
define("API_KEY", "2744A09B-A3E7-FA4C-B8F2-0615439E41AABE306789-C698-4521-B403-44DEF9D1A25E");

date_default_timezone_set('UTC');

require_once 'vendor/autoload.php';
require_once 'classes/AuthServer.php';

//Require all endpoints
foreach(glob("endpoints/*.php") as $endpoint){
    require_once $endpoint;
}

//Load settings
require_once "config.php";

//Connect to MySql
$client = new mysqli($config['db']['host'], $config['db']['username'], $config['db']['password'], $config['db']['database']);
if( $client->connect_error ) {
    die("Connection failed: " . $client->connect_error);
}

$mode = 'debug'; // 'debug' or 'production'
$server = new \Jacwright\RestServer\RestServer($mode);
$server->authHandler = new AuthServer();
$server->refreshCache(); // uncomment momentarily to clear the cache if classes change in production mode

//Setup Redis Cache
//$client = new Predis\Client();

class TestController {
    /**
     * Test Endpoint
     * 
     * @url GET /ping
     * @noAuth
     */
    public function ping() {
        return "PONG";
    }
}

$server->addClass('TestController',     '/v1');
$server->addClass('AuthController',     '/v1/auth');
$server->addClass('GuildController',    '/v1/guild');
$server->addClass('LotteryController',  '/v1/lottery');

$server->handle();