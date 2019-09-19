<?php
/**
 * This is a small Rest-API to expose data for the metagrid spider
 * We try to respect the setting of the cms, but we try to avoid the cms itself.
 *
 * In general you can get infos about each person in the humo-gen system
 */
define("CMS_ROOTPATH", '');

/**
 * API key to restrict access. If empty the api is open for everybody
 */
define("API_KEY", '');

/**
 * This class handles request and response to the api
 * Class Response
 */
class Response {

    /**
     * Handle request to the API and init humo_gen
     * @param array $get
     */
    static function handleRequest(array $get) {
        // get data from the env
        $start = (int) $get['start'];
        // maximal 50000
        $limit = (int) min($get['limit'], 5000);
        $prefix = (string) substr($get['tree'], 0, 20);

        // check key
        $key = (string) substr($get['api-key'], 0, 40);
        if(!empty(API_KEY)) {
            if($key !== API_KEY) {
                Response::unauthorized("Not authorized. Please provide a valid API-key.");
            }
        }

        // init humo-gen with ome global defaults
        global $dbh, $user, $humo_option;
        $user = $humo_option = [];
        include_once(CMS_ROOTPATH . "include/db_login.php"); //Inloggen database.
        include_once(CMS_ROOTPATH . "include/settings_global.php"); //Variables
        include_once(CMS_ROOTPATH . "include/settings_user.php"); // USER variables
        include_once(CMS_ROOTPATH . "include/db_functions_cls.php");

        // get tree from helper function
        $db_functions = New db_functions;
        $tree = $db_functions->get_tree($prefix);
        // Excludes private trees
        $hide_tree_array = explode(";", $user['group_hide_trees']);
        if (in_array($tree->tree_id, $hide_tree_array)) {
            Response::badRequest("The tree doesn't exist.");
        }

        // init API
        $api = new API($dbh, $user, $humo_option);
        try {
            self::ok($api->getPeronList($tree->tree_id, $tree->tree_prefix, $start, $limit));
        } catch (\Exception $exception) {
            self::serverError();
        }
    }

    /**
     * Send ok status
     * @param array $data
     */
    static function ok(array $data = []) {
        header ('Content-type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    /**
     * Send bad request status
     * @param string $msg
     */
    static function badRequest(string $msg) {
        header("HTTP/1.1 401 Unauthorized");
        header ('Content-type: application/json; charset=utf-8');
        self::sendMsg($msg);
        exit;
    }

    /**
     * print a message in the response body
     * @param string $msg
     * @param string $type
     */
    static private function sendMsg(string $msg, string $type = 'error'): void {
        echo json_encode([$type => $msg]);
    }

    /**
     * Send unauthorized response
     * @param $msg string
     */
    static function unauthorized(string $msg): void {
        header("HTTP/1.1 401 Unauthorized");
        header ('Content-type: application/json; charset=utf-8');
        self::sendMsg($msg);
        exit;
    }

    /**
     * Send server error
     */
    static function serverError(): void {
        header("HTTP/1.1 500 Internal Server Error");
        exit;
    }
}


class API {
    /**
     * @var PDO
     */
    private $db;

    /**
     * Config for users from humo
     * @var array
     */
    private $user = [];

    /**
     * Humo options from the cms
     * @var array
     */
    private $humoOption = [];

    /**
     * Min length of names
     * @var int
     */
    private $minLength = 3;

    /**
     * API constructor.
     * @param PDO $db
     * @param $user
     * @param $humoOption
     */
    public function __construct(PDO $db, $user, $humoOption)
    {
        $this->db = $db;
        $this->user = $user;
        $this->humoOption = $humoOption;
    }

    /**
     * Fetch data from the DB
     * @param int $start
     * @param int $limit
     * @param int $tree
     * @return false|PDOStatement
     */
    function getPersons(int $start, int $limit, int $tree ) {
        $query = "SELECT pers_indexnr, pers_gedcomnumber, pers_own_code, ".
                    "pers_firstname, pers_prefix, pers_lastname, pers_patronym, ".
                    "pers_birth_date, pers_death_date, pers_text ".
                    "FROM humo_persons ".
			        "WHERE pers_tree_id=" . $tree ." ".
                    "AND  CHAR_LENGTH(pers_firstname) > ".$this->minLength. " ".
                    "AND CHAR_LENGTH(pers_lastname) > ".$this->minLength. " " .
                    "Limit $limit OFFSET $start";
        return $this->db->query($query);
    }

    /**
     * Get a collection of persons
     * @param int $tree
     * @param string $treePrefix
     * @param int $start
     * @param int $limit
     * @return array
     * @throws Exception
     */
    function getPeronList(int $tree, string $treePrefix, int $start = 0, int $limit = 100): array {
        $data = [];
        $persons = $this->getPersons($start, $limit, $tree)->fetchAll(PDO::FETCH_OBJ);
        foreach($persons as $person) {
            // *** Completely filter person ***
            if ($this->user["group_pers_hide_totally_act"] == 'j'
                AND strpos(' ' . $person->pers_own_code, $this->user["group_pers_hide_totally"]) > 0) {
                continue;
            } else {
                $tmpData['first_name'] = trim(implode(" ", [$person->pers_firstname, $person->pers_patronym]));
                $tmpData['last_name'] = trim(implode(" ",[str_replace("_","", $person->pers_prefix), $person->pers_lastname]));
                $tmpData['birth_date'] = $person->pers_birth_date;
                $tmpData['death_date'] = $person->pers_death_date;
                $tmpData['url'] = $this->getUrl($person->pers_indexnr, $person->pers_gedcomnumber, $treePrefix);
                $tmpData['links'] = $this->extractUrls($person->pers_text);
                $data[] = $tmpData;
            }
        }
        return $data;
    }

    /**
     * Generate a uri for a specific person
     * Copied from humo-gen
     * @param string $familyId
     * @param string $persId
     * @param string $treePrefix
     * @return string
     */
    function getUrl(string $familyId, string $persId, string $treePrefix): string {
        $position = strrpos($_SERVER['PHP_SELF'], '/');
        $uri_path = substr($_SERVER['PHP_SELF'], 0, $position);
        if ($this->humoOption["url_rewrite"] == "j") {
            return $uri_path . '/family/' . $treePrefix . '/' . $familyId . '/' . $persId . '/';
        } else {
            return $uri_path . '/family.php?database=' . $treePrefix . '&id=' . $familyId . '&main_person=' . $persId;
        }
    }

    /**
     * Return an array of extracted url in a text
     * @param string $text
     * @return array
     */
    function extractUrls(string $text) {
        if(preg_match_all('/\b(?:(?:https?|ftp):\/\/|www\.)[-a-z0-9+&@#\/%?=~_|!:,.;]*[-a-z0-9+&@#\/%=~_|]/i',$text,$matches)) {
            return $matches[0];
        }
        return [];
    }
}

// init Response
Response::handleRequest($_GET);