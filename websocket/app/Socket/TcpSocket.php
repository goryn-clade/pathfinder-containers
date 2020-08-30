<?php
/**
 * Created by PhpStorm.
 * User: Exodus 4D
 * Date: 15.02.2019
 * Time: 14:29
 */

namespace Exodus4D\Socket\Socket;


use Exodus4D\Socket\Log\Store;
use React\EventLoop;
use React\Socket;
use React\Promise;
use React\Stream;
use Clue\React\NDJson;
use Ratchet\MessageComponentInterface;

class TcpSocket extends AbstractSocket{

    /**
     * unique name for this component
     * -> should be overwritten in child instances
     * -> is used as "log store" name
     */
    const COMPONENT_NAME                = 'tcpSock';

    /**
     * error message for unknown acceptType
     * @see TcpSocket::DEFAULT_ACCEPT_TYPE
     */
    const ERROR_ACCEPT_TYPE         = "Unknown acceptType: '%s'";

    /**
     * error message for connected stream is not readable
     */
    const ERROR_STREAM_NOT_READABLE = "Stream is not readable. Remote address: '%s'";

    /**
     * error message for connection stream is not writable
     */
    const ERROR_STREAM_NOT_WRITABLE = "Stream is not writable. Remote address: '%s'";

    /**
     * error message for missing 'task' key in payload
     */
    const ERROR_TASK_MISSING        = "Missing 'task' in payload";

    /**
     * error message for unknown 'task' key in payload
     */
    const ERROR_TASK_UNKNOWN        = "Unknown 'task': '%s' in payload";

    /**
     * error message for missing method
     */
    const ERROR_METHOD_MISSING      = "Method '%S' not found";

    /**
     * error message for waitTimeout exceeds
     * @see TcpSocket::DEFAULT_WAIT_TIMEOUT
     */
    const ERROR_WAIT_TIMEOUT        = "Exceeds 'waitTimeout': %ss";

    /**
     * default for: accepted data type
     * -> affects en/decoding socket data
     */
    const DEFAULT_ACCEPT_TYPE       = 'json';

    /**
     * default for: wait timeout
     * -> timeout until connection gets closed
     *    timeout should be "reset" right after successful response send to client
     */
    const DEFAULT_WAIT_TIMEOUT      = 3.0;

    /**
     * default for: send response by end() method (rather than write())
     * -> connection will get closed right after successful response send to client
     */
    const DEFAULT_END_WITH_RESPONSE = true;

    /**
     * default for: add socket statistic to response payload
     */
    const DEFAULT_ADD_STATS         = false;

    /**
     * max length for JSON data string
     * -> throw OverflowException on exceed
     */
    const JSON_DECODE_MAX_LENGTH    = 65536 * 4;

    /**
     * @see TcpSocket::DEFAULT_ACCEPT_TYPE
     * @var string
     */
    private $acceptType             = self::DEFAULT_ACCEPT_TYPE;

    /**
     * @see TcpSocket::DEFAULT_WAIT_TIMEOUT
     * @var float
     */
    private $waitTimeout            = self::DEFAULT_WAIT_TIMEOUT;

    /**
     * @see TcpSocket::DEFAULT_END_WITH_RESPONSE
     * @var bool
     */
    private $endWithResponse        = self::DEFAULT_END_WITH_RESPONSE;

    /**
     * @see TcpSocket::DEFAULT_STATS
     * @var bool
     */
    private $addStats               = self::DEFAULT_ADD_STATS;

    /**
     * storage for all active connections
     * -> can be used to get current count of connected clients
     * @var \SplObjectStorage
     */
    private $connections;

    /**
     * max count of concurrent open connections
     * -> represents number of active connected clients
     * @var int
     */
    private $maxConnections         = 0;

    /**
     * timestamp on startup
     * @var int
     */
    private $startupTime            = 0;

    /**
     * TcpSocket constructor.
     * @param EventLoop\LoopInterface $loop
     * @param MessageComponentInterface $handler
     * @param Store $store
     * @param string $acceptType
     * @param float $waitTimeout
     * @param bool $endWithResponse
     */
    public function __construct(
        EventLoop\LoopInterface $loop,
        MessageComponentInterface $handler,
        Store $store,
        string $acceptType          = self::DEFAULT_ACCEPT_TYPE,
        float $waitTimeout          = self::DEFAULT_WAIT_TIMEOUT,
        bool $endWithResponse       = self::DEFAULT_END_WITH_RESPONSE
    ){
        parent::__construct($loop, $handler, $store);

        $this->acceptType           = $acceptType;
        $this->waitTimeout          = $waitTimeout;
        $this->endWithResponse      = $endWithResponse;
        $this->connections          = new \SplObjectStorage();
        $this->startupTime          = time();
    }

    /**
     * @param Socket\ConnectionInterface $connection
     */
    public function onConnect(Socket\ConnectionInterface $connection){
        $this->log('debug', $connection, __FUNCTION__, 'open connection…');

        if($this->isValidConnection($connection)){
            // connection can be used
            // add connection to global connection pool
            $this->addConnection($connection);
            // set waitTimeout timer for connection
            $this->setTimerTimeout($connection, $this->waitTimeout);

            // register connection events ... -------------------------------------------------------------------------
            $this->initRead($connection)
                ->then($this->initDispatch($connection))
                ->then($this->initResponse($connection))
                ->then(
                    function(array $payload) use ($connection) {
                        $this->log(['debug', 'info'], $connection,'DONE', 'task "' . $payload['task'] . '" done → response send');
                    },
                    function(\Exception $e) use ($connection) {
                        $this->log(['debug', 'error'], $connection, 'ERROR', $e->getMessage());
                        $this->connectionError($connection, $e);
                    });

            $connection->on('end', function() use ($connection) {
                $this->log('debug', $connection, 'onEnd');
            });

            $connection->on('close', function() use ($connection) {
                $this->log(['debug'], $connection, 'onClose', 'close connection');
                $this->removeConnection($connection);
            });

            $connection->on('error', function(\Exception $e)  use ($connection) {
                $this->log(['debug', 'error'], $connection, 'onError', $e->getMessage());
            });
        }else{
            // invalid connection -> can not be used
            $connection->close();
        }
    }

    /**
     * @param Socket\ConnectionInterface $connection
     * @return Promise\PromiseInterface
     */
    protected function initRead(Socket\ConnectionInterface $connection) : Promise\PromiseInterface {
        if($connection->isReadable()){
            if('json' == $this->acceptType){
                // new empty stream for processing JSON
                $stream = new Stream\ThroughStream();
                $streamDecoded = new NDJson\Decoder($stream, true, 512, 0, self::JSON_DECODE_MAX_LENGTH);

                // promise get resolved on first emit('data')
                $promise = Promise\Stream\first($streamDecoded);

                // register on('data') for main input stream
                $connection->on('data', function ($chunk) use ($stream) {
                    // send current data chunk to processing stream -> resolves promise
                    $stream->emit('data', [$chunk]);
                });

                return $promise;
            }else{
                return new Promise\RejectedPromise(
                    new \InvalidArgumentException(
                        sprintf(self::ERROR_ACCEPT_TYPE, $this->acceptType)
                    )
                );
            }
        }else{
            return new Promise\RejectedPromise(
                new \Exception(
                    sprintf(self::ERROR_STREAM_NOT_READABLE, $connection->getRemoteAddress())
                )
            );
        }
    }

    /**
     * init dispatcher for payload
     * @param Socket\ConnectionInterface $connection
     * @return callable
     */
    protected function initDispatch(Socket\ConnectionInterface $connection) : callable {
        return function(array $payload) use ($connection) : Promise\PromiseInterface {
            $task = (string)$payload['task'];
            if(!empty($task)){
                $load = $payload['load'];
                $deferred = new Promise\Deferred();
                $this->dispatch($connection, $deferred, $task, $load);
                return $deferred->promise();
            }else{
                return new Promise\RejectedPromise(
                    new \InvalidArgumentException(self::ERROR_TASK_MISSING)
                );
            }
        };
    }

    /**
     * @param Socket\ConnectionInterface $connection
     * @param Promise\Deferred $deferred
     * @param string $task
     * @param null $load
     */
    protected function dispatch(Socket\ConnectionInterface $connection, Promise\Deferred $deferred, string $task, $load = null) : void {
        $addStatusData = false;

        switch($task){
            case 'getStats':
                $addStatusData = true;
                $deferred->resolve($this->newPayload($task, null, $addStatusData));
                break;
            case 'healthCheck':
                $addStatusData = true;
            case 'characterUpdate':
            case 'characterLogout':
            case 'mapConnectionAccess':
            case 'mapAccess':
            case 'mapUpdate':
            case 'mapDeleted':
            case 'logData':
                if(method_exists($this->handler, 'receiveData')){
                    $this->log(['info'], $connection, __FUNCTION__, 'task "' . $task . '" processing…');

                    $deferred->resolve(
                        $this->newPayload(
                            $task,
                            call_user_func_array([$this->handler, 'receiveData'], [$task, $load]),
                            $addStatusData
                        )
                    );
                }else{
                    $deferred->reject(new \Exception(sprintf(self::ERROR_METHOD_MISSING, 'receiveData')));
                }
                break;
            default:
                $deferred->reject(new \InvalidArgumentException(sprintf(self::ERROR_TASK_UNKNOWN, $task)));
        }
    }

    /**
     * @param Socket\ConnectionInterface $connection
     * @return callable
     */
    protected function initResponse(Socket\ConnectionInterface $connection) : callable {
        return function(array $payload) use ($connection) : Promise\PromiseInterface {
            $this->log('debug', $connection, 'initResponse', 'task "' . $payload['task'] . '" → init response');

            $deferred = new Promise\Deferred();
            $this->write($deferred, $connection, $payload);

            return $deferred->promise();
        };
    }

    /**
     * @param Promise\Deferred $deferred
     * @param Socket\ConnectionInterface $connection
     * @param array $payload
     */
    protected function write(Promise\Deferred $deferred, Socket\ConnectionInterface $connection, array $payload) : void {
        $write = false;
        if($connection->isWritable()){
            if('json' == $this->acceptType){
                $connection = new NDJson\Encoder($connection);
            }

            // write a new chunk of data to connection stream
            $write = $connection->write($payload);

            if($this->endWithResponse){
                // connection should be closed (and removed from this socket server)
                $connection->end();
            }
        }

        if($write){
            $deferred->resolve($payload);
        }else{
            $deferred->reject(new \Exception(
                sprintf(self::ERROR_STREAM_NOT_WRITABLE, $connection->getRemoteAddress())
            ));
        }
    }

    /**
     * $connection has error
     * -> if writable -> end() connection with $payload (close() is called by default)
     * -> if readable -> close() connection
     * @param Socket\ConnectionInterface $connection
     * @param \Exception $e
     */
    protected function connectionError(Socket\ConnectionInterface $connection, \Exception $e){
        $errorMessage = $e->getMessage();
        $this->log(['debug', 'error'], $connection, __FUNCTION__, $errorMessage);

        if($connection->isWritable()){
            if('json' == $this->acceptType){
                $connection = new NDJson\Encoder($connection);
            }

            // send "end" data, then close
            $connection->end($this->newPayload('error', $errorMessage, true));
        }else{
            // close connection
            $connection->close();
        }
    }

    /**
     * check if $connection is found in global pool
     * @param Socket\ConnectionInterface $connection
     * @return bool
     */
    protected function hasConnection(Socket\ConnectionInterface $connection) : bool {
        return $this->connections->contains($connection);
    }

    /**
     * cancels a previously set timer callback for a $connection
     * @param Socket\ConnectionInterface $connection
     * @param string $timerName
     */
    protected function cancelTimer(Socket\ConnectionInterface $connection, string $timerName){
        if(
            $this->hasConnection($connection) &&
            ($data = (array)$this->connections->offsetGet($connection)) &&
            isset($data['timers']) && isset($data['timers'][$timerName]) &&
            ($data['timers'][$timerName] instanceof EventLoop\TimerInterface)
        ){
            $this->loop->cancelTimer($data['timers'][$timerName]);

            unset($data['timers'][$timerName]);
            $this->connections->offsetSet($connection, $data);
        }
    }

    /**
     * cancels all previously set timers for a $connection
     * @param Socket\ConnectionInterface $connection
     */
    protected function cancelTimers(Socket\ConnectionInterface $connection){
        if(
            $this->hasConnection($connection) &&
            ($data = (array)$this->connections->offsetGet($connection)) &&
            isset($data['timers'])
        ){
            foreach((array)$data['timers'] as $timerName => $timer){
                $this->loop->cancelTimer($timer);
            }

            $data['timers'] = [];
            $this->connections->offsetSet($connection, $data);
        }
    }

    /**
     * @param Socket\ConnectionInterface $connection
     * @param string $timerName
     * @param float $interval
     * @param callable $timerCallback
     */
    protected function setTimer(Socket\ConnectionInterface $connection, string $timerName, float $interval, callable $timerCallback){
        if(
            $this->hasConnection($connection) &&
            ($data = (array)$this->connections->offsetGet($connection)) &&
            isset($data['timers'])
        ){
            $data['timers'][$timerName] = $this->loop->addTimer($interval, function() use ($connection, $timerCallback) {
                $timerCallback($connection);
            });

            // store new timer to $connection
            $this->connections->offsetSet($connection, $data);
        }
    }

    /**
     * cancels and removes previous connection timeout timers
     * -> set new connection timeout
     * @param Socket\ConnectionInterface $connection
     * @param float $waitTimeout
     */
    protected function setTimerTimeout(Socket\ConnectionInterface $connection, float $waitTimeout = self::DEFAULT_WAIT_TIMEOUT){
        $this->cancelTimer($connection, 'disconnectTimer');
        $this->setTimer($connection, 'disconnectTimer', $waitTimeout, function(Socket\ConnectionInterface $connection) use ($waitTimeout) {
            $errorMessage = sprintf(self::ERROR_WAIT_TIMEOUT, $waitTimeout);

            $this->connectionError(
                $connection,
                new Promise\Timer\TimeoutException($waitTimeout, $errorMessage)
            );
        });
    }

    /**
     * add new connection to global pool
     * @param Socket\ConnectionInterface $connection
     */
    protected function addConnection(Socket\ConnectionInterface $connection){
        if(!$this->hasConnection($connection)){
            $this->connections->attach($connection, [
                'remoteAddress' => $connection->getRemoteAddress(),
                'timers' => []
            ]);

            // update maxConnections count
            $this->maxConnections = max($this->connections->count(), $this->maxConnections);

            $this->log(['debug'], $connection, __FUNCTION__, 'add new connection');
        }else{
            $this->log(['debug'], $connection, __FUNCTION__, 'connection already exists');
        }
    }

    /**
     * remove $connection from global connection pool
     * @param Socket\ConnectionInterface $connection
     */
    protected function removeConnection(Socket\ConnectionInterface $connection){
        if($this->hasConnection($connection)){
            $this->log(['debug'], $connection, __FUNCTION__, 'remove connection');
            $this->cancelTimers($connection);
            $this->connections->detach($connection);
        }
    }

    /**
     * get new payload
     * @param string $task
     * @param null $load
     * @param bool $addStats
     * @return array
     */
    protected function newPayload(string $task, $load = null, bool $addStats = false) : array {
        $payload = [
            'task'  => $task,
            'load'  => $load
        ];

        if($addStats || $this->addStats){
            // add socket statistics
            $payload['stats'] = $this->getStats();
        }

        return $payload;
    }

    /**
     * check if connection is "valid" and can be used for data transfer
     * @param Socket\ConnectionInterface $connection
     * @return bool
     */
    protected function isValidConnection(Socket\ConnectionInterface $connection) : bool {
        return $connection->isReadable() || $connection->isWritable();
    }

    /**
     * get socket server statistics
     * -> e.g. connected clients count
     * @return array
     */
    protected function getStats() : array {
        return [
            'tcpSocket' => $this->getSocketStats(),
            'webSocket' => $this->handler->getSocketStats()
        ];
    }

    /**
     * get TcpSocket stats data
     * @return array
     */
    protected function getSocketStats() : array {
        return [
            'startup'           => time() - $this->startupTime,
            'connections'       => $this->connections->count(),
            'maxConnections'    => $this->maxConnections,
            'logs'              => array_reverse($this->logStore->getStore())
        ];
    }
}