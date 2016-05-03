<?php

require_once('mysqldb.class.php');
require_once('app.class.php');
App::run();
$db_name = "ORMTest";

/**
* Model (A simple ORM for PHP)
* @class Model
* @author Ivan Munguia <ivanalejandro249@gmail.com>
*/
class ActiveRecord {

  private $new_record = false;

  /**
   * Relationship options
   */
  const HAS_ONE = 1;
  const HAS_MANY = 2;
  const BELONGS_TO = 3;

  /**
   * Get the table name related to the child class
   *
   * @access public
   * @static
   * @return string
   */
  protected static function get_table_name() {
    if(isset(static::$table_name)) {
      return static::$table_name;
    } else {
      return self::get_table_name_from_class();
    }
  }

  /**
   * Get the primary key specified on the child class or set a default
   *
   * @access public
   * @static
   * @return string
   */
  protected static function get_primary_key() {
    if(isset(static::$primary_key)) {
      return static::$primary_key;
    } else {
      return self::get_table_name() . '_id';
    }
  }

  /**
   * Constructor
   *
   * @access public
   * @return void
   */
  public function __construct($new_record = true) {
    $result_set = App::$db->query("DESC ".self::get_table_name());
    $object_array = array();
    while($row = App::$db->fetch_assoc($result_set)) {
      $object_array[] = $row['Field'];
    }

    foreach ($object_array as $index => $column) {
      if($column === self::get_table_name_from_class().'_id') {
        $column = 'id';
      }
      $this->$column = null;
    }
    $this->new_record = $new_record;
  }

  // Common database methods

  /**
   * Return all record from the database in Model form
   *
   * @access public
   * @static
   * @return model
   */
  public static function all() {
    return static::find_by_sql("SELECT * FROM ".self::get_table_name());
  }

  /**
   * Return the founded record on the database based on the primary key
   *
   * @access public
   * @static
   * @param $pk_value
   * @return model | null
   */
  public static function find($pk_value = 0) {
    $result_array = static::find_by_sql("SELECT * FROM " .self::get_table_name(). " WHERE ".self::get_primary_key()."='{$pk_value}' LIMIT 1");
    return !empty($result_array) ? array_shift($result_array) : null;
  }

  /**
   * Base method for finding records by column name and value
   *
   * @access public
   * @static
   * @param string $column
   * @param mixed $value
   * @return model | array
   */
  public static function find_by($column, $value) {
    $result_array = static::find_by_sql("SELECT * FROM ".self::get_table_name(). " WHERE {$column} = '{$value}'");
    return !empty($result_array) ? $result_array : [];
  }

  /**
   * Find records by the sql query provided and create objects
   * based on the provided class or child class by default
   *
   * @access public
   * @static
   * @param string $sql
   * @param string $class_name
   * @return array
   */
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

  /**
   *
   *
   */
   public function insert() {
     $fields = $field_markers = $types = $values = array();

     foreach ($this->get_modifiable_fields() as $column => $value) {
       $fields[] = $column;
       $field_markers[] = '?';
       $types[] = $this->get_data_type($value);
       $values[] = &$this->{$column};
     }

     $sql = sprintf("INSERT INTO %s (%s) VALUES (%s)", self::get_table_name(), implode(', ', $fields), implode(', ', $field_markers));

     $stmt = App::$db->prepare($sql);

     if(! $stmt) {
       throw new \Exception(App::$db->connection()->error."\n\n".$sql);
     }
     print_r($values);
     call_user_func_array(array($stmt, 'bind_param'), array_merge(array(implode($types)), $values));
     $stmt->execute();

     if($stmt->error) {
       throw new \Exception($stmt->error."\n\n".$sql);
     }

     if($stmt->insert_id) {
       $this->id = $stmt->insert_id;
     }
     $this->new_record = false;
   }

  /**
   * Handle find_by_attribute methods calling the find_by method
   *
   * @access public
   * @static
   * @param string $method_name
   * @param array $args
   */

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

  /**
   * Instantiate a model object from a database record
   *
   * @access private
   * @static
   * @param mysqli_result $record
   * @param boolean $establish_relationships
   */
  private static function instantiate($record, $establish_relationships = true) {
    // Check that $record exists and is an array
    if(!isset($record) && !is_array($record)) {
      return false;
    }
    // Simple, long-form approach:
    $object = new static(false);
    // Dynamic, short-form approach:
    foreach($record as $attribute => $value) {
      if ($attribute === self::get_table_name_from_class().'_id') {
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

  /**
   * Know if a given attribute is present on the current object
   *
   * @access private
   * @return boolean
   */
  private function has_attribute($attribute) {
    // get_object_vars returns an associative array with all the attributes
    $object_vars = get_object_vars($this);
    // Checks if the given key or index exists in the array
    return array_key_exists($attribute, $object_vars);
  }

  /**
   * Convert camel case string into snake case
   *
   * @access private
   * @param string $input
   */
  private static function camel_case_to_snake_case($input) {
    return ltrim(strtolower(preg_replace('/[A-Z]/', '_$0', $input)), '_');
  }

  /**
   * Convert snake case string into camel case
   *
   * @access private
   * @param string $input
   */
  private static function snake_case_to_camel_case($input) {
    return  preg_replace_callback("/(?:^|_)([a-z])/", function($matches) {
      return strtoupper($matches[1]);
    }, $input);
  }

  /**
   * Return the convey table name based on the called class name
   *
   * @access private
   * @static
   * @return string
   */
  private static function get_table_name_from_class() {
    return self::camel_case_to_snake_case(get_called_class());
  }

  /**
   * Instantiate the assosiated objects of the model
   *
   * @access private
   * @static
   * @return void
   */
  private static function instantiate_relationships($object) {
    if(isset(static::$has_many) && is_array(static::$has_many)) {
      self::add_related_objects($object, static::$has_many, self::HAS_MANY);
    }

    if(isset(static::$belongs_to) && is_array(static::$belongs_to)) {
      self::add_related_objects($object, static::$belongs_to, self::BELONGS_TO);
    }
  }

  /**
   * Add instantiated objects to the model according to the association type
   *
   * @access private
   * @static
   * @return void
   */
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

  /**
   * Return the object attributes allowed to be updated at database level
   *
   * @access private
   * @static
   * @return array
   */
  private function get_modifiable_fields() {
    $table_fields = array();
    $r = new ReflectionObject($this);
    foreach ($r->getProperties(ReflectionProperty::IS_PUBLIC) AS $key => $value)
    {
      $key = $value->getName();
      $value = $value->getValue($this);

      if(! is_object($value) && $key !== 'id') {
        $table_fields[$key] =  $value;
      }
    }
    return $table_fields;
  }

  private function get_data_type($value) {
    if(is_int($value)) return 'i';
    if(is_double($value)) return 'd';
    return 's';
  }
}

?>
