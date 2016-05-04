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
  const FETCH_ONE = 4;
  const FETCH_ALL = 5;

  /**
   * Get the table name related to the child class
   *
   * @access protected
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
   * @access protected
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
   * Get the column names form the table that corresponds to the class
   *
   * @access protected
   * @static
   * @return array
   */
  protected static function get_table_columns() {
    $result_set = App::$db->query("DESC ".self::get_table_name());
    $object_array = array();
    while($row = App::$db->fetch_assoc($result_set)) {
      $object_array[] = $row['Field'];
    }
    return $object_array;
  }

  /**
   * Constructor
   *
   * @access public
   * @param mixed $param
   * @return void
   */
  public function __construct($param = null) {
    $object_array = self::get_table_columns();

    foreach ($object_array as $index => $column) {
      if($column === self::get_table_name_from_class().'_id') {
        $column = 'id';
      }
      if(is_array($param)) {
        $this->$column = $param[$column];
      } else {
        $this->$column = null;
      }
    }
    $this->new_record = is_bool($param) ? $param : true;
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
   * @param mixed $pk_value
   * @return model | null
   */
  public static function find($pk_value = 0) {
    $result_array = static::find_by_sql("SELECT * FROM " .self::get_table_name(). " WHERE ".self::get_primary_key()."='{$pk_value}' LIMIT 1");
    if(!empty($result_array)) {
      return array_shift($result_array);
    }
    throw new \Exception(sprintf('%s record not found in database with %s = %s', get_called_class(), self::get_primary_key(), $pk_value));
  }

  /**
   * Base method for finding records by column name and value
   *
   * @access public
   * @static
   * @param string $column
   * @param mixed $value
   * @param int $fetch
   * @return model | array
   */
  public static function find_by($column, $value, $fetch = self::FETCH_ONE) {
    $sql = "SELECT * FROM ".self::get_table_name(). " WHERE {$column} = '{$value}'";
    $sql .= $fetch === self::FETCH_ONE ? ' LIMIT 1' : "";
    $result_array = static::find_by_sql($sql);
    return !empty($result_array) ? ($fetch == self::FETCH_ONE ? array_shift($result_array) : $result_array) : [];
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
        if(class_exists($class_name)) {
          $object_array[] = $class_name::instantiate($row, false);
        } else {
          throw new \Exception(sprintf('Cannot instantiate relationship of type %s class does not exist', $class_name));
        }
      }
    }
    return $object_array;
  }

  /**
   * Insert a new record in the database
   *
   * @access public
   * @return void
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
    * Create associated objects
    *
    * @access public
    * @return model
    */
    public function build($class_name, $param = null) {
      $pk = self::get_primary_key();
      if(isset($this->$pk)) {
        $pk_value = $this->$pk;
      } else if(isset($this->id)) {
        $pk_value = $this->id;
      } else {
        throw new Exception(sprintf('Unable to build a %s record, parent has no primary key value', $class_name));
      }
      $param[$pk] = $pk_value;
      return new $class_name($param);
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

    if(substr($method_name, 0, 7) === 'find_by') {
      // Extract the field name from the method name
      $array = split('_', $method_name);
      array_splice($array, 0, 2);
      $field =  implode('_', $array);

      if(in_array($field, self::get_table_columns())) {
        array_unshift($args, $field);
        return call_user_func_array(array($class_name, 'find_by'), $args);
      }

      throw new \Exception(sprintf('There is no attribute named "%s" in the class "%s".', $field, $class_name));
    }
  }

  public function __call($method_name, $args) {
    $class_name = get_called_class();

    if(substr($method_name, 0, 5) === 'build') {
      $associated_class = trim(str_replace('build', '', $method_name), '_');
      $associated_class = self::snake_case_to_camel_case($associated_class);
      if(in_array($associated_class, $this->get_associated_classes())) {
        array_unshift($args, $associated_class);
        return call_user_func_array(array($class_name, 'build'), $args);
      }

      throw new \Exception(sprintf('There is no association with the class "%s" on "%s" object.', $associated_class, $class_name));
    }
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
      throw new \Exception(sprintf('Unable to extract column values from %s', gettype($record)));
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
   * @param model $object
   * @return void
   */
  private static function instantiate_relationships($object) {
    if(isset(static::$has_many)) {
      if((count(static::$has_many) !== count(static::$has_many, COUNT_RECURSIVE))) {
        self::add_related_objects($object, static::$has_many, self::HAS_MANY);
      } else {
        throw new \Exception('Has many associations are not declared as arrays');
      }
    }

    if(isset(static::$belongs_to)) {
      if((count(static::$belongs_to) !== count(static::$belongs_to, COUNT_RECURSIVE))) {
        self::add_related_objects($object, static::$belongs_to, self::BELONGS_TO);
      } else {
        throw new \Exception('Belongs to associations are not declared as arrays');
      }
    }
  }

  /**
   * Add instantiated objects to the model according to the association type
   *
   * @access private
   * @param model reference $object
   * @param array $collection
   * @param int $relation_type
   * @return void
   */
  private function add_related_objects(&$object, $collection, $relation_type) {
    foreach ($collection as $relation) {
      // $relation_name = $related_table = $class_name = null;
      $relation_size = count($relation);
      if($relation_size > 3) {
        throw new \Exception("To many arguments, {$relation_size} given, expected 1..3");
      }

      $relation = self::generate_association_info($relation, $relation_size, $relation_type);

      if($relation_type === self::HAS_MANY) {
        $sql = "SELECT * FROM {$relation[1]} WHERE ".self::get_table_name() . "_id" ." = '{$object->id}'";
        $object->$relation[0] = self::find_by_sql($sql, $relation[2]);
      } else if($relation_type === self::BELONGS_TO) {
        $fk = $relation[1] . '_id';
        $sql = "SELECT * FROM {$relation[1]} WHERE ". $relation[1] . "_id" ." = '{$object->$fk}'";
        $result_array = self::find_by_sql($sql, $relation[2]);
        $object->$relation[0] = !empty($result_array) ? array_shift($result_array) : null;
      }
    }
  }

  /**
   * Returns association, table and class names in array form
   *
   * @access private
   * @param array $relation
   * @param int $size
   * @param int $relation_type
   * @return array
   */
  private function generate_association_info($relation, $size, $relation_type = self::HAS_MANY) {
    if($size === 1) {
      if($relation_type === self::HAS_MANY) {
        $relation[] = substr($relation[0], 0, - 1);
      } else if($relation_type === self::BELONGS_TO) {
        $relation[] = $relation[0];
      }
      $size++;
    }
    if($size == 2) {
      $relation[] = self::snake_case_to_camel_case($relation[1]);
    }

    return $relation;
  }

  /**
   * Return the object attributes allowed to be updated at database level
   *
   * @access private
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

  /**
   * Return the needed bind_param that corresponds a datatype
   *
   * @access private
   * @param mixed $value
   * @return char
   */
  private function get_data_type($value) {
    if(is_int($value)) return 'i';
    if(is_double($value)) return 'd';
    return 's';
  }

  /**
   * Return associated class names for the calling object
   *
   * @access private
   * @return array
   */
  private function get_associated_classes() {
    $has_many_classes = $belongs_to_classes = array();
    if(isset(static::$has_many)) {
      $relation_type = self::HAS_MANY;
      $has_many_classes = self::get_associated_classes_for(static::$has_many, $relation_type);
    }
    if(isset(static::$belongs_to)) {
      $relation_type = self::BELONGS_TO;
      $belongs_to_classes = self::get_associated_classes_for(static::$belongs_to, $relation_type);
    }
    return array_merge($has_many_classes, $belongs_to_classes);
  }

  /**
   * Return associated class names a specific relation
   *
   * @access private
   * @param array $collection
   * @param int $relation_type
   * @return array
   */
  private function get_associated_classes_for($collection, $relation_type) {
    $array = array();
    foreach ($collection as $relation) {
      $relation_size = count($relation);
      $relation = self::generate_association_info($relation, $relation_size, $relation_type);
      $array[] = $relation[2];
    }

    return $array;
  }

}

?>
