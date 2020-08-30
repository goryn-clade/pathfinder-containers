<?php


namespace Exodus4D\Socket\Component;


use Exodus4D\Socket\Data\Payload;
use Exodus4D\Socket\Log\Store;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use React\EventLoop\TimerInterface;

abstract class AbstractMessageComponent implements MessageComponentInterface {

    /**
     * unique name for this component
     * -> should be overwritten in child instances
     * -> is used as "log store" name
     */
    const COMPONENT_NAME                = 'default';

    /**
     * log message server start
     */
    const LOG_TEXT_SERVER_START         = 'start WebSocket server…';

    /**
     * store for logs
     * @var Store
     */
    protected $logStore;

    /**
     * stores all active connections
     * -> regardless of its subscription state
     * [
     *      '$conn1->resourceId' => [
     *          'connection' => $conn1,
     *          'data' => null
     *      ],
     *      '$conn2->resourceId' => [
     *          'connection' => $conn2,
     *          'data' => null
     *      ]
     * ]
     * @var array
     */
    private $connections;

    /**
     * max count of concurrent open connections
     * @var int
     */
    private $maxConnections = 0;

    /**
     * AbstractMessageComponent constructor.
     * @param Store $store
     */
    public function __construct(Store $store){
        $this->connections = [];
        $this->logStore = $store;

        $this->log(['debug', 'info'], null, 'START', static::LOG_TEXT_SERVER_START);
    }

    // Connection callbacks from MessageComponentInterface ============================================================

    /**
     * new client connection onOpen
     * @param ConnectionInterface $conn
     */
    public function onOpen(ConnectionInterface $conn){
        $this->log(['debug'], $conn, __FUNCTION__, 'open connection');

        $this->addConnection($conn);
    }

    /**
     * client connection onClose
     * @param ConnectionInterface $conn
     */
    public function onClose(ConnectionInterface $conn){
        $this->log(['debug'], $conn, __FUNCTION__, 'close connection');

        $this->removeConnection($conn);
    }

    /**
     * client connection onError
     * @param ConnectionInterface $conn
     * @param \Exception $e
     */
    public function onError(ConnectionInterface $conn, \Exception $e){
        $this->log(['debug', 'error'], $conn, __FUNCTION__, $e->getMessage());
    }

    /**
     * new message received from client connection
     * @param ConnectionInterface $conn
     * @param string $msg
     */
    public function onMessage(ConnectionInterface $conn, $msg){
        // parse message into payload object
        $payload = $this->getPayloadFromMessage($msg);

        if($payload){
            $this->dispatchWebSocketPayload($conn, $payload);
        }
    }

    // Connection handling ============================================================================================

    /**
     * add connection
     * @param ConnectionInterface $conn
     */
    private function addConnection(ConnectionInterface $conn) : void {
        $this->connections[$conn->resourceId] = [
            'connection' => $conn,
        ];

        $this->maxConnections = max(count($this->connections), $this->maxConnections);
    }

    /**
     * remove connection
     * @param ConnectionInterface $conn
     */
    private function removeConnection(ConnectionInterface $conn) : void {
        if($this->hasConnection($conn)){
            unset($this->connections[$conn->resourceId]);
        }
    }

    /**
     * @param ConnectionInterface $conn
     * @return bool
     */
    protected function hasConnection(ConnectionInterface $conn) : bool {
        return isset($this->connections[$conn->resourceId]);
    }

    /**
     * @param int $resourceId
     * @return bool
     */
    protected function hasConnectionId(int $resourceId) : bool {
        return isset($this->connections[$resourceId]);
    }

    /**
     * @param int $resourceId
     * @return ConnectionInterface|null
     */
    protected function getConnection(int $resourceId) : ?ConnectionInterface {
        return $this->hasConnectionId($resourceId) ? $this->connections[$resourceId]['connection'] : null;
    }

    /**
     * update meta data for $conn
     * @param ConnectionInterface $conn
     */
    protected function updateConnection(ConnectionInterface $conn){
        if($this->hasConnection($conn)){
            $meta = [
                'mTimeSend' => microtime(true)
            ];
            $this->connections[$conn->resourceId]['data'] = array_merge($this->getConnectionData($conn), $meta);
        }
    }

    /**
     * get meta data from $conn
     * @param ConnectionInterface $conn
     * @return array
     */
    protected function getConnectionData(ConnectionInterface $conn) : array {
        $meta = [];
        if($this->hasConnection($conn)){
            $meta = (array)$this->connections[$conn->resourceId]['data'];
        }
        return $meta;
    }

    /**
     * wrapper for ConnectionInterface->send()
     * -> this stores some meta data to the $conn
     * @param ConnectionInterface $conn
     * @param $data
     */
    protected function send(ConnectionInterface $conn, $data){
        $conn->send($data);
        $this->updateConnection($conn);
    }

    /**
     * @param ConnectionInterface $conn
     * @param Payload $payload
     */
    abstract protected function dispatchWebSocketPayload(ConnectionInterface $conn, Payload $payload) : void;

    /**
     * get Payload class from client message
     * @param mixed $msg
     * @return Payload|null
     */
    protected function getPayloadFromMessage($msg) : ?Payload {
        $payload = null;
        $msg = (array)json_decode($msg, true);

        if(isset($msg['task'], $msg['load'])){
            $payload = $this->newPayload((string)$msg['task'], $msg['load']);
        }

        return $payload;
    }

    /**
     * @param string $task
     * @param null $load
     * @param array|null $characterIds
     * @return Payload|null
     */
    protected function newPayload(string $task, $load = null, ?array $characterIds = null) : ?Payload {
        $payload = null;
        try{
            $payload = new Payload($task, $load, $characterIds);
        }catch(\Exception $e){
            $this->log(['debug', 'error'], null, __FUNCTION__, $e->getMessage());
        }

        return $payload;
    }

    /**
     * get WebSocket stats data
     * @return array
     */
    public function getSocketStats() : array {
        return [
            'connections'       => count($this->connections),
            'maxConnections'    => $this->maxConnections,
            'logs'              => array_reverse($this->logStore->getStore())
        ];
    }

    /**
     * @param $logTypes
     * @param ConnectionInterface|null $connection
     * @param string $action
     * @param string $message
     */
    protected function log($logTypes, ?ConnectionInterface $connection, string $action, string $message = '') : void {
        if($this->logStore){
            $remoteAddress = $connection ? $connection->remoteAddress : null;
            $resourceId = $connection ? $connection->resourceId : null;
            $this->logStore->log($logTypes, $remoteAddress, $resourceId, $action, $message);
        }
    }

    /**
     *
     * @param TimerInterface $timer
     */
    public function housekeeping(TimerInterface $timer) : void {

    }
}