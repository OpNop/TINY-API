<?php

use \Firebase\JWT\JWT;
use \Jacwright\RestServer\RestException;

class AuthController_V2
{
    private $cache;

    private $rank = [
        0 => 'Member',
        1 => 'Tiny Officer',
        2 => 'Tiny General',
        3 => 'Tiny Leader',
    ];

    private $cookie_options = [
        'expires'   => 0,
        'path'      => '/',
        'domain'    => null,
        'secure'    => true,
        'httponly'  => true,
        'samesite' => 'None'
    ];

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
    public function login($data)
    {
        if (empty($data) || empty($data->token)) {
            throw new RestException(400);
        }

        global $api, $config, $db;

        // Try and fetch the account
        try {
            $api_account = $api->account($data->token)->get();
        } catch ( \Exception $exception) {
            throw new RestException(401);
            die();
        }

        // Check if the account has access
        $db->where('account', $api_account->name);
        $user = $db->withTotalCount()->getOne('members');

        // No Account found
        if ($db->totalCount == 0) {
            throw new RestException(401);
            die();
        }

        // No Access
        if ($user['access'] == 0) {
            throw new RestException(401);
            die();
        }

        $token_data = [
            "account" => $user['account'],
            "name" => substr($user['account'], 0, -5),
            "rank" => $this->rank[$user['access']],
        ];

        $payload = $this->make_payload($token_data);
        $refresh = $this->make_guid();

        $this->cache->setex("refresh_tokens:{$refresh}", 86400, serialize($token_data));

        setcookie("refresh_token", $refresh, $this->cookie_options);
        return [
            'token' => JWT::encode($payload, $config['jwt_key']),
            'user' => substr($user['account'], 0, -5),
        ];

    }

    /**
     * Refresh JWT Token
     *
     * @url POST /refresh_token
     * @noAuth
     */
    public function refresh_token()
    {
        global $config;
        
        if (empty($_COOKIE['refresh_token'])) {
            throw new RestException(400);
        }

        $result = $this->cache->get("refresh_tokens:{$_COOKIE['refresh_token']}");
        if ($result) {
            $token_data = unserialize($result);
            $payload = $this->make_payload($token_data);
            $refresh = $this->make_guid();

            $this->cache->del("refresh_tokens:{$_COOKIE['refresh_token']}");
            $this->cache->setex("refresh_tokens:{$refresh}", 86400, serialize($token_data));

            setcookie("refresh_token", $refresh, $this->cookie_options);
            return [
                'token' => JWT::encode($payload, $config['jwt_key']),
                'user' => $token_data['name'],
            ];
        } else {
            throw new RestException(401);
        }
    }

    private function make_payload(array $data): array
    {
        $time = time();
        $payload = [
            "iss" => "https://api.tinyarmy.org/",
            "iat" => $time,
            "nbf" => $time - 10,
            "exp" => $time + 3600,
            "data" => $data,
        ];
        return $payload;
    }

    private function make_guid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Set bits 6-7 to 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
