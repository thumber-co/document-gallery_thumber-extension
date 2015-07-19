<?php

ThumberClient::init();

/**
 * Class to process sending requests and receiving responses.
 */
class ThumberClient
{
   /**
    * The Thumber.co API subdomain.
    */
   const ThumberServerHost = 'api.thumber.co';

   /**
    * The path for creating a new thumbnail.
    */
   const ThumberServerCreatePath = '/create.json';

   /**
    * The path for retrieving the supported MIME types.
    */
   const ThumberServerMimeTypesPath = '/mime_types.json';

   /**
    * @var string The user agent to send HTTP requests as.
    */
   protected static $thumberUserAgent;

   /**
    * @var string UID for the user accessing the Thumber API.
    */
   protected static $uid;

   /**
    * @var string The user secret assoicataed with the UID for the user
    * accessing the Thumber API.
    */
   protected static $userSecret;

   /**
    * @var string Fully-qualified system path to this file.
    */
   private static $thumberClientPath;

   /**
    * Initialized class members.
    */
   public static function init() {
      self::$thumberUserAgent = 'Thumber Client 1.0 (PHP ' . phpversion() . '; ' . php_uname() . ')';
      self::$thumberClientPath = dirname( __FILE__ ) . '/';
   }

   /**
    * Sends the provided request to the API endpoint.
    *
    * @param ThumberReq $req The request to be sent. UID, callback, and timestamp
    * will be written by client. Additionally, nonce will be set if not already set.
    * @return array containing data about success of the cURL request.
    */
   public function sendRequest($req) {
      include_once self::$thumberClientPath . 'request.php';

      if (!($req instanceof ThumberReq)) {
         $this->handleError('Request must be of type ThumberReq.');
      }

      $req->setTimestamp(time());

      $uid = $req->getUid();
      if (empty($uid)) {
         $req->setUid(self::$uid);
      }

      $nonce = $req->getNonce();
      if (empty($nonce)) {
         $req->setNonce();
      }

      $req->setChecksum($req->computeChecksum(self::$userSecret));

      if (!$req->isValid(self::$userSecret)) {
         $this->handleError('Invalid request provided.');
      }

      $json = $req->toJson();
      $url = 'http://' . self::ThumberServerHost . self::ThumberServerCreatePath;
      $headers = array('Content-Type: application/json', 'Content-Length: ' . strlen($json));
      $result = $this->sendToThumber('POST', $url, $headers, $json);
      $result['nonce'] = !array_key_exists('error', $result) ? $req->getNonce() : '';

      // caller should handle errors sensibly
      return $result;
   }

   /**
    * Processes the POST request, generating a ThumberResponse, validating, and passing the result to $callback.
    * If not using client.php as the webhook, whoever receives webhook response should first invoke this method to
    * validate response.
    */
   public function receiveResponse() {
      include_once self::$thumberClientPath . 'response.php';

      $json = stream_get_contents(fopen('php://input', 'r'));
      $resp = ThumberResp::parseJson($json);

      if (is_null($resp)) {
         $this->handleError('Failed to parse JSON in POST body: ' . $json);
      }

      if (!$resp->isValid(self::$userSecret)) {
         $this->handleError('Received invalid response: ' . $json);
      }

      // This method should be overridden in order to use response
      return $resp;
   }

   /**
    * Retrieves the supported MIME types from Thumber.
    * @return array The supported MIME types reported by the Thumber server.
    */
   public function getMimeTypes() {
      $headers = array('Content-Type: application/json', 'Content-Length: 0');
      $url = 'http://' . self::ThumberServerHost . self::ThumberServerMimeTypesPath;
      $result = $this->sendToThumber('GET', $url, $headers);
      return !array_key_exists('error', $result) ? json_decode($result['body'], true) : array();
   }

   /**
    * Sends cURL request to Thumber server.
    * @param $type string GET or POST
    * @param $url string The URL endpoint being targeted.
    * @param $httpHeaders array The headers to be sent.
    * @param $body string The POST body. Ignored if type is GET.
    * @return array The result of the request.
    */
   protected function sendToThumber($type, $url, $httpHeaders, $body = '') {
      // open connection
      $ch = curl_init();

      curl_setopt($ch, CURLOPT_USERAGENT,      self::$thumberUserAgent);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_URL,            $url);
      curl_setopt($ch, CURLOPT_HTTPHEADER,     $httpHeaders);
      if ($type == 'POST' && strlen($body) != 0) {
         curl_setopt($ch, CURLOPT_POSTFIELDS,  $body);
      }

      // execute post, storing useful information about result
      $response = curl_exec($ch);
      $error = curl_error($ch);
      $result = array (
          'header'          => '',
          'body'            => '',
          'error'           => '',
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
         $result ['error'] = $error;
      }

      curl_close($ch);

      return $result;
   }

   /**
    * @param $err string Fires on fatal error.
    */
   protected function handleError($err) {
      die($err);
   }
}