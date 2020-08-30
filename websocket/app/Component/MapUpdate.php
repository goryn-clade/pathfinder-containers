<?php
/**
 * Created by PhpStorm.
 * User: Exodus
 * Date: 02.12.2016
 * Time: 22:29
 */

namespace Exodus4D\Socket\Component;

use Exodus4D\Socket\Component\Handler\LogFileHandler;
use Exodus4D\Socket\Component\Formatter\SubscriptionFormatter;
use Exodus4D\Socket\Data\Payload;
use Exodus4D\Socket\Log\Store;
use Ratchet\ConnectionInterface;

class MapUpdate extends AbstractMessageComponent {

    /**
     * unique name for this component
     * -> should be overwritten in child instances
     * -> is used as "log store" name
     */
    const COMPONENT_NAME                = 'webSock';

    /**
     * log message unknown task name
     */
    const LOG_TEXT_TASK_UNKNOWN         = 'unknown task: %s';

    /**
     * log message for denied subscription attempt. -> character data unknown
     */
    const LOG_TEXT_SUBSCRIBE_DENY       = 'sub. denied for charId: %d';

    /**
     * log message for invalid subscription data
     */
    const LOG_TEXT_SUBSCRIBE_INVALID    = 'sub. data invalid';

    /**
     * log message for subscribe characterId
     */
    const LOG_TEXT_SUBSCRIBE            = 'sub. charId: %s to mapIds: [%s]';

    /**
     * log message unsubscribe characterId
     */
    const LOG_TEXT_UNSUBSCRIBE          = 'unsub. charId: %d from mapIds: [%s]';

    /**
     * log message for map data updated broadcast
     */
    const LOG_TEXT_MAP_UPDATE           = 'update map data, mapId: %d → broadcast to %d connections';

    /**
     * log message for map subscriptions data updated broadcast
     */
    const LOG_TEXT_MAP_SUBSCRIPTIONS    = 'update map subscriptions data, mapId: %d. → broadcast to %d connections';

    /**
     * log message for delete mapId broadcast
     */
    const LOG_TEXT_MAP_DELETE           = 'delete mapId: $d → broadcast to %d connections';

    /**
     * timestamp (ms) from last healthCheck ping
     * -> timestamp received from remote TCP socket
     * @var int|null
     */
    protected $healthCheckToken;

    /**
     * expire time for map access tokens (seconds)
     * @var int
     */
    protected $mapAccessExpireSeconds = 30;

    /**
     * character access tokens for clients
     * -> tokens are unique and expire onSubscribe!
     * [
     *      'charId_1' => [
     *          [
     *              'token' => $characterToken1,
     *              'expire' => $expireTime1,
     *              'characterData' => $characterData1
     *          ],
     *          [
     *              'token' => $characterToken2,
     *              'expire' => $expireTime2,
     *              'characterData' => $characterData1
     *          ]
     *      ],
     *      'charId_2' => [
     *          [
     *              'token' => $characterToken3,
     *              'expire' => $expireTime3,
     *              'characterData' => $characterData2
     *          ]
     *      ]
     * ]
     * @var array
     */
    protected $characterAccessData;

    /**
     * access tokens for clients grouped by mapId
     * -> tokens are unique and expire onSubscribe!
     * @var array
     */
    protected $mapAccessData;

    /**
     * connected characters
     * [
     *      'charId_1' => [
     *          '$conn1->resourceId' => $conn1,
     *          '$conn2->resourceId' => $conn2
     *      ],
     *      'charId_2' => [
     *          '$conn1->resourceId' => $conn1,
     *          '$conn3->resourceId' => $conn3
     *      ]
     * ]
     * @var array
     */
    protected $characters;

    /**
     * valid client connections subscribed to maps
     * [
     *      'mapId_1' => [
     *          'charId_1' => $charId_1,
     *          'charId_2' => $charId_2
     *      ],
     *      'mapId_2' => [
     *          'charId_1' => $charId_1,
     *          'charId_3' => $charId_3
     *      ]
     * ]
     *
     * @var array
     */
    protected $subscriptions;

    /**
     * collection of characterData for valid subscriptions
     * [
     *      'charId_1' => $characterData1,
     *      'charId_2' => $characterData2
     * ]
     *
     * @var array
     */
    protected $characterData;

    /**
     * MapUpdate constructor.
     * @param Store $store
     */
    public function __construct(Store $store){
        parent::__construct($store);

        $this->characterAccessData  = [];
        $this->mapAccessData        = [];
        $this->characters           = [];
        $this->subscriptions        = [];
        $this->characterData        = [];
    }

    /**
     * new client connection
     * @param ConnectionInterface $conn
     */
    public function onOpen(ConnectionInterface $conn){
        parent::onOpen($conn);
    }

    /**
     * @param ConnectionInterface $conn
     */
    public function onClose(ConnectionInterface $conn){
        parent::onClose($conn);

        $this->unSubscribeConnection($conn);
    }

    /**
     * @param ConnectionInterface $conn
     * @param \Exception $e
     */
    public function onError(ConnectionInterface $conn, \Exception $e){
        parent::onError($conn, $e);

        // close connection should trigger the onClose() callback for unSubscribe
        $conn->close();
    }

    /**
     * @param ConnectionInterface $conn
     * @param string $msg
     */
    public function onMessage(ConnectionInterface $conn, $msg){
        parent::onMessage($conn, $msg);
    }

    /**
     * @param ConnectionInterface $conn
     * @param Payload $payload
     */
    protected function dispatchWebSocketPayload(ConnectionInterface $conn, Payload $payload) : void {
        switch($payload->task){
            case 'healthCheck':
                $this->broadcastHealthCheck($conn, $payload);
                break;
            case 'subscribe':
                $this->subscribe($conn, (array)$payload->load);
                break;
            case 'unsubscribe':
                // make sure characterIds got from client are valid
                // -> intersect with subscribed characterIds for current $conn
                $characterIds = array_intersect((array)$payload->load, $this->getCharacterIdsByConnection($conn));
                if(!empty($characterIds)){
                    $this->unSubscribeCharacterIds($characterIds, $conn);
                }
                break;
            default:
                $this->log(['debug', 'error'], $conn, __FUNCTION__, sprintf(static::LOG_TEXT_TASK_UNKNOWN, $payload->task));
                break;
        }
    }

    /**
     * checks healthCheck $token and respond with validation status + subscription stats
     * @param ConnectionInterface $conn
     * @param Payload $payload
     */
    private function broadcastHealthCheck(ConnectionInterface $conn, Payload $payload) : void {
        $isValid = $this->validateHealthCheckToken((int)$payload->load);

        $load = [
            'isValid' => $isValid,
        ];

        // Make sure WebSocket client request is valid
        if($isValid){
            // set new healthCheckToken for next check
            $load['token'] = $this->setHealthCheckToken(microtime(true));

            // add subscription stats if $token is valid
            $load['subStats'] = $this->getSubscriptionStats();
        }

        $payload->setLoad($load);

        $connections = new \SplObjectStorage();
        $connections->attach($conn);

        $this->broadcast($connections, $payload);

    }

    /**
     * compare token (timestamp from initial TCP healthCheck message) with token send from WebSocket
     * @param int $token
     * @return bool
     */
    private function validateHealthCheckToken(int $token) : bool {
        $isValid = false;

        if($token && $this->healthCheckToken && $token === (int)$this->healthCheckToken){
            $isValid = true;
        }

        // reset token
        $this->healthCheckToken = null;

        return $isValid;
    }

    /**
     * subscribes a connection to valid accessible maps
     * @param ConnectionInterface $conn
     * @param $subscribeData
     */
    private function subscribe(ConnectionInterface $conn, array $subscribeData) : void {
        $characterId = (int)$subscribeData['id'];
        $characterToken = (string)$subscribeData['token'];

        if($characterId && $characterToken){
            // check if character access token is valid (exists and not expired in $this->characterAccessData)
            if($characterData = $this->checkCharacterAccess($characterId, $characterToken)){
                $this->characters[$characterId][$conn->resourceId] = $conn;

                // insert/update characterData cache
                // -> even if characterId does not have access to a map "yet"
                // -> no maps found but character can get map access at any time later
                $this->setCharacterData($characterData);

                // valid character -> check map access
                $changedSubscriptionsMapIds = [];
                foreach((array)$subscribeData['mapData'] as $data){
                    $mapId = (int)$data['id'];
                    $mapToken = (string)$data['token'];
                    $mapName = (string)$data['name'];

                    if($mapId && $mapToken){
                        // check if token is valid (exists and not expired) in $this->mapAccessData
                        if($this->checkMapAccess($characterId, $mapId, $mapToken)){
                            // valid map subscribe request
                            $this->subscriptions[$mapId]['characterIds'][$characterId] = $characterId;
                            $this->subscriptions[$mapId]['data']['name'] = $mapName;
                            $changedSubscriptionsMapIds[] = $mapId;
                        }
                    }
                }

                sort($changedSubscriptionsMapIds, SORT_NUMERIC);

                $this->log(['debug', 'info'], $conn, __FUNCTION__,
                    sprintf(static::LOG_TEXT_SUBSCRIBE, $characterId, implode(',', $changedSubscriptionsMapIds))
                );

                // broadcast all active subscriptions to subscribed connections -------------------------------------------
                $this->broadcastMapSubscriptions($changedSubscriptionsMapIds);
            }else{
                $this->log(['debug', 'info'], $conn, __FUNCTION__, sprintf(static::LOG_TEXT_SUBSCRIBE_DENY, $characterId));
            }
        }else{
            $this->log(['debug', 'error'], $conn, __FUNCTION__, static::LOG_TEXT_SUBSCRIBE_INVALID);
        }
    }

    /**
     * subscribes an active connection from maps
     * @param ConnectionInterface $conn
     */
    private function unSubscribeConnection(ConnectionInterface $conn){
        $characterIds = $this->getCharacterIdsByConnection($conn);
        $this->unSubscribeCharacterIds($characterIds, $conn);
    }

    /**
     * unSubscribe a $characterId from ALL maps
     * -> if $conn is set -> just unSub the $characterId from this $conn
     * @param int $characterId
     * @param ConnectionInterface|null $conn
     * @return bool
     */
    private function unSubscribeCharacterId(int $characterId, ?ConnectionInterface $conn = null) : bool {
        if($characterId){
            // unSub from $this->characters ---------------------------------------------------------------------------
            if($conn){
                // just unSub a specific connection (e.g. single browser window)
                unset($this->characters[$characterId][$conn->resourceId]);

                if( !count($this->characters[$characterId]) ){
                    // no connection left for this character
                    unset($this->characters[$characterId]);
                }
                // TODO unset $this->>$characterData if $characterId does not have any other map subscribed to
            }else{
                // unSub ALL connections from a character (e.g. multiple browsers)
                unset($this->characters[$characterId]);

                // unset characterData cache
                $this->deleteCharacterData($characterId);
            }

            // unSub from $this->subscriptions ------------------------------------------------------------------------
            $changedSubscriptionsMapIds = [];
            foreach($this->subscriptions as $mapId => $subData){
                if(array_key_exists($characterId, (array)$subData['characterIds'])){
                    unset($this->subscriptions[$mapId]['characterIds'][$characterId]);

                    if( !count($this->subscriptions[$mapId]['characterIds']) ){
                        // no characters left on this map
                        unset($this->subscriptions[$mapId]);
                    }

                    $changedSubscriptionsMapIds[] = $mapId;
                }
            }

            sort($changedSubscriptionsMapIds, SORT_NUMERIC);

            $this->log(['debug', 'info'], $conn, __FUNCTION__,
                sprintf(static::LOG_TEXT_UNSUBSCRIBE, $characterId, implode(',', $changedSubscriptionsMapIds))
            );

            // broadcast all active subscriptions to subscribed connections -------------------------------------------
            $this->broadcastMapSubscriptions($changedSubscriptionsMapIds);
        }

        return true;
    }

    /**
     * unSubscribe $characterIds from ALL maps
     * -> if $conn is set -> just unSub the $characterId from this $conn
     * @param int[] $characterIds
     * @param ConnectionInterface|null $conn
     * @return bool
     */
    private function unSubscribeCharacterIds(array $characterIds, ?ConnectionInterface $conn = null) : bool {
        $response = false;
        foreach($characterIds as $characterId){
            $response = $this->unSubscribeCharacterId($characterId, $conn);
        }
        return $response;
    }

    /**
     * delete mapId from subscriptions and broadcast "delete msg" to clients
     * @param string $task
     * @param int $mapId
     * @return int
     */
    private function deleteMapId(string $task, int $mapId) : int {
        $connectionCount = $this->broadcastMapData($task, $mapId, $mapId);

        // remove map from subscriptions
        if(isset($this->subscriptions[$mapId])){
            unset($this->subscriptions[$mapId]);
        }

        $this->log(['debug', 'info'], null, __FUNCTION__,
            sprintf(static::LOG_TEXT_MAP_DELETE, $mapId, $connectionCount)
        );

        return $connectionCount;
    }

    /**
     * get all mapIds a characterId has subscribed to
     * @param int $characterId
     * @return int[]
     */
    private function getMapIdsByCharacterId(int $characterId) : array {
        $mapIds = [];
        foreach($this->subscriptions as $mapId => $subData) {
            if(array_key_exists($characterId, (array)$subData['characterIds'])){
                $mapIds[] = $mapId;
            }
        }
        return $mapIds;
    }

    /**
     * @param ConnectionInterface $conn
     * @return int[]
     */
    private function getCharacterIdsByConnection(ConnectionInterface $conn) : array {
        $characterIds = [];
        $resourceId = $conn->resourceId;

        foreach($this->characters as $characterId => $resourceIDs){
            if(
                array_key_exists($resourceId, $resourceIDs) &&
                !in_array($characterId, $characterIds)
            ){
                $characterIds[] = $characterId;
            }
        }
        return $characterIds;
    }

    /**
     * @param $mapId
     * @return array
     */
    private function getCharacterIdsByMapId(int $mapId) : array {
        $characterIds = [];
        if(
            array_key_exists($mapId, $this->subscriptions) &&
            is_array($this->subscriptions[$mapId]['characterIds'])
        ){
            $characterIds = array_keys($this->subscriptions[$mapId]['characterIds']);
        }
        return $characterIds;
    }

    /**
     * get connections by $characterIds
     * @param int[] $characterIds
     * @return \SplObjectStorage
     */
    private function getConnectionsByCharacterIds(array $characterIds) : \SplObjectStorage {
        $connections = new \SplObjectStorage;
        foreach($characterIds as $characterId){
            $connections->addAll($this->getConnectionsByCharacterId($characterId));
        }
        return $connections;
    }

    /**
     * get connections by $characterId
     * @param int $characterId
     * @return \SplObjectStorage
     */
    private function getConnectionsByCharacterId(int $characterId) : \SplObjectStorage {
        $connections = new \SplObjectStorage;
        if(isset($this->characters[$characterId])){
            foreach(array_keys($this->characters[$characterId]) as $resourceId){
                if(
                    $this->hasConnectionId($resourceId) &&
                    !$connections->contains($conn = $this->getConnection($resourceId))
                ){
                    $connections->attach($conn);
                }
            }
        }
        return $connections;
    }

    /**
     * check character access against $this->characterAccessData whitelist
     * @param $characterId
     * @param $characterToken
     * @return array
     */
    private function checkCharacterAccess(int $characterId, string $characterToken) : array {
        $characterData = [];
        if( !empty($characterAccessData = (array)$this->characterAccessData[$characterId]) ){
            // check expire for $this->characterAccessData -> check ALL characters and remove expired
            foreach($characterAccessData as $i => $data){
                $deleteToken = false;

                if( ((int)$data['expire'] - time()) > 0 ){
                    // still valid -> check token
                    if($characterToken === $data['token']){
                        $characterData = $data['characterData'];
                        $deleteToken = true;
                        // NO break; here -> check other characterAccessData as well
                    }
                }else{
                    // token expired
                    $deleteToken = true;
                }

                if($deleteToken){
                    unset($this->characterAccessData[$characterId][$i]);
                    // -> check if tokens for this charId is empty
                    if( empty($this->characterAccessData[$characterId]) ){
                        unset($this->characterAccessData[$characterId]);

                    }
                }
            }
        }

        return $characterData;
    }

    /**
     * check map access against $this->mapAccessData whitelist
     * @param $characterId
     * @param $mapId
     * @param $mapToken
     * @return bool
     */
    private function checkMapAccess(int $characterId, int $mapId, string $mapToken) : bool {
        $access = false;
        if( !empty($mapAccessData = (array)$this->mapAccessData[$mapId][$characterId]) ){
            foreach($mapAccessData as $i => $data){
                $deleteToken = false;
                // check expire for $this->mapAccessData -> check ALL characters and remove expired
                if( ((int)$data['expire'] - time()) > 0 ){
                    // still valid -> check token
                    if($mapToken === $data['token']){
                        $access = true;
                        $deleteToken = true;
                    }
                }else{
                    // token expired
                    $deleteToken = true;
                }

                if($deleteToken){
                    unset($this->mapAccessData[$mapId][$characterId][$i]);
                    // -> check if tokens for this charId is empty
                    if( empty($this->mapAccessData[$mapId][$characterId]) ){
                        unset($this->mapAccessData[$mapId][$characterId]);
                        // -> check if map has no access tokens left for characters
                        if( empty($this->mapAccessData[$mapId]) ){
                            unset($this->mapAccessData[$mapId]);
                        }
                    }
                }
            }
        }
        return $access;
    }

    /**
     * broadcast $payload to $connections
     * @param \SplObjectStorage $connections
     * @param Payload $payload
     */
    private function broadcast(\SplObjectStorage $connections, Payload $payload) : void {
        $data = json_encode($payload);
        foreach($connections as $conn){
            $this->send($conn, $data);
        }
    }

    // custom calls ===================================================================================================

    /**
     * receive data from TCP socket (main App)
     * -> send response back
     * @param string $task
     * @param null|int|array $load
     * @return bool|float|int|null
     */
    public function receiveData(string $task, $load = null){
        $responseLoad = null;

        switch($task){
            case 'healthCheck':
                $responseLoad = $this->setHealthCheckToken((float)$load);
                break;
            case 'characterUpdate':
                $this->updateCharacterData((array)$load);
                $mapIds = $this->getMapIdsByCharacterId((int)$load['id']);
                $this->broadcastMapSubscriptions($mapIds);
                break;
            case 'characterLogout':
                $responseLoad = $this->unSubscribeCharacterIds((array)$load);
                break;
            case 'mapConnectionAccess':
                $responseLoad = $this->setConnectionAccess($load);
                break;
            case 'mapAccess':
                $responseLoad = $this->setAccess($task, $load);
                break;
            case 'mapUpdate':
                $responseLoad = $this->broadcastMapUpdate($task, (array)$load);
                break;
            case 'mapDeleted':
                $responseLoad = $this->deleteMapId($task, (int)$load);
                break;
            case 'logData':
                $this->handleLogData((array)$load['meta'], (array)$load['log']);
                break;
        }

        return $responseLoad;
    }

    /**
     * @param float $token
     * @return float
     */
    private function setHealthCheckToken(float $token) : float {
        $this->healthCheckToken = $token;
        return $this->healthCheckToken;
    }

    /**
     * @param array $characterData
     */
    private function setCharacterData(array $characterData) : void {
        if($characterId = (int)$characterData['id']){
            $this->characterData[$characterId] = $characterData;
        }
    }

    /**
     * @param int $characterId
     * @return array
     */
    private function getCharacterData(int $characterId) : array {
        return empty($this->characterData[$characterId]) ? [] : $this->characterData[$characterId];
    }

    /**
     * @param array $characterIds
     * @return array
     */
    private function getCharactersData(array $characterIds) : array {
        return array_filter($this->characterData, function($characterId) use($characterIds) {
            return in_array($characterId, $characterIds);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array $characterData
     */
    private function updateCharacterData(array $characterData) : void {
        $characterId = (int)$characterData['id'];
        if($this->getCharacterData($characterId)){
            $this->setCharacterData($characterData);
        }
    }

    /**
     * @param int $characterId
     */
    private function deleteCharacterData(int $characterId) : void {
        unset($this->characterData[$characterId]);
    }

    /**
     * @param array $mapIds
     */
    private function broadcastMapSubscriptions(array $mapIds) : void {
        $mapIds = array_unique($mapIds);

        foreach($mapIds as $mapId){
            if(
                !empty($characterIds = $this->getCharacterIdsByMapId($mapId)) &&
                !empty($charactersData = $this->getCharactersData($characterIds))
            ){
                $systems = SubscriptionFormatter::groupCharactersDataBySystem($charactersData);

                $mapUserData = (object)[];
                $mapUserData->config = (object)['id' => $mapId];
                $mapUserData->data = (object)['systems' => $systems];

                $connectionCount = $this->broadcastMapData('mapSubscriptions', $mapId, $mapUserData);

                $this->log(['debug'], null, __FUNCTION__,
                    sprintf(static::LOG_TEXT_MAP_SUBSCRIPTIONS, $mapId, $connectionCount)
                );
            }
        }
    }

    /**
     * @param string $task
     * @param array $mapData
     * @return int
     */
    private function broadcastMapUpdate(string $task, array $mapData) : int {
        $mapId = (int)$mapData['config']['id'];
        $connectionCount =  $this->broadcastMapData($task, $mapId, $mapData);

        $this->log(['debug'], null, __FUNCTION__,
            sprintf(static::LOG_TEXT_MAP_UPDATE, $mapId, $connectionCount)
        );

        return $connectionCount;
    }

    /**
     * send map data to ALL connected clients
     * @param string $task
     * @param int $mapId
     * @param mixed $load
     * @return int
     */
    private function broadcastMapData(string $task, int $mapId, $load) : int {
        $characterIds = $this->getCharacterIdsByMapId($mapId);
        $connections = $this->getConnectionsByCharacterIds($characterIds);

        $this->broadcast($connections, $this->newPayload($task, $load, $characterIds));

        return count($connections);
    }

    /**
     * set/update map access for allowed characterIds
     * @param string $task
     * @param array $accessData
     * @return int count of connected characters
     */
    private function setAccess(string $task, $accessData) : int {
        $newMapCharacterIds = [];

        if($mapId = (int)$accessData['id']){
            $mapName = (string)$accessData['name'];
            $characterIds = (array)$accessData['characterIds'];
            // check all charactersIds that have map access... --------------------------------------------------------
            foreach($characterIds as $characterId){
                // ... for at least ONE active connection ...
                // ... and characterData cache exists for characterId
                if(
                    !empty($this->characters[$characterId]) &&
                    !empty($this->getCharacterData($characterId))
                ){
                    $newMapCharacterIds[$characterId] = $characterId;
                }
            }

            $currentMapCharacterIds = (array)$this->subscriptions[$mapId]['characterIds'];

            // broadcast "map delete" to no longer valid characters ---------------------------------------------------
            $removedMapCharacterIds = array_keys(array_diff_key($currentMapCharacterIds, $newMapCharacterIds));
            $removedMapCharacterConnections = $this->getConnectionsByCharacterIds($removedMapCharacterIds);

            $this->broadcast($removedMapCharacterConnections, $this->newPayload($task, $mapId, $removedMapCharacterIds));

            // update map subscriptions -------------------------------------------------------------------------------
            if( !empty($newMapCharacterIds) ){
                // set new characters that have map access (overwrites existing subscriptions for that map)
                $this->subscriptions[$mapId]['characterIds'] = $newMapCharacterIds;
                $this->subscriptions[$mapId]['data']['name'] = $mapName;

                // check if subscriptions have changed
                if( !$this->arraysEqualKeys($currentMapCharacterIds, $newMapCharacterIds) ){
                    $this->broadcastMapSubscriptions([$mapId]);
                }
            }else{
                // no characters (left) on this map
                unset($this->subscriptions[$mapId]);
            }
        }
        return count($newMapCharacterIds);
    }

    /**
     * set map access data (whitelist) tokens for map access
     * @param $connectionAccessData
     * @return bool
     */
    private function setConnectionAccess($connectionAccessData){
        $response = false;
        $characterId = (int)$connectionAccessData['id'];
        $characterData = $connectionAccessData['characterData'];
        $characterToken = $connectionAccessData['token'];

        if(
            $characterId &&
            $characterData &&
            $characterToken
        ){
            // expire time for character and map tokens
            $expireTime = time() + $this->mapAccessExpireSeconds;

            // tokens for character access
            $this->characterAccessData[$characterId][] = [
                'token' => $characterToken,
                'expire' => $expireTime,
                'characterData' => $characterData
            ];

            foreach((array)$connectionAccessData['mapData'] as $mapData){
                $mapId = (int)$mapData['id'];

                $this->mapAccessData[$mapId][$characterId][] = [
                    'token' => $mapData['token'],
                    'expire' => $expireTime
                ];
            }

            $response = 'OK';
        }

        return $response;
    }

    /**
     * get stats data
     * -> lists all channels, subscribed characters + connection info
     * @return array
     */
    protected function getSubscriptionStats() : array {
        $uniqueConnections = [];
        $uniqueSubscriptions = [];
        $channelsStats = [];

        foreach($this->subscriptions as $mapId => $subData){
            $characterIds = $this->getCharacterIdsByMapId($mapId);
            $uniqueMapConnections = [];

            $channelStats = [
                'channelId'     => $mapId,
                'channelName'   => $subData['data']['name'],
                'countSub'      => count($characterIds),
                'countCon'      => 0,
                'subscriptions' => []
            ];

            foreach($characterIds as $characterId){
                $characterData = $this->getCharacterData($characterId);
                $connections = $this->getConnectionsByCharacterId($characterId);

                $characterStats = [
                    'characterId'   => $characterId,
                    'characterName' => isset($characterData['name']) ? $characterData['name'] : null,
                    'countCon'      => $connections->count(),
                    'connections'   => []
                ];

                foreach($connections as $connection){
                    if(!in_array($connection->resourceId, $uniqueMapConnections)){
                        $uniqueMapConnections[] = $connection->resourceId;
                    }

                    $metaData = $this->getConnectionData($connection);
                    $microTime = (float)$metaData['mTimeSend'];
                    $logTime = Store::getDateTimeFromMicrotime($microTime);

                    $characterStats['connections'][] = [
                        'resourceId'        => $connection->resourceId,
                        'remoteAddress'     => $connection->remoteAddress,
                        'mTimeSend'         => $microTime,
                        'mTimeSendFormat1'  => $logTime->format('Y-m-d H:i:s.u'),
                        'mTimeSendFormat2'  => $logTime->format('H:i:s')
                    ];
                }

                $channelStats['subscriptions'][] = $characterStats;
            }

            $uniqueConnections = array_unique(array_merge($uniqueConnections, $uniqueMapConnections));
            $uniqueSubscriptions = array_unique(array_merge($uniqueSubscriptions, $characterIds));

            $channelStats['countCon'] = count($uniqueMapConnections);

            $channelsStats[] = $channelStats;
        }

        return [
            'countSub' => count($uniqueSubscriptions),
            'countCon' => count($uniqueConnections),
            'channels' => $channelsStats
        ];
    }

    /**
     * compare two assoc arrays by keys. Key order is ignored
     * -> if all keys from array1 exist in array2 && all keys from array2 exist in array 1, arrays are supposed to be equal
     * @param array $array1
     * @param array $array2
     * @return bool
     */
    protected function arraysEqualKeys(array $array1, array $array2) : bool {
        return !array_diff_key($array1, $array2) && !array_diff_key($array2, $array1);
    }

    /**
     * dispatch log writing to a LogFileHandler
     * @param array $meta
     * @param array $log
     */
    private function handleLogData(array $meta, array $log){
        $logHandler = new LogFileHandler((string)$meta['stream']);
        $logHandler->write($log);
    }
}