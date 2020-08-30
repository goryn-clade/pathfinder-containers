<?php


namespace Exodus4D\Socket\Log;


class ShellColors {

    /**
     * all foreground color codes
     * @var array
     */
    private $foregroundColors = [];

    /**
     * all background color codes
     * @var array
     */
    private $backgroundColors = [];

    public function __construct() {
        // set up "Shell" colors
        $this->foregroundColors['black']        = '0;30';
        $this->foregroundColors['dark_gray']    = '1;30';
        $this->foregroundColors['blue']         = '0;34';
        $this->foregroundColors['light_blue']   = '1;34';
        $this->foregroundColors['green']        = '0;32';
        $this->foregroundColors['light_green']  = '1;32';
        $this->foregroundColors['cyan']         = '0;36';
        $this->foregroundColors['light_cyan']   = '1;36';
        $this->foregroundColors['red']          = '0;31';
        $this->foregroundColors['light_red']    = '1;31';
        $this->foregroundColors['purple']       = '0;35';
        $this->foregroundColors['light_purple'] = '1;35';
        $this->foregroundColors['brown']        = '0;33';
        $this->foregroundColors['yellow']       = '1;33';
        $this->foregroundColors['light_gray']   = '0;37';
        $this->foregroundColors['white']        = '1;37';

        $this->backgroundColors['black']        = '40';
        $this->backgroundColors['red']          = '41';
        $this->backgroundColors['green']        = '42';
        $this->backgroundColors['yellow']       = '43';
        $this->backgroundColors['blue']         = '44';
        $this->backgroundColors['magenta']      = '45';
        $this->backgroundColors['cyan']         = '46';
        $this->backgroundColors['light_gray']   = '47';
    }

    /**
     * get colored string
     * @param string $string
     * @param string|null $foregroundColor
     * @param string|null $backgroundColor
     * @return string
     */
    public function getColoredString(string $string, ?string $foregroundColor = null, ?string $backgroundColor = null) : string {
        $coloredString = "";

        // Check if given foreground color found
        if (isset($this->foregroundColors[$foregroundColor])) {
            $coloredString .= "\033[" . $this->foregroundColors[$foregroundColor] . "m";
        }
        // Check if given background color found
        if (isset($this->backgroundColors[$backgroundColor])) {
            $coloredString .= "\033[" . $this->backgroundColors[$backgroundColor] . "m";
        }

        // Add string and end coloring
        $coloredString .=  $string . "\033[0m";

        return $coloredString;
    }

    /**
     * returns all foreground color names
     * @return array
     */
    public function getForegroundColors() : array {
        return array_keys($this->foregroundColors);
    }

    /**
     * returns all background color names
     * @return array
     */
    public function getBackgroundColors() : array {
        return array_keys($this->backgroundColors);
    }
}