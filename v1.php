<?php
spl_autoload_register(); // don't load our classes unless we use them

//API Key to be used for guild tasks
define("API_KEY", "59766592-73DB-2341-8DF4-4CE068F17012E880837A-C8FA-4DCF-9D9D-DB52D2D8F67E");

date_default_timezone_set('UTC');

require_once 'vendor/autoload.php';
require_once 'classes/AuthServer.php';

//Require all endpoints
foreach (glob("endpoints/*.php") as $endpoint) {
    require_once $endpoint;
}

//Load settings
require_once "config.php";

//Connect to MySql
try {
    $db = new MysqliDb($config['db']);
    //$db = new MysqliDb($config['db']['host'], $config['db']['username'], $config['db']['password'], $config['db']['database']);
} catch (\Exception $e) {
    die($e->getMessage());
}

//Create GW2 API client
$api = new \GW2Treasures\GW2Api\GW2Api();

$mode = 'debug'; // 'debug' or 'production'
$server = new \Jacwright\RestServer\RestServer($mode);
$server->authHandler = new AuthServer();
//$server->refreshCache(); // uncomment momentarily to clear the cache if classes change in production mode
$server->useCors = true;

//Setup Redis Cache
//$client = new Predis\Client();

$server->addClass('AuthController', '/v1/auth');
$server->addClass('GuildController', '/v1/guild');
$server->addClass('MemberController', '/v1/members'); 
$server->addClass('LotteryController', '/v1/lottery');

$server->handle();
