<?php
require_once __DIR__ . "/PdoDB.php";

class SqliteDB extends PdoDB
{
    public function __construct($id)
    {
        $this->db = new \PDO("sqlite:" . __DIR__ . "/" . $id . ".db");

        $sql = <<<EOF
CREATE TABLE IF NOT EXISTS LAYOUT (time int NOT NULL UNIQUE, value text NOT NULL);
CREATE TABLE IF NOT EXISTS EVENT (time int NOT NULL UNIQUE, name varchar(256) NOT NULL, color varchar(7) NOT NULL, end int);
CREATE TABLE IF NOT EXISTS INFO (time int NOT NULL UNIQUE, info text NOT NULL);
CREATE TABLE IF NOT EXISTS INVOICE (`key` varchar(256) NOT NULL UNIQUE, `value` text NOT NULL);
CREATE TABLE IF NOT EXISTS NOTE (`key` varchar(256) NOT NULL UNIQUE, `value` text NOT NULL);
EOF;

        $ret = $this->db->exec($sql);
        if ($ret === false) {
            echo "PDO::errorCode(): ", $this->db->errorCode();
            print_r($this->db->errorInfo());
            exit(9);
        }
    }

    public function loadEventDays(&$days)
    {
        $days = [];
        // Events
        $stmt = $this->db->prepare(
            'SELECT DISTINCT DATE(ROUND(`time` / 1000), \'unixepoch\', \'localtime\') AS `day` FROM `EVENT` ORDER BY `time` ASC'
        );
        $stmt->execute([]);
        while ($data = $stmt->fetch()) {
            $days[] = $data["day"];
        }
        //Infos
        $stmt = $this->db->prepare(
            'SELECT DISTINCT DATE(ROUND(`time` / 1000), \'unixepoch\', \'localtime\') AS `day` FROM `INFO` ORDER BY `time` ASC'
        );
        $stmt->execute([]);
        while ($data = $stmt->fetch()) {
            if (!in_array($data["day"], $days)) {
                $days[] = $data["day"];
            }
        }
        sort($days);
    }
    public function loadInvoiceChecksums(&$checksums)
    {
        $stmt = $this->db->prepare(
            'SELECT `key`, `value` FROM `INVOICE` WHERE `key` like \'invoice_%\''
        );
        $stmt->execute([]);
        $checksums = [];
        while ($data = $stmt->fetch()) {
            $checksums[$data["key"]] = hash("sha256", $data["value"]);
        }
    }

    public function loadNoteChecksums(&$checksums)
    {
        $stmt = $this->db->prepare(
            'SELECT `key`, `value` FROM `NOTE` WHERE `key` like \'note_%\''
        );
        $stmt->execute([]);
        $checksums = [];
        while ($data = $stmt->fetch()) {
            $checksums[$data["key"]] = hash("sha256", $data["value"]);
        }
    }

}
