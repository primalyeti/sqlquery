<?
class SQLQuery
{
    protected $_dbObj = false;
    protected $_result;

	public function __construct( $db_host, $db_user, $db_password, $db_name )
	{
		$this->_dbObj = new PDOConn( $db_host, $db_user, $db_password, $db_name );
		
		if( $this->_dbObj->isValid() == false )
		{
			echo "Could not connect to DB";
			return;
		}
	}
	
	public function clean( $string, $type = "str" )
	{
		return $this->_dbObj->clean( $string, $type );
	}
	
	public function query_obj( $query )
	{
		$params = null;
		if( func_get_args() > 1 )
		{
			$params = func_get_args();
			array_shift( $params );
		}
		
		$return = $this->_dbObj->query( $query, true, $params );
		
		return $return;
	}
	
	public function query( $query )
	{
		$params = null;
		if( func_get_args() > 1 )
		{
			$params = func_get_args();
			array_shift( $params );
		}
		
		$return = $this->_dbObj->query( $query, DBH_OBJ_DEFAULT, $params );
		
		return $return;
	}
	
	public function id()
	{
		return $this->_dbObj->id();
	}
	
	public function error()
	{
		return $this->_dbObj->error();
	}
	
	public function errno()
	{
		return $this->_dbObj->errno();
	}
	
	public function errdesc()
	{
		return $this->_dbObj->errdesc();
	}
}

interface SQLConn
{
	function connect( $host, $username, $password, $dbname );
	function isValid();
	function clean( $string );
	function query( $query, $isObj, $params );
	function id();
	function error();
	function errno();
	function errdesc();
}

abstract class SQLHandle implements SQLConn
{
	protected $_dbHandle = false;
    protected $_result;
    protected $_isConn = false;
    
    public function __construct( $host, $username, $password, $dbname )
	{
		$this->connect( $host, $username, $password, $dbname );
	}
	
	public function isValid()
    {
    	return $this->_isConn;
    }
	
	protected function put_error( $msg )
    {
	    if( ENVIRONMENT == "LIVE" )
		{
			error_log( $msg );
		}
		else
		{
			error_log( $msg );
		}
		
		return;
    }
}

class PDOConn extends SQLHandle
{
    /** Connects to database **/
    public function connect( $host, $username, $password, $dbname )
	{
		try
		{
			$this->_dbHandle = new PDO('mysql:host=' . $host . ';dbname=' . $dbname, $username, $password );
		}
		catch( PDOException $e )
		{
			$this->put_error( $e->getMessage() );
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

	public function clean( $string, $type = "str" )
	{
		$toReturn = $this->_dbHandle->quote( $string, PDO::PARAM_STR );
	
		switch( $type )
		{
			case "bool":
			case "null":
			case "int":
				$toReturn = substr( $toReturn, 1, -1 );
				break;
			default:
			case "str":
				$toReturn = $toReturn;
				break;
		}
		
		return $toReturn;
	}
		
	public function query( $query, $asObj, $params )
	{
		$query = trim( $query );
		
		$results = new SQLResult();
		
		// no query called
		if( $query == "" || !is_string( $query ) || $this->_dbHandle == null )
		{
			return ( $asObj == false ? false : $results );
		}
		
		$results->set_query( $query, $params );
		
		try
		{
			$stmt = $this->_dbHandle->prepare( $query );
		}
		catch( PDOException $e )
		{
			$this->put_error( $e->getMessage() . " SQL STATEMENT:" . $query );
			return ( $asObj == false ? false : $results );
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
					return ( $asObj == false ? false : $results );
				}
				array_push( $lens, count( $param ) );
			}
			unset( $param, $key );
			$lens = array_unique( $lens );
			
			if( count( $lens ) != 1 )
			{
				$this->put_error( "Statement contains both question mark and named placeholders. SQL STATEMENT:" . $query );
				return ( $asObj == false ? false : $results );
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
					
					if( !in_array( $data_type, array( "bool", "null", "int", "str" ) ) )
					{
						$data_type = "str";
					}
				}
				
				if( $results->get_stmt()->bindValue( $parameter, $value, constant( "PDO::PARAM_" . strtoupper( $data_type ) ) ) === false )
				{
					$this->put_error( "Statement parameter " . $parameter . " ( " . $parameter . "," . $value . "," . $data_type . " ) is invalid. SQL STATEMENT:" . $query );
					return ( $asObj == false ? false : $results );
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
			return ( $asObj == false ? false : $results );
		}
		
		$result = array();
		$table = array();
		$field = array();
		$tempResults = array();
				
		if( preg_match( "/^select/im", $query ) && $results->get_stmt()->rowCount() > 0 )
		{
			$numOfFields = $results->get_stmt()->columnCount();
			for( $i = 0; $i < $numOfFields; ++$i )
			{
				$meta = $results->get_stmt()->getColumnMeta( $i );
				
				array_push( $field, $meta['name'] );
				
				if( $asObj && empty( $meta['table'] ) )
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
		
		return ( $asObj == false ? $results->as_array() : $results );
	}
	
	public function id()
	{
		return $this->_dbHandle->lastInsertId();
	}

    /** Get error string **/
    public function error()
    {
        return ( $this->_dbHandle->errorCode() != "00000" );
    }
    
     /** Get error number **/
    public function errno()
    {
        return $this->_dbHandle->errorCode();
    }
    
    /** Get error desc **/
    public function errdesc()
    {
         return print_r( $this->_dbHandle->errorInfo(), true );
    }
}

class SQLResult
{
	protected $_isValid 		= false;
	protected $_query 			= null;
	protected $_params 			= null;
	protected $_stmt 			= null;
	protected $_results			= array();
	protected $_pos 			= -1;
	protected $_wasSerialized 	= false;
	
	public function __construct(){}
	
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
	
	/**
	* 
	* PUBLIC USE
	* 
	**/
	public function isValid()
	{
		return $this->_isValid;
	}
	
	public function first()
	{
		if( !isset( $this->_results[0] ) )
		{
			return false;
		}
		
		return $this->_results[0];
	}
	
	public function last()
	{
		if( $this->length() == 0 )
		{
			return false;
		}
		
		return $this->_results[$this->length()-1];
	}
	
	public function curr()
	{
		$pos = ( $this->_pos == -1 && $this->length() > 0 ? 0 : -1 );
		if( !isset( $this->_results[$pos] ) )
		{
			return false;
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

	public function length()
	{
		return count( $this->_results );
	}
	
	public function all( $table, $field )
	{
		// get position, then go to the beginning
		$pos = $this->_pos;
		$this->reset();
		
		// store the values
		$vals = array();
		while( $row = $this->next() )
		{
			if( isset( $this->$table ) && isset( $this->$table->$field ) && ( $val = $this->$table->$field ) != "" )
			{
				array_push( $vals, $val );
			}
		}
		
		$this->_pos = $pos;
		
		return $vals;
	}
	
	public function error()
	{
		if( $this->wasSerialized() && $this->isValid() )
		{
			return false;
		}
		else if( is_null( $this->_stmt ) )
		{
			return true;
		}
		
		return ( $this->_stmt->errorCode() != "00000" );
	}
	
	public function errno()
	{
		if( is_null( $this->_stmt ) )
		{
			return -1;
		}
		
		return $this->_stmt->errorCode();
	}
	
	public function errdesc()
	{
		if( is_null( $this->_stmt ) )
		{
			return "";
		}
		
		return print_r( $this->_stmt->errorInfo(), true );
	}
	
	public function as_array()
	{
		$resultArray = array();
		foreach( $this->_results as $result )
		{
			array_push( $resultArray, $result->as_array() );
		}

		return $resultArray;
	}

	
	/**
	* 
	* SETTERS
	* 
	**/
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
			$row = new SQLRow( $result );
			array_push($this->_results, $row );
		}
		
		$this->_isValid = true;
	}
	
	/**
	* 
	* GETTERS
	* 
	**/
	public function get_query()
	{
		return $this->_query . " - Params: " . print_r( $params, true );
	}
	
	public function get_stmt()
	{
		return $this->_stmt;
	}
	
	public function __get( $key )
	{
		if( !isset( $this->$key ) )
		{
			return $this->first()->$key;
		}
		
		return null;
	}
	
	/**
	*
	* PRIVATE
	* 
	**/
	protected function wasSerialized()
	{
		return $this->_wasSerialized;
	}
}

class SQLRow
{
	protected $_row;

	public function __construct( $arr )
	{
		$this->_row = $this->init( $arr );
	}
	
	private function init( $arr )
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
				$new->{$key} = $this->init( $val );
			}
		}
		else
		{
			$new = $arr;
		}
		
		return $new;
	}
	
	public function __get( $key )
	{
		if( !isset( $this->_row->$key ) )
		{
			$this->_row->$key = $this->init( array() );
		}
		
		return $this->_row->$key;
	}
	
	public function as_array()
	{
		$array = array();
		
		foreach( $this->_row as $key => $value )
		{
			$array[$key] = (array) $value;
		}
		
		return $array;
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
	// save some time in the case that singular and plural are the same
        if( in_array( strtolower( $string ), self::$uncountable ) )
		{
            return $string;
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
	 // save some time in the case that singular and plural are the same
        if( in_array( strtolower( $string ), self::$uncountable ) )
	{
            return $string;
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
