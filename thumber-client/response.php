<?php
defined('WPINC') OR exit;

include_once 'transaction.php';

class ThumberResp extends ThumberTransaction {
   /**
    * @var bool Whether the related ThumberReq was successful.
    */
   protected $success;
   
   /**
    * Sets whether the related ThumberReq was successful.
    * 
    * @param bool $success Whether the related ThumberReq was successful.
    */
   public function setSuccess($success) {
      $this->success = $success;
   }
   
   /**
    * Gets whether the related ThumberReq was successful.
    * 
    * @return bool Whether the related ThumberReq was successful.
    */
   public function getSuccess() {
      return $this->success;
   }
   
   /**
    * The error string indicating what went wrong.
    * 
    * @var string The error string.
    */
   protected $error;
   
   /**
    * Sets the error string indicating what went wrong.
    * 
    * @param string $error The error string.
    */
   public function setError($error) {
      $this->error = $error;
   }
   
   /**
    * Gets the error string indicating what went wrong.
    * 
    * @return string The error string.
    */
   public function getError() {
      return $this->error;
   }

   /**
    * (non-PHPdoc)
    * @see ThumberTransaction::isValid()
    */
   public function isValid($secret = null) {
      $data = $this->getEncodedData();
      return parent::isValid($secret) &&
         is_bool($this->success) &&
         ($this->success ? (!empty($data)) : isset($this->error));
   }

   /**
    * Creates a ThumberResp instance from the given JSON.
    * @param string $json The JSON to create instance from.
    * @return ThumberResp|NULL The resultant object from parsing the JSON.
    */
   public static function parseJson($json) {
      try {
         return new ThumberResp($json);
      } catch (InvalidArgumentException $e) {
         return null;
      }
   }
}