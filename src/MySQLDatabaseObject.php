<?php
# *Note this might not work well with classes that has compulsory iniitialization parameters
namespace TymFrontiers\Helper;
use \TymFrontiers\InstanceError;

trait MySQLDatabaseObject{
  protected static $_prop_type = [];
  protected static $_prop_size = [];

  public static function findAll(){
    return static::findBySql("SELECT * FROM `:db:`.`:tbl:`");
  }
	public static function findById($id) {
		$result_array = static::findBySql("SELECT * FROM `:db:`.`:tbl:` WHERE :pkey:='{$id}' LIMIT 1");
		return !empty($result_array) ? array_shift($result_array) : false;
	}
	public static function findBySql(string $sql) {
		global $db,$database;
    // there must be an instance of TymFrontiers\MySQLDatabase in the name of $db or $databse on global scope
    $db = ( $db instanceof \TymFrontiers\MySQLDatabase ) ? $db : (
      ( $database instanceof \TymFrontiers\MySQLDatabase ) ? $database : false
    );
    if( !$db  ){
      throw new \Exception('There must be an instance of TymFrontiers\MySQLDatabase in the name of \'$db\' or \'$database\' on global scope', 1);
    }
    $sql = \str_replace([':database:',':db:'],static::$_db_name,$sql);
    $sql = \str_replace([':table:',':tbl:'],static::$_table_name,$sql);
    $sql = \str_replace([':pkey:',':primary_key:'],static::$_primary_key,$sql);
		// static::_getDbFields();
		if( $result_set = $db->query($sql) ){
			$object_array = [];
			while($row = $db->fetchArray($result_set)){
				$object_array[] = static::_instantiate($row);
			}
			return $object_array;
		}
    return false;
	}
  public static function valExist( string $val, string $field_name='username'){
		global $db;
    $db = ( $db instanceof \TymFrontiers\MySQLDatabase ) ? $db : (
      ( $database instanceof \TymFrontiers\MySQLDatabase ) ? $database : false
    );
    if( !$db  ){
      throw new \Exception('There must be an instance of TymFrontiers\MySQLDatabase in the name of \'$db\' or \'$database\' on global scope', 1);
    }
		$val = $db->escapeValue($val);
		$field_name = $db->escapeValue($field_name);
		$sql = "SELECT * FROM :db:.:tbl: ";
		$sql .= " WHERE `{$field_name}` = '{$val}' ";
		$sql .= "LIMIT 1";
		$result_array = self::findBySql($sql);
		return !empty($result_array);
	}
  public static function 	countAll(){
    global $db,$database;
    // there must be an instance of \TymFrontiers\MySQLDatabase in the name of $db or $databse on global scope
    $db = ($db instanceof \TymFrontiers\MySQLDatabase) ? $db : (
      ($database instanceof \TymFrontiers\MySQLDatabase) ? $database : false
    );
    if( !$db  ){
      static::$errors['countAll'][] = [3,256,'There must be an instance of TymFrontiers\MySQLDatabase in the name of \'$db\' or \'$databse\' on global scope',__FILE__,__LINE__];
      return false;
    }
		$sql = "SELECT COUNT(*) FROM `".static::$_db_name."`.`".static::$_table_name."`";
		$resultSet = $db->query($sql);
		$row = $db->fetchArray($resultSet);
		return array_shift($row);
	}
  public function save(){
    $pkey = static::$_primary_key;
    return !empty( $this->$pkey ) ? $this->_update() : $this->_create();
  }
  public function delete(){
    global $db,$database;
    // there must be an instance of \TymFrontiers\MySQLDatabase in the name of $db or $databse on global scope
    $db = ($db instanceof \TymFrontiers\MySQLDatabase) ? $db : (
      ($database instanceof \TymFrontiers\MySQLDatabase) ? $database : false
    );
    if( !$db  ){
      $this->errors['delete'][] = [3,256,'There must be an instance of TymFrontiers\MySQLDatabase in the name of \'$db\' or \'$databse\' on global scope',__FILE__,__LINE__];
      return false;
    }
    $pkey = static::$_primary_key;
		$sql = "DELETE FROM `".static::$_db_name."`.`".static::$_table_name."`";
		$sql .= " WHERE {$pkey} = '{$db->escapeValue($this->$pkey)}' ";
		$sql .= " LIMIT 1";
		if( $db->query($sql) ){
      return ($db->affectedRows() == 1) ? true : false;
    }else{
      $this->mergeErrors();
    }
	}
  public function setProp (string $prop, $val) {
    if (\property_exists($this,$prop) ) $this->$prop = $val;
  }
	public function nextAutoIncrement(){
    global $db,$database;
    // there must be an instance of \TymFrontiers\MySQLDatabase in the name of $db or $databse on global scope
    $db = ($db instanceof \TymFrontiers\MySQLDatabase) ? $db : (
      ($database instanceof \TymFrontiers\MySQLDatabase) ? $database : false
    );
    if( !$db  ){
      $this->errors['nextAutoIncrement'][] = [3,256,'There must be an instance of TymFrontiers\MySQLDatabase in the name of \'$db\' or \'$databse\' on global scope',__FILE__,__LINE__];
      return false;
    }
    $dbname = !empty( static::$_db_name) ? static::$_db_name : $db->getDBname();
    $tblname = static::$_table_name;
    if( empty($dbname) ){
      $this->errors['nextAutoIncrement'][] = [3,256,'Database name not set',__FILE__,__LINE__];
      return false;
    }
		$sql = "SELECT `AUTO_INCREMENT` ";
		$sql .= "FROM  INFORMATION_SCHEMA.TABLES ";
		$sql .= "WHERE TABLE_SCHEMA ='{$dbname}' ";
		$sql .= "AND   TABLE_NAME   = '{$tblname}' ";
		$resultSet = $db->query($sql);
    if( $resultSet ){
      $row = $db->fetchArray($resultSet);
      return !empty($row) ? array_shift($row) : false;
    }else{
      $this->mergeErrors();
    }
    return false;
	}
	public function created(){ return property_exists(__CLASS__,'_created') ? $this->_created : null; }
	public function updated(){ return property_exists(__CLASS__,'_updated') ? $this->_updated : null; }
	public function author(){ return property_exists(__CLASS__,'_author') ? $this->_author : null; }

  // private/protected methods
  public function isEmpty(string $prop, $value) {
    if( empty(static::$_prop_type) ) $this->_getFieldInfo();
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
  private static function _instantiate($record){
    $class_name = \get_called_class();
		// $object = new $class_name();
		$object = new $class_name(static::$_db_name,static::$_table_name,static::$_primary_key);
		foreach($record as $attribute=>$value){
      if( !\is_int($attribute) ){
        $object->$attribute = $value;
      }
			// if($object->_hasAttribute($attribute)){
			// }
		}
		return $object;
	}
	protected function _getDbFields() {
    global $db,$database;
    // there must be an instance of TymFrontiers\MySQLDatabase in the name of $db or $databse on global scope
    $db = ($db instanceof \TymFrontiers\MySQLDatabase) ? $db : (
      ($database instanceof \TymFrontiers\MySQLDatabase) ? $database : false
    );
    if( !$db  ){
      $this->errors['_getDbFields'][] = [3,256,'There must be an instance of TymFrontiers\MySQLDatabase in the name of \'$db\' or \'$databse\' on global scope',__FILE__,__LINE__];
      return false;
    }
    $result = $db->query("SHOW COLUMNS FROM `".static::$_db_name."`.`".static::$_table_name."`");
    if( !$result ) $this->mergeErrors();
	  $fieldnames = [];
    if ($db->numRows($result) > 0) {
      while ($row = $db->fetchAssocArray($result)) {
        $fieldnames[] = $row['Field'];
      }
    }
		foreach ($fieldnames as $prop) {
			if( empty($this->$prop) ){
				$this->$prop = null;
			}
		}
    static::$_db_fields = $fieldnames;
	}
	public function _getFieldInfo() {
    global $db,$database;
    // there must be an instance of TymFrontiers\MySQLDatabase in the name of $db or $databse on global scope
    $db = ($db instanceof \TymFrontiers\MySQLDatabase) ? $db : (
      ($database instanceof \TymFrontiers\MySQLDatabase) ? $database : false
    );
    if( !$db  ){
      $this->errors['_getFieldInfo'][] = [3,256,'There must be an instance of TymFrontiers\MySQLDatabase in the name of \'$db\' or \'$databse\' on global scope',__FILE__,__LINE__];
      return false;
    }
    $result = $db->query("SELECT COLUMN_NAME AS prop, DATA_TYPE AS type, CHARACTER_MAXIMUM_LENGTH AS size FROM INFORMATION_SCHEMA.COLUMNS
  WHERE table_name = '".static::$_table_name."'");
    if( !$result ) $this->mergeErrors();
    if ($db->numRows($result) > 0) {
      while ($row = $db->fetchAssocArray($result)) {
        static::$_prop_type[$row['prop']] = $row['type'];
        static::$_prop_size[$row['prop']] = (int)$row['size'];
      }
    }
	}
  private function _hasAttribute($attribute) {
		$object_vars = $this->_attributes();
		return \array_key_exists($attribute, $object_vars);
	}
	protected function _attributes(){
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
	protected function _sanitizedAttributes(){
    global $db,$database;
    // there must be an instance of TymFrontiers\MySQLDatabase in the name of $db or $databse on global scope
    $db = ($db instanceof \TymFrontiers\MySQLDatabase) ? $db : (
      ($database instanceof \TymFrontiers\MySQLDatabase) ? $database : false
    );
    if( !$db  ){
      $this->errors['_sanitizedAttributes'][] = [3,256,'There must be an instance of TymFrontiers\MySQLDatabase in the name of \'$db\' or \'$databse\' on global scope',__FILE__,__LINE__];
      return false;
    }
    $clean_attributs = [];
    if (empty(static::$_prop_type)) $this->_getFieldInfo();
		foreach ($this->_attributes() as $key => $value) {
      if (\in_array(\strtoupper(static::$_prop_type[$key]),["BIT", "TINYINT", "BOOLEAN", "SMALLINT"]) && (int)$value < 1) {
        $clean_attributs[$key] = (bool)$value ? 1 : 0;
      } else {
        $clean_attributs[$key] = $db->escapeValue($value);
      }
		}
		return $clean_attributs;
	}
	protected function _create(){
    global $db,$database,$session;
    // there must be an instance of TymFrontiers\MySQLDatabase in the name of $db or $databse on global scope
    $db = ($db instanceof \TymFrontiers\MySQLDatabase) ? $db : (
      ($database instanceof \TymFrontiers\MySQLDatabase) ? $database : false
    );
    if( !$db  ){
      $this->errors['_create'][] = [3,256,'There must be an instance of TymFrontiers\MySQLDatabase in the name of \'$db\' or \'$databse\' on global scope',__FILE__,__LINE__];
      return false;
    }
		if( property_exists(__CLASS__, '_created'))	$this->_created = strftime("%Y-%m-%d %H:%M:%S",time());
		if( property_exists(__CLASS__, '_updated'))	$this->_updated = strftime("%Y-%m-%d %H:%M:%S",time());
		if( property_exists(__CLASS__, '_author')){
      if( !($session instanceof \TymFrontiers\Session) ){
        $this->errors['_create'][] = [3,256,'There must be an instance of TymFrontiers\Session in the name of \'$session\' on global scope',__FILE__,__LINE__];
        return false;
      }
      $this->_author = $session->name;
    }
		$attributes = $this->_sanitizedAttributes();
    foreach ($attributes as $key => $value) {
      if( $this->isEmpty($key,$value) ) unset($attributes[$key]);
    }
		$sql = "INSERT INTO `".static::$_db_name."`.`".static::$_table_name."` (";
		$sql .= "`". join("`, `", array_keys($attributes))."`";
		$sql .= ") VALUES ('";
		$sql .= join("', '", array_values($attributes));
		$sql .= "')";
		if( $db->query($sql) ){
			if( \property_exists(__CLASS__,'id') ) $this->id = $db->insertId();
			return true;
		}else{
      $this->mergeErrors();
			return false;
		}
	}
	protected function _update(){
    global $db,$database,$session;
    // there must be an instance of \TymFrontiers\MySQLDatabase in the name of $db or $databse on global scope
    $db = ($db instanceof \TymFrontiers\MySQLDatabase) ? $db : (
      ($database instanceof \TymFrontiers\MySQLDatabase) ? $database : false
    );
    if( !$db  ){
      $this->errors['_update'][] = [3,256,'There must be an instance of TymFrontiers\MySQLDatabase in the name of \'$db\' or \'$databse\' on global scope',__FILE__,__LINE__];
      return false;
    }
		if( \property_exists(__CLASS__,'_updated') ){ $this->_updated = strftime("%Y-%m-%d %H:%M:%S",time()); }
    $pkey = static::$_primary_key;
		$attributes = $this->_sanitizedAttributes();
		$attribute_pairs = [];
		foreach ($attributes as $key => $value) {
      $attribute_pairs[] = "`{$key}`='{$value}'";
		}
		$sql = "UPDATE `".static::$_db_name."`.`".static::$_table_name."` SET ";
		$sql .= join(", ",$attribute_pairs);
		$sql .= " WHERE {$pkey} = '{$db->escapeValue($this->$pkey)}' ";
		if( $db->query($sql) ){
      // return true;
      return ($db->affectedRows() == 1) ? true : 0;
    }else{
      $this->mergeErrors();
      return false;
    }
	}
  public function mergeErrors(){
    global $db;
    $errors = (new InstanceError($db,true))->get('query');
    if( $errors ){
      if( isset($db->errors['query']) ) unset($db->errors['query']);
      foreach($errors as $err){
        $this->errors['query'][] = $err;
      }
    }
  }
  protected function _listMoreErrors(string $method='Self', object $instance, string $ins_method=''){
    if( !empty($instance->errors) ){
      $errors = (new InstanceError($instance,true))->get($ins_method);
      if( $errors ){
        foreach($errors as $err){
          $this->errors[$method][] = $err;
        }
      }
    }
  }
}
