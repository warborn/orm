<?php

require_once('mysqldb.class.php');
require_once('app.class.php');
App::run();
$db_name = "ORMTest";

class Model {
  const HAS_ONE = 1;
  const HAS_MANY = 2;
  const BELONGS_TO = 3;

  protected static function get_table_name() {
    if(isset(static::$table_name)) {
      return static::$table_name;
    } else {
      return self::table_name_from_class();
    }
  }

  protected static function get_primary_key() {
    if(isset(static::$primary_key)) {
      return static::$primary_key;
    } else {
      return self::get_table_name() . '_id';
    }
  }

  public function __construct() {
    $result_set = App::$db->query("DESC ".self::get_table_name());
    $object_array = array();
    while($row = App::$db->fetch_assoc($result_set)) {
      $object_array[] = $row['Field'];
    }

    foreach ($object_array as $index => $column) {
      if($column === self::table_name_from_class().'_id') {
        $column = 'id';
      }
      $this->$column = null;
    }
  }

  // Common database methods
  public static function all() {
    return static::find_by_sql("SELECT * FROM ".self::get_table_name());
  }

  public static function find($id = 0) {
    $result_array = static::find_by_sql("SELECT * FROM " .self::get_table_name(). " WHERE ".self::get_primary_key()."='{$id}' LIMIT 1");
    return !empty($result_array) ? array_shift($result_array) : null;
  }

  public static function find_by($column, $value) {
    $result_array = static::find_by_sql("SELECT * FROM ".self::get_table_name(). " WHERE {$column} = '{$value}'");
    return !empty($result_array) ? $result_array : [];
  }

  public static function find_by_sql($sql="", $class_name = null) {
    $result_set = App::$db->query($sql);
    $object_array = array();
    while($row = App::$db->fetch_assoc($result_set)) {
      if($class_name === null) {
        $object_array[] = static::instantiate($row);
      } else {
        $object_array[] = $class_name::instantiate($row, false);
      }
    }
    return $object_array;
  }

  // Handle find_by_attribute methods

  public static function __callStatic($method_name, $args) {
    $class_name = get_called_class();

    // Extract the field name from the method name
    $array = split('_', $method_name);
    array_splice($array, 0, 2);
    $field =  implode('_', $array);

    array_unshift($args, $field);

    if(substr($method_name, 0, 7) === 'find_by') {
      return call_user_func_array(array($class_name, 'find_by'), $args);
    }

    throw new \Exception(sprintf('There is no static method named "%s" in the class "%s".', $method_name, $class_name));
  }

  private static function instantiate($record, $establish_relationships = true) {
    // Check that $record exists and is an array
    if(!isset($record) && !is_array($record)) {
      return false;
    }
    // Simple, long-form approach:
    $object = new static();
    // Dynamic, short-form approach:
    foreach($record as $attribute => $value) {
      if ($attribute === self::table_name_from_class().'_id') {
        $attribute = 'id';
      }
      if($object->has_attribute($attribute)) {
          $object->$attribute = $value;
      }
    }

    // Set relationships (has_many, belongs_to) on object
    if($establish_relationships){
      self::instantiate_relationships($object);
    }

    return $object;
  }

  private function has_attribute($attribute) {
    // get_object_vars returns an associative array with all the attributes
    $object_vars = get_object_vars($this);
    // Checks if the given key or index exists in the array
    return array_key_exists($attribute, $object_vars);
  }

  private static function camel_case_to_snake_case($input) {
    return ltrim(strtolower(preg_replace('/[A-Z]/', '_$0', $input)), '_');
  }

  private static function snake_case_to_camel_case($input) {
    return  preg_replace_callback("/(?:^|_)([a-z])/", function($matches) {
      return strtoupper($matches[1]);
    }, $input);
  }

  private static function table_name_from_class() {
    return self::camel_case_to_snake_case(get_called_class());
  }

  private static function class_from_table_name($table_name) {
    return self::snake_case_to_camel_case($table_name);
  }

  private static function instantiate_relationships($object) {
    if(isset(static::$has_many) && is_array(static::$has_many)) {
      self::add_related_objects($object, static::$has_many, self::HAS_MANY);
    }

    if(isset(static::$belongs_to) && is_array(static::$belongs_to)) {
      self::add_related_objects($object, static::$belongs_to, self::BELONGS_TO);
    }
  }

  private function add_related_objects(&$object, $collection, $relation_type) {
    foreach ($collection as $relation) {
      $relation_name = $related_table = $class_name = null;

      if(count($relation) === 3) {
        $class_name = array_pop($relation);
      }
      if(count($relation) === 2) {
        $related_table = array_pop($relation);
        $class_name = $class_name ? $class_name : self::snake_case_to_camel_case($related_table);
      }
      if(count($relation) == 1) {
        $relation_name = $relation[0];
        if($relation_type === self::HAS_MANY) {
          $related_table = $related_table ? $related_table : substr($relation_name, 0, -1);
        } else if($relation_type === self::BELONGS_TO) {
          $related_table = $relation_name;
        }
        $class_name = $class_name ? $class_name : self::snake_case_to_camel_case($related_table);
      }

      if($relation_type === self::HAS_MANY) {
        $sql = "SELECT * FROM {$related_table} WHERE ".self::get_table_name() . "_id" ." = '{$object->id}'";
        $object->$relation_name = self::find_by_sql($sql, $class_name);
      } else if($relation_type === self::BELONGS_TO) {
        $sql = "SELECT * FROM {$related_table} WHERE ".$related_table . "_id" ." = '{$object->id}'";
        $result_array = self::find_by_sql($sql, $class_name);
        $object->$relation_name = !empty($result_array) ? array_shift($result_array) : null;
      }
    }
  }

}

?>
