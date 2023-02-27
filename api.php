<?php

// don't load our classes unless we use them
spl_autoload_register();

// Set Timezone
date_default_timezone_set('UTC');

// Allowed version numbers
const VERSIONS = ["v1", "v2"];

// API Key to be used for guild tasks
const API_KEY = "59766592-73DB-2341-8DF4-4CE068F17012E880837A-C8FA-4DCF-9D9D-DB52D2D8F67E";

require_once 'vendor/autoload.php';
require_once 'classes/AuthServer.php';
require_once "config.php";

// Figure out API Version 
$version = "";
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = explode('/', $uri);
if (in_array($uri[1], VERSIONS)) {
    $version = $uri[0];
} else {
    die("Invalid API Version Number.");
}

// Require all endpoints
foreach (VERSIONS as $version) {
    foreach (glob("endpoints/{$version}/*.php") as $endpoint) {
        require_once $endpoint;
    }
}

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

// V1
$server->addClass('AuthController_V1', '/v1/auth');
$server->addClass('GuildController_V1', '/v1/guild');
$server->addClass('SearchController_V1', '/v1/search');
$server->addClass('MemberController_V1', '/v1/members');
$server->addClass('LotteryController_V1', '/v1/lottery');

// V2
$server->addClass('AuthController_V2', '/v2/auth');
$server->addClass('GuildController_V2', '/v2/guild');
$server->addClass('SearchController_V2', '/v2/search');
$server->addClass('MemberController_V2', '/v2/members');
$server->addClass('LotteryController_V2', '/v2/lottery');

$server->handle();
