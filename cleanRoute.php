<?php
/**
 * Created by JetBrains PhpStorm.
 * User: jruiz
 * Date: 09/07/13
 * Time: 20:11
 * Use: <script_name>.php <source_filename.csv> [<destination_filename.csv>]
 * Read a csv file with latitude,logitude,timestamp fields and check for pontentially 
 * incorrect values removing them and print/save the cleaned route
 */

//File parameters
const FILE_LONGEST_LINE_SIZE = 1000;
const FILE_FIELD_DELIMITER_CHAR = ',';
const EXPECTED_NUMBER_OF_FIELDS = 3;

//Filter parameters
const EARTH_RADIUS = 6370;                  //in Km
const MAX_ALLOWED_SPEED = 120;              //in Km/h
const SECONDS_PER_HOUR = 3600;

//Custom exceptions messages
const EXCEPTION_WRONG_FIELD_NUMBER_MESSAGE = "[Exception 0] Wrong number of fields in the provided file...";
const EXCEPTION_WRONG_TIMESTAMP_ORDERING_MESSAGE = "[Exception 1] Wrong file ordering, trying to fix it...";
const EXCEPTION_SAME_TIMESTAMP_MESSAGE = "[Exception 2] Same timestamp for two positions...";
const EXCEPTION_WRONG_ATTRIBUTE_VALUE_MESSAGE = "[Exception 3] routePositionsList is not an array...";
const EXCEPTION_FILE_NOT_EXIST_MESSAGE = "[Exception 4] File not exist...";

/*
 * Custom exceptions interface
 */
interface CustomExceptionInterface
{
    /* Protected methods inherited from Exception class */
    public function getMessage();                 // Exception message
    public function getCode();                    // User-defined Exception code
    public function getFile();                    // Source filename
    public function getLine();                    // Source line
    public function getTrace();                   // An array of the backtrace()
    public function getTraceAsString();           // Formated string of trace

    /* Overrideable methods inherited from Exception class */
    public function __toString();                 // formated string for display
    public function __construct($message = null, $code = 0);
}


/*
 * Custom exceptions abstract class
 */
abstract class CustomException extends Exception implements CustomExceptionInterface
{
    protected $message = 'Unknown exception';     // Exception message
    protected $code    = 0;                       // User-defined exception code
    protected $file;                              // Source filename of exception
    protected $line;                              // Source line of exception


    public function __construct($message = null, $code = 0)
    {
        if (!$message)
        {
            throw new $this('Unknown '. get_class($this) ." exception");
        }
        parent::__construct($message."\n", $code);
    }

    public function __toString()
    {
        return get_class($this) . " '{$this->message}' in {$this->file}({$this->line})\n"
        . "{$this->getTraceAsString()}";
    }
}


class WrongNumberOfFieldsException extends CustomException {}
class WrongTimestampOrderException extends CustomException {}
class SameTimestampException extends CustomException {}
class WrongAttributeValueException extends CustomException {}
class FileNotExistException extends CustomException {}


/**
 * CabRoute import, store and check the route of a cab
 *
 * @author Jonathan Ruiz <contact@jonathanruiz.co.uk>
 */
class CabRoute
{
    
    private $routePositionsList = array(); //Array of Positions

    /*
     *Returns the string representation of the CabRoute object.
     */
    public function __toString()
    {
        $returned_string ="";
        foreach($this->routePositionsList as $position)
        {
            $returned_string.=$position."\n";
        }
        return $returned_string;
    }
    
    /**
     * Add a position to the route ($this->routePositionsList)
     *
     * @param float $latitude the angular (degrees) north-south location of the added position point on the Earth's surface
     * @param float $longitude the angular (degrees) east-west location of the added position point on the Earth's surface
     * @param float $timestamp POSIX/Unix Time of when the location (latitude & longitude) was captured
     */
    public function addRoutePosition( $latitude, $longitude, $timestamp )
    {
        $this->routePositionsList[$timestamp] = new Position( $latitude, $longitude, $timestamp );
        //echo "[Log.debug] added position: ".$this->routePositionsList[$timestamp]."\n";
    }

    /**
     * Remove position of $this->routePositionsList
     *
     * @param Position $position
     * @internal param string $timestamp key of the element that will be removed
     */
    public function removeRoutePosition(Position $position)
    {
        //echo "[Log.debug] removing position: ".$position."\n";
        unset($this->routePositionsList[$position->getTimestamp()]);
    }


    /**
     * Disregard potentially erroneous positions following this criteria:
     * A point is erroneus if the distance is too much for the elapsed time,
     * in other words the cab velocity was unreal.
     *
     * NOTE: The first position must be correct & timestamps must be unique.
     * We'll try to order the readed postitions using timestamp as key.
     *
     * @throws WrongAttributeValueException
     * @internal param string $controller A string in the class::method notation
     * and remove wrong positions logged from a thaxi
     * @return string A short notation controller (a:b:c)
     *
     */
    function disregardErroneousPositions()
    {
        if(!is_array($this->routePositionsList))
        {
            throw new WrongAttributeValueException(EXCEPTION_WRONG_ATTRIBUTE_VALUE_MESSAGE);
        }
        else
        {
            $previous_position = reset($this->routePositionsList);
            //next($this->routePositionsList);
            //echo "[Log.debug] first position: ".$previous_position."\n";

            while( $current_position = next( $this->routePositionsList ) )
            {
                $distance_between_positions = $previous_position->getDistanceTo($current_position);
                $time_between_positions = $previous_position->getTimeTo($current_position);

                // Km/h
                $speed = $distance_between_positions/ ( $time_between_positions / SECONDS_PER_HOUR );
                //echo "[Log.debug] speed: ".$speed."\n";
                if( MAX_ALLOWED_SPEED < $speed )
                {
                    //disregarding erroneus position
                    //echo "[Log.debug] erroneus position: ".$current_position.", ".$sp and remove wrong positions logged from a thaxieed."\n";
                    $this->removeRoutePosition( $current_position );

                }
                else
                {
                    $previous_position = $current_position;
                }

            }
        }
    }

    /**
     * Fix the order of $this->routePositionsList from lower to higher timestamp
     */
    public function fixOrder()
    {
        //TODO: use adapted buble algorithm to popup unordered position could save some cpu time
        ksort( $this->routePositionsList );
    }

    /**
     * Import a csv file with route positions, file has to have three fields (latitude,longitude,timestamp)
     * fixing the input order using timestamp as key field
     *
     * @param string $file_path The csv file that contains the positions
     *
     * @throws WrongTimestampOrderException
     * @throws WrongNumberOfFieldsException
     * @throws FileNotExistException
     */
    public function importRouteFromFile($file_path)
    {
        if (!file_exists($file_path))
        {
            throw new FileNotExistException(EXCEPTION_FILE_NOT_EXIST_MESSAGE);
        }
        else
        {
            $latest_timestamp = 0;
            if (($handle = fopen($file_path, "r")) !== FALSE)
            {
                $is_ordered = true;
                while (($line = fgetcsv($handle, FILE_LONGEST_LINE_SIZE, FILE_FIELD_DELIMITER_CHAR)) !== FALSE)
                {
                    $number_of_fields = count($line);
                    if($number_of_fields != EXPECTED_NUMBER_OF_FIELDS)
                    {
                        throw new WrongNumberOfFieldsException(EXCEPTION_WRONG_FIELD_NUMBER_MESSAGE);
                    }
                    else
                    {
                        list($latitude, $longitude, $timestamp) = $line;
                        $this->addRoutePosition($latitude, $longitude, $timestamp);

                        if ($latest_timestamp >= $timestamp)
                        {
                            $is_ordered = false;                                                       
                        }
                        else
                        {
                            $latest_timestamp = $timestamp;
                        }
                    }
                }
                fclose($handle);
                
                if ($is_ordered)
                {
                    throw new WrongTimestampOrderException(EXCEPTION_WRONG_TIMESTAMP_ORDERING_MESSAGE); 
                }
            }
        }
    }
}

/*
 * Represents a location(latitude and longitude) in a time instant
 * @author Jonathan Ruiz <contact@jonathanruiz.co.uk>
 */
Class Position
{
    private $longitude;
    private $latitude;
    private $timestamp;

    function __construct( $latitude, $longitude, $timestamp)
    {
        $this->longitude = floatval($longitude);
        $this->latitude = floatval($latitude);
        $this->timestamp = intval($timestamp);
    }
    
    /*
     *Returns the string representation of the CabRoute object.
     */
    public function __toString()
    {
        return "$this->latitude, $this->longitude, $this->timestamp";
    }

    /**
     * Get the distance in Km from this position to the postion passed as parameter
     *
     * @param Position $other_position the position the we'll use as the last point for our distance meassurement
     * @return int
     */
    function getDistanceTo(Position $other_position)
    {
        $latitude_difference = deg2rad( $other_position->getLatitude() - $this->getLatitude() );
        $longitude_difference = deg2rad( $other_position->getLongitude() - $this->getLongitude() );

        // Haversine formula
        $a = sin( $latitude_difference/2 ) * sin( $latitude_difference/2 ) + cos( deg2rad($this->getLatitude()) ) * cos( deg2rad($other_position->getLatitude()) ) * sin($longitude_difference/2) * sin($longitude_difference/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $d = EARTH_RADIUS * $c;
        return $d;
    }

    /**
     * Get the elapsed time in seconds from this position to the postion passed as parameter
     *
     * @param Position $other_position the position the we'll use as the last time for our time meassurement
     * @return int
     */
    function getTimeTo(Position $other_position)
    {
        return  $other_position->getTimestamp() -$this->timestamp;
    }

    /**
     * Return the position latitude
     *
     * @return float $latitude
     */
    public function getLatitude()
    {
        return $this->latitude;
    }

    /**
     * Return the position longitude
     *
     * @return float $longitude
     */
    public function getLongitude()
    {
        return $this->longitude;
    }

    /**
     * Return the position timestamp
     *
     * @return int $timestamp
     */
    public function getTimestamp()
    {
        return $this->timestamp;
    }
}


/******************
 * Main program
 ******************/
// Check arguments passed through command line
if(empty($argv[1]))
{
    echo<<<HELP
Please use:
$argv[0] source_filename [desination_filename]

HELP;

}
else
{
    try
    {
        $cab_route = new CabRoute();
        $cab_route->importRouteFromFile($argv[1]);
        $cab_route->disregardErroneousPositions();        
        returnResults($cab_route, $argv);  
    }    
    //If a WrongTimestampOrderException is thrown we'll fix order before disregard erroneous positions
    catch (WrongTimestampOrderException $invalid_order_exception)
    {
        echo $invalid_order_exception->getMessage();
        $cab_route->fixOrder();        
        $cab_route->disregardErroneousPositions();
        returnResults($cab_route, $argv);
    }
    catch (Exception $e)
    {
        echo $e->getMessage();
    }    
}


/*
 * Return the results to screen or file depending on the arguments provided
 */

function returnResults(CabRoute $cab_route, $argv)
{    
   //If destination file wasn't provided, just print the result
    if(empty($argv[2]))
    {
        echo $cab_route;    
    }
    //else try to write it to the provided filename
    else
    {
        if (false === file_put_contents ($argv[2], $cab_route ))
        {
            echo "There was a problem saving the results to $argv[2]\n";
        }
        else
        {
            echo "the result was correctly saved in $argv[2]\n";
        } 
    }      
}