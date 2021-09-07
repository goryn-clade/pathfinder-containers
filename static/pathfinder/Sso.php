<?php
/**
 * Created by PhpStorm.
 * User: Exodus 4D
 * Date: 26.12.2018
 * Time: 16:21
 */

namespace Exodus4D\ESI\Client\Ccp\Sso;

use Exodus4D\ESI\Client\Ccp;
use Exodus4D\ESI\Config\ConfigInterface;
use Exodus4D\ESI\Config\Ccp\Sso\Config;
use Exodus4D\ESI\Lib\RequestConfig;
use Exodus4D\ESI\Lib\WebClient;
use Exodus4D\ESI\Mapper;

class Sso extends Ccp\AbstractCcp implements SsoInterface {

    /**
     * verify character data by "access_token"
     * -> get some basic information (like character id)
     * -> if more character information is required, use ESI "characters" endpoints request instead
     * @param string $accessToken
     * @return RequestConfig
     */
    protected function getVerifyCharacterRequest(string $accessToken) : RequestConfig {
        $requestOptions = [
            'headers' => $this->getAuthHeader($accessToken, 'Bearer')
        ];

        return new RequestConfig(
            WebClient::newRequest('GET', $this->getVerifyUserEndpointURI()),
            $requestOptions,
            function($body) : array {
                $characterData = [];
                if(!$body->error){
                    $characterData = (new Mapper\Sso\Character($body))->getData();
                }

                return $characterData;
            }
        );
    }

    /**
     * get a valid "access_token" for oAuth 2.0 verification
     * -> verify $authCode and get NEW "access_token"
     *      $requestParams['grant_type]     = 'authorization_code'
     *      $requestParams['code]           = 'XXXX'
     * -> request NEW "access_token" if isset:
     *      $requestParams['grant_type]     = 'refresh_token'
     *      $requestParams['refresh_token]  = 'XXXX'
     * @param array $credentials
     * @param array $requestParams
     * @return RequestConfig
     */
    protected function getAccessRequest(array $credentials, array $requestParams = []) : RequestConfig {
        $requestOptions = [
            'form_params' => $requestParams,
            'auth' => $credentials
        ];

        return new RequestConfig(
            WebClient::newRequest('POST',  $this->getVerifyAuthorizationCodeEndpointURI()),
            $requestOptions,
            function($body) : array {
                $accessData = [];
                if(!$body->error){
                    $accessData = (new Mapper\Sso\Access($body))->getData();
                }

                return $accessData;
            }
        );
    }

    /**
     * @return string
     */
    public function getAuthorizationEndpointURI() : string {
        return '/oauth/authorize';
    }

    /**
     * @return string
     */
    public function getVerifyUserEndpointURI() : string {
        return '/oauth/verify';
    }

    /**
     * @return string
     */
    public function getVerifyAuthorizationCodeEndpointURI() : string {
        return '/oauth/token';
    }

    /**
     * @return ConfigInterface
     */
    protected function getConfig() : ConfigInterface {
        return ($this->config instanceof ConfigInterface) ? $this->config : $this->config = new Config();
    }
}