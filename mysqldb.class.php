<?php

class MySQLDB {
  private $connection;

  public function __construct($host, $user, $password, $db_name) {
    $this->open_connection($host, $user, $password, $db_name);
  }

  public function open_connection($host, $user, $password, $db_name) {
    $this->connection = mysqli_connect($host, $user, $password, $db_name);

    if(mysqli_connect_errno()) {
      die('Database connection failed: ' .
        mysqli_connect_error() .
        ' (' .mysqli_connect_errno() . ')'
      );
    }
  }

  public function close_connection() {
    if(isset($this->connection)) {
      mysqli_close($this->connection);
      unset($this->connection);
    }
  }

  public function query($sql) {
    $result = mysqli_query($this->connection, $sql);
    $this->confirm_query($result);
    return $result;
  }

  public function escape_value($string) {
    $escaped_string = mysqli_real_escape_string($this->connection, $string);
    return $escaped_string;
  }

  // "Database neutral" functions

  public function fetch_array($result_set) {
    return mysqli_fetch_array($result_set);
  }

  public function fetch_assoc($result_set) {
    return mysqli_fetch_assoc($result_set);
  }

  public function num_rows($result_set) {
    return mysqli_num_rows($result_set);
  }

  public function insert_id() {
    // Get the last id inserted over the current db connection
    return mysqli_insert_id($this->connection);
  }

  public function affected_rows() {
    return mysqli_affected_rows($this->connection);
  }

  // Private functions

  private function confirm_query($result) {
    if (!$result) {
      die('Database query failed. ' . mysqli_error($this->connection));
    }
  }

}

?>
