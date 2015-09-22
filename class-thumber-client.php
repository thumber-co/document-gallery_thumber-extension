<?php
defined('WPINC') OR exit;

DG_ThumberClient::init();

class DG_ThumberClient extends ThumberClient {

	/**
	 * @var DG_ThumberClient Backs the getter.
	 */
	private static $instance;

	/**
	 * @return DG_ThumberClient The singleton instance.
	 */
	public static function getInstance() {
		return isset(self::$instance) ? self::$instance : (self::$instance = new DG_ThumberClient());
	}

	/**
	 * Enforce singleton.
	 */
	private function __construct() {
	}

	/**
	 * Initialized class members.
	 */
	public static function init() {
		self::$uid = 'TODO';
		self::$userSecret = 'TODO';
		self::$thumberUserAgent = 'Document Gallery Thumber Client 1.0 (PHP ' . phpversion() . '; ' . php_uname() . ')';
	}

	/**
	 * Sends HTTP request to Thumber server.
	 * @param $type string GET or POST
	 * @param $url string The URL endpoint being targeted.
	 * @param $httpHeaders array The headers to be sent.
	 * @param $body string The POST body. Ignored if type is GET.
	 * @return array The result of the request.
	 */
	protected function sendToThumber($type, $url, $httpHeaders, $body = '') {
		$headers = array();
		foreach ($httpHeaders as $v) {
			$kvp = explode(':', $v);
			$headers[trim($kvp[0])] = trim($kvp[1]);
		}

		// NOTE: Failure was local so not actual HTTP error, but makes error checking much
		// simpler if we set the value to something above the success range
		$result = array (
			'http_code'       => 600,
			'header'          => '',
			'body'            => '',
			'last_url'        => ''
		);
		$args = array(
			'headers'      => $headers,
			'user-agent'   => self::$thumberUserAgent
		);

		switch ($type) {
			case 'GET':
				if (!empty($body)) {
					$args['body'] = $body;
				}

				$resp = wp_remote_get($url, $args);
				break;

			case 'POST':
				if (!empty($body)) {
					$args['body'] = $body;
				}

				$resp = wp_remote_post($url, $args);
				break;

			default:
				$err = 'Invalid HTTP type given: ' . $type;
				self::handleError($err);
				$result['error'] = 'Invalid HTTP type given: ' . $type;
		}

		if (isset($resp)) {
			if (!is_wp_error($resp)) {
				$result['http_code'] = $resp['response']['code'];
				$result['body'] = $resp['body'];
			} else {
				$result['body'] = $resp->get_error_message();
			}
		}

		return $result;
	}

	/**
	 * Processes the POST request, generating a ThumberResponse, validating, and passing the result to $callback.
	 * If not using client.php as the webhook, whoever receives webhook response should first invoke this method to
	 * validate response.
	 */
	public function receiveThumbResponse() {
		$resp = parent::receiveThumbResponse();
		if (is_null($resp)) {
			return;
		}

		$nonce = $resp->getNonce();
		$split = explode(DocumentGalleryThumberExtension::NonceSeparator, $nonce);
		if ($resp->getSuccess() && count($split) == 2) {
			$ID = absint($split[0]);
			$tmpfile = get_temp_dir() . self::getTempFile(get_temp_dir());

			file_put_contents($tmpfile, $resp->getDecodedData());

			DG_Thumber::setThumbnail($ID, $tmpfile, array(__CLASS__, 'getThumberThumbnail'));
			if (DocumentGalleryThumberExtension::logEnabled()) {
				DG_Logger::writeLog( DG_LogLevel::Detail, "Received thumbnail from Thumber for attachment #{$split[0]}." );
			}
		} elseif (DocumentGalleryThumberExtension::logEnabled()) {
			$ID = (count($split) > 0) ? $split[0] : $nonce;
			DG_Logger::writeLog(DG_LogLevel::Warning, "Thumber was unable to process attachment #$ID: " . $resp->getError());
		}
	}

	/**
	 * @param $err string Fires on fatal error.
	 */
	protected function handleError($err) {
		if (DocumentGalleryThumberExtension::logEnabled()) {
			DG_Logger::writeLog(DG_LogLevel::Error, $err);
		}
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
}