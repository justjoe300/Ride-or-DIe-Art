<?php
/**
 * The file that defines File System class
 *
 * Themify_Filesystem class provide instance of Filesystem Api to do some file operation.
 * Based on WP_Filesystem the class method will remain same.
 *
 *
 * @package    Themify
 * @subpackage Filesystem
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Themify_Filesystem' ) ) {

	/**
	 * The Themify_Filesystem class.
	 *
	 * This is used to initialize WP_Filesytem Api instance
	 * check for filesytem method and return correct filesystem method
	 *
	 * @package    Themify
	 * @subpackage Filesystem
	 * @author     Themify
	 */
	class Themify_Filesystem {

		/**
		 * Instance of WP_Filesytem api class.
		 *
		 * @access public
		 * @var $execute Store the instance of WP_Filesystem class being used.
		 */
		public $execute = null;

		/**
		 * Class constructor.
		 *
		 * @access public
		 */
		private function __construct() {
			$this->initialize();
		}

		/**
		 * Return an instance of this class.
		 *
		 * @return    object    A single instance of this class.
		 */
		public static function get_instance() {
			static $instance=null;
			if($instance===null){
				$instance=new self;
			}
			return $instance;
		}

		/**
		 * Initialize filesystem method.
		 */
		private function initialize() {
			// Load WP Filesystem
			if ( ! function_exists('WP_Filesystem') ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();
			global $wp_filesystem;
			$access_type = get_filesystem_method();

			if ( 'direct' === $access_type ) {
				$this->execute = $wp_filesystem;
			} else {
				$this->execute = self::load_direct();
			}
		}

		/**
		 * Initialize Filesystem direct method.
		 */
		private static function load_direct() {
			require_once ABSPATH . '/wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . '/wp-admin/includes/class-wp-filesystem-direct.php';
			return new WP_Filesystem_Direct( array() );
		}

		public static function get_file_content($file){
			$instance=self::get_instance();

			if(strpos($file,'fonts.googleapis')===false && strpos($file,WP_CONTENT_URL)!==false){
				$f = pathinfo($file);
				$absolute = str_replace(WP_CONTENT_URL, '', $f['dirname']);
				$name = explode('?', $f['basename']);
				$dir = trailingslashit(WP_CONTENT_DIR) . trailingslashit(trim($absolute,'/')) . $name[0];
			}
			else{
				$dir=$file;
			}
			$data=$instance->execute->get_contents($dir);
			return !empty($data) && !is_wp_error($data)?$data:null;
		}

		public static function is_dir($dir){
			$instance=self::get_instance();
			return $instance->execute->is_dir($dir);
		}

		public static function is_file($file){
			$instance=self::get_instance();
			return $instance->execute->is_file($file);
		}

		public static function is_readable($dir){
			$instance=self::get_instance();
			return $instance->execute->is_readable($dir);
		}

		public static function is_writable($dir){
			$instance=self::get_instance();
			return $instance->execute->is_writable($dir);
		}

		public static function exists($file){
			$instance=self::get_instance();
			return $instance->execute->exists($file);
		}

		public static function size($file){
			$instance=self::get_instance();
			return $instance->execute->size($file);
		}

		public static function delete($file,$recursive=false,$type=false){
			$instance=self::get_instance();
			return $instance->execute->delete($file,$recursive,$type);
		}

		public static function put_contents($file,$contents, $mode = FS_CHMOD_FILE){
			$instance=self::get_instance();
			return $instance->execute->put_contents($file,$contents, $mode);
		}

		public static function get_contents($file,$contents, $mode = FS_CHMOD_FILE){
			$instance=self::get_instance();
			return $instance->execute->get_contents($file,$contents, $mode);
		}

	}

}
