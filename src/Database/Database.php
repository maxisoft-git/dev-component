<?php

namespace MaxiSoft\Database;

class Database
{
    private static $instance = null;

    private function __construct()
    {
    }

    public static function init()
    {
        if (!self::$instance) {

            $cfg = \Config::getInstance();

            self::$instance = new dbPDO("mysql:host=" . $cfg->db_host . ";dbname=" . $cfg->db_base, $cfg->db_user, $cfg->db_pass);
        }

        return self::$instance;
    }

    private function __clone()
    {
    }
}
