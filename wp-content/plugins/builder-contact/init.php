<?php
/*
  Plugin Name:  Builder Contact
  Plugin URI:   https://themify.me/addons/contact
  Version:      1.4.1 
  Author:       Themify
  Author URI:   https://themify.me
  Description:  Simple contact form. It requires to use with the latest version of any Themify theme or the Themify Builder plugin.
  Text Domain:  builder-contact
  Domain Path:  /languages
 */

defined('ABSPATH') or die('-1');

class Builder_Contact
{

    public $url;
    private $dir;
    public $version;
    private $from_name;

    /**
     * Creates or returns an instance of this class.
     *
     * @return    A single instance of this class.
     */
    public static function get_instance()
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new self;
        }
        return $instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', array($this, 'constants'), 1);
        add_action('plugins_loaded', array($this, 'i18n'), 5);
        add_action('themify_builder_setup_modules', array($this, 'register_module'));

        if (is_admin()) {
            add_action('plugins_loaded', array($this, 'admin'), 10);
            add_action('themify_builder_admin_enqueue', array($this, 'admin_enqueue'));
            add_filter('plugin_row_meta', array($this, 'themify_plugin_meta'), 10, 2);
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'action_links'));
            add_action('wp_ajax_builder_contact_send', array($this, 'contact_send'));
            add_action('wp_ajax_nopriv_builder_contact_send', array($this, 'contact_send'));
            add_filter('manage_contact_messages_posts_columns', array($this, 'set_custom_columns'));
            add_action('manage_contact_messages_posts_custom_column', array($this, 'custom_contact_messages_columns'), 10, 2);
        } else {
            add_action('themify_builder_frontend_enqueue', array($this, 'admin_enqueue'));
        }
        add_action('init', array($this, 'create_post_type'));
    }

    public function constants()
    {
        $data = get_file_data(__FILE__, array('Version'));
        $this->version = $data[0];
        $this->url = trailingslashit(plugin_dir_url(__FILE__));
        $this->dir = trailingslashit(plugin_dir_path(__FILE__));
    }

    public function themify_plugin_meta($links, $file)
    {
        if (plugin_basename(__FILE__) === $file) {
            $row_meta = array(
                'changelogs' => '<a href="' . esc_url('https://themify.me/changelogs/') . basename(dirname($file)) . '.txt" target="_blank" aria-label="' . esc_attr__('Plugin Changelogs', 'themify') . '">' . esc_html__('View Changelogs', 'themify') . '</a>'
            );

            return array_merge($links, $row_meta);
        }
        return (array)$links;
    }

    public function action_links($links)
    {
        if (is_plugin_active('themify-updater/themify-updater.php')) {
            $tlinks = array(
                '<a href="' . admin_url('index.php?page=themify-license') . '">' . __('Themify License', 'themify') . '</a>',
            );
        } else {
            $tlinks = array(
                '<a href="' . esc_url('https://themify.me/docs/themify-updater-documentation') . '">' . __('Themify Updater', 'themify') . '</a>',
            );
        }
        return array_merge($links, $tlinks);
    }

    public function i18n()
    {
        load_plugin_textdomain('builder-contact', false, '/languages');
    }

    public function admin_enqueue()
    {
        wp_enqueue_script('builder-contact-admin-scripts', themify_enque($this->url . 'assets/admin.js'), array('themify-builder-app-js'), $this->version, true);
        wp_localize_script('builder-contact-admin-scripts', 'tb_contact_l10n', array(
            'req' => __('Required', 'builder-contact'),
            'captcha' => Builder_Contact::get_instance()->get_option('recapthca_public_key')?'':sprintf(__('Requires Captcha keys entered at: <a target="_blank" href="%s">reCAPTCHA settings</a>.', 'builder-contact'), admin_url('admin.php?page=builder-contact')),
	    'admin_css' => themify_enque($this->url . 'assets/admin.css'),
            'v' => $this->version,
            'field_name' => __('Field Name', 'builder-contact'),
            'static_text' => __('Enter text or HTML here', 'builder-contact'),
            'add_option' => __('Add Option', 'builder-contact'),
            'types' => array(
                'text' => __('Text', 'builder-contact'),
		'tel'=> __('Telephone', 'builder-contact'),
                'textarea' => __('Textarea', 'builder-contact'),
                'upload' => __('Upload File', 'builder-contact'),
                'radio' => __('Radio', 'builder-contact'),
                'select' => __('Select', 'builder-contact'),
                'checkbox' => __('Checkbox', 'builder-contact'),
                'static' => __('Static Text', 'builder-contact')
            )
        ));
    }

    public function register_module()
    {
        Themify_Builder_Model::register_directory('templates', $this->dir . 'templates');
        Themify_Builder_Model::register_directory('modules', $this->dir . 'modules');

    }

    public function contact_send()
    {

        if (!empty($_POST)) {


            $result = array();
            /* reCAPTCHA validation */
            if (isset($_POST['contact-recaptcha'])) {
                $response = wp_remote_get("https://www.google.com/recaptcha/api/siteverify?secret=" . $this->get_option('recapthca_private_key') . "&response=" . $_POST['contact-recaptcha']);
                if (isset($response['body'])) {
                    $response = json_decode($response['body'], true);
                    if (!true == $response['success']) {
                        $result['themify_message'] = '<p class="ui red contact-error">' . __('Please verify captcha.', 'builder-contact') . '</p>';
                        $result['themify_error'] = 1;;
                    }
                } else {
                    $result['themify_message'] = '<p class="ui red contact-error">' . __('Trouble verifying captcha. Please try again.', 'builder-contact') . '</p>';
                    $result['themify_error'] = 1;
                }
            }
            if (empty($result)) {

                //end of save uploaded files variables
                $_POST['contact-settings'] = base64_decode($_POST['contact-settings']);
                $settings = unserialize(stripslashes($_POST['contact-settings']));
                if($settings['send_to_admins'] === 'true'){
                	if('author'===$settings['user_role']){
						$authors_email = get_the_author_meta('user_email');
						$recipients = ''!==$authors_email ? array($authors_email):array(get_option('admin_email'));
					}else{
						$recipients = array(get_option('admin_email'));
					}
				}else{
                	$recipients = array_map('trim', explode(',', $settings['sendto']));
				}
	            $active_bcc = $settings['active_bcc_email'];
	            $bcc_recipients = array_map('trim', explode(',', $settings['bcc']));

	            $active_specify_from_address =$settings['specify_from_address'];
	            $specify_email_address =  trim($settings['specify_email_address']);

                $name = isset($_POST['contact-name'])?trim(stripslashes($_POST['contact-name'])):'';
                $email = isset($_POST['contact-email'])?trim(stripslashes($_POST['contact-email'])):'';
                $subject = isset($_POST['contact-subject']) ? trim(stripslashes($_POST['contact-subject'])) : '';
                if (empty($subject)) {
                    $subject = $settings['default_subject'];
                }
                $message = trim(stripslashes(isset($_POST['contact-message']) ? $_POST['contact-message'] : ''));
                $successMessage = $settings['success_message_text'];

                $fliped_array = array_flip(preg_grep('/^(field_extra_name|contact-message)/', array_keys($_POST)));
                $extra_fields = array_intersect_key($_POST, $fliped_array);
                $field = '';
                $set_table = false;
				$uploaded_files_path = $uploaded_files_url = array();
                foreach ($extra_fields as $key => $field_name) {
                    if ('contact-message' === $key) {
                        $field .= "<br/><br/><tr><td colspan='2'>" . wpautop($message) . "</td><tr><br/><br/>" ;
                        continue;
                    }
                    if (!$set_table) {
                        $field .= '<table style="width: 100%;">';
                        $set_table = true;
                    }
                    $index = str_replace('_name', '', $key);
                    if (isset($_FILES[$index]) && 0 !== $_FILES[$index]['size']) { //+
                        $file_info = $_FILES[$index];
                        $upload_file = $this->upload_attachment($file_info);
                        if ($upload_file && !isset($upload_file['themify_error'])) {
                            $uploaded_files_url[$index] = $upload_file['url'];
                            $uploaded_files_path[$index] = $upload_file['file'];
                            continue;
                        } else {
                            $result = $upload_file;
                        }
                    }
                    if (!isset($_POST[$index])) {
                        continue;
                    }
                    $value = $_POST[$index];

                    if (is_array($value)) {
                        $final_value = '';
                        foreach ($value as $val) {
                            $final_value .= $val . ', ';
                        }
                        $value = trim(stripslashes(substr($final_value, 0, -2)));
                    } else {
                        $value = trim(stripslashes($value));
                    }
                    $field_name = trim(stripslashes($field_name));
                    $field .= '<tr><td style="width: 15%;min-width:120px;font-weight: bold;">' . $field_name . " :</td><td style='width: 85%;'>" . wpautop($value) . "</td></tr>";
                }


                if ($set_table) {
                    $field .= '</table>';
                }
                $subject = apply_filters('builder_contact_subject', $subject);
                if (('' == $name && $settings['contact_name_require']) || ('' == $email && $settings['contact_email_require'])) {
                    $result['themify_message'] = '<p class="ui red contact-error">' . __('Please fill in the required data.', 'builder-contact') . '</p>';
                    $result['themify_error'] = 1;
                } elseif (empty($result)) {

                    if ( $settings['contact_email_require'] && !is_email($email)) {
                        $result['themify_message'] = '<p class="ui red contact-error">' . __('Invalid Email address!', 'builder-contact') . '</p>';
                        $result['themify_error'] = 1;
                    } else {
                        $this->from_name = $name;
	                    if( 'enable' === $active_specify_from_address){
		                    $headers = array('from: ' . $specify_email_address, ' Reply-To: ' . $name . ' <' . $email . '>');
	                    }else if ('' !== $email){
		                    $headers = array('from: ' . $name . ' <' . $email . '>', ' Reply-To: ' . $name . ' <' . $email . '>');
	                    }
                        add_filter('wp_mail_from_name', array($this, 'set_from_name'));
                        // add the email address to message body
	                    $message = '';
	                    if('' !== $name && '' === $email){
		                    $message = __('From:', 'builder-contact') . ' ' . $name ;
	                    }elseif('' === $name && '' !== $email){
		                    $message .= __('From:', 'builder-contact') . ' '. ' &lt;' . $email . '&gt;' . "\n\n" ;
	                    }elseif('' !== $name && '' !== $email){
		                    $message .= __('From:', 'builder-contact') . ' ' . $name . ' &lt;' . $email . '&gt;' . "\n\n";
	                    }
	                    $message .= $field;
                        if ('enable' === $settings ['contact_sent_from']) {
                            if (isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] != '') {
                                $referer = $_SERVER['HTTP_REFERER'];
                            } else {
                                $referer = get_site_url();
                            }
                            $message .= "\n\n" . __('Sent from:', 'builder-contact') . ' ' . $referer;
                        }
                        add_filter('wp_mail_content_type', array($this, 'set_content_type'), 100, 1);

                        if (isset($_POST['contact-sendcopy']) && $_POST['contact-sendcopy'] == '1') {
                            wp_mail($email, $subject, $message, $headers, $uploaded_files_path);
                        }

						if ( isset( $_POST['contact-optin'] ) && $_POST['contact-optin'] == '1' ) {
							if ( ! class_exists( 'Builder_Optin_Services_Container' ) )
								include_once( THEMIFY_BUILDER_INCLUDES_DIR. '/optin-services/base.php' );
							$optin_instance = Builder_Optin_Services_Container::get_instance()->get_provider( $_POST['contact-optin-provider'] );
							if ( $optin_instance ) {
								// collect the data for optin service
								$data = array(
									'email' => $email,
									'fname' => $name,
									'lname' => '',
								);
								foreach ( $_POST as $key => $value ) {
									if ( preg_match( '/^contact-optin-/', $key ) ) {
										$key = preg_replace( '/^contact-optin-/', '', $key );
										$data[ $key ] = sanitize_text_field( trim( $value ) );
									}
								}
								$optin_instance->subscribe( $data );
							}
						}

                        if ($settings['post_type'] === 'enable') {
                            $files_links = '';// for add file link to the post
                            if ($uploaded_files_url && !empty($uploaded_files_url)) {
								$files_links .= '<br>Attachments : ';
                                foreach ($uploaded_files_url as $link) {
                                    $files_links .= "<br><a href='" . $link . "'>" . $link . "</a><br>";
                                }
                            }
                            if ($settings['post_author'] === 'add') {
                                $post_author_email = $recipients[0];
                                $post_author_id = $this->create_new_author($post_author_email);
                            }
                            $this->send_via_post_type($subject, $message . $files_links, $post_author_id);
                        }
                        $auto_respond_sent = false;

                        $headerStr = $headers;
                        $recipientsArr = $recipients;
                        unset($recipientsArr[0]);
                        $recipientsArr = implode(',', $recipientsArr);
                        if ($recipientsArr) {
	                        array_push($headerStr, 'Cc: ' . $recipientsArr . "\r\n");
                        }

	                    if('enable' === $active_bcc){
		                    array_push($headerStr, 'bcc: ' . implode(',', $bcc_recipients) . "\r\n");
	                    }

                        if (wp_mail($recipients[0], $subject, $message, $headerStr, $uploaded_files_path)) {
                            $sent = true;

                            if (!$auto_respond_sent && !empty($settings['auto_respond']) && !empty($settings['auto_respond_message'])) {
                                $auto_respond_sent = true;
                                $ar_subject = trim(stripslashes($settings['auto_respond_subject']));
                                $ar_message = wpautop(trim(stripslashes($settings['auto_respond_message'])));
                                $ar_headers = '';
                                wp_mail($email, $ar_subject, $ar_message, $ar_headers);
                            }
                        } else {
                            global $ts_mail_errors, $phpmailer;
                            if (!isset($ts_mail_errors))
                                $ts_mail_errors = array();
                            if (isset($phpmailer)) {
                                $ts_mail_errors[] = $phpmailer->ErrorInfo;
                            }
                            $sent = false;
                        }

                        if ($sent) {
                            $result['themify_message'] = '<p class="ui light-green contact-success">' . $successMessage . '</p>';
                            $result['themify_success'] = 1;
                            if (!empty($settings['success_url'])) {
                                $result['themify_message'] .= '<script>window.location = "' . esc_attr($settings['success_url']) . '"</script>';
                            }
                        } else {
                            ob_start();
                            print_r($ts_mail_errors);
                            $mail_error = ob_get_clean();
                            $result['themify_message'] = '<p class="ui red contact-error">' . __('There was an error. Please try again.', 'builder-contact') . '<!-- ' . $mail_error . ' -->' . '</p>';
                            $result['themify_error'] = 1;
                        }
                        remove_filter('wp_mail_content_type', array($this, 'set_content_type'), 100, 1);
                        do_action('builder_contact_mail_sent');

                        if ($uploaded_files_url) { // delete saved file , if no save in media library
                            if ($settings['post_type'] !== 'enable') {
                                foreach ($uploaded_files_url as $attachment) {
                                    unlink($attachment);
                                }
                            }
                        }
                    }
                }
            }
            echo wp_json_encode($result);
        }
        wp_die();

    }

    public function upload_attachment($file_info)
    {


        if (!empty($file_info)) {
            if (!$file_info['error']) {
                if ($file_info['size'] <= wp_max_upload_size()) {
                    $movefile = wp_handle_upload($file_info, array('test_form' => false));
                    if ($movefile && !isset($movefile['error'])) {
                        $result = $movefile;
                    } else {
                        $result['themify_message'] = '<p class="ui red contact-error">' . __('WordPress doesn\'t allow this type of uploads.', 'builder-contact') . '</p>';
                        $result['themify_error'] = 1;
                    }
                } else {
                    $result['themify_message'] = '<p class="ui red contact-error">' . __('The selected file size is larger than the limit.', 'builder-contact') . '</p>';
                    $result['themify_error'] = 1;
                }
            }
            return $result;
        }
        return false;
    }

    public function set_from_name($name)
    {
        return $this->from_name;
    }

    protected function create_new_author($email)
    {

        $exists = email_exists($email);
        if (false !== $exists) {
            return $exists;
        }

        $random_password = wp_generate_password($length = 12, $include_standard_special_chars = false);
        $user_id = wp_create_user($email, $random_password, $email);

        return $user_id;


    }

    public function send_via_post_type($title, $message, $author = false)
    {

        $post_info = array(
            'post_title' => $title,
            'post_type' => 'contact_messages',
            'post_content' => $message
        );

        if (false !== $author) {
            $post_info['post_author'] = $author;
        }
        remove_filter('content_save_pre', 'wp_filter_post_kses', 10);
        return wp_insert_post($post_info);

    }

    public function create_post_type()
    {

        return register_post_type('contact_messages',
            array(
                'labels' => array(
                    'name' => __('Builder Contact Submissions', 'builder-contact'),
                    'singular_name' => __('Builder Contact Submission', 'builder-contact'),
                    'all_items' => __('Contact Submissions', 'builder-contact'),
                    'menu_name' => __('Builder Contact', 'builder-contact'),
                ),
                'public' => false,
                'supports' => array('title', 'editor', 'author'),
                'show_ui' => true,
				'show_in_admin_bar' => false
            )
        );

    }

    public function set_custom_columns($columns)
    {

        unset($columns['date'], $columns['author']);
        $columns['sender'] = __('Sender', 'builder-contact');
        $columns['subject'] = __('Subject', 'builder-contact');
        $columns['date'] = __('Date', 'builder-contact');
        return $columns;

    }

    public function custom_contact_messages_columns($column, $post_id)
    {

        switch ($column) {

            case 'sender' :
                $content_post = get_post($post_id);
                $content = $content_post->post_content;
	            preg_match('/[a-z0-9_\-\+\.]+@[a-z0-9\-]+\.([a-z]{2,4})(?:\.[a-z]{2})?/i', $content, $result);
	            echo (isset($result[0])) ? $result[0] : '';
                break;

            case 'subject' :
                echo get_the_title($post_id);
                break;
        }

    }

    public function set_content_type($content_type)
    {
        return 'text/html';
    }

    public function admin()
    {
        require_once($this->dir . 'includes/admin.php');
        new Builder_Contact_Admin();
    }

    public function get_option($name, $default = null)
    {
        $options = get_option('builder_contact');
        return isset($options[$name]) ? $options[$name] : $default;
    }
}

Builder_Contact::get_instance();
