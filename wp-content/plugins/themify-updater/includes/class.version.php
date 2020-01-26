<?php

/**
 * Utility class of various static functions
 *
 * @since      1.1.4
 * @package    Themify_Updater_Version
 * @author     Themify
 */

if ( !class_exists('Themify_Updater_Version') ) :
	
class Themify_Updater_Version {
	
	private $versions_url;
	private $versions_xml;
	private $cache;
	private $filename = 'versions';
	private $upload_dir;
	private $file;

	function __construct () {
		$this->versions_url = Themify_Updater_utils::$uri . '/'. $this->filename .'/'. $this->filename .'.xml';
		$this->cache = new Themify_Updater_Cache();
		$this->upload_dir = wp_upload_dir();
        $this->upload_dir = rtrim($this->upload_dir['basedir'], '/') . '/themify-updater';
        $this->file = $this->upload_dir . '/'. $this->filename .'.xml';
		$this->get_xml();
	}

    /**
     * @param string $name
     * @return string
     */
    public function remote_version($name = '') {
        $version = '';

        if (is_object($this->versions_xml)) {
            $query = "//version[@name='" . $name . "']";
            $elements = $this->versions_xml->query($query);
            if ($elements->length) {
                foreach ($elements as $field) {
                    $version = $field->nodeValue;
                }
            }
        }
        return $version;
    }

    private function get_xml() {

        if ( ! $this->check_file() ) {
            $this->fetch_file();
        }

        $wp_filesystem = Themify_Filesystem::get_instance();
        $content = $wp_filesystem->execute->get_contents( $this->file );

        $this->versions_xml = !empty($content) ? $content : null;

        unset($content);

		if ( trim($this->versions_xml) && class_exists('DOMDocument') ) {
            $xml = new DOMDocument;
            @$xml->loadXML(trim($this->versions_xml));
            $xml->preserveWhiteSpace = false;
            $xml->formatOutput = true;
            $this->versions_xml = new DOMXPath($xml);
        } elseif ( !class_exists('DOMDocument') ) {
			$notification = Themify_Updater_Notifications::get_instance();
			$notification -> add_notice( __('<b>Themify Updater</b> required PHP DOM library. please install it for plugin\'s complete functionality.', 'themify-updater'), 'error');
		}
    }

    /**
     * @return bool
     */
    private function check_file() {
        $wp_filesystem = Themify_Filesystem::get_instance();

        if ( ! $wp_filesystem->execute->is_file( $this->file ) ) {
            return false;
        }

        $cTime = $this->cache->get($this->filename . '_fetchTime');

        if ( !$cTime ) return false;

        $mTime = filemtime($this->file);

        if ( (int)$cTime >= (int)$mTime ) return false;

        return true;
    }

    private function fetch_file() {
        $wp_filesystem = Themify_Filesystem::get_instance();
        if ( ! $wp_filesystem->execute->is_dir( $this->upload_dir ) && !wp_mkdir_p( $this->upload_dir ) && Themify_Updater_utils::is_admin_penal() ) {
            $notification = Themify_Updater_Notifications::get_instance();
            $dir = dirname($this->upload_dir);
            $notification -> add_notice( sprintf(
                __('<b>Themify Updater</b>: %s is not writable. failed to create subdirectory.', 'themify-updater'),
                $dir
            ), 'error');
            return;
        }

        $key = $this->filename . '_fetchTime';
        $cTime = $this->cache->get($key);
        $request = new Themify_Updater_Requests();

        if ( $wp_filesystem->execute->is_file($this->file) ) {
            $lmTime = $request->head( $this->versions_url, 'Last-Modified');
            $lmTime = !empty($lmTime) ? strtotime($lmTime) : '';

            if ( (int)$cTime >= $lmTime ) {
                $this->cache->set($key, $lmTime, 6 * HOUR_IN_SECONDS);
                return;
            }
        }

        $xml = $request->get($this->versions_url);
        $tmp = $wp_filesystem->execute->put_contents( $this->file, $xml, FS_CHMOD_FILE);

        if ($tmp) {
            $this->cache->set($key, time(), 6 * HOUR_IN_SECONDS);
        }

        if ( !$tmp && Themify_Updater_utils::is_admin_penal() ) {
            $notification = Themify_Updater_Notifications::get_instance();
            $dir = dirname($this->upload_dir);
            $notification -> add_notice( sprintf(
                    __('Themify Updater: %s is not writable.', 'themify-updater'),
                    $dir
                ), 'error', false);
        }
    }

    /**
     * @param $name
     * @param $attr
     * @param bool $return_value
     * @return bool|string
     */
    public function has_attribute($name, $attr, $return_value = false) {

        $ret = false;
        $value = '';

        if (is_object($this->versions_xml)) {
            $query = "//version[@name='" . $name . "']";
            $elements = $this->versions_xml->query($query);
            if ($elements->length) {
                foreach ($elements as $field) {
                    $value = $field->getAttribute($attr);
                    $ret = empty($value) ? false : true;
                }
            }
        }

        return $return_value ? $value : $ret;
    }

    /**
     * @param string $query
     * @return array
     */
    public function run_query ($query ) {
        if (is_object($this->versions_xml)) {
            $elements = $this->versions_xml->query($query);
            return $elements;
        }
        return array();
    }

	/**
	 * this function is used to check for update. it can also be used to check if product is themify's
	 *
	 * @param string $name
	 * @param string $version
	 *
	 * @return mixed
	 */
    public function is_update_available($name = '', $version = '1.0') {

        $new_version = $this->remote_version($name);

        if ( version_compare( $new_version, $version, '<') ) $version = '0.0.1';

        return version_compare($version, $new_version, '<');
    }
}
endif;
