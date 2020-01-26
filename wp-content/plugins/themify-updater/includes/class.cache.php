<?php

/**
 * Cache class for caching functionality.
 *
 * @since      1.1.4
 * @package    Themify_Updater_Cache
 * @author     Themify
 */
if ( !class_exists('Themify_Updater_Cache') ) :

class Themify_Updater_Cache {

	private $cache_type = 'db';
	private static $cache = array();
	private $dbCacheKey = 'themify_updater_cache';

	function __construct () {

	    if ( defined('THEMIFY_UPDATER_DEBUG') && THEMIFY_UPDATER_DEBUG) {
	        $this->clear_all();
        }
		
		$this->load_db_cache();

		$this->clean();
	}

	private function clear_all() {
        delete_option($this->dbCacheKey);
    }

	private function load_db_cache() {
		$options = get_option( $this->dbCacheKey, array());
		self::$cache['db'] = isset($options['db']) ? $options['db'] : array();
	}

    private function update_db_cache() {

        $this->clean();

        $options = get_option( $this->dbCacheKey, array());

        $options['db'] = self::$cache[ $this->cache_type ];
		
		if ( isset( $options['h'] ) ) unset($options['h']);		// remove old mix cache. created in version 1.1.4 - 1.2.0

        delete_option( $this->dbCacheKey );
        add_option( $this->dbCacheKey, $options);
    }

	public function get($key) {
        if ( isset( self::$cache[ $this->cache_type ][$key] ) )
            return self::$cache[ $this->cache_type ][$key]['value'];
        else return false;
	}

	private function _set($key , $value, $time) {
	    self::$cache[ $this->cache_type ][ $key ] = array( 'value' => $value, 'expire' => $time);
	}

	public function set($key = false, $value, $time = 3600) {
		if(!$key) {
			$key = Themify_Updater_utils::get_hash( $value );
		}
		$time = time() + $time;

		$this->_set($key , $value, $time);

		$this->update_db_cache();

		return $key;
	}

	function remove( $key ) {
	    if ( isset( self::$cache[ $this->cache_type ][$key] ) )
	        unset( self::$cache[ $this->cache_type ][$key] );

	    $this->update_db_cache();
	}
	
	private function clean( $cache_type = '') {
		if ( empty(self::$cache) ) return;

		if ( !empty($cache_type) ) {
            $this->_clean($cache_type);
        } else {
            foreach ( self::$cache as $type => $cache) {
                $this->_clean($type);
            }
        }
	}

    private function _clean( $type ) {
        foreach ( self::$cache[ $type ] as $key => $value ) {
            if ( time() > (int)$value['expire'] ) {
                unset(self::$cache[$type][$key]);
            }
        }
    }
}
endif;
