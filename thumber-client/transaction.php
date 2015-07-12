<?php
defined('WPINC') OR exit;

abstract class ThumberTransaction {
   /**
    * Constructs new ThumberTransaction instance.
    * 
    * @param string $json JSON string to for populating instance.
    */
   public function __construct($json = null) {
      if (!is_null($json)) {
         $this->fromJson($json);
      }
   }
   
   /**
    * @var string The unique identifier for this transaction set (same used in both req & resultant resp).
    */
   protected  $nonce;
   
   /**
    * Sets the NONCE. Generates the NONCE from microtime() if none is given.
    * 
    * @param string $nonce The NONCE.
    */
   public function setNonce($nonce = null) {
      if (is_null($nonce)) {
         $nonce = md5(microtime());
      }
      
      $this->nonce = $nonce;
   }
   
   /**
    * Gets the NONCE.
    * 
    * @return string The NONCE.
    */
   public function getNonce() {
      return $this->nonce;
   }
   
   /**
    * @var int The UTC timestamp representing when this transaction was sent.
    */
   protected $timestamp;
   
   /**
    * Sets the timestamp.
    * 
    * @param int $timestamp The timestamp.
    */
   public function setTimestamp($timestamp) {
      $this->timestamp = $timestamp;
   }
   
   /**
    * Gets the timestamp.
    * 
    * @return int The timestamp.
    */
   public function getTimestamp() {
      return $this->timestamp;
   }
   
   /**
    * @var string The checksum which is calculated with the contents of the 
    * transaction (minus the checksum) and the user's secret with the HMAC-SHA256 algorithm.
    */
   protected $checksum;
   
   /**
    * Sets the checksum.
    * 
    * @param string $checksum The checksum.
    */
   public function setChecksum($checksum) {
      $this->checksum = $checksum;
   }
   
   /**
    * Gets the checksum.
    * 
    * @return string The checksum.
    */
   public function getChecksum() {
      return $this->checksum;
   }
   
   /**
    * The base64-encoded data.
    * 
    * @var string base64-encoded data.
    */
   protected $data;
   
   /**
    * Sets the base64-encoded data.
    * 
    * @param string $data The base64-encoded data.
    */
   public function setEncodedData($data) {
      $this->data = $data;
      $this->decodedData = null;
   }
   
   /**
    * Gets the base64-encoded data.
    * 
    * NOTE: If only raw data is initialized, this method will populate the base64-encoded data from that value.
    * 
    * @return string The base64-encoded data.
    */
   public function getEncodedData() {
      if (empty($this->data) && !empty($this->decodedData)) {
         $this->data = base64_encode($this->decodedData);
      }
      
      return $this->data;
   }
   
   /**
    * The raw file data.
    * 
    * @var data Raw data read from file.
    */
   private $decodedData;
   
   /**
    * Gets the raw file data.
    * 
    * @param data $decodedData The raw file data.
    */
   public function setDecodedData($decodedData) {
      $this->decodedData = $decodedData;
      $this->data = null;
   }
   
   /**
    * Gets the raw file data.
    * 
    * NOTE: If only base64 data is initialized, this method will populate the raw data from that value.
    * 
    * @return data The raw file data.
    */
   public function getDecodedData() {
      if (empty($this->decodedData) && !empty($this->data)) {
         $this->decodedData = base64_decode($this->data);
      }
      
      return $this->decodedData;
   }
   
   /**
    * Whether this instance is valid. If secret is provided, then validity will include checksum validation.
    * 
    * @param string $secret The user secret.
    * @return bool Whether this instance is valid.
    */
   public function isValid($secret = null) {
      return isset($this->nonce) &&
         isset($this->timestamp) &&
         isset($this->checksum) &&
         (is_null($secret) || $this->isValidChecksum($secret));
   }
   
   /**
    * Computes checksum for this instance and compares against the value set as the instance checksum.
    * 
    * @param string $secret The user secret.
    * @return bool Whether this instance's checksum value is valid for this instance's contents.
    */
   public function isValidChecksum($secret) {
      return $this->checksum === $this->computeChecksum($secret);
   }
   
   /**
    * Computes checksum based on instance variables.
    * 
    * @param string $secret The user secret.
    * @return string The checksum representing this instance.
    */
   public function computeChecksum($secret) {
      $arr = $this->toArray();
      unset($arr['checksum']);
      
      // only use up to the first 1024 characters of each value in computing checksum
      // encode any special characters in value
      foreach ($arr as &$v) {
         if (is_bool($v)) {
            $v = $v ? 'true' : 'false';
         } else {
            $v = substr((string)$v, 0, 1024);
         }
      }
      
      ksort($arr, SORT_STRING);
      
      $query = self::implode('=', '&', $arr);
      return hash_hmac('sha256', $query, $secret, false);
   }
   
   /**
    * Gets array represenation of this instance.
    * 
    * @return array Array representation of this instance.
    */
   public function toArray() {
     $ret = array();
     
     foreach(get_object_vars($this) as $k => $v) {
        if (is_null($v)) continue;
        
        // cammel case to underscore word deliniation
        $k = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $k));
        $ret[$k] = $v;
     }
     
     return $ret;
   }
   
   /**
    * Creates JSON string with class fields, renaming field names to underscore rather than cammel.
    * 
    * @return string JSON representation of defined object accessible non-static properties.
    */
   public function toJson() {
     return json_encode($this->toArray());
  }
  
  /**
   * Populates instance with values from JSON.
   * 
   * @param string $json The JSON string to populate this instance with.
   */
   public function fromJson($json) {
      $json = json_decode($json, true);
      if (is_null($json)) {
         throw new InvalidArgumentException("Provided JSON string is invalid: $json");
      }
      
      foreach ($json as $k => $v) {
         // underscore word deliniation to cammel case
         $k = preg_replace_callback('/_([a-z])/', array (__CLASS__, 'secondCharToUpper'), $k);
         if (property_exists($this, $k)) {
            $this->$k = $v;
         }
      }
   }

   /**
    * @param string $string To take second char from.
    * @return char Capitalized second char of given string.
    */
   private static function secondCharToUpper($string) {
      return strtoupper($string[1]);
   }
   
   /**
    * Implodes associative array.
    * @param string $inner Glue between key and value.
    * @param string $outer Glue between key-value pairs.
    * @param array $arr The array to be imploded.
    * @return string The implosion.
    */
   private static function implode($inner, $outer, $arr) {
      if (count($arr) == 0) return '';
      $ret = '';
      
      foreach ($arr as $k => $v) {
         $ret .= "$k$inner$v$outer";
      }
      
      return substr($ret , 0, -strlen($outer));
   }
}