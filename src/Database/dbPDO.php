<?php

namespace MaxiSoft\Database;

use MaxiSoft\Database\NestedSets\NestedSets;
use PDO;
use PDOException;

class dbPDO extends PDO
{
    private $error;
    private $sql;
    private $bind   = [];
    private $type;
    private $table;
    private $alias;
    private $jointables;
    private $fields = [];
    private $where  = '';
    private $groupby;
    private $having = '';
    private $orderby;
    private $limit;
    private $on_duplicate;
    private $stmt;
    private $errorCallbackFunction;
    private $errorMsgFormat;
    private $is_debug;

    public function __construct($dsn, $user = null, $passwd = null, $options = null, $errorCallbackFunction = 'print_r', $errorFormat = 'html')
    {

        $options = [
            PDO::ATTR_PERSISTENT         => true,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES UTF8",
        ];

        $this->errorMsgFormat == $errorFormat ? $errorFormat : 'html';
        $this->errorCallbackFunction = $errorCallbackFunction ? $errorCallbackFunction : 'print_r';

        try {
            parent::__construct($dsn, $user, $passwd, $options);
            $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [
                '\\MaxiSoft\\Database\\Statement\\Statement',
                [$this],
            ]);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function replacePrefix($sql, $prefix = 'cms_')
    {

        $sql = trim(str_replace($prefix, \Config::getInstance()->db_prefix . '_', $sql));

        return $sql;
    }

    private function saveSqlConstruct()
    {

        $class_vars = get_class_vars($this);

        foreach ($class_vars as $name => $value) {
            $new_name        = $name . '_tmp';
            $this->$new_name = $this->{$name};
        }
    }

    private function restoreSqlConstruct()
    {

        $class_vars = get_class_vars($this);

        foreach ($class_vars as $name => $value) {
            $new_name        = $name . '_tmp';
            $this->{$name}   = $this->$new_name;
            $this->$new_name = null;
        }
    }

    public function setDebug()
    {
        $this->is_debug = true;
    }

    public function reset()
    {

        $this->error        = null;
        $this->sql          = null;
        $this->bind         = [];
        $this->type         = null;
        $this->table        = null;
        $this->alias        = null;
        $this->jointables   = null;
        $this->fields       = [];
        $this->where        = "";
        $this->groupby      = null;
        $this->having       = "";
        $this->orderby      = null;
        $this->limit        = null;
        $this->on_duplicate = null;
        $this->stmt         = null;
    }

    public function prepare($sql, $driver_options = [])
    {

        if (!isset($driver_options[PDO::ATTR_STATEMENT_CLASS])) {
            $driver_options[PDO::ATTR_STATEMENT_CLASS] = ['\\MaxiSoft\\Database\\Statement\\Statement'];
        }

        return parent::prepare($sql, $driver_options);
    }

    public function prepared()
    {

        if (empty($this->sql)) {
            $this->build();
        }

        $this->sql   = $this->replacePrefix($this->sql);
        $this->bind  = $this->cleanup($this->bind);
        $this->error = "";

        try {
            $stmt = $this->prepare($this->sql);
        } catch (PDOException $e) {
            print $e->getMessage();
            $this->error = $e->getMessage();
            $this->debug();

            return false;
        }

        return $stmt;
    }

    public function query($sql)
    {

        $sql = $this->replacePrefix($sql);

        $stmt = $this->prepare($sql);

        $stmt->execute();

        return $this;
    }

    public function select($table)
    {

        //$this->reset();
        $this->type = 'select';
        $table      = trim($table);
        if (strpos($table, " ")) {
            $this->table = trim(substr($table, 0, strpos($table, " ")));
            $this->alias = trim(substr($table, strpos($table, " ")));
        } else {
            $this->table = $table;
        }

        return $this;
    }

    public function insert($table)
    {

        $this->reset();
        $this->type  = 'insert';
        $this->table = $table;

        return $this;
    }

    public function on_duplicate_key_update(array $fields)
    {

        $f    = array_keys($fields);
        $bind = [];
        foreach ($f as $field) {
            $key = str_replace('.', '', $field);
            if ($fields[$key] != 'CURRENT_TIMESTAMP') {
                $i = 0;
                while (isset($this->bind[":$key"])) {
                    $i++;
                    $key = str_replace('.', '', $field) . $i;
                }

                $bind[":$key"] = $fields[$field];
                $this->on_duplicate .= $field . ' = :' . $key . ', ';
            } else {
                $this->on_duplicate .= $field . ' = CURRENT_TIMESTAMP, ';
            }
        }
        $this->on_duplicate = substr($this->on_duplicate, 0, -2);
        $this->bind += $this->cleanup($bind);

        return $this;
    }

    public function update($table)
    {

        $this->reset();
        $this->type  = 'update';
        $this->table = $table;

        return $this;
    }

    public function delete($table)
    {

        $this->reset();
        $this->type  = 'delete';
        $this->table = $table;

        return $this;
    }

    public function getFields($table, array $fields = [], array $condition = [], array $ordering = [], $all = null)
    {

        $this->saveSqlConstruct();

        $this->reset();
        $this->type  = 'select';
        $this->table = $table;
        $this->fields($fields);
        if ($ordering) {
            $this->orderBy($ordering[0], $ordering[1]);
        }
        $this->where($condition[0], $condition[1], $condition[2]);
        if ($all) {
            $data = $this->run()->fetchAllAssoc();
        } else {
            $data = $this->run()->fetchAssoc();
        }

        $this->restoreSqlConstruct();

        return $data;
    }

    public function fields($fields)
    {

        if (!is_array($fields)) {
            $fields = [$fields];
        }

        switch ($this->type) {
            case 'insert':
            case 'update':
                $fields = $this->removeTheMissingCell($fields);

                if (!empty($fields)) {
                    $this->fields += array_keys($fields);
                    $bind = [];
                    foreach ($this->fields as $field) {
                        $key = str_replace('.', '', $field);
                        $i   = 0;
                        while (isset($this->bind[":$key"]) || isset($bind[":$key"])) {
                            $key = str_replace('.', '', $field) . $i;
                        }

                        $bind[":$key"] = $fields[$field];
                    }
                    $this->bind += $this->cleanup($bind);
                }
                break;

            case 'select':
                if (!empty($fields)) {
                    $this->fields = array_merge((array)$this->fields, $fields);
                }

                break;

            default:
                if (!empty($fields)) {
                    $this->fields = array_merge((array)$this->fields, $fields);
                }

                break;
        }

        return $this;
    }

    public function join($table, $condition = null)
    {

        return $this->addJoin('INNER', $table, $condition);
    }

    public function innerJoin($table, $condition = null)
    {

        return $this->addJoin('INNER', $table, $condition);
    }

    public function outerJoin($table, $condition = null)
    {

        return $this->addJoin('OUTER', $table, $condition);
    }

    public function leftOuterJoin($table, $condition = null)
    {

        return $this->addJoin('LEFT OUTER', $table, $condition);
    }

    public function leftInnerJoin($table, $condition = null)
    {

        return $this->addJoin('LEFT INNER', $table, $condition);
    }

    public function rightOuterJoin($table, $condition = null)
    {

        return $this->addJoin('RIGHT OUTER', $table, $condition);
    }

    public function rightInnerJoin($table, $condition = null)
    {

        return $this->addJoin('RIGHT INNER', $table, $condition);
    }

    public function rightJoin($table, $condition = null)
    {
        $this->addJoin('RIGHT', $table, $condition);

        return $this;
    }

    public function leftJoin($table, $condition = null)
    {
        $this->addJoin('LEFT', $table, $condition);

        return $this;
    }

    public function addJoin($type, $table, $condition = null)
    {

        $orig_alias      = trim(substr($table, strpos($table, ' ')));
        $alias           = $orig_alias;
        $alias_candidate = $alias;
        $count           = 2;
        while (!empty($this->jointables[$alias_candidate])) {
            $alias_candidate = $alias . '_' . $count++;
        }

        $alias     = $alias_candidate;
        $condition = str_replace($orig_alias, $alias, $condition);

        $this->jointables[$alias] = [
            'join type' => $type,
            'table'     => trim(substr($table, 0, strpos($table, ' '))),
            'alias'     => $alias,
            'condition' => $condition,
        ];

        return $this;
    }

    public function _where($field, $operator = null, $value = null, $concatenator = null)
    {

        if ($this->where !== '' && !$concatenator) {
            $concatenator = 'AND';
        }

        $this->where .= $concatenator . $field . ' ' . $operator . ' ' . $value;

        return $this;
    }

    public function where($field, $operator = null, $value = null, $concatenator = null)
    {

        if (empty($concatenator)) {
            $concatenator = '';
        }

        if ($this->where !== '' && !$concatenator) {
            $concatenator = 'AND';
        }

        $this->where .= $this->condition($field, $value, $operator, $concatenator);

        return $this;
    }

    public function andWhere($field, $operator = null, $value = null)
    {

        $this->where($field, $operator, $value, 'AND');

        return $this;
    }

    public function orWhere($field, $operator = null, $value = null)
    {

        $this->where($field, $operator, $value, 'OR');

        return $this;
    }

    public function possibly($fields, $concatenator = 'AND')
    {
        $where_str = '';
        $concat    = '';
        foreach ($fields as $key => $field) {
            if ($key > 0) {
                $concat = $field[3] ?: 'OR';
            }

            $where_str .= $this->condition($field[0], $field[2], $field[1], $concat);
        }

        if ($where_str) {
            if ($this->where === '') {
                $concatenator = '';
            }
            $this->where .= ' ' . $concatenator . ' (' . $where_str . ') ';
        }

        return $this;
    }

    public function filterCompound(array $chunks, $concatenator = 'AND')
    {
        $where_str = '';
        foreach ($chunks as $chunk) {
            $chunk_str = '';

            foreach ($chunk[0] as $field) {
                $chunk_str .= $this->condition($field[0], $field[2], $field[1], $field[3]);
            }

            $where_str .= ' ' . $chunk[1] . ' (' . $chunk_str . ') ';
        }

        if (!$this->where) {
            $concatenator = '';
        }

        $this->where .= ' ' . $concatenator . ' (' . $where_str . ') ';

        return $this;
    }

    public function filter($sql, $concatenator = 'AND')
    {
        if ($this->where == '') {
            $concatenator = '';
        }
        print_r($this->where);
        $this->where .= $concatenator . $sql;
    }

    public function filterNotNull($field)
    {

        return $this->where($field, 'IS NOT', null);
    }

    public function filterIsNull($field)
    {

        return $this->where($field, 'IS', null);
    }

    public function filterEqual($field, $value)
    {

        if (is_null($value)) {
            $this->where($field, 'IS', null);
        } else {
            $this->where($field, '=', $value);
        }

        return $this;
    }

    public function filterNotEqual($field, $value)
    {

        if (is_null($value)) {
            $this->where($field, 'NOT IS', null);
        } else {
            $this->where($field, '<>', $value);
        }

        return $this;
    }

    public function filterGt($field, $value)
    {

        return $this->where($field, '>', $value);
    }

    public function filterLt($field, $value)
    {

        return $this->where($field, '<', $value);
    }

    public function filterGtEqual($field, $value)
    {

        return $this->where($field, '>=', $value);
    }

    public function filterLtEqual($field, $value)
    {

        return $this->where($field, '<=', $value);
    }

    public function filterLike($field, $value)
    {

        return $this->where($field, 'LIKE', $value);
    }

    public function filterBetween($field, $start, $end)
    {

        return $this->where($field, 'BETWEEN', $start . ' AND ' . $end);
    }

    public function filterDateYounger($field, $value, $interval = 'DAY')
    {
        if ($this->where !== '') {
            $concatenator = ' AND ';
        }

        $this->where .= $concatenator . $field . ">= DATE_SUB(NOW(), INTERVAL {$value} {$interval})";

        return $this;
    }

    public function filterDateOlder($field, $value, $interval = 'DAY')
    {
        if ($this->where !== '') {
            $concatenator = ' AND ';
        }

        $this->where .= $concatenator . $field . "<= DATE_SUB(NOW(), INTERVAL {$value} {$interval})";

        return $this;
    }

    public function groupBy($fields)
    {

        if (!is_array($fields)) {
            $fields = [$fields];
        }
        foreach ($fields as $f) {
            if (!empty($this->groupby)) {
                $this->groupby .= ", " . $f;
            } else {
                $this->groupby = $f;
            }
        }

        return $this;
    }

    public function having($field, $operator = null, $value = null, $concatenator = null)
    {

        if (empty($concatenator)) {
            $concatenator = "";
        }
        $this->having .= $this->condition($field, $value, $operator, $concatenator);

        return $this;
    }

    public function andHaving($field, $operator = null, $value = null)
    {

        return $this->having($field, $operator, $value, "AND");
    }

    public function orHaving($field, $operator = null, $value = null)
    {

        return $this->having($field, $operator, $value, "OR");
    }

    public function orderBy($fields, $order = null)
    {

        if (empty($order)) {
            $order = "ASC";
        }
        if (!is_array($fields)) {
            $fields = [$fields];
        }
        foreach ($fields as $f) {
            $f = $f . " " . $order;
            if (!empty($this->orderby)) {
                $this->orderby .= ", " . $f;
            } else {
                $this->orderby = $f;
            }
        }

        return $this;
    }

    public function limit($limit, $range = null)
    {

        if (empty($range) || $this->type == 'update' || $this->type == 'delete') {
            if (is_numeric($limit)) {
                $this->limit = $limit;
            }
        } else {
            if (is_numeric($limit) && is_numeric($range)) {
                $this->limit = $limit . ', ' . $range;
            }
        }

        return $this;
    }

    public function limitPage($page, $per_page)
    {

        return $this->limit(($page - 1) * $per_page, $per_page);
    }

    public function page($page = 1, $perpage = 15)
    {

        $this->limit(($page - 1) * $perpage, $perpage);

        return $this;
    }

    public function countRecords()
    {

        $stmt = $this->run(false);

        $this->sql        = '';
        $this->fields     = [];
        $this->jointables = [];

        return $stmt;
    }

    public function increase($field, $amount = 1)
    {
        $where = '';
        if ($this->where) {
            $where = 'WHERE ' . $this->where;
        }

        $this->sql = 'UPDATE ' . $this->table . ' SET ' . $field . ' = ' . $field . ' + ' . $amount . ' ' . $where;

        return $this;
    }

    public function decrease($field, $amount = 1)
    {
        $where = '';
        if ($this->where) {
            $where = 'WHERE ' . $this->where;
        }

        $this->sql = 'UPDATE ' . $this->table . ' SET ' . $field . ' = ' . $field . ' - ' . $amount . ' ' . $where;

        return $this;
    }

    public function multiply($field, $amount = 1)
    {
        $where = '';
        if ($this->where) {
            $where = 'WHERE ' . $this->where;
        }

        $this->sql = 'UPDATE ' . $this->table . ' SET ' . $field . ' = ' . $field . ' * ' . $amount . ' ' . $where;

        return $this;
    }

    public function divided($field, $amount = 1)
    {
        $where = '';
        if ($this->where) {
            $where = 'WHERE ' . $this->where;
        }

        $this->sql = 'UPDATE ' . $this->table . ' SET ' . $field . ' = ' . $field . ' / ' . $amount . ' ' . $where;

        return $this;
    }

    public function run($reset = true)
    {

        if (empty($this->sql)) {
            $this->build();
        }

        $this->sql   = $this->replacePrefix($this->sql);
        $this->bind  = $this->cleanup($this->bind);
        $this->error = '';

        try {
            $stmt = $this->prepare($this->sql);
            foreach ($this->bind as $bind => $value) {
                switch (gettype($value)) {
                    case 'array':
                        $type  = PDO::PARAM_STR;
                        $value = \Symfony\Component\Yaml\Yaml::dump($value);
                        break;
                    case 'integer':
                        $type  = PDO::PARAM_INT;
                        $value = (integer)$value;
                        break;
                    case 'string':
                        $type  = PDO::PARAM_STR;
                        $value = (string)$value;
                        break;
                    case 'boolean':
                        $type  = PDO::PARAM_BOOL;
                        $value = (boolean)$value;
                        break;
                    case 'NULL':
                        $type = PDO::PARAM_NULL;
                        break;
                    default:
                        null;
                        break;
                }

                $stmt->bindValue($bind, $value, $type);
            }
            if ($this->is_debug) {
                echo '<pre>';
                print_r($this->logQuery());
                echo '</pre>';
            }
            if ($stmt->execute() !== false) {
                if ($reset) {
                    $this->reset();
                }

                return $stmt;
            }
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            $this->debug();

            return false;
        }
    }

    private function logQuery()
    {

        $sql_query = $this->sql;

        foreach ($this->bind as $bind => $value) {
            $sql_query = str_replace($bind, $value, $sql_query);
        }

        return $sql_query;
    }

    private function build()
    {

        switch ($this->type) {
            case 'select':
                if (is_array($this->fields)) {
                    if (empty($this->fields)) {
                        if (empty($this->jointables)) {
                            $this->fields = ['*'];
                        } else {
                            $this->fields($this->prefixed_table_fields_wildcard($this->table, $this->alias));
                            foreach ($this->jointables as $table) {
                                $this->fields($this->prefixed_table_fields_wildcard($table['table'], $table['alias']));
                            }
                        }
                    }
                    $this->fields = implode($this->fields, ', ');
                }
                $this->sql = 'SELECT ' . $this->fields . ' FROM ' . $this->table;
                if (!empty($this->alias)) {
                    $this->sql .= ' ' . $this->alias;
                }

                if (!empty($this->jointables)) {
                    foreach ($this->jointables as $table) {
                        $this->sql .= ' ' . $table['join type'] . ' JOIN ' . $table['table'] . ' ' . $table['alias'] . ' ON (' . $table['condition'] . ')';
                    }
                }
                if (!empty($this->where)) {
                    $this->sql .= ' WHERE ' . $this->where;
                }

                if (!empty($this->groupby)) {
                    $this->sql .= ' GROUP BY ' . $this->groupby;
                }

                if (!empty($this->having)) {
                    $this->sql .= ' HAVING ' . $this->having;
                }

                if (!empty($this->orderby)) {
                    $this->sql .= ' ORDER BY ' . $this->orderby;
                }

                if (!empty($this->limit)) {
                    $this->sql .= ' LIMIT ' . $this->limit;
                }

                $this->sql .= ';';

                break;

            case 'update':
                $this->sql = "UPDATE " . $this->table . " SET";

                foreach ($this->fields as $f) {
                    $this->sql .= " " . $f . '= :' . $f . ",";
                }

                $this->sql = substr($this->sql, 0, -1);
                if (!empty($this->where)) {
                    $this->sql .= " WHERE " . $this->where;
                }

                if (!empty($this->orderby)) {
                    $this->sql .= " ORDER BY " . $this->orderby;
                }

                if (!empty($this->limit)) {
                    $this->sql .= " LIMIT " . $this->limit;
                }
                $this->sql .= ';';
                break;

            case 'insert':

                $this->sql = "INSERT INTO " . $this->table . " (" . implode($this->fields, ", ") . ") VALUES (:" . implode($this->fields, ", :") . ")";
                if (!empty($this->on_duplicate)) {
                    $this->sql .= " ON DUPLICATE KEY UPDATE " . $this->on_duplicate;
                }

                $this->sql .= ';';
                break;

            case 'delete':
                $this->sql = "DELETE FROM " . $this->table;
                if (!empty($this->where)) {
                    $this->sql .= " WHERE " . $this->where;
                }

                if (!empty($this->orderby)) {
                    $this->sql .= " ORDER BY " . $this->orderby;
                }

                if (!empty($this->limit)) {
                    $this->sql .= " LIMIT " . $this->limit;
                }

                $this->sql .= ';';
                break;

            default:
                break;
        }

        return $this;
    }

    private function prefixed_table_fields_wildcard($table, $alias)
    {
        $sql_table = "SHOW COLUMNS FROM {$table}";

        $stmt = $this->prepare($this->replacePrefix($sql_table));
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $field_names = [];

        foreach ($columns as $column) {
            $field_names[] = $column["Field"];
        }

        $prefixed = [];

        foreach ($field_names as $field_name) {
            $prefixed[] = "`{$alias}`.`{$field_name}` AS `{$alias}_{$field_name}`";
        }

        return $prefixed;
    }

    private function removeTheMissingCell(array $fields = [])
    {

        switch ($this->type) {
            case 'insert':
            case 'update':
                $sql_table = "SHOW COLUMNS FROM {$this->table}";

                $stmt = $this->prepare($this->replacePrefix($sql_table));
                $stmt->execute();
                $table_fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $list = [];
                foreach ($table_fields as $fl) {
                    $list[$fl['Field']] = 0;
                }

                foreach ($fields as $k => $f) {
                    if (!isset($list[$k]) || ($this->type === 'update' && $k === 'id')) {
                        unset($fields[$k]);
                    }
                }
                break;

            default:
                break;
        }

        return $fields;
    }

    public function nestedSets($table)
    {

        return new NestedSets($table);
    }

    public function setErrorCallbackFunction($errorCallbackFunction, $errorMsgFormat = null)
    {

        if (empty($errorMsgFormat)) {
            $errorMsgFormat = "html";
        }
        if (in_array(strtolower($errorCallbackFunction), ["echo", "print",])) {
            $errorCallbackFunction = "print_r";
        }

        if (function_exists($errorCallbackFunction)) {
            $this->errorCallbackFunction = $errorCallbackFunction;
            if (!in_array(strtolower($errorMsgFormat), ["html", "text",])) {
                $errorMsgFormat = "html";
            }
            $this->errorMsgFormat = $errorMsgFormat;
        }

        return $this;
    }

    public function isTableExists($table)
    {

        try {
            $result = $this->query("SELECT 1 FROM $table LIMIT 1");
        } catch (Exception $e) {
            return false;
        }

        return $result !== false;
    }

    public function importFromFile($file, $pref = '#_')
    {

        try {
            if (file_exists($file)) {
                $sqlStream = file_get_contents($file);
                $sqlStream = rtrim($sqlStream);
                $sqlStream = str_replace($pref, \Config::getInstance()->db_prefix, $sqlStream);
                $newStream = preg_replace_callback("/\((.*)\)/", function ($matches) {

                    return str_replace(";", " $$$ ", $matches[0]);
                }, $sqlStream);
                $sqlArray  = explode(";", $newStream);

                foreach ($sqlArray as $value) {
                    if (!empty($value)) {
                        $sql = str_replace(" $$$ ", ";", $value) . ";";
                        $this->query($sql);
                    }
                }

                return true;
            }
        } catch (Exception $e) {
            return false;
        }
    }

    private function condition($field, $value, $operator, $concatenator)
    {

        if (!isset($operator) || $operator == "IN" || $operator == "NOT IN") {
            if (is_array($value)) {
                if (!isset($operator)) {
                    $operator = 'IN';
                }

                $v = '(';
                foreach ($value as $val) {
                    $key = strtolower(substr(md5($val), 0, 5));
                    $v .= ':' . str_replace('.', '_', $field) . '_' . $key . ', ';
                    $bind[':' . str_replace('.', '_', $field) . '_' . $key] = $val;
                }

                $v = substr($v, 0, -2);
                $v .= ')';
                $placeholder = $v;
            } elseif (!isset($value)) {
                $operator = 'IS NULL';
            } else {
                $operator = '=';
            }
        }

        if (!isset($placeholder)) {
            $placeholder = ':' . $field;
            $placeholder = str_replace('.', '', $placeholder);
            $i           = 0;
            while (isset($this->bind[$placeholder])) {
                $i++;
                $placeholder = ':' . $field . $i;
                $placeholder = str_replace('.', '', $placeholder);
            }
            $bind[$placeholder] = $value;
        }

        $this->bind += (array)$bind;
        if (!empty($concatenator)) {
            $concatenator = ' ' . trim($concatenator) . ' ';
        }

        return $concatenator . $field . ' ' . $operator . ' ' . $placeholder;
    }

    private function debug()
    {

        if (!empty($this->errorCallbackFunction)) {
            $error = ["Error" => $this->error];

            if (!empty($this->sql)) {
                $error["SQL Statement"] = $this->sql;
            }

            if (!empty($this->bind)) {
                $error["Bind Parameters"] = trim(print_r($this->bind, true));
            }

            $backtrace = debug_backtrace();

            if (!empty($backtrace)) {
                foreach ($backtrace as $info) {
                    if ($info["file"] != __FILE__) {
                        $error["Backtrace"] = $info["file"] . " at line " . $info["line"];
                    }
                }
            }
            $error['query'] = $this->logQuery();

            $msg = "";

            if (!empty($error["Bind Parameters"])) {
                $error["Bind Parameters"] = "<pre>" . $error["Bind Parameters"] . "</pre>";
            }

            $msg .= "\n" . '<div class="db-error">' . "\n\t<h3>SQL Error</h3>";
            $msg .= "\n" . '<div class="db-error">' . "\n\t" . $error['query'] . "<br/>";
            foreach ($error as $key => $val) {
                $msg .= "\n\t<label>" . $key . ":</label>" . $val;
            }
            $msg .= "\n\t</div>\n</div>";

            $func = $this->errorCallbackFunction;
            $func($msg);
        }
    }

    private function cleanup($bind)
    {

        if (!is_array($bind)) {
            if (!empty($bind)) {
                $bind = [$bind];
            } else {
                $bind = [];
            }
        }

        return $bind;
    }

    public function __toString()
    {

        return '<pre>' . print_r($this, true) . '</pre>';
    }
}
