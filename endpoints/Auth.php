<?php

use \Firebase\JWT\JWT;
use \Jacwright\RestServer\RestException;

class AuthController
{
    private $key = "e832ea47-75ae-45c3-b5c3-2c4df5babb91";

    private $cache;

    private $rank = [
        0 => 'Member',
        1 => 'Tiny Officer',
        2 => 'Tiny General',
        3 => 'Tiny Leader',
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

        global $api;
        global $db;

        // Try and fetch the account
        try {
            $api_account = $api->account($data->token)->get();
        } catch (AuthenticationException $exception) {
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

        $payload = $this->make_payload(
            [
                "name" => substr($user['account'], -5),
                "rank" => $this->rank[$user['access']],
            ]
        );
        $refresh = $this->make_guid();

        $this->cache->setex("refresh_tokens:{$refresh}", 86400, serialize($payload));

        setcookie("refresh_token", $refresh, 0, "/", "api.tinyarmy.org", true, true);
        return [
            'token' => JWT::encode($payload, $this->key),
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
        if (empty($_COOKIE['refresh_token'])) {
            throw new RestException(400);
        }

        $result = $this->cache->get("refresh_tokens:{$_COOKIE['refresh_token']}");
        if ($result) {
            $payload = unserialize($result);
            $refresh = $this->make_guid();

            $this->cache->del("refresh_tokens:{$_COOKIE['refresh_token']}");
            $this->cache->setex("refresh_tokens:{$refresh}", 86400, serialize($payload));

            setcookie("refresh_token", $refresh, 0, "/", "api.tinyarmy.org", true, true);
            return JWT::encode($payload, $this->key);
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
            "nbf" => $time + 10,
            "exp" => $time + 60,
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
