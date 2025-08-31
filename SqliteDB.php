<?php

class SqliteDB
{
    public $db;

    public function __construct($id)
    {
        $this->db = new \PDO("sqlite:" . __DIR__ . "/" . $id . ".db");

        $sql = <<<EOF
CREATE TABLE IF NOT EXISTS LAYOUT (time int NOT NULL UNIQUE, value text NOT NULL);
CREATE TABLE IF NOT EXISTS EVENT (time int NOT NULL UNIQUE, name varchar(256) NOT NULL, color varchar(7) NOT NULL, end int);
CREATE TABLE IF NOT EXISTS INFO (time int NOT NULL UNIQUE, info text NOT NULL);
CREATE TABLE IF NOT EXISTS INVOICE (`key` varchar(256) NOT NULL UNIQUE, `value` text NOT NULL);
EOF;

        $ret = $this->db->exec($sql);
        if ($ret === false) {
            echo "PDO::errorCode(): ", $this->db->errorCode();
            print_r($this->db->errorInfo());
            exit(9);
        }
    }

    public function loadLayoutAndChanged(&$layout, &$changed)
    {
        $stmt = $this->db->prepare(
            "SELECT `value`, `time` FROM `LAYOUT` ORDER BY `time` DESC LIMIT 1"
        );
        $stmt->execute([]);
        $data = $stmt->fetch();
        if (!$data) {
            $layout = null;
            $changed = null;
            return;
        }
        $layout = $data["value"];
        $changed = $data["time"];
    }

    public function storeLayout($at, $value)
    {
        $sql = "DELETE FROM `LAYOUT` WHERE `time` = :time";
        $statement = $this->db->prepare($sql);
        $statement->execute(["time" => $at]);

        $sql = "INSERT INTO `LAYOUT` (`time`, `value`) VALUES (?, ?)";
        $statement = $this->db->prepare($sql);
        $statement->execute([$at, $value]);
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

    public function loadEventsOnDay($day, &$events)
    {
        $es = [];

        // Events:

        $stmt = $this->db->prepare(
            "SELECT `time`, `name`, `color`, `end` FROM `EVENT` WHERE `time` >= ? AND `time` <= ? ORDER BY `time` DESC"
        );
        $stmt->execute([
            strtotime($day) * 1000,
            strtotime($day . "T23:59:59") * 1000,
        ]);

        $lastTime = "";
        while ($data = $stmt->fetch()) {
            if (strlen($data["name"]) > 0) {
                $e = $data['time'] . "\t" . $data['name'] . "\t" . $data['color'];
                if ($data['end'] != null) {
                    $e .= "\t" . $data['end'];
                } else if (strlen($lastTime) > 0) {
                    $e .= "\t" . $lastTime;
                }
                $es[$data["time"] . "e"] = $e;
                $lastTime = $data["time"];
            }
        }

        // Infos:

        $stmt = $this->db->prepare(
            "SELECT `time`, `info` FROM `INFO` WHERE `time` >= ? AND `time` <= ? ORDER BY `time` ASC"
        );
        $stmt->execute([
            strtotime($day) * 1000,
            strtotime($day . "T23:59:59") * 1000,
        ]);

        while ($data = $stmt->fetch()) {
            if (strlen($data["info"]) > 0) {
                $es[$data["time"] . "i"] =
                    $data["time"] . "\t" . $data["info"];
            }
        }

        ksort($es);
        $c = "";
        foreach ($es as $t => $e) {
            if (strlen($c) > 0) {
                $c .= "\n";
            }
            $c .= $e;
        }
        $events = trim($c);
    }

    public function storeEvent($time, $name, $color, $end)
    {
        $sql = "DELETE FROM `EVENT` WHERE `time` = :time";
        $statement = $this->db->prepare($sql);
        $statement->execute(["time" => $time]);

        if ($end == $time) {
            $sql = 'UPDATE `EVENT` SET `end` = :time WHERE `time` < :time and (`end` is null OR `end` > :time)';
            $statement = $this->db->prepare($sql);
            $statement->execute(['time' => $time]);
        } else {
            $sql = "INSERT INTO `EVENT` (`time`, `name`, `color`, `end`) VALUES (:time, :name, :color, :end)";
            $statement = $this->db->prepare($sql);
            $statement->execute([
                "time" => $time,
                "name" => $name,
                "color" => $color,
                "end" => $end,
            ]);
        }
        
    }

    public function storeInfo($time, $info)
    {
        $sql = "DELETE FROM `INFO` WHERE `time` = :time";
        $statement = $this->db->prepare($sql);
        $statement->execute(["time" => $time]);

        $sql = "INSERT INTO `INFO` (`time`, `info`) VALUES (:time, :info)";
        $statement = $this->db->prepare($sql);
        $statement->execute(["time" => $time, "info" => $info]);
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

    public function loadInvoiceValue($key)
    {
        $stmt = $this->db->prepare(
            "SELECT `value` FROM `INVOICE` WHERE `key` like :key"
        );
        $stmt->execute(["key" => $key]);
        $data = $stmt->fetch();
        if (!$data) {
            return null;
        }
        return $data["value"];
    }

    public function storeInvoice($invoiceNumber, $extracted)
    {
        $this->storeInvoiceKeyValue(
            "invoice_" . preg_replace("/[^0-9a-zA-Z\.]/", "_", $invoiceNumber),
            json_encode($extracted["invoice"])
        );
        foreach ($extracted["twigs"] as $k => $v) {
            $this->storeInvoiceKeyValue($k, $v);
        }
        foreach ($extracted["assets"] as $k => $v) {
            $this->storeInvoiceKeyValue($k, $v);
        }
    }

    private function storeInvoiceKeyValue($key, $value)
    {
        $this->deleteInvoiceKeyValue($key);
        $sql = "INSERT INTO `INVOICE` (`key`, `value`) VALUES (:key, :value)";
        $statement = $this->db->prepare($sql);
        $statement->execute(["key" => $key, "value" => $value]);
    }

    public function deleteInvoice($invoiceNumber)
    {
        $key =
            "invoice_" . preg_replace("/[^0-9a-zA-Z\.]/", "_", $invoiceNumber);
        $this->deleteInvoiceKeyValue($key);
    }

    private function deleteInvoiceKeyValue($key)
    {
        $sql = "DELETE FROM `INVOICE` WHERE `key` = :key";
        $statement = $this->db->prepare($sql);
        $statement->execute(["key" => $key]);
    }

    public function deleteAllInvoices() {
        $sql = 'DELETE FROM `INVOICE`';
        $statement = $this->db->prepare($sql);
        $statement->execute([]);
    }

    public function cleanup()
    {
        // orphaned twigs and assets
        $stmt = $this->db->prepare(
            'SELECT `value` FROM `INVOICE` WHERE `key` like \'invoice_%\''
        );
        $stmt->execute([]);
        $inUse = [];
        while ($data = $stmt->fetch()) {
            $invoice = json_decode($data["value"], true);
            $inUse[$invoice["main"]] = true;
            $inUse[$invoice["footer"]] = true;
            foreach ($invoice["assets"] as $key) {
                $inUse[$key] = true;
            }
        }
        $stmt = $this->db->prepare(
            'SELECT `key` FROM `INVOICE` WHERE `key` not like \'invoice_%\''
        );
        $stmt->execute([]);
        $orphaned = [];
        while ($data = $stmt->fetch()) {
            if (!array_key_exists($data["key"], $inUse)) {
                $orphaned[] = $data["key"];
            }
        }
        foreach ($orphaned as $key) {
            $this->deleteInvoiceKeyValue($key);
        }
    }
}
