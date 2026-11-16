<?php
require_once __DIR__ . '/db_credentials.php';

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $charset;
    public $conn;

    public function __construct() {
        $db = db_credentials('intersig');
        $this->host = $db['host'];
        $this->db_name = $db['name'];
        $this->username = $db['user'];
        $this->password = $db['pass'];
        $this->charset = isset($db['charset']) ? $db['charset'] : 'utf8';
    }

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset, $this->username, $this->password);
            $this->conn->exec("set names " . $this->charset);
        } catch(PDOException $exception) {
            echo "Erro de conexão: " . $exception->getMessage();
        }
        return $this->conn;
    }
}
?>
