<?
class SQLConn
{
	protected $_dbHandle = false;
	protected $_isConn = false;
	protected $_result;

	public function __construct( $db_host, $db_user, $db_password, $db_name )
	{
		try
		{
			$this->_dbHandle = new PDO( 'mysql:host=' . $db_host . ';dbname=' . $db_name, $db_user, $db_password );
		}
		catch( PDOException $e )
		{
			$this->put_error( $e->getMessage() );
			//echo "Could not connect to DB";
			return false;
		}

		$this->_dbHandle->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->_dbHandle->setAttribute( PDO::ATTR_EMULATE_PREPARES, false );

		$this->_isConn = true;
	}

	public function isValid()
	{
		return $this->_isConn;
	}

	protected function put_error( $msg )
	{
		error_log( $msg );
		return;
	}

	public function beginTransaction()
	{
		return $this->_dbHandle->beginTransaction();
	}

	public function commit()
	{
		return $this->_dbHandle->commit();
	}

	public function rollBack()
	{
		return $this->_dbHandle->rollBack();
	}

	public function clean( $string, $type = "str" )
	{
		$toReturn = $this->_dbHandle->quote( $string, PDO::PARAM_STR );

		switch( $type )
		{
			case "bool":
			case "null":
			case "int":
			case "noquote":
				$toReturn = substr( $toReturn, 1, -1 );
				break;
			default:
			case "str":
				$toReturn = $toReturn;
				break;
		}

		return $toReturn;
	}

	public function query( $query )
	{
		$params = null;
		if( func_get_args() > 1 )
		{
			$params = func_get_args();
			array_shift( $params );
		}

		$return = $this->_query( $query, $params );

		return $return;
	}

	protected function _query( $query, $params )
	{
		$query = trim( $query );

		$results = new SQLResult();

		// no query called
		if( $query == "" || !is_string( $query ) || $this->_dbHandle == null )
		{
			return $results;
		}

		$results->set_query( $query, $params );

		try
		{
			$stmt = $this->_dbHandle->prepare( $query );
		}
		catch( PDOException $e )
		{
			$this->put_error( $e->getMessage() . " SQL STATEMENT:" . $query );
			return $results;
		}

		$results->set_stmt( $stmt );
		if( $params != null && is_array( $params ) && !empty( $params ) )
		{
			// make sure they're all the same length
			$lens = array();
			foreach( $params as $key => $param )
			{
				if( !array( $param ))
				{
					$this->put_error( "Parameter " . $key . " is not an array. SQL STATEMENT:" . $query );
					return $results;
				}
				array_push( $lens, count( $param ) );
			}
			unset( $param, $key );
			$lens = array_unique( $lens );

			if( count( $lens ) != 1 )
			{
				$this->put_error( "Statement contains both question mark and named placeholders. SQL STATEMENT:" . $query );
				return $results;
			}
			$len = $lens[0];

			// go through each param
			for( $i = 1; $i <= count( $params ); $i++ )
			{
				$key = $i-1;
				$parameter 	= $i;
				$value 		= $params[$key][0];
				$data_type 	= $params[$key][1];

				// named placeholders
				if( $len == 3 )
				{
					$parameter 	= $params[$key][0];
					$value 		= $params[$key][1];
					$data_type 	= $params[$key][2];

					if( !in_array( $data_type, array( "bool", "null", "int", "str", "noquote" ) ) )
					{
						$data_type = "str";
					}
				}
				try
				{
					$test = $results->get_stmt()->bindValue( $parameter, $value, constant( "PDO::PARAM_" . strtoupper( $data_type ) ) );
					if( $results->get_stmt()->bindValue( $parameter, $value, constant( "PDO::PARAM_" . strtoupper( $data_type ) ) ) === false )
					{
						$this->put_error( "Statement parameter " . $parameter . " ( " . $parameter . "," . $value . "," . $data_type . " ) is invalid. SQL STATEMENT:" . $query );
						return $results;
					}
				}
				catch( Exception $e )
				{
					$this->put_error( $e );
				}
			}
		}

		try
		{
			$results->get_stmt()->execute();
		}
		catch( PDOException $e )
		{
			$this->put_error( $e->getMessage() . " SQL STATEMENT:" . $query );
			return $results;
		}

		$result = array();
		$table = array();
		$field = array();
		$tempResults = array();

		if( preg_match( "/^(\()?(\s)?select/im", $query ) && $results->get_stmt()->rowCount() > 0 )
		{
			$numOfFields = $results->get_stmt()->columnCount();
			for( $i = 0; $i < $numOfFields; ++$i )
			{
				$meta = $results->get_stmt()->getColumnMeta( $i );

				array_push( $field, $meta['name'] );

				if( empty( $meta['table'] ) )
				{
					array_push( $table, "fn" );
				}
				else
				{
					array_push( $table, $meta['table'] );
				}
			}

			while( $row = $results->get_stmt()->fetch( PDO::FETCH_NUM ) )
			{
				for( $i = 0; $i < $numOfFields; ++$i )
				{
					$table[$i] = Inflection::singularize( $table[$i] );
					$tempResults[$table[$i]][$field[$i]] = $row[$i];
				}
				array_push( $result, $tempResults );
			}
		}

		$results->set_results( $result );

		return $results;
	}

	public function id()
	{
		if( $this->isValid() === false )
		{
			return false;
		}

		return $this->_dbHandle->lastInsertId();
	}

	public function error()
	{
		if( $this->isValid() === false )
		{
			return false;
		}

		return ( $this->_dbHandle->errorCode() != "00000" );
	}

	public function errno()
	{
		if( $this->isValid() === false )
		{
			return false;
		}

		return $this->_dbHandle->errorCode();
	}

	public function errdesc()
	{
		if( $this->isValid() === false )
		{
			return false;
		}

		return print_r( $this->_dbHandle->errorInfo(), true );
	}

	public static function merge()
	{
		$newResultsArr = array();

		$passedResults = func_get_args();

		foreach( $passedResults as $result )
		{
			if( $result instanceof SQLResult === false )
			{
				continue;
			}

			$newResultsArr = array_merge( $newResultsArr, $result->get_results() );
		}

		$mergedResults = new SQLResult();
		$mergedResults->set_results( $newResultsArr );

		return $mergedResults;
	}
}

class SQLResult
{
	protected $_results			= array();
	protected $_isValid 		= false;
	protected $_query 			= null;
	protected $_params 			= null;
	protected $_stmt 			= null;
	protected $_pos 			= -1;
	protected $_wasSerialized 	= false;

	public function __contruct(){}

	public function __sleep()
	{
		return array(
			"_isValid",
			"_query",
			"_params",
			"_results",
			"_pos",
		);
	}

	public function __wakeup()
	{
		$this->_wasSerialized = true;
	}

	/* INITIALIZATION */
	/* ****************************** */
		// SETTERS
		public function set_query( $query, $params )
		{
			$this->_query = $query;
			$this->_params = $params;
		}

		public function set_stmt( $stmt )
		{
			if( get_class( $stmt ) != "PDOStatement" )
			{
				throw new Exception( "SQLResult: not a valid PDOStatement object" );
			}

			$this->_stmt = $stmt;
		}

		public function set_results( $results )
		{
			if( !is_array( $results ) )
			{
				throw new Exception( "SQLResult: not a valid result array" );
			}

			foreach( $results as $result )
			{
				$this->set_result( $result );
			}

			$this->_isValid = true;
		}

		public function set_result( $result )
		{
			$row = new SQLRow( $result );
			array_push( $this->_results, $row );
		}

		// GETTERS
		public function get_query()
		{
			return $this->_query . " - Params: " . print_r( $this->_params, true );
		}

		public function get_stmt()
		{
			return $this->_stmt;
		}

		public function get_results()
		{
			return $this->_results;
		}

	/* PRIVATE */
	/* ****************************** */
		protected function has_statement_error()
		{
			return ( $this->get_stmt()->errorCode() != "00000" );
		}

		protected function has_dbh_error()
		{
			return Registry::get("_dbh")->error();
		}

		protected function wasSerialized()
		{
			return $this->_wasSerialized;
		}

	/* ERROR DETAILS */
	/* ****************************** */
		public function isValid()
		{
			return $this->_isValid;
		}

		public function error()
		{
			if( $this->wasSerialized() && $this->isValid() )
			{
				return false;
			}
			else if(
				is_null( $this->get_stmt() )
				|| $this->has_statement_error()
				|| $this->has_dbh_error()
			)
			{
				return true;
			}

			return false;
		}

		public function errno()
		{
			if( is_null( $this->get_stmt() ) || $this->has_dbh_error() )
			{
				return Registry::get("_dbh")->errno();
			}
			else if( $this->has_statement_error() )
			{
				return $this->get_stmt()->errorCode();
			}

			return 0;
		}

		public function errdesc()
		{
			if( is_null( $this->get_stmt() ) || $this->has_dbh_error() )
			{
				return Registry::get("_dbh")->errdesc();
			}
			else if( $this->has_statement_error() )
			{
				return print_r( $this->get_stmt()->errorInfo(), true );
			}

			return "";
		}

		public function as_array()
		{
			$rowsArray = array();
			foreach( $this->_results as $key => $row )
			{
				if( method_exists( $row, "as_array" ) )
				{
					$rowsArray[$key] = $row->as_array();
					continue;
				}

				$rowsArray[$key] = $row;
			}

			return $rowsArray;
		}

	/* SETTERS, GETTERS */
	/* ****************************** */
		public function __get( $key )
		{
			if( !isset( $this->$key ) && $this->first() !== false )
			{
				return $this->first()->$key;
			}

			return null;
		}

		public function __set( $key, $value )
		{
			if( $this->first() === false )
			{
				return null;
			}

			$this->first()->$key = $value;
		}

	/* TRAVERSAL */
	/* ****************************** */
		public function first()
		{
			if( !isset( $this->_results[0] ) )
			{
				return null;
			}

			return $this->_results[0];
		}

		public function last()
		{
			if( $this->length() == 0 )
			{
				return null;
			}

			return $this->_results[$this->length()-1];
		}

		public function curr()
		{
			$pos = ( $this->_pos == -1 && $this->length() > 0 ? 0 : -1 );
			if( !isset( $this->_results[$pos] ) )
			{
				return curr;
			}

			return $this->_results[ $this->_pos ];
		}

		public function next()
		{
			if( !isset( $this->_results[$this->_pos+1] ) )
			{
				return false;
			}
			return $this->_results[++$this->_pos];
		}

		public function prev()
		{
			if( !isset( $this->_results[$this->_pos-1] ) )
			{
				return false;
			}

			return $this->_results[--$this->_pos];
		}

		public function reset()
		{
			$this->_pos = -1;
			return true;
		}

		public function position()
		{
			return $this->_pos;
		}

	/* USABILITY */
	/* ****************************** */
		public function all()
		{
			return $this->_results;
		}

		public function length()
		{
			return count( $this->_results );
		}

		public function search( $table, $field = "" )
		{
			// get position, then go to the beginning
			$pos = $this->_pos;
			$this->reset();

			// store the values
			$vals = array();
			while( $row = $this->next() )
			{
				if( isset( $row->$table->$field ) && ( $val = $row->$table->$field ) != "" )
				{
					array_push( $vals, $val );
				}
			}

			$this->_pos = $pos;

			return $vals;
		}

		public function apply_function_to_cells( $table, $field, $functionName, $additionalParams = array() )
		{
			if( !function_exists( $functionName ) )
			{
				return false;
			}

			// get position, then go to the beginning
			$pos = $this->_pos;
			$this->reset();

			// store the values
			while( $row = $this->next() )
			{
				$newVal = @call_user_func_array( $functionName, array_merge( array( $row->$table->$field ), $additionalParams ) );

				if( $newVal === false )
				{
					return false;
				}

				$row->$table->$field = $newVal;
			}

			$this->_pos = $pos;

			return true;
		}

		public function shuffle()
		{
			shuffle( $this->_results );

			return $this;
		}

		public function reverse()
		{
			$this->_results = array_reverse( $this->_results );

			return $this;
		}

		public function slice( $start, $end = -1 )
		{
			if( $end == -1 )
			{
				$end = $this->lenght();
			}

			$this->reset();

			while( $row = $this->next() )
			{
				if( $this->position() < $start || $this->position() >= $end )
				{
					unset( $this->_results[$this->position()] );
				}
			}

			$this->reset();

			return $this;
		}

		public function merge( $results2 )
		{
			if( $results2 instanceof SQLResult === false )
			{
				return false;
			}

			$new = new SQLResult();

			$rows = $this->_results;
			$rows2 = $results2->_results;

			print_r( $rows );
			print_r( $rows2 );

			$newRows = array_merge( $rows, $rows2 );

			$new->_results = $newRows;

			return $new;
		}
}

class SQLTable
{
	protected $_cells = array();

	public function __construct( $cells = array() )
	{
		if( !empty( $cells ) && ( is_array( $cells ) || is_object( $cells ) ) )
		{
			foreach( $cells as $key => $value )
			{
				$this->$key = $value;
			}
		}
	}

	public function __set( $key, $value )
	{
		#echo var_export( $value );
		if( $value instanceof SQLResult )
		{
			#echo "--- ";
			$this->_cells[$key] = $value;
		}
		else if( $value instanceof SQLRow || $value instanceof SQLTable )
		{
			#echo "+++ ";
			#print_r( $value );
			$this->_cells[$key] = $value;
		}
		else
		{
			#echo "=== ";
			$this->_cells[$key] = $value;
		}
	}

	public function __get( $key )
	{
		if( !isset( $this->_cells[$key] ) )
		{
			return null;
		}

		return $this->_cells[$key];
	}

	public function __isset( $key )
	{
		$val = $this->$key;

		return ( $val !== null );
	}

	public function __toString()
	{
		return print_r( $this->as_array(), true );
	}

	public function length()
	{
		return count( $this->_cells );
	}

	public function as_array()
	{
		$cellsArr = array();
		foreach( $this->_cells as $key => $cell )
		{
			if( method_exists( $cell, "as_array" ) )
			{
				$cellsArr[$key] = $cell->as_array();
				continue;
			}

			$cellsArr[$key] = $cell;
		}

		return $cellsArr;
	}
}

class SQLRow
{
	protected $_row = array();

	public function __construct( $tables = array() )
	{
		if( !empty( $tables ) )
		{
			foreach( $tables as $key => $value )
			{
				$this->$key = $value;
			}
		}
	}

	public function __set( $key, $value )
	{
		if( is_array( $value ) )
		{
			$cell = new SQLTable( $value );
			$this->_row[$key] = $cell;
		}
		else if( $value instanceof SQLResult )
		{
			$this->_row[$key] = $value;
		}
		else if( $value instanceof SQLRow || $value instanceof SQLTable )
		{
			$this->_row[$key] = $value;
		}
	}

	public function __get( $key )
	{
		if( !isset( $this->_row[$key] ) )
		{
			return null;
		}

		return $this->_row[$key];
	}

	public function __isset( $key )
	{
		$val = $this->$key;

		return ( $val !== null );
	}

	public function __toString()
	{
		return print_r( $this->as_array(), true );
	}

	public function as_array()
	{
		$tableArr = array();
		foreach( $this->_row as $key => $table )
		{
			if( method_exists( $table, "as_array" ) )
			{
				$tableArr[$key] = $table->as_array();
				continue;
			}

			$tableArr[$key] = $table;
		}

		return $tableArr;
	}

	public function as_object( $arr )
	{
		if( is_array( $arr ) )
		{
			$arr = (object) $arr;
		}

		if( is_object( $arr ) )
		{
			$new = new stdClass();

			foreach( $arr as $key => $val )
			{
				$new->{$key} = $this->as_object( $val );
			}
		}
		else
		{
			$new = $arr;
		}

		return $new;
	}
}

class SQLCell
{
	protected $_val;

	public function __construct( $val = "" )
	{
		$this->_val = $val;
	}

	public function __set( $key, $value )
	{
		$this->_val = $value;
	}

	public function __get( $key )
	{
		return $this->_val;
	}

	public function __isset( $key )
	{
		return true;
	}

	public function __toString()
	{
		return (string) $this->_val;
	}

	public function as_array()
	{
		return $this->_val;
	}
}

class Inflection
{
	static $plural = array(
		'/(quiz)$/i'               => "$1zes",
		'/^(ox)$/i'                => "$1en",
		'/([m|l])ouse$/i'          => "$1ice",
		'/(matr|vert|ind)ix|ex$/i' => "$1ices",
		'/(x|ch|ss|sh)$/i'         => "$1es",
		'/([^aeiouy]|qu)y$/i'      => "$1ies",
		'/(hive)$/i'               => "$1s",
		'/(?:([^f])fe|([lr])f)$/i' => "$1$2ves",
		'/(shea|lea|loa|thie)f$/i' => "$1ves",
		'/sis$/i'                  => "ses",
		'/([ti])um$/i'             => "$1a",
		'/(tomat|potat|ech|her|vet)o$/i'=> "$1oes",
		'/(bu)s$/i'                => "$1ses",
		'/(alias)$/i'              => "$1es",
		'/(octop)us$/i'            => "$1i",
		'/(ax|test)is$/i'          => "$1es",
		'/(us)$/i'                 => "$1es",
		'/s$/i'                    => "s",
		'/$/'                      => "s"
	);

	static $singular = array(
		'/(quiz)zes$/i'             => "$1",
		'/(matr)ices$/i'            => "$1ix",
		'/(vert|ind)ices$/i'        => "$1ex",
		'/^(ox)en$/i'               => "$1",
		'/(alias)es$/i'             => "$1",
		'/(octop|vir)i$/i'          => "$1us",
		'/(cris|ax|test)es$/i'      => "$1is",
		'/(shoe)s$/i'               => "$1",
		'/(o)es$/i'                 => "$1",
		'/(bus)es$/i'               => "$1",
		'/([m|l])ice$/i'            => "$1ouse",
		'/(x|ch|ss|sh)es$/i'        => "$1",
		'/(m)ovies$/i'              => "$1ovie",
		'/(s)eries$/i'              => "$1eries",
		'/([^aeiouy]|qu)ies$/i'     => "$1y",
		'/([lr])ves$/i'             => "$1f",
		'/(tive)s$/i'               => "$1",
		'/(hive)s$/i'               => "$1",
		'/(li|wi|kni)ves$/i'        => "$1fe",
		'/(shea|loa|lea|thie)ves$/i'=> "$1f",
		'/(^analy)ses$/i'           => "$1sis",
		'/((a)naly|(b)a|(d)iagno|(p)arenthe|(p)rogno|(s)ynop|(t)he)ses$/i'  => "$1$2sis",
		'/([ti])a$/i'               => "$1um",
		'/(n)ews$/i'                => "$1ews",
		'/(h|bl)ouses$/i'           => "$1ouse",
		'/(corpse)s$/i'             => "$1",
		'/(us)es$/i'                => "$1",
		'/ss$/i'                    => "ss",
		'/s$/i'                     => "",
	);

	static $irregular = array(
		'move'   => 'moves',
		'foot'   => 'feet',
		'goose'  => 'geese',
		'sex'    => 'sexes',
		'child'  => 'children',
		'man'    => 'men',
		'tooth'  => 'teeth',
		'person' => 'people',
		'admin' => 'admin'
	);

	static $uncountable = array(
		'sheep',
		'fish',
		'deer',
		'series',
		'species',
		'money',
		'rice',
		'information',
		'equipment'
	);

	public static function pluralize( $string )
	{
		global $irregularWords;

		// save some time in the case that singular and plural are the same
		if( in_array( strtolower( $string ), self::$uncountable ) )
		{
			return $string;
		}

		// check for irregular singular forms
		if( !empty( $irregularWords ) )
		{
			foreach( $irregularWords as $pattern => $result )
			{
				$pattern = '/' . $pattern . '$/i';

				if( preg_match( $pattern, $string ) )
				{
					return preg_replace( $pattern, $result, $string );
				}
			}
		}

		// check for irregular singular forms
		foreach( self::$irregular as $pattern => $result )
		{
			$pattern = '/' . $pattern . '$/i';

			if( preg_match( $pattern, $string ) )
			{
				return preg_replace( $pattern, $result, $string );
			}
		}

		// check for matches using regular expressions
		foreach( self::$plural as $pattern => $result )
		{
			if( preg_match( $pattern, $string ) )
			{
				return preg_replace( $pattern, $result, $string );
			}
		}

		return $string;
	}

	public static function singularize( $string )
	{
		global $irregularWords;
		// save some time in the case that singular and plural are the same
		if( in_array( strtolower( $string ), self::$uncountable ) )
		{
			return $string;
		}

		if( !empty( $irregularWords ) )
		{
			// check for irregular words
			foreach( $irregularWords as $result => $pattern )
			{
				$pattern = '/' . $pattern . '$/i';
				if ( preg_match( $pattern, $string ) )
				{
					return preg_replace( $pattern, $result, $string );
				}
			}
		}

		// check for irregular plural forms
		foreach( self::$irregular as $result => $pattern )
		{
			$pattern = '/' . $pattern . '$/i';

			if( preg_match( $pattern, $string ) )
			{
				return preg_replace( $pattern, $result, $string );
			}
		}

		// check for matches using regular expressions
		foreach( self::$singular as $pattern => $result )
		{
			if( preg_match( $pattern, $string ) )
			{
				return preg_replace( $pattern, $result, $string );
			}
		}

		return $string;
	}

	public static function pluralize_if( $count, $string )
	{
		if( $count == 1 )
		{
			return "1 $string";
		}
		else
		{
			return $count . " " . self::pluralize( $string );
		}
	}
}
?>
