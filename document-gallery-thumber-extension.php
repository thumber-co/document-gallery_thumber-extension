<?php
defined('WPINC') OR exit;

/*
 Plugin Name: Document Gallery - Thumber Extension
 Plugin URI: http://wordpress.org/extend/plugins/document-gallery/
 Description: An extension for the Document Gallery plugin to support Thumber.co API.
 Version: 1.0
 Author: Dan Rossiter
 Author URI: http://danrossiter.org/
*/

add_action('dg_thumbers', array('DocumentGalleryThumberExtension', 'thumbersFilter'));
add_filter('allowed_http_origin', array('DocumentGalleryThumberExtension', 'allowThumberWebhooks'), 10, 2);
add_action('admin_post_nopriv_' . DocumentGalleryThumberExtension::ThumberAction, array('ThumberClient', 'receiveResponse'), 5, 0);
add_filter('upload_mimes', array('DocumentGalleryThumberExtension', 'customUploadMimeTypes'));

DocumentGalleryThumberExtension::init();
class DocumentGalleryThumberExtension {

   const ThumberAction = 'dg_thumber_extension';

   /**
    * @var string Path to the base directory for this extension.
    */
   private static $path;
   
   private static $webhook;
   
   private static $nonce_separator = '_';
    
   /**
    * Initializes the static values for this class.
    */
   public static function init() {
      self::$path = plugin_dir_path(__FILE__);
      self::$webhook = admin_url('admin-post.php?action=' . self::ThumberAction);
      
      include_once self::$path . 'thumber-client/client.php';
      
      ThumberClient::setCallback(array(__CLASS__, 'webhookCallback'));
      ThumberClient::setUid('TODO');
      ThumberClient::setUserSecret('TODO');
   }

   /**
    * @param $mimes array
    * @return array
    */
   public static function customUploadMimeTypes($mimes) {
      $mimes['pub'] = 'application/x-mspublisher';
      return $mimes;
   }

   /**
    * @param $resp ThumberResp
    */
   public static function webhookCallback($resp) {
      include_once self::$path . 'thumber-client/response.php';
      
      $nonce = $resp->getNonce();
      $split = explode(self::$nonce_separator, $nonce);
      if ($resp->getSuccess() && count($split) == 2) {
         $ID = absint($split[0]);
         
         $tmpfile = get_temp_dir() . self::getTempFile(get_temp_dir());
         file_put_contents($tmpfile, $resp->getDecodedData());
         
         DG_Thumber::setThumbnail($ID, $tmpfile, array(__CLASS__, 'getThumberThumbnail'));

         DG_Logger::writeLog(DG_LogLevel::Detail, "Received thumbnail from Thumber for attachment #{$split[0]}.");
      } elseif (self::logEnabled()) {
         $ID = (count($split) > 0) ? $split[0] : $nonce;
         DG_Logger::writeLog(DG_LogLevel::Warning, "Thumber was unable to process attachment #$ID: " . $resp->getError());
      }
   }
   
   public static function allowThumberWebhooks($origin, $origin_arg) {
      return $origin || (isset($_REQUEST['action']) && $_REQUEST['action'] === self::ThumberAction);
   }
    
   /**
    *
    * @param multitype:callable $thumbers The thumbnail generators being used by Document Gallery.
    * @return multitype:callable The thumbnail generators being used by Document Gallery, with the
    * default Google Drive generaor replaced if present.
    */
   public static function thumbersFilter($thumbers) {
      if (self::logEnabled()) {
         DG_Logger::writeLog(DG_LogLevel::Detail, 'Adding ' . __CLASS__ . '::getThumberThumbnail()');
      }

      // avoid values being removed as a result of current user but also include any MIME types
      // that are added outside of the default WP values
      $wp_types = array_merge(wp_get_mime_types(), get_allowed_mime_types());

      // TODO: Needs to be cached
      $allowed = array_intersect($wp_types, ThumberClient::getMimeTypes());
      $allowed = array_keys($allowed);

      $thumbers[implode('|', $allowed)] = array(__CLASS__, 'getThumberThumbnail');
      return $thumbers;
   }
   
   public static function getThumberThumbnail($ID, $pg = 1) {
      if (self::logEnabled()) {
         DG_Logger::writeLog(DG_LogLevel::Detail, "Getting thumbnail for $ID.");
      }
      
      include_once self::$path . 'thumber-client/client.php';
      include_once self::$path . 'thumber-client/request.php';
       
      $url = wp_get_attachment_url($ID);
      $mime_type = get_post_mime_type($ID);
      
      if (!$url || !$mime_type) {
         return false;
      }
      
      $options = DocumentGallery::getOptions();
      $geometry = "{$options['thumber']['width']}x{$options['thumber']['height']}";
      
      $req = new ThumberReq();
      $req->setCallback(self::$webhook);
      $req->setMimeType($mime_type);
      $req->setNonce($ID. self::$nonce_separator . md5(microtime()));
      $req->setPg($pg);
      $req->setUrl($url);
      $req->setGeometry($geometry);
      
      $resp = ThumberClient::sendRequest($req);

      if (is_wp_error($resp)) {
         DG_Logger::writeLog(DG_LogLevel::Error, 'Failed to post: ' . $resp->get_error_message());
      } else {
         DG_Logger::writeLog(DG_LogLevel::Detail, 'Response: ' . $resp['body']);
      }
      
      return false;
   }
    
   /**
    * @param string $dir The directory that the generated filename is destined.
    * @param string $ext The file extension to generate the file with.
    * @return string The filename to be used relative to the directory provided.
    */
   private static function getTempFile($dir, $ext = 'png') {
      static $base;

      if (!isset($base)) {
         $base = md5(time());
      }

      return wp_unique_filename(untrailingslashit($dir), "$base.$ext");
   }

   /**
    * @return bool Whether debug logging is currently enabled.
    */
   public static function logEnabled() {
      return class_exists('DG_Logger') && DG_Logger::logEnabled();
   }

   /**
    * Blocks instantiation. All functions are static.
    */
   private function __construct() {

   }
}

?>