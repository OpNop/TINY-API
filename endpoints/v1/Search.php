<?php

class SearchController_V1
{
    public function __construct()
    {

    }

    /**
     * Search all the things
     *
     * @url GET /
     */
    public function SearchAll()
    {
        $q = $_GET['q'] ?? null;

        //No results for nothing
        if (is_null($q)) {
            return [];
        }

        global $db;

        //lead each word with a +
        $q = preg_replace('/\b(\w)/', '+$1', $q);

        $results = $db->rawQuery("CALL sp_members_search(?)", array($q));
        return $results;
    }

}
