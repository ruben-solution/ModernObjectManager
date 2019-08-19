<?php

class ObjManager
{
    const SAVE_METHOD_UPDATE = 'u';
    const SAVE_METHOD_CREATE = 'c';

    protected $db;
    protected $data;
    protected $table = '';
    protected $changes = [];

    protected $save_method = self::SAVE_METHOD_UPDATE;

    function __construct($db_connection)
    {
        $this->db = $db_connection;
    }

    /**
     * Builts prepared WHERE string form array. Uses same syntax as Medoo: https://medoo.in/api/where
     *
     * @param array $where
     * @param array &$param_bag holds all parameters for prepared statement
     *
     * @return string
     */
    private function buildPrepWhere(array $where, array &$param_bag): string
    {
        $where_str = '';

        foreach ($where as $how => $what) {
            $conditions = [];

            foreach ($what as $field => $value) {
                if (is_array($value)) {
                    // recursion with dynamic function name, reference passing only works with call_user_func_array
                    $conditions[] = call_user_func_array(
                        __METHOD__,
                        [
                            [$field => $value],
                            &$param_bag
                        ]
                    );
                } else {
                    // make field name unique
                    $prep_name = $this->stripFilterOptions($field) . uniqid();

                    // put key and value in parameter bag
                    $param_bag[':' . $prep_name] = $value;
                    $conditions[] = $this->parseFilterOption($field) . ':' . $prep_name;
                }
            }

            $where_str .= '(' . implode(" $how ", $conditions) . ')';
        }

        return $where_str;
    }

    /**
     * Remove filter parameters from field name string
     * "id[<]" becomes "id"
     *
     * @param string $filter
     *
     * @return string
     */
    private function stripFilterOptions(string $filter): string
    {
        if (strpos($filter, '[') !== false) {
            return substr($filter, 0, strpos($filter, '['));
        }

        return $filter;
    }

    /**
     * Returns parsed filter option string
     * "id[<]" becomes " id < "
     *
     * @param string $filter
     *
     * @return string
     */
    private function parseFilterOption(string $filter): string
    {
        $filter = trim($filter);

        if (strpos($filter, '[') !== false) {
            // holds filter option eg. "<", ">", "!", "like" usw..
            $filter_option = substr($filter, strpos($filter, '['));
            // bare field name
            $field_name = $this->stripFilterOptions($filter);

            switch ($filter_option) {
                case '[>]':
                    return " `$field_name` > ";
                case '[<]':
                    return " `$field_name` < ";
                case '[>=]':
                    return " `$field_name` >= ";
                case '[<=]':
                    return " `$field_name` <= ";
                case '[!]':
                    return " `$field_name` != ";
                case '[like]':
                    return " `$field_name` LIKE ";
                case '[!like]':
                    return " `$field_name` NOT LIKE ";
                default:
                    return " `$field_name` = ";
            }
        }

        return " `$filter` = ";
    }

    /**
     * Gets one object from a single table
     *
     * @param string $table
     * @param array  $where
     *
     * @return self
     */
    public function get(string $table, array $where=[])
    {
        $this->table = $table;

        // has array more than one dimension
        if (count($where) === count($where, COUNT_RECURSIVE) && count($where) > 0) {
            $field = array_key_first($where);

            // get bare field name
            $field_name = $this->stripFilterOptions($field);

            // create prepared statemend parameter
            $params[':' . $field_name] = $where[$field];

            // create where condition
            $where_string = $this->parseFilterOption($field) . ':' . $field_name;
        } else {
            $params = [];
            $where_string = $this->buildPrepWhere($where, $params);
        }

        $this->data = $this->db->getPrepRow("SELECT * FROM $table WHERE $where_string", $params);

        // execute db query
        return $this;
    }

    /**
     * Returns value from field
     *
     * @param string $field
     *
     * @return mixed
     */
    public function attr(string $field)
    {
        if (isset($this->data->{$field})) {
            return $this->data->{$field};
        }

        throw new \Exception('field "' . $field . '" does not exist.');
    }

    /**
     * Set new value for field. save it with the method "save"
     *
     * @param string $field
     * @param mixed  $value
     *
     * @return self
     */
    public function change(string $field, $value)
    {
        if (isset($this->data->{$field})) {
            $this->changes[] = $field;
            $this->data->{$field} = $value;

            // make it an update
            $this->save_method = self::SAVE_METHOD_UPDATE;

            return $this;
        }

        throw new \Exception('Field "' . $field . '" does not exist.');
    }

    /**
     * Updates existing entry in database
     *
     * @return bool
     */
    private function saveUpdate(): bool
    {
        if (count($this->changes) > 0) {
            $params = $sets = [];

            // build update string
            foreach ($this->changes as $field_name) {
                $sets[] = '`' . $field_name . '`=:' . $field_name;

                $params[':' . $field_name] = $this->data->{$field_name};
            }

            // build where
            $object_keys = array_keys(get_object_vars($this->data));

            foreach ($object_keys as $object_key) {
                if (
                    !in_array($object_key, $this->changes) &&
                    strlen((string) $this->data->{$object_key}) > 0
                ) {
                    $where[] = '`' . $object_key . '`=:' . $object_key;

                    $params[':' . $object_key] = $this->data->{$object_key};
                }
            }

            return $this->db->prepQuery(
                "UPDATE $this->table SET " . implode(', ', $sets) . " WHERE " . implode(' AND ', $where),
                $params
            ) !== false;
        } else {
            throw new \Exception('No changes detected');
        }
    }

    /**
     * Stores new entry in database
     *
     * @return bool
     */
    private function saveNew(): bool
    {
        // build where
        $object_keys = array_keys(get_object_vars($this->data));
        $field_names_prep = [];

        foreach ($object_keys as $object_key) {
            if (
                !in_array($object_key, $this->changes) &&
                strlen((string) $this->data->{$object_key}) > 0
            ) {
                $field_names_prep[] = ':' . $object_key;
                $params[':' . $object_key] = $this->data->{$object_key};
            }
        }

        $object_keys = array_map(
            function ($e) {
                return '`' . $e . '`';
            },
            $object_keys
        );

        return $this->db->prepQuery(
            "INSERT INTO $this->table (" . implode(', ', $object_keys) . ") VALUES (" . implode(', ', $field_names_prep) . ")",
            $params
        ) !== false;
    }

    /**
     * Create new entry
     *
     * @param string    $table
     * @param \stdClass $data
     *
     * @return self
     */
    public function create(string $table, \stdClass $data)
    {
        $this->table = $table;
        $this->data = $data;
        $this->save_method = self::SAVE_METHOD_CREATE;

        return $this;
    }

    /**
     * Stores changes (update or create).
     *
     * @param string $method
     *
     * @return bool
     */
    public function save($method=null): bool
    {
        $valid_methods = [
            self::SAVE_METHOD_UPDATE,
            self::SAVE_METHOD_CREATE
        ];

        if (!in_array($method, $valid_methods)) {
            $method = $this->save_method;
        }

        switch ($this->method) {
            // if an existing row is updated
            case self::SAVE_METHOD_UPDATE:
                return $this->saveUpdate();
            // if a new row has been created
            case self::SAVE_METHOD_CREATE:
                return $this->saveNew();
        }

        throw new \Exception('Saving method "' . $method . '" not found');
    }
}
