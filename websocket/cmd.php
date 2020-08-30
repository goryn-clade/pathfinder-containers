<?php
require 'vendor/autoload.php';


use Exodus4D\Socket;

if(PHP_SAPI === 'cli'){
    // optional CLI params -> default values
    // The default values should be fine for 99% of you!
    $longOpts = [
        'wsHost:'   => '0.0.0.0',       // WebSocket connection (for WebClients => Browser). '0.0.0.0' <-- any client can connect!
        'wsPort:'   => 8020,            // ↪ default WebSocket URI: 127.0.0.1:8020. This is where Nginx must proxy WebSocket traffic to
        'tcpHost:'  => '127.0.0.1',     // TcpSocket connection (for WebServer ⇄ WebSocket)
        'tcpPort:'  => 5555,            // ↪ default TcpSocket URI: tcp://127.0.0.1:5555
        'debug:'    => 2                // Debug level [0-3] 0 = silent, 1 = errors, 2 = error + info, 3 = error + info + debug
    ];

    // get options from CLI parameter + default values
    $cliOpts = getopt('', array_keys($longOpts));

    $options = [];
    array_walk($longOpts, function($defaultVal, $optKey) use ($cliOpts, &$options) {
        $key = trim($optKey, ':');
        $val = $defaultVal;
        if(array_key_exists($key, $cliOpts)){
            $val = is_int($defaultVal) ? (int)$cliOpts[$key] : $cliOpts[$key] ;
        }
        $options[$key] = $val;
    });

    /**
     * print current config parameters to Shell
     * @param array $longOpts
     * @param array $options
     */
    $showHelp = function(array $longOpts, array $options){
        $optKeys = array_keys($longOpts);
        $colors = new Socket\Log\ShellColors();
        $data = [];

        // headline for CLI config parameters
        $rowData = $colors->getColoredString(str_pad('  param', 12), 'white');
        $rowData .= $colors->getColoredString(str_pad('value', 18, ' ', STR_PAD_LEFT), 'white');
        $rowData .= $colors->getColoredString(str_pad('default', 15, ' ', STR_PAD_LEFT), 'white');

        $data[] = $rowData;
        $data[] = str_pad(' ', 45, '-');

        $i = 0;
        foreach($options as $optKey => $optVal){
            $rowData = $colors->getColoredString(str_pad('  -' . $optKey, 12), 'yellow');
            $rowData .= $colors->getColoredString(str_pad($optVal, 18, ' ', STR_PAD_LEFT), 'light_purple');
            $rowData .= $colors->getColoredString(str_pad($longOpts[$optKeys[$i]], 15, ' ', STR_PAD_LEFT), 'dark_gray');
            $data[] = $rowData;
            $i++;
        }
        $data[] = '';

        echo implode(PHP_EOL, $data) . PHP_EOL;
    };

    /**
     * set error reporting based on debug option value
     * @param int $debug
     */
    $setErrorReporting = function(int $debug){
        switch($debug){
            case 0: error_reporting(0); break; // Turn off all error reporting
            case 1: error_reporting(E_ERROR); break; // Errors only
            case 2: error_reporting(E_ALL & ~E_NOTICE); break; // Report all errors except E_NOTICE
            default: error_reporting(E_ALL);
        }
    };

    $setErrorReporting($options['debug']);

    if($options['debug']){
        // print if -debug > 0
        $showHelp($longOpts, $options);
    }

    $dsn = 'tcp://' . $options['tcpHost'] . ':' . $options['tcpPort'];

    new Socket\WebSockets($dsn, $options['wsPort'], $options['wsHost'], $options['debug']);

}else{
    echo "Script need to be called by CLI!";
}



