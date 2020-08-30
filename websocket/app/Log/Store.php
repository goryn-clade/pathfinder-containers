<?php


namespace Exodus4D\Socket\Log;


class Store {

    /**
     * default for: unique store name
     */
    const DEFAULT_NAME              = 'store';

    /**
     * default for: echo log data in terminal
     */
    const DEFAULT_LOG_TO_STDOUT     = true;

    /**
     * default for: max cached log entries
     */
    const DEFAULT_LOG_STORE_SIZE    = 50;

    /**
     * @see Store::DEFAULT_NAME
     * @var string
     */
    private $name                   = self::DEFAULT_NAME;

    /**
     * log store for log entries
     * -> store size should be limited for memory reasons
     * @var array
     */
    private $store                  = [];

    /**
     * all valid types for custom log events
     * if value is false, logs for this type are ignored
     * @var array
     */
    protected $logTypes             = [
        'error'     =>  true,
        'info'      =>  true,
        'debug'     =>  true
    ];

    /**
     * if Store is locked, current state can not be changed
     * @var bool
     */
    protected $locked               = false;

    /**
     * @var ShellColors
     */
    static $colors;

    /**
     * Store constructor.
     * @param string $name
     */
    public function __construct(string $name){
        $this->name = $name;
    }

    /**
     * get all stored log entries
     * @return array
     */
    public function getStore() : array {
        return $this->store;
    }

    /**
     * @param bool $locked
     */
    public function setLocked(bool $locked){
        $this->locked = $locked;
    }

    /**
     * @return bool
     */
    public function isLocked() : bool {
        return $this->locked;
    }

    /**
     * @param int $logLevel
     */
    public function setLogLevel(int $logLevel){
        switch($logLevel){
            case 3:
                $this->logTypes['error'] = true;
                $this->logTypes['info'] = true;
                $this->logTypes['debug'] = true;
                break;
            case 2:
                $this->logTypes['error'] = true;
                $this->logTypes['info'] = true;
                $this->logTypes['debug'] = false;
                break;
            case 1:
                $this->logTypes['error'] = true;
                $this->logTypes['info'] = false;
                $this->logTypes['debug'] = false;
                break;
            case 0:
            default:
                $this->setLocked(true); // no logging
        }
    }

    /**
     * this is used for custom log events like 'error', 'debug',...
     * works as dispatcher method that calls individual log*() methods
     * @param $logTypes
     * @param string|null $remoteAddress
     * @param int|null $resourceId
     * @param string $action
     * @param string $message
     */
    public function log($logTypes, ?string $remoteAddress, ?int $resourceId, string $action, string $message = '') : void {
        if(!$this->isLocked()){
            // filter out logTypes that should not be logged
            $logTypes = array_filter((array)$logTypes, function(string $type) : bool {
                return array_key_exists($type, $this->logTypes) && $this->logTypes[$type];
            });

            if($logTypes){
                // get log entry data
                $logData = $this->getLogData($logTypes, $remoteAddress, $resourceId, $action, $message);

                if(self::DEFAULT_LOG_TO_STDOUT){
                    $this->echoLog($logData);
                }

                // add entry to local store and check size limit for store
                $this->store[] = $logData;
                $this->store = array_slice($this->store, self::DEFAULT_LOG_STORE_SIZE * -1);
            }
        }
    }

    /**
     * get log data as array for a custom log entry
     * @param array $logTypes
     * @param string|null $remoteAddress
     * @param int|null $resourceId
     * @param string $action
     * @param string $message
     * @return array
     */
    private function getLogData(array $logTypes, ?string $remoteAddress, ?int $resourceId, string $action, string $message = '') : array {
        $file = null;
        $lineNum = null;
        $function = null;

        $traceIndex = 4;
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $traceIndex);
        if(count($backtrace) == $traceIndex){
            $caller = $backtrace[$traceIndex - 2];
            $callerOrig = $backtrace[$traceIndex - 1];

            $file = substr($caller['file'], strlen(dirname(dirname(dirname($caller['file'])))) + 1);
            $lineNum = $caller['line'];
            $function = $callerOrig['function'];
        }

        $microTime = microtime(true);
        $logTime = self::getDateTimeFromMicrotime($microTime);

        return [
            'store'         => $this->name,
            'mTime'         => $microTime,
            'mTimeFormat1'  => $logTime->format('Y-m-d H:i:s.u'),
            'mTimeFormat2'  => $logTime->format('H:i:s'),
            'logTypes'      => $logTypes,
            'remoteAddress' => $remoteAddress,
            'resourceId'    => $resourceId,
            'fileName'      => $file,
            'lineNumber'    => $lineNum,
            'function'      => $function,
            'action'        => $action,
            'message'       => $message
        ];
    }

    /**
     * echo log data to stdout -> terminal
     * @param array $logData
     */
    private function echoLog(array $logData) : void {
        if(!self::$colors){
            self::$colors = new ShellColors();
        }

        $data = [
            self::$colors->getColoredString($logData['mTimeFormat1'], 'dark_gray'),
            self::$colors->getColoredString($logData['store'], $logData['store'] == 'webSock' ? 'brown' : 'cyan'),
            $logData['remoteAddress'] . ($logData['resourceId'] ? ' #' . $logData['resourceId'] : ''),
            self::$colors->getColoredString($logData['fileName'] . ' line ' . $logData['lineNumber'], 'dark_gray'),
            self::$colors->getColoredString($logData['function'] . '()' . (($logData['function'] !== $logData['action']) ? ' [' . $logData['action'] . ']' : ''), 'dark_gray'),
            implode(',', (array)$logData['logTypes']),
            self::$colors->getColoredString($logData['message'], 'light_purple')
        ];

        echo implode(' | ', array_filter($data)) . PHP_EOL;
    }

    /**
     * @see https://stackoverflow.com/a/29598719/4329969
     * @param float $mTime
     * @return \DateTime
     */
    public static function getDateTimeFromMicrotime(float $mTime) : \DateTime {
        return \DateTime::createFromFormat('U.u', number_format($mTime, 6, '.', ''));
    }
}