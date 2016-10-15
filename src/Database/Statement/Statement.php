<?php

namespace MaxiSoft\Database\Statement;

use MaxiSoft\Database;
use PDO;
use PDOStatement;

class Statement extends PDOStatement
{
    public function fetchField($fieldname = 0)
    {
        $data = $this->fetch(PDO::FETCH_BOTH);
        if (!isset($data[$fieldname])) {
            $data[$fieldname] = false;
        }

        return $data[$fieldname];
    }

    public function fetchAssoc()
    {
        return $this->fetch(PDO::FETCH_ASSOC);
    }

    public function fetchAllAssoc()
    {
        return $this->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchCount()
    {
        return $this->rowCount();
    }

    public function fetchTable($attributes = array())
    {
        $table = "<table";
        $table .= !empty($table_id) ? " id='$table_id'" : '';
        $table .= !empty($table_class) ? " class='$table_class'" : '';
        foreach ($attributes as $attribute => $value) {
            if (is_array($value)) {
                //support multiple classes (e.g. class = "class1 class2").
                $value = implode(" ", $value);
            }
            $table .= " " . $attribute . "=\"" . $value . "\"";
        }
        $table .= ">\n";
        $tableheaders = "";
        $rows         = "";
        $header       = "";
        while ($row = $this->fetchAssoc()) {
            if (empty($tableheaders)) {
                $header .= "\t<tr>\n";
            }
            $rows .= "\t<tr>\n";
            foreach ($row as $fieldname => $field) {
                if (empty($tableheaders)) {
                    $header .= "\t\t<th>" . ucfirst(strtolower($fieldname)) . "</th>\n";
                }
                $rows .= "\t\t<td>" . $field . "</td>\n";
            }
            $rows .= "\t</tr>\n";
            if (empty($tableheaders)) {
                $header .= "\t</tr>\n";
                $tableheaders .= $header;
            }
        }
        $table .= $tableheaders . $rows . "</table>\n";

        return $table;
    }
}
