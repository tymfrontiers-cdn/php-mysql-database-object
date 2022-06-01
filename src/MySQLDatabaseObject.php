<?php
# *Note this might not work well with classes that has compulsory iniitialization parameters
namespace TymFrontiers\Helper;
use \TymFrontiers\InstanceError;

trait MySQLDatabaseObject{
  public $empty_prop = [];

  public static function findAll(){
    return static::findBySql("SELECT * FROM `:db:`.`:tbl:`");
  }
	public static function findById($id) {
		$result_array = static::findBySql("SELECT * FROM `:db:`.`:tbl:` WHERE :pkey:='{$id}' LIMIT 1");
		return !empty($result_array) ? array_shift($result_array) : false;
	}
	public static function findBySql (string $sql) {
    self::_checkEnv ();
		global $database;

    $sql = \str_replace([':database:',':db:'],static::$_db_name,$sql);
    $sql = \str_replace([':table:',':tbl:'],static::$_table_name,$sql);
    $sql = \str_replace([':pkey:',':primary_key:'],static::$_primary_key,$sql);
		// static::_getDbFields();
		if( $result_set = $database->query($sql) ){
			$object_array = [];
			while($row = $database->fetchArray($result_set)){
				$object_array[] = static::_instantiate($row);
			}
			return $object_array;
		}
    return false;
	}
  public static function primaryKey () { return static::$_primary_key; }
  public static function tableName () { return static::$_table_name; }
  public static function databaseName () { return static::$_db_name; }
  public static function tableFields () { return static::$_db_fields; }
  public static function valExist( string $val, string $field_name='username'){
    self::_checkEnv ();
		global $database;

		$val = $database->escapeValue($val);
		$field_name = $database->escapeValue($field_name);
		$sql = "SELECT * FROM :db:.:tbl: ";
		$sql .= " WHERE `{$field_name}` = '{$val}' ";
		$sql .= "LIMIT 1";
		$result_array = self::findBySql($sql);
		return !empty($result_array);
	}
  public static function 	countAll(){
    self::_checkEnv ();
    global $database;

		$sql = "SELECT COUNT(*) FROM `".static::$_db_name."`.`".static::$_table_name."`";
		$resultSet = $database->query($sql);
		$row = $database->fetchArray($resultSet);
		return array_shift($row);
	}
  public function save(){
    $pkey = static::$_primary_key;
    return !empty( $this->$pkey ) ? $this->_update() : $this->_create();
  }
  public function delete($conn = false){
    if (!$conn instanceof \TymFrontiers\MySQLDatabase) {
      self::_checkEnv ();
      global $database;
      $conn =& $database;
    }
    // there must be an instance of \TymFrontiers\MySQLDatabase in the name of $database or $database on global scope

    $pkey = static::$_primary_key;
		$sql = "DELETE FROM `".static::$_db_name."`.`".static::$_table_name."`";
		$sql .= " WHERE {$pkey} = '{$conn->escapeValue($this->$pkey)}' ";
		$sql .= " LIMIT 1";
		if( $conn->query($sql) ){
      return ($conn->affectedRows() == 1) ? true : false;
    }else{
      $this->mergeErrors($conn);
    }
	}
  public function setProp (string $prop, $val) {
    if (\property_exists($this,$prop) ) $this->$prop = $val;
  }
	public function nextAutoIncrement () {
    self::_checkEnv ();
    global $database;

    $database_name = !empty( static::$_db_name)
      ? static::$_db_name
      : $database->getDBname();
    $tblname = static::$_table_name;
    if( empty($database_name) ){
      $this->errors['nextAutoIncrement'][] = [3,256,'Database name not set',__FILE__,__LINE__];
      return false;
    }
		$sql = "SELECT `AUTO_INCREMENT` ";
		$sql .= "FROM  INFORMATION_SCHEMA.TABLES ";
		$sql .= "WHERE TABLE_SCHEMA ='{$database_name}' ";
		$sql .= "AND   TABLE_NAME   = '{$tblname}' ";
		$resultSet = $database->query($sql);
    if( $resultSet ){
      $row = $database->fetchArray($resultSet);
      return !empty($row) ? \array_shift($row) : false;
    }else{
      $this->mergeErrors();
    }
    return false;
	}
	public function created () { return property_exists(__CLASS__,'_created') ? $this->_created : null; }
	public function updated () { return property_exists(__CLASS__,'_updated') ? $this->_updated : null; }
	public function author () { return property_exists(__CLASS__,'_author') ? $this->_author : null; }

  // private/protected methods
  public function isEmpty (string $prop, $value) {
    if( empty(static::$_prop_type) ) $this->_getFieldInfo ();
    if (\array_key_exists($prop,static::$_prop_type)) {
      switch ($prop) {
        case \in_array(\strtoupper(static::$_prop_type[$prop]),["BIT","TINYINT","BOOLEAN","SMALLINT","MEDIUMINT","INT","INTEGER","BIGINT"]):
          return \is_bool($value) ? false : empty((int)$value);
          break;
        case \in_array(\strtoupper(static::$_prop_type[$prop]),["FLOAT","DOUBLE","DECIMAL","DEC"]): // decimal
          return empty( (float)$value);
          break;
        case \in_array(\strtoupper(static::$_prop_type[$prop]),['DATE','DATETIME','TIMESTAMP','TIME','YEAR']): // date/time
          return !(bool) \strtotime($value);
          break;
        case \in_array(\strtoupper(static::$_prop_type[$prop]),["CHAR", "VARCHAR", "BLOB", "TEXT", "TINYBLOB", "TINYTEXT", "MEDIUMBLOB", "MEDIUMTEXT", "LONGBLOB", "LONGTEXT", "ENUM"]): // text
          return \is_bool($value) ? true : empty($value);
          break;
        default:
          return empty($value);
          break;
      }
    }
  }
  private static function _instantiate ($record) {
    $class_name = \get_called_class();
		// $object = new $class_name();
		$object = new $class_name (static::$_db_name,static::$_table_name,static::$_primary_key);
		foreach ($record as $attribute=>$value) {
      if ( !\is_int($attribute) ) {
        $object->$attribute = $value;
      }
			// if($object->_hasAttribute($attribute)){
			// }
		}
		return $object;
	}
	protected function _getDbFields () {
    self::_checkEnv ();
    global $database;

    $result = $database->query("SHOW COLUMNS FROM `".static::$_db_name."`.`".static::$_table_name."`");
    if( !$result ) $this->mergeErrors();
	  $field_names = [];
    if ($database->numRows($result) > 0) {
      while ($row = $database->fetchAssocArray($result)) {
        $field_names[] = $row['Field'];
      }
    }
		foreach ($field_names as $prop) {
			if( empty($this->$prop) ){
				$this->$prop = null;
			}
		}
    static::$_db_fields = $field_names;
	}
	public function _getFieldInfo () {
    static::_checkEnv ();
    global $database;

    $result = $database->query("SELECT COLUMN_NAME AS prop, DATA_TYPE AS type, CHARACTER_MAXIMUM_LENGTH AS size FROM INFORMATION_SCHEMA.COLUMNS
    WHERE table_name = '".static::$_table_name."'");
    if( !$result ) $this->mergeErrors();
    if ($database->numRows($result) > 0) {
      while ($row = $database->fetchAssocArray($result)) {
        static::$_prop_type[$row['prop']] = $row['type'];
        static::$_prop_size[$row['prop']] = (int)$row['size'];
      }
    }
	}
  private function _hasAttribute ($attribute) {
		$object_vars = $this->_attributes();
		return \array_key_exists($attribute, $object_vars);
	}
	protected function _attributes () {
		$attributes = [];
		// $this->_getDbFields();
		if( empty(static::$_db_fields) ){ $this->_getDbFields();}
		foreach (static::$_db_fields as $field) {
			if(property_exists($this, $field)){
				$attributes[$field] = $this->$field;
			}
		}
		return $attributes;
	}
	protected function _sanitizedAttributes () {
    self::_checkEnv ();
    global $database;

    $clean_attributs = [];
    $this->_getFieldInfo();
		foreach ($this->_attributes() as $key => $value) {
      if (\in_array(\strtoupper(static::$_prop_type[$key]),["BIT", "TINYINT", "BOOLEAN", "SMALLINT"]) && (int)$value < 1) {
        $clean_attributs[$key] = (bool)$value ? 1 : 0;
      } else if(\in_array($key, $this->empty_prop)) {
        // get empty type
        if (
          \in_array(\strtoupper(static::$_prop_type[$key]), ["BIT","TINYINT","BOOLEAN","SMALLINT","MEDIUMINT","INT","INTEGER","BIGINT", "FLOAT","DOUBLE","DECIMAL","DEC"])
          && $this->isEmpty($key, $value)
          ) {
          $clean_attributs[\strtoupper(static::$_key_type[$prop])] = 0;
        } else if(\in_array($key, ['DATE','DATETIME','TIMESTAMP','TIME','YEAR']) && $this->isEmpty($key, $value)) {
          $clean_attributs[$key] = NULL;
        } else {
          if ($this->isEmpty($key, $value)) {
            $clean_attributs[$key] = "";
          } else {
            $clean_attributs[$key] = $database->escapeValue($value);
          }
        }
      } else {
        if (!empty($value)) $clean_attributs[$key] = $database->escapeValue($value);
      }
		}
		return $clean_attributs;
	}
	protected function _create ($conn = false) {
    if (!$conn instanceof \TymFrontiers\MySQLDatabase) {
      self::_checkEnv ();
      global $database;
      $conn =& $database;
    }
    global $session;

		if( property_exists(__CLASS__, '_created'))	$this->_created = \date("Y-m-d H:i:s",time());
		if( property_exists(__CLASS__, '_updated'))	$this->_updated = \date("Y-m-d H:i:s",time());
		if( property_exists(__CLASS__, '_author')){
      if( !($session instanceof \TymFrontiers\Session) ){
        $this->errors['_create'][] = [3,256,'There must be an instance of TymFrontiers\Session in the name of \'$session\' on global scope',__FILE__,__LINE__];
        return false;
      }
      $this->_author = $session->name;
    }
		$attributes = $this->_sanitizedAttributes ();
    foreach ($attributes as $key => $value) {
      if( $this->isEmpty($key,$value) ) unset($attributes[$key]);
    }
		$sql = "INSERT INTO `".static::$_db_name."`.`".static::$_table_name."` (";
		$sql .= "`". join("`, `", array_keys($attributes))."`";
		$sql .= ") VALUES ('";
		$sql .= join("', '", array_values($attributes));
		$sql .= "')";
		if( $conn->query($sql) ){
			if( \property_exists(__CLASS__,'id') ) $this->id = $conn->insertId();
			return true;
		}else{
      $this->mergeErrors($conn);
			return false;
		}
	}
	protected function _update($conn = false){
    if (!$conn instanceof \TymFrontiers\MySQLDatabase) {
      self::_checkEnv ();
      global $database;
      $conn =& $database;
    }

		if( \property_exists(__CLASS__,'_updated') ){ $this->_updated = \date("Y-m-d H:i:s",time()); }
    $pkey = static::$_primary_key;
		$attributes = $this->_sanitizedAttributes();
		$attribute_pairs = [];
		foreach ($attributes as $key => $value) {
      $attribute_pairs[] = !\in_array(\strtoupper(static::$_prop_type[$key]),['DATE','DATETIME','TIMESTAMP','TIME','YEAR'])
      ? "`{$key}`='{$value}'"
      : "`{$key}`=". (!empty($value) ? "'{$value}'" : "NULL");
		}
		$sql = "UPDATE `".static::$_db_name."`.`".static::$_table_name."` SET ";
		$sql .= join(", ",$attribute_pairs);
		$sql .= " WHERE {$pkey} = '{$conn->escapeValue($this->$pkey)}' ";
		if( $conn->query($sql) ){
      // return true;
      return ($conn->affectedRows() == 1) ? true : 0;
    }else{
      $this->mergeErrors($conn);
      return false;
    }
	}
  public function mergeErrors($conn = false){
    if (!$conn instanceof \TymFrontiers\MySQLDatabase) {
      self::_checkEnv ();
      global $database;
      $conn =& $database;
    }

    $errors = (new InstanceError($conn,true))->get('query');
    if( $errors ){
      if( isset($conn->errors['query']) ) unset($conn->errors['query']);
      foreach($errors as $err){
        $this->errors['query'][] = $err;
      }
    }
  }
  protected function _listMoreErrors(string $method, object $instance, string $ins_method=''){
    if( !empty($instance->errors) ){
      $errors = (new InstanceError($instance,true))->get($ins_method);
      if( $errors ){
        foreach($errors as $err){
          $this->errors[$method][] = $err;
        }
      }
    }
  }
  private static function _checkEnv(){
    global $database;
    if ( !$database instanceof \TymFrontiers\MySQLDatabase ) {
      if(
        !\defined("MYSQL_BASE_DB") ||
        !\defined("MYSQL_SERVER") ||
        !\defined("MYSQL_GUEST_USERNAME") ||
        !\defined("MYSQL_GUEST_PASS")
      ){
        throw new \Exception("Required defination(s)[MYSQL_BASE_DB, MYSQL_SERVER, MYSQL_GUEST_USERNAME, MYSQL_GUEST_PASS] not [correctly] defined.", 1);
      }
      // check if guest is logged in
      $GLOBALS['database'] = new \TymFrontiers\MySQLDatabase(MYSQL_SERVER, MYSQL_GUEST_USERNAME, MYSQL_GUEST_PASS, self::$_db_name);
    }
  }

}
