<?php

namespace Travel\Config;

use PDO;
use PDOException;

class Database {
    private $host;
    private $port;
    private $dbname;
    private $user;
    private $password;
    private $sslmode;
    public $conn;

    public function __construct() {
        $this->host = getenv('DB_HOST');
        $this->port = getenv('DB_PORT');
        $this->dbname = getenv('DB_NAME');
        $this->user = getenv('DB_USER');
        $this->password = getenv('DB_PASSWORD');
        $this->sslmode = getenv('DB_SSLMODE');
    }

    public function connect() {
        $this->conn = null;
        
        $dsn = "pgsql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->dbname . ";sslmode=" . $this->sslmode;

        try {
            $this->conn = new PDO($dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (PDOException $e) {
            // Log error instead of leaking it 
            error_log("Connection Error: " . $e->getMessage());
            die(json_encode([
                "success" => false,
                "error" => "Service unavailable (Database Connection)"
            ]));
        }

        return $this->conn;
    }
}
