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

include_once plugin_dir_path(__FILE__) . 'thumber-client/client.php';
include_once plugin_dir_path(__FILE__) . 'class-thumber-client.php';

add_action('dg_thumbers', array('DocumentGalleryThumberExtension', 'thumbersFilter'));
add_filter('allowed_http_origin', array('DocumentGalleryThumberExtension', 'allowThumberWebhooks'), 10, 2);
add_filter('upload_mimes', array('DocumentGalleryThumberExtension', 'customMimeTypes'));
add_action('admin_post_nopriv_' . DocumentGalleryThumberExtension::ThumberAction, array(DG_ThumberClient::getInstance(), 'receiveThumbResponse'), 5, 0);

DocumentGalleryThumberExtension::init();
class DocumentGalleryThumberExtension {

   /**
    * @const string Name of the action performed in the webhook.
    */
   const ThumberAction = 'dg_thumber_extension';

   /**
    * @const string Used in nonce to separate the attachment ID from the "random" segment.
    */
   const NonceSeparator = '_';

   /**
    * @var string Path to the base directory for this extension.
    */
   private static $path;

   /**
    * @var string URL to webhook.
    */
   private static $webhook;

   /**
    * @var DG_ThumberClient The thumber client isntance.
    */
   private static $client;

   /**
    * Initializes the static values for this class.
    */
   public static function init() {
      if (!isset(self::$path)) {
         self::$path = plugin_dir_path(__FILE__);
         self::$webhook = admin_url('admin-post.php?action=' . self::ThumberAction);
         self::$client = DG_ThumberClient::getInstance();
      }
   }

   /**
    * TODO: This should be a configurable option and should include all Thumber types not default WP-supported.
    * @param $mimes array The MIME types WP knows about.
    * @return array Modified MIME types -- adding additional supported types.
    */
   public static function customMimeTypes($mimes) {
      $mimes['pub'] = 'application/mspublisher';
      return $mimes;
   }

   /**
    * WP by default will not handle POSTs from Thumber so add a special case for the action we want to handle.
    * @param $origin
    * @param $origin_arg
    *
    * @return bool Whether WP will handle the action.
    */
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
      $allowed = array_intersect($wp_types, self::$client->getMimeTypes());
      $allowed = array_keys($allowed);

      $thumbers[implode('|', $allowed)] = array(__CLASS__, 'getThumberThumbnail');
      return $thumbers;
   }

   /**
    * @param int $ID The attachment ID.
    * @param int $pg The page to thumbnail.
    *
    * @return bool Always false. Asynchronously set the thumbnail in webhook later.
    */
   public static function getThumberThumbnail($ID, $pg = 1) {
      if (self::logEnabled()) {
         DG_Logger::writeLog(DG_LogLevel::Detail, "Getting thumbnail for attachment #$ID.");
      }

      include_once self::$path . 'thumber-client/client.php';
      include_once self::$path . 'thumber-client/thumb-request.php';

      if (!self::checkFilesize(get_attached_file($ID))) {
         DG_Logger::writeLog(DG_LogLevel::Detail, "Skipping attachment #$ID as it exceeds Thumber.co subscription limits.");
         return false;
      }

      $url = wp_get_attachment_url($ID);
      $mime_type = get_post_mime_type($ID);

      if (!$url || !$mime_type) {
         return false;
      }

      $options = DocumentGallery::getOptions();
      $geometry = "{$options['thumber']['width']}x{$options['thumber']['height']}";

      $req = new ThumberThumbReq();
      $req->setCallback(self::$webhook);
      $req->setMimeType($mime_type);
      $req->setNonce($ID. self::NonceSeparator . md5(microtime()));
      $req->setPg($pg);
      $req->setUrl($url);
      $req->setGeometry($geometry);

      $resp = self::$client->sendThumbRequest($req);

      if (self::logEnabled())
      {
         if (is_wp_error($resp)) {
            DG_Logger::writeLog(DG_LogLevel::Error, 'Failed to post: ' . $resp->get_error_message());
         }
      }

      return false;
   }

   /**
    * @return bool Whether debug logging is currently enabled.
    */
   public static function logEnabled() {
      return class_exists('DG_Logger') && DG_Logger::logEnabled();
   }

   /**
    * @param string $filename File to be tested.
    * @return bool Whether file is acceptable to be sent to Thumber.
    */
   private static function checkFilesize($filename) {
      $sub = self::$client->getSubscription();
      $size = filesize($filename);
      return empty($sub) || ($size > 0 && $size <= $sub->file_size_limit);
   }

   /**
    * Blocks instantiation. All functions are static.
    */
   private function __construct() {

   }
}