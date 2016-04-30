<?php

require_once('mysqldb.class.php');
require_once('app.class.php');
App::run();
$db_name = "ORMTest";

class Model {

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
    $result_array = static::find_by_sql("SELECT * FROM " .self::get_table_name(). " WHERE ".self::get_primary_key()."={$id} LIMIT 1");
    return !empty($result_array) ? array_shift($result_array) : null;
  }

  public static function find_by_sql($sql="") {
    $result_set = App::$db->query($sql);
    $object_array = array();
    while($row = App::$db->fetch_assoc($result_set)) {
      $object_array[] = static::instantiate($row);
    }
    return $object_array;
  }

  private static function instantiate($record) {
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

  private static function table_name_from_class() {
    return self::camel_case_to_snake_case(get_called_class());
  }

}

?>
