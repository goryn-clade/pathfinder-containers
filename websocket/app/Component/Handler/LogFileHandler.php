<?php
/**
 * Created by PhpStorm.
 * User: exodu
 * Date: 03.09.2017
 * Time: 17:02
 */

namespace Exodus4D\Socket\Component\Handler;


class LogFileHandler {

    const ERROR_DIR_CREATE              = 'There is no existing directory at "%s" and its not buildable.';

    /**
     * steam uri
     * @var string
     */
    private $stream                     = '';

    /**
     * stream dir
     * @var string
     */
    private $dir                        = '.';

    /**
     * file base dir already created
     * @var bool
     */
    private $dirCreated = false;

    public function __construct(string $stream){
        $this->stream = $stream;
        $this->dir = dirname($this->stream);
        $this->createDir();
    }

    /**
     * write log data into to file
     * @param array $log
     */
    public function write(array $log){
        $log = (string)json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if( !empty($log) ){
            if($stream = fopen($this->stream, 'a')){
                flock($stream, LOCK_EX);
                fwrite($stream, $log . PHP_EOL);
                flock($stream, LOCK_UN);
                fclose($stream);

                // logs should be writable for non webSocket user too
                @chmod($this->stream, 0666);
            }
        }
    }

    /**
     * create directory
     */
    private function createDir(){
        // Do not try to create dir if it has already been tried.
        if ($this->dirCreated){
            return;
        }

        if ($this->dir && !is_dir($this->dir)){
            $status = mkdir($this->dir, 0777, true);
            if (false === $status) {
                throw new \UnexpectedValueException(sprintf(self::ERROR_DIR_CREATE, $this->dir));
            }
        }
        $this->dirCreated = true;
    }
}