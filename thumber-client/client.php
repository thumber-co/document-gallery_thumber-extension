<?php
defined('WPINC') OR exit;

/**
 * Hanlde processing POST callbacks (which will include the generated thumbnail or an error msg).
 */
if (isset($_GET['response'])) {
   ThumberClient::receiveResponse();
}

/**
 * Class to process sending requests and receiving responses.
 */
ThumberClient::init();
class ThumberClient
{
   private static $thumberUserAgent;

   private static $thumberClientPath;

   const ThumberServerHost = 'api.thumber.co';

   const ThumberServerCreatePath = '/create.json';

   const ThumberServerMimeTypesPath = '/mime_types.json';

   /**
    * Initializes ThumberClient.
    */
   public static function init() {
      if (!isset(self::$thumberUserAgent)) {
         self::$thumberUserAgent = 'Thumber Client 1.0 (PHP ' . phpversion() . '; ' . php_uname() . ')';
         self::$thumberClientPath = dirname(__FILE__) . '/';
      }
   }

   /**
    * @var string UID for the user accessing the Thumber API.
    */
   private static $uid;

   public static function setUid($uid) {
      self::$uid = $uid;
   }

   /**
    * @var string The user secret assoicataed with the UID for the user
    * accessing the Thumber API.
    */
   private static $userSecret;

   public static function setUserSecret($userSecret) {
      self::$userSecret = $userSecret;
   }

   /**
    * @var string The URL pointing to this file's directory. Must be publically
    * accessible for Thumebr API to send generated thumbnail following request.
    */
   private static $webhook;

   private static function setWebhook($webhook) {
      self::$webhook = $webhook;
   }

   /**
    * @var callable The method to be invoked when response arrives.
    */
   private static $callback;

   public static function setCallback($callback) {
      self::$callback = $callback;
   }

   /**
    * Sends the provided request to the API endpoint.
    *
    * @param ThumberReq $req The request to be sent. UID, callback, and timestamp
    * will be written by client. Additionally, nonce will be set if not already set.
    * @return array containing data about success of the cURL request.
    */
   public static function sendRequest($req) {
      include_once self::$thumberClientPath . 'request.php';

      if (!($req instanceof ThumberReq)) {
         die('Request must be of type ThumberReq.');
      }

      $req->setTimestamp(time());

      $uid = $req->getUid();
      if (empty($uid)) {
         $req->setUid(self::$uid);
      }

      $callback = $req->getCallback();
      if (empty($callback)) {
         $req->setCallback(self::$directoryUrl . 'client.php?response=1');
      }

      $nonce = $req->getNonce();
      if (empty($nonce)) {
         $req->setNonce();
      }

      $req->setChecksum($req->computeChecksum(self::$userSecret));

      if (!$req->isValid(self::$userSecret)) {
         if (DocumentGalleryThumberExtension::logEnabled()) {
            DG_Logger::writeLog(DG_LogLevel::Detail, 'Request is invalid. Not sending.');
         }

         return new WP_Error();
      }

      $json = $req->toJson();
      if (DocumentGalleryThumberExtension::logEnabled()) {
         DG_Logger::writeLog(DG_LogLevel::Detail, "Sending request: $json");
      }

      $args = array(
          'headers'      => array('Content-Type' => 'application/json'),
          'user-agent'   => self::$thumberUserAgent,
          'body'         => $json
      );

      // caller should handle failures sensibly
      return wp_remote_post('http://' . self::ThumberServerHost . self::ThumberServerCreatePath, $args);
   }

   /**
    * Processes the POST request, generating a ThumberResponse, validating, and passing the result to $callback.
    */
   public static function receiveResponse() {
      include_once self::$thumberClientPath . 'response.php';

      if (!isset(self::$callback) && DocumentGalleryThumberExtension::logEnabled()) {
         DG_Logger::writeLog(DG_LogLevel::Error, __CLASS__ . '::$callback must be initialized.');
      }

      $json = stream_get_contents(fopen('php://input', 'r'));
      $resp = ThumberResp::parseJson($json);

      if (is_null($resp)) {
         if (DocumentGalleryThumberExtension::logEnabled()) {
            DG_Logger::writeLog(DG_LogLevel::Error, 'Failed to parse JSON in POST body: ' . $json);
         }
      } elseif (!$resp->isValid(self::$userSecret) && DocumentGalleryThumberExtension::logEnabled()) {
         DG_Logger::writeLog(DG_LogLevel::Error, 'Received invalid response: ' . $json);
      }

      // response passed validation -- relay to callback function
      call_user_func(self::$callback, $resp);
   }

   /**
    * @return array The supported MIME types reported by the Thumber server.
    */
   public static function getMimeTypes() {
      $args = array(
          'headers'      => array('Content-Type' => 'application/json'),
          'user-agent'   => self::$thumberUserAgent
      );

      // caller should handle failures sensibly
      $resp = wp_remote_get('http://' . self::ThumberServerHost . self::ThumberServerMimeTypesPath, $args);

      $ret = is_array($resp) ? json_decode($resp['body'], true) : array();

      if (DocumentGalleryThumberExtension::logEnabled()) {
         DG_Logger::writeLog(DG_LogLevel::Detail, 'Thumber MIME Types: ' . implode(', ', $ret));
      }

      return $ret;
   }

   /**
    * Sends cURL request to Thumber server.
    * @param $ch resource The curl connection to be used.
    * @return array The result of the request.
    */
   private static function sendToThumber($ch) {
      // execute post, storing useful information about result
      $response = curl_exec($ch);
      $error = curl_error($ch);
      $result = array (
          'header'          => '',
          'body'            => '',
          'curl_error'      => '',
          'http_code'       => '',
          'last_url'        => ''
      );

      if ($error === '') {
         $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

         $result['header']    = substr($response, 0, $header_size);
         $result['body']      = substr($response, $header_size);
         $result['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
         $result['last_url']  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
      } else {
         $result ['curl_error'] = $error;
      }

      curl_close($ch);

      return $result;
   }
}