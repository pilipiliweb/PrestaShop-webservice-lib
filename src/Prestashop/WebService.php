<?php
/*
 * 2007-2013 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author PrestaShop SA <contact@prestashop.com>
 *  @copyright  2007-2013 PrestaShop SA
 *  @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 * PrestaShop Webservice Library
 * @package PrestaShopWebservice
 */

namespace PrestaShopWebservice\PrestaShop;

use PrestaShopWebservice\WebServiceException;

/**
 * @package PrestaShopWebService
 */
class WebService
{

    /** @var string Shop URL */
    protected $url;

    /** @var string Authentification key */
    protected $key;

    /** @var boolean is debug activated */
    protected $debug;

    /** @var string PS version */
    protected $version;

    /**
     * WebService constructor. Throw an exception when CURL is not installed/activated
     * @param string $url Root URL for the shop
     * @param string $key Authentification key
     * @param mixed $debug Debug mode Activated (true) or deactivated (false)
     */
    public function __construct($url, $key, $debug = true)
    {
        if (!extension_loaded('curl')) {
            throw new WebServiceException('Please activate the PHP extension \'curl\' to allow use of PrestaShop webservice library');
        }
        $this->url = preg_replace('/\/$/', '', $url);
        $this->key = $key;
        $this->debug = $debug;
        $this->version = 'unknown';
    }

    /**
     * Take the status code and throw an exception if the server didn't return 200 or 201 code
     * @param int $status_code Status code of an HTTP return
     */
    protected function checkStatusCode($status_code)
    {
        $error_label = 'This call to PrestaShop Web Services failed and returned an HTTP status of %d. That means: %s.';
        switch ($status_code) {
            case 200:
            case 201:
                break;
            case 204:
                throw new WebServiceException(sprintf($error_label, $status_code, 'No content'));
                break;
            case 400:
                throw new WebServiceException(sprintf($error_label, $status_code, 'Bad Request'));
                break;
            case 401:
                throw new WebServiceException(sprintf($error_label, $status_code, 'Unauthorized'));
                break;
            case 404:
                throw new WebServiceException(sprintf($error_label, $status_code, 'Not Found'));
                break;
            case 405:
                throw new WebServiceException(sprintf($error_label, $status_code, 'Method Not Allowed'));
                break;
            case 500:
                throw new WebServiceException(sprintf($error_label, $status_code, 'Internal Server Error'));
                break;
            default:
                throw new WebServiceException('This call to PrestaShop Web Services returned an unexpected HTTP status of:'.$status_code);
        }
    }

    /**
     * Handles a CURL request to PrestaShop Webservice. Can throw exception.
     * @param string $url Resource name
     * @param mixed $curl_params CURL parameters (sent to curl_set_opt)
     * @return array status_code, response
     */
    protected function executeRequest($url, $curl_params = array())
    {
        $defaultParams = array(
            CURLOPT_HEADER         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $this->key.':',
            CURLOPT_HTTPHEADER     => array('Content-type: application/json'),
        );

        $session = curl_init($url);

        $curl_options = array();
        foreach ($defaultParams as $defkey => $defval) {
            if (isset($curl_params[$defkey])) {
                $curl_options[$defkey] = $curl_params[$defkey];
            } else {
                $curl_options[$defkey] = $defaultParams[$defkey];
            }
        }
        foreach ($curl_params as $defkey => $defval) {
            if (!isset($curl_options[$defkey])) {
                $curl_options[$defkey] = $curl_params[$defkey];
            }
        }

        curl_setopt_array($session, $curl_options);
        $response = curl_exec($session);

        $index = strpos($response, "\r\n\r\n");
        if ($index === false && $curl_params[CURLOPT_CUSTOMREQUEST] != 'HEAD') {
            throw new WebServiceException('Bad HTTP response:'.$response);
        }

        $header = substr($response, 0, $index);
        $body = substr($response, $index + 4);

        $headerArrayTmp = explode("\n", $header);

        $headerArray = array();
        foreach ($headerArrayTmp as &$headerItem) {
            $tmp = explode(':', $headerItem);
            $tmp = array_map('trim', $tmp);
            if (count($tmp) == 2) {
                $headerArray[$tmp[0]] = $tmp[1];
            }
        }

        if (array_key_exists('PSWS-Version', $headerArray)) {
            $this->version = $headerArray['PSWS-Version'];
        }

        if ($this->debug) {
            $this->printDebug('HTTP REQUEST HEADER', curl_getinfo($session, CURLINFO_HEADER_OUT));
            $this->printDebug('HTTP RESPONSE HEADER', $header);
        }
        $status_code = curl_getinfo($session, CURLINFO_HTTP_CODE);
        if ($status_code === 0) {
            throw new WebServiceException('CURL Error: '.curl_error($session));
        }
        curl_close($session);
        if ($this->debug) {
            if ($curl_params[CURLOPT_CUSTOMREQUEST] == 'PUT' || $curl_params[CURLOPT_CUSTOMREQUEST] == 'POST') {
                $this->printDebug('XML SENT', urldecode($curl_params[CURLOPT_POSTFIELDS]));
            }
            if ($curl_params[CURLOPT_CUSTOMREQUEST] != 'DELETE' && $curl_params[CURLOPT_CUSTOMREQUEST] != 'HEAD') {
                $this->printDebug('RETURN HTTP BODY', $body);
            }
        }
        return array('status_code' => $status_code, 'response' => $body, 'header' => $header);
    }

    public function printDebug($title, $content)
    {
        echo '<div style="display:table;background:#CCC;font-size:8pt;padding:7px"><h6 style="font-size:9pt;margin:0">'.$title.'</h6><pre>'.htmlentities($content).'</pre></div>';
    }

    public function getVersion()
    {
        return $this->version;
    }

    /**
     * Load XML from string. Can throw exception
     * @param string $response String from a CURL response
     * @return SimpleXMLElement status_code, response
     */
    protected function parseXML($response)
    {
        if ($response != '') {
            libxml_clear_errors();
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA);
            if (libxml_get_errors()) {
                $msg = var_export(libxml_get_errors(), true);
                libxml_clear_errors();
                throw new WebServiceException('HTTP XML response is not parsable: '.$msg);
            }
            return $xml;
        } else {
            throw new WebServiceException('HTTP response is empty');
        }
    }

    /**
     * Add (POST) a resource
     * @param array $options
     * @return SimpleXMLElement status_code, response
     */
    public function add($options)
    {
        $xml = '';

        if (isset($options['resource'], $options['postXml']) || isset($options['url'], $options['postXml'])) {
            $url = (isset($options['resource']) ? $this->url.'/api/'.$options['resource'] : $options['url']);
            $xml = $options['postXml'];
            if (isset($options['id_shop'])) {
                $url .= '&id_shop='.$options['id_shop'];
            }
            if (isset($options['id_group_shop'])) {
                $url .= '&id_group_shop='.$options['id_group_shop'];
            }
        } else {
            throw new WebServiceException('Bad parameters given');
        }
        $request = $this->executeRequest($url, array(CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => $xml));

        $this->checkStatusCode($request['status_code']);
        return $this->parseXML($request['response']);
    }

    /**
     * Retrieve (GET) a resource
     * @param array $options Array representing resource to get.
     * @return SimpleXMLElement status_code, response
     */
    public function get($options)
    {
        if (isset($options['url'])) {
            $url = $options['url'];
        } elseif (isset($options['resource'])) {
            $url = $this->url.'/api/'.$options['resource'];
            $url_params = array();
            if (isset($options['id'])) {
                $url .= '/'.$options['id'].'/?output_format=JSON';
            } else {
                $url .= '/?output_format=JSON';
            }

            $params = array('filter', 'display', 'sort', 'limit', 'id_shop', 'id_group_shop');
            foreach ($params as $p) {
                foreach ($options as $k => $o) {
                    if (strpos($k, $p) !== false) {
                        $url_params[$k] = $options[$k];
                    }
                }
            }
            if (count($url_params) > 0) {
                $url .= '?'.http_build_query($url_params);
            }
        } else {
            throw new WebServiceException('Bad parameters given');
        }

        $request = $this->executeRequest($url, array(CURLOPT_CUSTOMREQUEST => 'GET'));

        $this->checkStatusCode($request['status_code']); // check the response validity
        return json_decode($request['response']);
    }

    /**
     * Head method (HEAD) a resource
     *
     * @param array $options Array representing resource for head request.
     * @return SimpleXMLElement status_code, response
     */
    public function head($options)
    {
        if (isset($options['url'])) {
            $url = $options['url'];
        } elseif (isset($options['resource'])) {
            $url = $this->url.'/api/'.$options['resource'];
            $url_params = array();
            if (isset($options['id'])) {
                $url .= '/'.$options['id'];
            }

            $params = array('filter', 'display', 'sort', 'limit');
            foreach ($params as $p) {
                foreach ($options as $k => $o) {
                    if (strpos($k, $p) !== false) {
                        $url_params[$k] = $options[$k];
                    }
                }
            }
            if (count($url_params) > 0) {
                $url .= '?'.http_build_query($url_params);
            }
        } else {
            throw new WebServiceException('Bad parameters given');
        }
        $request = $this->executeRequest($url, array(CURLOPT_CUSTOMREQUEST => 'HEAD', CURLOPT_NOBODY => true));
        $this->checkStatusCode($request['status_code']); // check the response validity
        return $request['header'];
    }

    /**
     * Edit (PUT) a resource
     * @param array $options Array representing resource to edit.
     */
    public function edit($options)
    {
        $xml = '';
        if (isset($options['url'])) {
            $url = $options['url'];
        } elseif ((isset($options['resource'], $options['id']) || isset($options['url'])) && $options['putXml']) {
            $url = (isset($options['url']) ? $options['url'] : $this->url.'/api/'.$options['resource'].'/'.$options['id']);
            $xml = $options['putXml'];
            if (isset($options['id_shop'])) {
                $url .= '&id_shop='.$options['id_shop'];
            }
            if (isset($options['id_group_shop'])) {
                $url .= '&id_group_shop='.$options['id_group_shop'];
            }
        } else {
            throw new WebServiceException('Bad parameters given');
        }

        $request = $this->executeRequest($url, array(CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $xml));
        $this->checkStatusCode($request['status_code']); // check the response validity
        return $this->parseXML($request['response']);
    }

    /**
     * Delete (DELETE) a resource.
     * @param array $options Array representing resource to delete.
     */
    public function delete($options)
    {
        if (isset($options['url'])) {
            $url = $options['url'];
        } elseif (isset($options['resource']) && isset($options['id'])) {
            if (is_array($options['id'])) {
                $url = $this->url.'/api/'.$options['resource'].'/?id=['.implode(',', $options['id']).']';
            } else {
                $url = $this->url.'/api/'.$options['resource'].'/'.$options['id'];
            }
        }
        if (isset($options['id_shop'])) {
            $url .= '&id_shop='.$options['id_shop'];
        }
        if (isset($options['id_group_shop'])) {
            $url .= '&id_group_shop='.$options['id_group_shop'];
        }
        $request = $this->executeRequest($url, array(CURLOPT_CUSTOMREQUEST => 'DELETE'));
        $this->checkStatusCode($request['status_code']); // check the response validity
        return true;
    }

    /**
     * Get
     *
     * @param string $resource The resource name (orders, customers, ...)
     * @return SimpleXMLElement blank schema
     */
    public function getSchema($resource)
    {
        $url = sprintf('%s/api/%s?schema=blank', $this->url, strtolower($resource));
        return $this->get(array('url' => $url));
    }

    public function isPrestaShop16()
    {
        return version_compare($this->version, '1.6.0.0', '>=');
    }
}
