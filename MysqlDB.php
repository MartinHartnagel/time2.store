<?php
require_once __DIR__ . "/PdoDB.php";

class MysqlDB extends PdoDB
{
    public function __construct($id)
    {
        $this->prefix = $id . '_';
        $this->db = new PDO('mysql:host=' . DB_HOST . ':' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASSWORD);

        if (DB_ALLOW_CREATE_TABLES) {
            $exists = true;
            try {
                $result = $this->db->query('SELECT 1 FROM `' . $this->prefix . 'LAYOUT` LIMIT 1');
            } catch (Exception $e) {
                $exists = false;
            }
            if ($$exists) {
                $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $this->prefix . 'LAYOUT (time bigint NOT NULL UNIQUE, value text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL);');
                $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $this->prefix . 'EVENT (time bigint NOT NULL UNIQUE, name varchar(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, color varchar(7) NOT NULL, end bigint);');
                $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $this->prefix . 'INFO (time bigint NOT NULL UNIQUE, info text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL);');
                $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $this->prefix . 'INVOICE (`key` varchar(256) NOT NULL UNIQUE, `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL);');
                $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $this->prefix . 'NOTE (`key` varchar(256) NOT NULL UNIQUE, `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL);');
            }
        }
    }

    public function loadEventDays(&$days)
    {
        $days = [];
        // Events
        $stmt = $this->db->prepare('SELECT DISTINCT DATE_FORMAT(CONVERT_TZ(FROM_UNIXTIME(ROUND(`time` / 1000)), \'+00:00\', @@session.time_zone), \'%Y-%m-%d\') AS `day` FROM `' . $this->prefix . 'EVENT` ORDER BY `time` ASC');
        $stmt->execute([]);
        while ($data = $stmt->fetch()) {
            $days[] = $data['day'];
        }
        //Infos
        $stmt = $this->db->prepare('SELECT DISTINCT DATE_FORMAT(CONVERT_TZ(FROM_UNIXTIME(ROUND(`time` / 1000)), \'+00:00\', @@session.time_zone), \'%Y-%m-%d\') AS `day` FROM `' . $this->prefix . 'INFO` ORDER BY `time` ASC');
        $stmt->execute([]);
        while ($data = $stmt->fetch()) {
            if (!in_array($data['day'], $days)) {
                $days[] = $data['day'];
            }
        }
        sort($days);
    }


    public function loadInvoiceChecksums(&$checksums)
    {
        $stmt = $this->db->prepare('SELECT `key`, SHA2(`value`, 256) as  `checksum` FROM `' . $this->prefix . 'INVOICE` WHERE `key` like \'invoice_%\'');
        $stmt->execute([]);
        $checksums = [];
        while ($data = $stmt->fetch()) {
            $checksums[$data['key']] = $data['checksum'];
        }
    }

    public function loadNoteChecksums(&$checksums)
    {
        $stmt = $this->db->prepare('SELECT `key`, SHA2(`value`, 256) as  `checksum` FROM `' . $this->prefix . 'NOTE` WHERE `key` like \'note_%\'');
        $stmt->execute([]);
        $checksums = [];
        while ($data = $stmt->fetch()) {
            $checksums[$data['key']] = $data['checksum'];
        }
    }

}