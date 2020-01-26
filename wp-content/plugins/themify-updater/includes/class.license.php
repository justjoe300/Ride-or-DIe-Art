<?php

/**
 * License class for various license functionality.
 *
 * @since      1.1.4
 * @package    Themify_Updater_License
 * @author     Themify
 */
if ( !class_exists('Themify_Updater_License') ) :

class Themify_Updater_License {

    private $cache;
    private $error = array( 'code' => 'ok', 'message' => '', 'notice' => '');
    private $credentials = array();
    private $reNotice = false;
    private $reCheck = false;
    private $products = array();
    private $notice = false;

    const OK = 'ok';
    const LICENSE_EMPTY = 'license_empty';
    const LICENSE_NOT_FOUND = 'license_not_found';
    const LICENSE_DISABLED = 'license_disabled';
    const LICENSE_EXPIRED = 'license_expired';

    function __construct($username, $license)
    {
        $this->cache = new Themify_Updater_Cache();
        $this->credentials['username'] = Themify_Updater_utils::preg_replace($username, 'username');
        $this->credentials['license'] = Themify_Updater_utils::preg_replace($license, 'key');
        $this->hooks();
        $this->load_products();
        $this->load_messages();
    }

    private function hooks() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_script'), 15); // priority is low to ensure that enqueue_script of Themify_Updater class run's first.
        add_action('themify_verify_license', array($this, 'license_check_action'));
    }

    /**
     * @return bool
     */
    public function license_check_action(){
        $this->reCheck = true;
        $temp = $this->license_check();
        $this->load_messages();
        return $temp;
    }

    /**
     * @param string $username
     * @param string $license
     */
    public function update_credentials($username, $license){
        $this->credentials['username'] = Themify_Updater_utils::preg_replace($username, 'username');
        $this->credentials['license'] = Themify_Updater_utils::preg_replace($license, 'key');
    }

    /**
     * @return bool
     */
    private function license_check() {

        $cache = $this->cache->get('tu_license_expires');
        if ( $this->reCheck || $cache === false || ( (int)  $cache ) - time() <= 0 ){
            $err = true;
        }

        if ( isset($err) ) {
            $time = 2*HOUR_IN_SECONDS;
            if ( empty($this->credentials['username']) || empty($this->credentials['license']) ) {
                $this->error['message'] = __('License key or username is missing.','themify-updater');
                $this->error['notice'] = sprintf('%s <a href="%s" class="">%s</a>%s', __("Themify Updater: license key or username is missing. Please enter ", 'themify-updater'), esc_attr( admin_url( 'index.php?page=themify-license' ) ), __('Themify License', 'themify-updater'), __(' credentials.', 'themify-updater'));
                $this->error['code'] = self::LICENSE_EMPTY;
                $this->products = array();
            } else {
                $this->reNotice = true;
                $request = new Themify_Updater_Requests();
                $content = $request->get( $this->apiRequestPath() );

                if ( !empty($content) ) $content = @json_decode($content, true); // suppress php warning for unknown json parser error

                if ( !is_array($content) ) {
                    $content = array();
                    $content['message'] = __('Themify Updater: Failed to check license key.', 'themify-updater');
                    $content['code'] = 'failed_to_check';
                }

                $cache = isset($content['license_expires']) ? strtotime($content['license_expires']) : time();
                if ( $content['code'] !== self::OK) {
                    $this->products = array();
                    switch ($content['code']) {
                        case 'usernameMismatch':
                            $this->error['message'] = __('Username and license key doesn\'t match.','themify-updater');
                            $this->error['notice'] = sprintf('%s <a href="%s" class="">%s</a>.', __("Themify Updater: username and license key doesn't match. Please ", 'themify-updater'), esc_attr( admin_url( 'index.php?page=themify-license' ) ), __('correct it', 'themify-updater'));
                            $time *= 720;
                            break;
                        case self::LICENSE_EMPTY:
                            $this->error['message'] = __('License key is missing.','themify-updater');
                            $this->error['notice'] = sprintf('%s <a href="%s" class="">%s</a>%s', __("Themify Updater: license key is missing. Please enter ", 'themify-updater'), esc_attr( admin_url( 'index.php?page=themify-license' ) ), __('Themify License', 'themify-updater'), __(' key.', 'themify-updater'));
                            break;
                        case self::LICENSE_NOT_FOUND:
                            $this->error['message'] = __('License key is invalid','themify-updater');
                            $this->error['notice'] = sprintf('%s <a href="%s" class="">%s</a>%s', __("Themify Updater: ", 'themify-updater'), esc_attr( admin_url( 'index.php?page=themify-license' ) ), __('license key', 'themify-updater'), __(' is invalid. Please enter a valid license key.', 'themify-updater'));
                            $time *= 720;
                            break;
                        case self::LICENSE_EXPIRED:
                            $this->error['message'] = __('Your license key is expired.','themify-updater');
                            $this->error['notice'] = sprintf('%s <a href="%s" class="">%s</a>%s', __("Themify Updater: your license key is expired. Please renew your membership or ", 'themify-updater'), esc_attr('https://themify.me/contact'), __('contact Themify', 'themify-updater'), __(' for more details.', 'themify-updater'));
                            $time *= 720;
                            break;
                        case self::LICENSE_DISABLED:
                            $this->error['message'] = __('Your license key is disabled.','themify-updater');
                            $this->error['notice'] = sprintf('%s <a href="%s" class="">%s</a>%s', __("Themify Updater: your license key is disabled. Please ", 'themify-updater'), esc_attr('https://themify.me/contact'), __('contact Themify', 'themify-updater'), __(' for more details.', 'themify-updater'));
                            $time *= 12;
                            break;
                        default:
                            $this->error['notice'] = $content['message'];
                    }
                } else {
                    $this->error['message'] = $this->error['notice'] = '';
                    $this->products = isset($content['products']) && is_array($content['products']) ? $content['products'] : array();
                    $time *= 6;
                    if ( time() > $cache ) $time = (6 * HOUR_IN_SECONDS); // This is due to some unknown error. re-check in 6hr.
                    elseif ( $time + time() > $cache) $time = (($time + time()) - $cache) + (2 * HOUR_IN_SECONDS); // 2hour is grace period.
                }
                $this->error['code'] = $content['code'];
                $this->cache->set('tu_license_expires', $cache, $cache - time() );
            }
            $this->cache->set('tu_license_error', $this->error, $time );
            $this->cache->set('tu_license_products', $this->products, $time );
        }
        if ( $this->error['code'] === self::OK ) return true;
        return false;
    }

    private function load_products(){
        $cache = $this->cache->get('tu_license_products');
        if ( $cache !== false ){
            $this->products = $cache;
        } else {
            $this->reCheck = true;
            $this->license_check();
        }
    }

    private function load_messages() {
        $cache = $this->cache->get('tu_license_error');
        if ( $cache !== false ) {
            $this->error = $cache;
        } else {
            $this->reCheck = true;
            $this->license_check();
        }

        $notification = Themify_Updater_Notifications::get_instance();
        if ( $this->notice !== false ) $notification->remove_notice( $this->notice );

        if ($this->error['code'] !== self::OK) {

            if ($this->reNotice) $notification->reAdd_notice('tu_license_err');

            $this->notice = $notification->add_notice($this->error['notice'], 'error', array(), true, 'tu_license_err');
        }
    }

    /**
     * @return array
     */
    public function get_products() {
        return $this->products;
    }

    /**
     * @param string $product
     * @return bool
     */
    public function has_product_access($product ) {
        if ( in_array($product, $this->products) )
            return true;
        else
            return false;
    }

    /**
     * @return bool
     */
    public function has_error(){
        if ( empty( $this->error['notice'] ) ) return false;
        return true;
    }

    /**
     * @return mixed
     */
    public function get_error_message() {
        return $this->error['message'];
    }

    public function enqueue_script() {
        wp_localize_script('themify-upgrader', 'themify_upgrader_license', array(
            'error_message' => $this->error['notice']
        ) );
   }

    /**
     * @param string $request
     * @param string $product
     * @param string $version
     * @return string
     */
    private function apiRequestPath($request = 'check', $product = '', $version = '' ) {
       $domain = Themify_Updater_utils::$uri;
       $path = '/member/softsale/api/';
       $key = array();
       $key['key'] = $this->credentials['license'];
       $key['product'] = !empty($product) ? $product : '';
       $key['version'] = !empty($version) ? $version : '';
       $key['u'] = $this->credentials['username']; $key['n'] = 1;
       $key = gzcompress(json_encode($key));
       $key = str_replace(array('+', '/'), array('-', '_'), base64_encode($key));
       $key = '?s=' . urlencode($key);
       switch ($request) {
           case 'get':
               $action = 'get-themify';
               break;
           default :
               $action = 'check-license';
       }

       return $domain . $path . $action . $key;
   }

    /**
     * @param string $product
     * @param string $version
     * @return string
     */
    public function get_product_link ($product, $version = '' ){
        return $this->apiRequestPath('get', $product, $version);
   }
}
endif;