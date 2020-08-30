<?php


namespace Exodus4D\Socket\Socket;

use Exodus4D\Socket\Log\Store;
use React\EventLoop;
use React\Socket;
use Ratchet\MessageComponentInterface;

abstract class AbstractSocket {

    /**
     * unique name for this component
     * -> should be overwritten in child instances
     * -> is used as "log store" name
     */
    const COMPONENT_NAME                = 'default';

    /**
     * global server loop
     * @var EventLoop\LoopInterface
     */
    protected $loop;

    /**
     * @var MessageComponentInterface
     */
    protected $handler;

    /**
     * @var Store
     */
    protected $logStore;

    /**
     * AbstractSocket constructor.
     * @param EventLoop\LoopInterface $loop
     * @param MessageComponentInterface $handler
     * @param Store $store
     */
    public function __construct(
        EventLoop\LoopInterface $loop,
        MessageComponentInterface $handler,
        Store $store
    ){
        $this->loop     = $loop;
        $this->handler  = $handler;
        $this->logStore = $store;

        $this->log(['debug', 'info'], null, 'START', 'start Socket server…');
    }

    /**
     * @param $logTypes
     * @param Socket\ConnectionInterface|null $connection
     * @param string $action
     * @param string $message
     */
    public function log($logTypes, ?Socket\ConnectionInterface $connection, string $action, string $message = '') : void {
        if(!$this->logStore->isLocked()){
            $remoteAddress = $connection ? $connection->getRemoteAddress() : null;
            $this->logStore->log($logTypes, $remoteAddress, null, $action, $message);
        }
    }

}