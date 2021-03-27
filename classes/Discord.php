<?php
class Discord
{
    private static function sendToDiscord($url){
        global $config;

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url, 
            CURLOPT_HTTPHEADER     => array("Authorization: Bot {$config['discord_key']}"), 
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_SSL_VERIFYPEER => 0,
        ));

        $data = curl_exec($ch);
        $info = curl_getinfo($ch);
        return [
            'data'    => json_decode($data, true),
            'info'    => $info
        ];
    }

    public static function GetUserData(int $id)
    {
        return Discord::sendToDiscord("https://discord.com/api/users/{$id}");
    }
}