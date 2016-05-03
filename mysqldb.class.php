<?php

class MySQLDB {
  private $db;

  public function __construct($host, $user, $password, $db_name) {
    $this->open_connection($host, $user, $password, $db_name);
  }

  public function open_connection($host, $user, $password, $db_name) {
    $this->db = new mysqli($host, $user, $password, $db_name);

    if($this->db->mysqli_connect_errno) {
      die('Database connection failed: ' .
        $this->db->mysqli_connect_error .
        ' (' .$this->db->mysqli_connect_errno . ')'
      );
    }

    $this->db->set_charset('utf8');
  }

  public function close_connection() {
    if(isset($this->db)) {
      $this->db->close();
      unset($this->db);
    }
  }

  public function query($sql) {
    $result = $this->db->query($sql);
    $this->confirm_query($result);
    return $result;
  }

  public function escape_value($string) {
    return $this->db->real_escape_string($string);
  }

  // "Database neutral" functions

  public function fetch_array($result) {
    return $result->fetch_array();
  }

  public function fetch_assoc($result) {
    return $result->fetch_assoc();
  }

  public function num_rows() {
    return $this->db->num_rows;
  }

  public function insert_id() {
    // Get the last id inserted over the current db connection
    return $this->db->insert_id;
  }

  public function affected_rows() {
    return $this->db->affected_rows;
  }

  // Private functions

  private function confirm_query($result) {
    if (!$result) {
      die('Database query failed. ' . $this->db->error);
    }
  }

}

?>
