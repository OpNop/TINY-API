<?php

use \Firebase\JWT\JWT;
use \Jacwright\RestServer\RestException;

class AuthServer implements \Jacwright\RestServer\AuthServer
{

    public function isAuthenticated($classObj)
    {
        global $config;
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        if (!$auth) {
            return false;
        }

        //Try for Discord bot token
        if ($auth == $config['bot_key']) {
            return true;
        }

        //Try for JWT Token
        try {
            $token = JWT::decode($auth, $config['jwt_key'], array('HS256'));
        } catch (Firebase\JWT\ExpiredException $ex) {
            return false;
        }
        return true;
    }

    public function unauthenticated($path)
    {
        //header("WWW-Authenticate: Basic realm=\"$this->realm\"");
        //return print_r($_SERVER, true);
        throw new RestException(401, "Invalid credentials, access is denied to $path.");
    }

    public function isAuthorized($classObj, $method)
    {
        return true;
    }

    public function unauthorized($path)
    {
        throw new RestException(403, "You are not authorized to access $path.");
    }
}
