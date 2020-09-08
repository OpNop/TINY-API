<?php

use \Jacwright\RestServer\RestException;
use \Firebase\JWT\JWT;

class AuthController
{
    private $key = "e832ea47-75ae-45c3-b5c3-2c4df5babb91";

    private $cache;

    public function __construct()
    {
        $this->cache = new Predis\Client();
    }

    /**
     * Login using API key
     * 
     * @url POST /login
     * @noAuth
     */
    public function login( $data )
    {die("wtf");
        if( empty( $data ) || empty( $data->login ) ) {
            throw new RestException( 400 );
        }
        

        if( $data->login == "tinytiny-t1ny-t1ny-t1ny-tinytinytiny") {
            
            $payload = $this->make_payload( ["name" => "Tiny Tester", "rank" => "Tiny Leader"]);
            $refresh = $this->make_guid();

            //$this->cache->set("refresh_tokens:1:{$refresh}", $payload, 86400 );
            
            //setcookie("refresh_token", $refresh, 0, "/", "api.tinyarmy.org", true, true );
            //return JWT::encode($payload, $this->key);
        }
        else {
            throw new RestException( 401 );
        }
    }

    /**
     * Refresh JWT Token
     * 
     * @url POST /refresh_token
     * @noAuth
     */
    public function refresh_token( )
    {
        if( empty( $_COOKIE['refresh_token'] ) ) {
            throw new RestException( 400 );
        }

        $result = $this->cache->get($_COOKIE['refresh_token']);
        if( $result ) {
            //TODO:: pull from JWT token
            $payload = $this->make_payload( ["name" => "Tiny Tester", "rank" => "Tiny Leader"] );
            $refresh = $this->make_guid();

            //$this->cache->set("refresh_tokens:{$refresh}", $payload, 86400 );

            //setcookie("refresh_token", $refresh, 0, "/", "api.tinyarmy.org", true, true );
            return JWT::encode($payload, $this->key);
        } else {
            throw new RestException( 400 );
        }
    }

    private function make_payload( array $data ): array
    {
        $time = time();
        $payload = [
            "iss"   => "https://api.tinyarmy.org/",
            "iat"   => $time,
            "nbf"   => $time + 10,
            "exp"   => $time + 60,
            "data"  => $data
        ];
        return $payload;
    }

    private function make_guid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);    // Set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);    // Set bits 6-7 to 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}