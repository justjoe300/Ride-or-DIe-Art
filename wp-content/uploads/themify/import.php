<?php

defined( 'ABSPATH' ) or die;

$GLOBALS['processed_terms'] = array();
$GLOBALS['processed_posts'] = array();

require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

/**
 * Add an Import Action, this data is stored for processing after import is done.
 *
 * Each action is sent as an Ajax request and is handled by themify-ajax.php file
 */ 
function themify_add_import_action( $action = '', $data = array() ) {
	global $import_actions;

	if ( ! isset( $import_actions[ $action ] ) ) {
		$import_actions[ $action ] = array();
	}

	$import_actions[ $action ][] = $data;
}

function themify_import_post( $post ) {
	global $processed_posts, $processed_terms;

	if ( ! post_type_exists( $post['post_type'] ) ) {
		return;
	}

	/* Menu items don't have reliable post_title, skip the post_exists check */
	if( $post['post_type'] !== 'nav_menu_item' ) {
		$post_exists = post_exists( $post['post_title'], '', $post['post_date'] );
		if ( $post_exists && get_post_type( $post_exists ) == $post['post_type'] ) {
			$processed_posts[ intval( $post['ID'] ) ] = intval( $post_exists );
			return;
		}
	}

	if( $post['post_type'] == 'nav_menu_item' ) {
		if( ! isset( $post['tax_input']['nav_menu'] ) || ! term_exists( $post['tax_input']['nav_menu'], 'nav_menu' ) ) {
			return;
		}
		$_menu_item_type = $post['meta_input']['_menu_item_type'];
		$_menu_item_object_id = $post['meta_input']['_menu_item_object_id'];

		if ( 'taxonomy' == $_menu_item_type && isset( $processed_terms[ intval( $_menu_item_object_id ) ] ) ) {
			$post['meta_input']['_menu_item_object_id'] = $processed_terms[ intval( $_menu_item_object_id ) ];
		} else if ( 'post_type' == $_menu_item_type && isset( $processed_posts[ intval( $_menu_item_object_id ) ] ) ) {
			$post['meta_input']['_menu_item_object_id'] = $processed_posts[ intval( $_menu_item_object_id ) ];
		} else if ( 'custom' != $_menu_item_type ) {
			// associated object is missing or not imported yet, we'll retry later
			// $missing_menu_items[] = $item;
			return;
		}
	}

	$post_parent = ( $post['post_type'] == 'nav_menu_item' ) ? $post['meta_input']['_menu_item_menu_item_parent'] : (int) $post['post_parent'];
	$post['post_parent'] = 0;
	if ( $post_parent ) {
		// if we already know the parent, map it to the new local ID
		if ( isset( $processed_posts[ $post_parent ] ) ) {
			if( $post['post_type'] == 'nav_menu_item' ) {
				$post['meta_input']['_menu_item_menu_item_parent'] = $processed_posts[ $post_parent ];
			} else {
				$post['post_parent'] = $processed_posts[ $post_parent ];
			}
		}
	}

	/**
	 * for hierarchical taxonomies, IDs must be used so wp_set_post_terms can function properly
	 * convert term slugs to IDs for hierarchical taxonomies
	 */
	if( ! empty( $post['tax_input'] ) ) {
		foreach( $post['tax_input'] as $tax => $terms ) {
			if( is_taxonomy_hierarchical( $tax ) ) {
				$terms = explode( ', ', $terms );
				$post['tax_input'][ $tax ] = array_map( 'themify_get_term_id_by_slug', $terms, array_fill( 0, count( $terms ), $tax ) );
			}
		}
	}

	$post['post_author'] = (int) get_current_user_id();
	$post['post_status'] = 'publish';

	$old_id = $post['ID'];

	unset( $post['ID'] );
	$post_id = wp_insert_post( $post, true );
	if( is_wp_error( $post_id ) ) {
		return false;
	} else {
		$processed_posts[ $old_id ] = $post_id;

		return $post_id;
	}
}

function themify_get_placeholder_image() {
	static $placeholder_image = null;

	if( $placeholder_image == null ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		$upload = wp_upload_bits( $post['post_name'] . '.jpg', null, $wp_filesystem->get_contents( THEMIFY_DIR . '/img/image-placeholder.jpg' ) );

		if ( $info = wp_check_filetype( $upload['file'] ) )
			$post['post_mime_type'] = $info['type'];
		else
			return new WP_Error( 'attachment_processing_error', __( 'Invalid file type', 'themify' ) );

		$post['guid'] = $upload['url'];
		$post_id = wp_insert_attachment( $post, $upload['file'] );
		wp_update_attachment_metadata( $post_id, wp_generate_attachment_metadata( $post_id, $upload['file'] ) );

		$placeholder_image = $post_id;
	}

	return $placeholder_image;
}

function themify_import_term( $term ) {
	global $processed_terms;

	if( $term_id = term_exists( $term['slug'], $term['taxonomy'] ) ) {
		if ( is_array( $term_id ) ) $term_id = $term_id['term_id'];
		if ( isset( $term['term_id'] ) )
			$processed_terms[ intval( $term['term_id'] ) ] = (int) $term_id;
		return (int) $term_id;
	}

	if ( empty( $term['parent'] ) ) {
		$parent = 0;
	} else {
		$parent = term_exists( $processed_terms[ intval( $term['parent'] ) ], $term['taxonomy'] );
		if ( is_array( $parent ) ) $parent = $parent['term_id'];
	}

	$id = wp_insert_term( $term['name'], $term['taxonomy'], array(
		'parent' => $parent,
		'slug' => $term['slug'],
		'description' => $term['description'],
	) );
	if ( ! is_wp_error( $id ) ) {
		if ( isset( $term['term_id'] ) ) {
			// success!
			$processed_terms[ intval($term['term_id']) ] = $id['term_id'];
			if ( isset( $term['thumbnail'] ) ) {
				themify_add_import_action( 'term_thumb', array(
					'id' => $id['term_id'],
					'thumb' => $term['thumbnail'],
				) );
			}
			return $term['term_id'];
		}
	}

	return false;
}

function themify_get_term_id_by_slug( $slug, $tax ) {
	$term = get_term_by( 'slug', $slug, $tax );
	if( $term ) {
		return $term->term_id;
	}

	return false;
}

function themify_undo_import_term( $term ) {
	$term_id = term_exists( $term['slug'], $term['taxonomy'] );
	if ( $term_id ) {
		if ( is_array( $term_id ) ) $term_id = $term_id['term_id'];

		if ( $term_thumbnail = get_term_meta( $term['term_id'], 'thumbnail_id', true ) ) {
			wp_delete_attachment( $term_thumbnail, true );
		}

		if ( isset( $term_id ) ) {
			wp_delete_term( $term_id, $term['taxonomy'] );
		}
	}
}

/**
 * Determine if a post exists based on title, content, and date
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param array $args array of database parameters to check
 * @return int Post ID if post exists, 0 otherwise.
 */
function themify_post_exists( $args = array() ) {
	global $wpdb;

	$query = "SELECT ID FROM $wpdb->posts WHERE 1=1";
	$db_args = array();

	foreach ( $args as $key => $value ) {
		$value = wp_unslash( sanitize_post_field( $key, $value, 0, 'db' ) );
		if( ! empty( $value ) ) {
			$query .= ' AND ' . $key . ' = %s';
			$db_args[] = $value;
		}
	}

	if ( !empty ( $args ) )
		return (int) $wpdb->get_var( $wpdb->prepare($query, $args) );

	return 0;
}

function themify_undo_import_post( $post ) {
	if( $post['post_type'] == 'nav_menu_item' ) {
		$post_exists = themify_post_exists( array(
			'post_name' => $post['post_name'],
			'post_modified' => $post['post_date'],
			'post_type' => 'nav_menu_item',
		) );
	} else {
		$post_exists = post_exists( $post['post_title'], '', $post['post_date'] );
	}
	if( $post_exists && get_post_type( $post_exists ) == $post['post_type'] ) {
		/**
		 * check if the post has been modified, if so leave it be
		 *
		 * NOTE: posts are imported using wp_insert_post() which modifies post_modified field
		 * to be the same as post_date, hence to check if the post has been modified,
		 * the post_modified field is compared against post_date in the original post.
		 */
		if( $post['post_date'] == get_post_field( 'post_modified', $post_exists ) ) {
			// find and remove all post attachments
			$attachments = get_posts( array(
				'post_type' => 'attachment',
				'posts_per_page' => -1,
				'post_parent' => $post_exists,
			) );
			if ( $attachments ) {
				foreach ( $attachments as $attachment ) {
					wp_delete_attachment( $attachment->ID, true );
				}
			}
			wp_delete_post( $post_exists, true ); // true: bypass trash
		}
	}
}

function themify_process_post_import( $post ) {
	if( ERASEDEMO ) {
		themify_undo_import_post( $post );
	} else {
		if ( $id = themify_import_post( $post ) ) {
			if ( defined( 'IMPORT_IMAGES' ) && ! IMPORT_IMAGES ) {
				/* if importing images is disabled and post is supposed to have a thumbnail, create a placeholder image instead */
				if ( isset( $post['thumb'] ) ) { // the post is supposed to have featured image
					$placeholder = themify_get_placeholder_image();
					if( ! is_wp_error( $placeholder ) ) {
						set_post_thumbnail( $id, $placeholder );
					}
				}
			} else {
				if ( isset( $post["thumb"] ) ) {
					themify_add_import_action( 'post_thumb', array(
						'id' => $id,
						'thumb' => $post["thumb"],
					) );
				}
				if ( isset( $post["gallery_shortcode"] ) ) {
					themify_add_import_action( 'gallery_field', array(
						'id' => $id,
						'fields' => $post["gallery_shortcode"],
					) );
				}
				if ( isset( $post["_product_image_gallery"] ) ) {
					themify_add_import_action( 'product_gallery', array(
						'id' => $id,
						'images' => $post["_product_image_gallery"],
					) );
				}
			}
		}
	}
}
$thumbs = array();
function themify_do_demo_import() {
global $import_actions;

	if ( isset( $GLOBALS["ThemifyBuilder_Data_Manager"] ) ) {
		remove_action( "save_post", array( $GLOBALS["ThemifyBuilder_Data_Manager"], "save_builder_text_only"), 10, 3 );
	}
$term = array (
  'term_id' => 2,
  'name' => 'Photography',
  'slug' => 'photography',
  'term_group' => 0,
  'taxonomy' => 'category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 4,
  'name' => 'Graphic Design',
  'slug' => 'graphic-design',
  'term_group' => 0,
  'taxonomy' => 'category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 5,
  'name' => 'Interior Design',
  'slug' => 'interior-design',
  'term_group' => 0,
  'taxonomy' => 'category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 6,
  'name' => 'Photography',
  'slug' => 'photography',
  'term_group' => 0,
  'taxonomy' => 'portfolio-category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 7,
  'name' => 'Graphic Design',
  'slug' => 'graphic-design',
  'term_group' => 0,
  'taxonomy' => 'portfolio-category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 8,
  'name' => 'Interior Design',
  'slug' => 'interior-design',
  'term_group' => 0,
  'taxonomy' => 'portfolio-category',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 9,
  'name' => 'Home',
  'slug' => 'home',
  'term_group' => 0,
  'taxonomy' => 'nav_menu',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$term = array (
  'term_id' => 10,
  'name' => 'Main Navigation',
  'slug' => 'main-navigation',
  'term_group' => 0,
  'taxonomy' => 'nav_menu',
  'description' => '',
  'parent' => 0,
);

if( ERASEDEMO ) {
	themify_undo_import_term( $term );
} else {
	themify_import_term( $term );
}

$post = array (
  'ID' => 11,
  'post_date' => '2018-10-23 14:16:00',
  'post_date_gmt' => '2018-10-23 14:16:00',
  'post_content' => 'Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Phasellus hendrerit. Pellentesque aliquet nibh nec urna. In nisi neque, aliquet vel, dapibus id, mattis vel, nisi. Sed pretium, ligula sollicitudin laoreet viverra, tortor libero sodales leo, eget blandit nunc tortor eu nibh. Nullam mollis. Ut justo. Suspendisse potenti.

Sed egestas, ante et vulputate volutpat, eros pede semper est, vitae luctus metus libero eu augue. Morbi purus libero, faucibus adipiscing, commodo quis, gravida id, est. Sed lectus. Praesent elementum hendrerit tortor. Sed semper lorem at felis. Vestibulum volutpat, lacus a ultrices sagittis, mi neque euismod dui, eu pulvinar nunc sapien ornare nisl. Phasellus pede arcu, dapibus eu, fermentum et, dapibus sed, urna.

Morbi interdum mollis sapien. Sed ac risus. Phasellus lacinia, magna a ullamcorper laoreet, lectus arcu pulvinar risus, vitae facilisis libero dolor a purus. Sed vel lacus. Mauris nibh felis, adipiscing varius, adipiscing in, lacinia vel, tellus. Suspendisse ac urna. Etiam pellentesque mauris ut lectus. Nunc tellus ante, mattis eget, gravida vitae, ultricies ac, leo. Integer leo pede, ornare a, lacinia eu, vulputate vel, nisl.
',
  'post_title' => 'New Haircut',
  'post_excerpt' => '',
  'post_name' => 'new-haircut',
  'post_modified' => '2018-10-23 14:16:00',
  'post_modified_gmt' => '2018-10-23 14:16:00',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=11',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"2355aa5\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"bddc126\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'photography',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/model-two-tone.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 25,
  'post_date' => '2018-10-22 14:16:34',
  'post_date_gmt' => '2018-10-22 14:16:34',
  'post_content' => 'Sed egestas, ante et vulputate volutpat, eros pede semper est, vitae luctus metus libero eu augue. Morbi purus libero, faucibus adipiscing, commodo quis, gravida id, est. Sed lectus. Praesent elementum hendrerit tortor. Sed semper lorem at felis. Vestibulum volutpat, lacus a ultrices sagittis, mi neque euismod dui, eu pulvinar nunc sapien ornare nisl. Phasellus pede arcu, dapibus eu, fermentum et, dapibus sed, urna.

Morbi interdum mollis sapien. Sed ac risus. Phasellus lacinia, magna a ullamcorper laoreet, lectus arcu pulvinar risus, vitae facilisis libero dolor a purus. Sed vel lacus. Mauris nibh felis, adipiscing varius, adipiscing in, lacinia vel, tellus. Suspendisse ac urna. Etiam pellentesque mauris ut lectus. Nunc tellus ante, mattis eget, gravida vitae, ultricies ac, leo. Integer leo pede, ornare a, lacinia eu, vulputate vel, nisl.',
  'post_title' => 'Business Plan',
  'post_excerpt' => '',
  'post_name' => 'business-plan',
  'post_modified' => '2018-10-23 14:18:36',
  'post_modified_gmt' => '2018-10-23 14:18:36',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=25',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"6f8aba7\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"52fed1a\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'graphic-design',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/business-plan-skecth-1.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 28,
  'post_date' => '2018-10-21 14:18:43',
  'post_date_gmt' => '2018-10-21 14:18:43',
  'post_content' => 'Donec nec justo eget felis facilisis fermentum. Aliquam porttitor mauris sit amet orci. Aenean dignissim pellentesque felis.

Morbi in sem quis dui placerat ornare. Pellentesque odio nisi, euismod in, pharetra a, ultricies in, diam. Sed arcu. Cras consequat.

Praesent dapibus, neque id cursus faucibus, tortor neque egestas auguae, eu vulputate magna eros eu erat. Aliquam erat volutpat. Nam dui mi, tincidunt quis, accumsan porttitor, facilisis luctus, metus.

Phasellus ultrices nulla quis nibh. Quisque a lectus. Donec consectetuer ligula vulputate sem tristique cursus. Nam nulla quam, gravida non, commodo a, sodales sit amet, nisi.',
  'post_title' => 'Best Friend Wedding',
  'post_excerpt' => '',
  'post_name' => 'best-friend-wedding',
  'post_modified' => '2018-10-23 14:19:47',
  'post_modified_gmt' => '2018-10-23 14:19:47',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=28',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"97e06bf\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"4d5bdd8\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'photography',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/wedding-day.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 31,
  'post_date' => '2018-10-20 14:20:57',
  'post_date_gmt' => '2018-10-20 14:20:57',
  'post_content' => 'Sodales ipsum potenti, nisl tristique vulputate viverra tortor. Dapibus cubilia proin, venenatis orci integer. Nec fermentum nostra arcu bibendum tortor. Etiam sociis euismod facilisi sem. Nisi volutpat feugiat neque adipiscing consectetur dui sed mauris risus quam tristique feugiat. Faucibus at porta facilisis cubilia nam. Etiam hendrerit at penatibus, ultrices porttitor erat augue ornare feugiat dictumst proin! Aliquet consectetur euismod.

',
  'post_title' => 'Perfect Lens',
  'post_excerpt' => '',
  'post_name' => 'perfect-lens',
  'post_modified' => '2018-10-23 14:21:35',
  'post_modified_gmt' => '2018-10-23 14:21:35',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=31',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"b9c09b1\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"21360c9\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'photography',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/phothographer-lens.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 33,
  'post_date' => '2018-10-19 14:21:39',
  'post_date_gmt' => '2018-10-19 14:21:39',
  'post_content' => 'Donec accumsan ipsum nec dolor aliquam pretium. Suspendisse consequat condimentum augue, vel ultricies quam efficitur quis. Aenean sapien turpis, tincidunt congue risus ac, eleifend
condimentum nunc. Quisque porta nulla erat, et maximus nisi venenatis at. Aliquam arcu ante, sagittis eu rutrum a, efficitur eget nibh. In venenatis metus est, a sagittis turpis cursus quis. Fusce odio neque, placerat ut porttitor eget, congue vestibulum purus.

',
  'post_title' => 'Simple Working Space',
  'post_excerpt' => '',
  'post_name' => 'simple-working-space',
  'post_modified' => '2018-10-23 14:26:32',
  'post_modified_gmt' => '2018-10-23 14:26:32',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=33',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"e0c5255\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"ef96ae0\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'interior-design',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/home-office.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 35,
  'post_date' => '2018-10-18 14:23:08',
  'post_date_gmt' => '2018-10-18 14:23:08',
  'post_content' => 'Praesent luctus, neque dictum feugiat maximus, sem enim maximus ipsum, sed ullamcorper est urna suscipit massa. Cras commodo eros nec eleifend vehicula. Praesent auctor augue in massa porta gravida. Nullam et ex eget diam mollis hendrerit id dignissim sem. Suspendisse viverra nibh a fringilla viverra.

Nulla facilisi. Praesent luctus, neque dictum feugiat maximus, sem enim maximus ipsum, sed ullamcorper est urna suscipit massa. Cras commodo eros nec eleifend vehicula. Praesent auctor augue in massa porta gravida. Nullam et ex eget diam mollis hendrerit id dignissim sem. Suspendisse viverra nibh a fringilla viverra.',
  'post_title' => 'Sunrise From The Top of Mountain',
  'post_excerpt' => '',
  'post_name' => 'sunrise-from-the-top-of-mountain',
  'post_modified' => '2018-10-23 14:25:56',
  'post_modified_gmt' => '2018-10-23 14:25:56',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=35',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"2374366\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"41e26d9\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'photography',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/girl-at-top-of-mountain.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 38,
  'post_date' => '2018-10-16 14:26:56',
  'post_date_gmt' => '2018-10-16 14:26:56',
  'post_content' => 'Vonec vitae volutpat erat. Donec non molestie lacus. Integer euismod leo euismod, fermentum tellus sed, consequat leo. Cras lobortis nisl non dapibus tempor. Donec a finibus tellus. Vivamus laoreet lacinia imperdiet. Fusce tincidunt metus ac sapien feugiat, sit amet laoreet lorem aliquam. Integer pharetra egestas mi vel aliquam.

Mauris pulvinar, massa eget semper imperdiet, sapien nisl vulputate mi, ut commodo mi erat et sapien. Interdum et malesuada fames ac ante ipsum primis in faucibus. Curabitur pellentesque augue nec nisl ultricies aliquet. Integer ipsum ante, interdum ac varius quis, ullamcorper vel ante. Donec eu mi vitae ex aliquam porttitor. Donec a mi in mauris finibus venenatis vitae at augue. Maecenas non pulvinar purus. Vestibulum posuere faucibus libero, eu dapibus magna mollis quis. Etiam non ante urna. Curabitur tincidunt ultrices sagittis. Donec quis felis leo. Cras ullamcorper, est eget convallis dapibus, diam lacus viverra magna, volutpat maximus lorem urna ac purus. Nam felis metus, eleifend ut fringilla a, sagittis nec mauris. In id congue justo.',
  'post_title' => 'Futuristic Building',
  'post_excerpt' => '',
  'post_name' => 'futuristic-building',
  'post_modified' => '2018-10-23 14:28:53',
  'post_modified_gmt' => '2018-10-23 14:28:53',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=38',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"555a922\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"f726c40\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'photography',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/Futuristic-building.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 41,
  'post_date' => '2018-10-15 14:29:11',
  'post_date_gmt' => '2018-10-15 14:29:11',
  'post_content' => 'Mauris pulvinar, massa eget semper imperdiet, sapien nisl vulputate mi, ut commodo mi erat et sapien. Interdum et malesuada fames ac ante ipsum primis in faucibus. Curabitur pellentesque augue nec nisl ultricies aliquet. Integer ipsum ante, interdum ac varius quis, ullamcorper vel ante. Donec eu mi vitae ex aliquam porttitor. Donec a mi in mauris finibus venenatis vitae at augue. Maecenas non pulvinar purus. Vestibulum posuere faucibus libero, eu dapibus magna mollis quis. Etiam non ante urna. Curabitur tincidunt ultrices sagittis. Donec quis felis leo. Cras ullamcorper, est eget convallis dapibus, diam lacus viverra magna, volutpat maximus lorem urna ac purus. Nam felis metus, eleifend ut fringilla a, sagittis nec mauris. In id congue justo.
',
  'post_title' => 'Low Budget Interior Design',
  'post_excerpt' => '',
  'post_name' => 'low-budget-interior-design',
  'post_modified' => '2018-10-23 14:30:20',
  'post_modified_gmt' => '2018-10-23 14:30:20',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=41',
  'menu_order' => 0,
  'post_type' => 'post',
  'meta_input' => 
  array (
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"b14fa23\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"c5ac4c0\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'category' => 'interior-design',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/cozy-place-design-interior.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 8,
  'post_date' => '2018-10-23 14:06:19',
  'post_date_gmt' => '2018-10-23 14:06:19',
  'post_content' => '<!--themify_builder_static--><h1>Hello</h1>
<p>With a focus in Branding and Art Direction, I strive to create lasting connections with people through meaningful and deliberate design.</p>
<a href="#portfolio" > See Works </a>
<img src="https://themify.me/demo/themes/ultra-portfolio/files/2018/10/about-bw-417x534.jpg" width="417" height="534" alt="about bw" />
<h2>About</h2>
<p>Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium quae ab illo inventore dicta</p>
<a href="#contact" > Contact </a>
<h2>Services</h2>
<img src="https://themify.me/demo/themes/ultra-portfolio/files/2018/10/design-496x363.jpg" width="496" height="363" title="Design" alt="Design" /> <h3> Design </h3>
<img src="https://themify.me/demo/themes/ultra-portfolio/files/2018/10/web-346x276.jpg" width="346" height="276" title="Web" alt="Web" /> <h3> Web </h3>
<img src="https://themify.me/demo/themes/ultra-portfolio/files/2018/10/seo-168x143.jpg" width="168" height="143" title="Seo" alt="Seo" /> <h3> Seo </h3>
<img src="https://themify.me/demo/themes/ultra-portfolio/files/2018/10/marketing-344x167.jpg" width="344" height="167" title="Marketing" alt="Marketing" /> <h3> Marketing </h3>

<form action="https://themify.me/demo/themes/ultra-portfolio/wp-admin/admin-ajax.php" id="contact-0--form" method="post"> <label for="contact-0--contact-name">Your Name </label> <input type="text" name="contact-name" placeholder="" id="contact-0--contact-name" value="" /> <label for="contact-0--contact-email">Your Email </label> <input type="text" name="contact-email" placeholder="" id="contact-0--contact-email" value="" /> <label for="contact-0--contact-subject">Subject *</label> <input type="text" name="contact-subject" placeholder="" id="contact-0--contact-subject" value="" required /> <label for="contact-0--contact-message">Message *</label> <textarea name="contact-message" placeholder="" id="contact-0--contact-message" rows="8" cols="45" required></textarea> <button type="submit"> Send </button> </form>
<h2>Contact</h2>
<p>Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium</p>
<a href="https://themify.me/"> </a> <a href="https://themify.me/"> </a> <a href="https://themify.me/"> </a><!--/themify_builder_static-->

<!-- wp:themify-builder/canvas /-->',
  'post_title' => 'Home',
  'post_excerpt' => '',
  'post_name' => 'home',
  'post_modified' => '2019-09-19 15:27:46',
  'post_modified_gmt' => '2019-09-19 15:27:46',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?page_id=8',
  'menu_order' => 0,
  'post_type' => 'page',
  'meta_input' => 
  array (
    'page_layout' => 'sidebar-none',
    'content_width' => 'full_width',
    'hide_page_title' => 'yes',
    'custom_menu' => 'home',
    'section_full_scrolling' => 'yes',
    'section_scrolling_mobile' => 'on',
    'header_design' => 'header-overlay',
    'mobile_menu_styles' => 'default',
    'header_wrap' => 'transparent',
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"d7d63b2\\",\\"cols\\":[{\\"element_id\\":\\"39ce1e4\\",\\"grid_class\\":\\"col4-2\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"a05631d\\",\\"mod_settings\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"font_size\\":\\"28\\",\\"text_align\\":\\"right\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"font_color_type_h1\\":\\"font_color_h1_solid\\",\\"font_color_type_h2\\":\\"font_color_h2_solid\\",\\"font_color_type_h3\\":\\"font_color_h3_solid\\",\\"font_color_type_h4\\":\\"font_color_h4_solid\\",\\"font_color_type_h5\\":\\"font_color_h5_solid\\",\\"font_color_type_h6\\":\\"font_color_h6_solid\\",\\"checkbox_dropcap_padding_apply_all\\":\\"1\\",\\"checkbox_dropcap_margin_apply_all\\":\\"1\\",\\"dropcap_border-type\\":\\"top\\",\\"breakpoint_tablet_landscape\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"font_color_type_h1\\":\\"font_color_h1_solid\\",\\"font_color_type_h2\\":\\"font_color_h2_solid\\",\\"font_color_type_h3\\":\\"font_color_h3_solid\\",\\"font_color_type_h4\\":\\"font_color_h4_solid\\",\\"font_color_type_h5\\":\\"font_color_h5_solid\\",\\"font_color_type_h6\\":\\"font_color_h6_solid\\",\\"checkbox_dropcap_padding_apply_all\\":\\"1\\",\\"checkbox_dropcap_margin_apply_all\\":\\"1\\",\\"dropcap_border-type\\":\\"top\\"},\\"breakpoint_tablet\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"font_color_type_h1\\":\\"font_color_h1_solid\\",\\"font_color_type_h2\\":\\"font_color_h2_solid\\",\\"font_color_type_h3\\":\\"font_color_h3_solid\\",\\"font_color_type_h4\\":\\"font_color_h4_solid\\",\\"font_color_type_h5\\":\\"font_color_h5_solid\\",\\"font_color_type_h6\\":\\"font_color_h6_solid\\",\\"checkbox_dropcap_padding_apply_all\\":\\"1\\",\\"checkbox_dropcap_margin_apply_all\\":\\"1\\",\\"dropcap_border-type\\":\\"top\\"},\\"breakpoint_mobile\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"font_size\\":\\"24\\",\\"text_align\\":\\"left\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"font_color_type_h1\\":\\"font_color_h1_solid\\",\\"font_color_type_h2\\":\\"font_color_h2_solid\\",\\"font_color_type_h3\\":\\"font_color_h3_solid\\",\\"font_color_type_h4\\":\\"font_color_h4_solid\\",\\"font_color_type_h5\\":\\"font_color_h5_solid\\",\\"font_color_type_h6\\":\\"font_color_h6_solid\\",\\"checkbox_dropcap_padding_apply_all\\":\\"1\\",\\"checkbox_dropcap_margin_apply_all\\":\\"1\\",\\"dropcap_border-type\\":\\"top\\"},\\"content_text\\":\\"<h1>Hello<\\\\/h1>\\",\\"animation_effect\\":\\"fadeInLeftBig\\"}},{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"8c5f530\\",\\"mod_settings\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"text_align\\":\\"right\\",\\"padding_left\\":\\"7\\",\\"padding_left_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"font_color_type_h1\\":\\"font_color_h1_solid\\",\\"font_color_type_h2\\":\\"font_color_h2_solid\\",\\"font_color_type_h3\\":\\"font_color_h3_solid\\",\\"font_color_type_h4\\":\\"font_color_h4_solid\\",\\"font_color_type_h5\\":\\"font_color_h5_solid\\",\\"font_color_type_h6\\":\\"font_color_h6_solid\\",\\"checkbox_dropcap_padding_apply_all\\":\\"1\\",\\"checkbox_dropcap_margin_apply_all\\":\\"1\\",\\"dropcap_border-type\\":\\"top\\",\\"content_text\\":\\"<p>With a focus in Branding and Art Direction, I strive to create lasting connections with people through meaningful and deliberate design.<\\\\/p>\\",\\"breakpoint_mobile\\":{\\"background_color\\":\\"#ffe657_0.43\\",\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"text_align\\":\\"left\\",\\"padding_top\\":\\"5\\",\\"padding_top_unit\\":\\"%\\",\\"padding_right\\":\\"5\\",\\"padding_right_unit\\":\\"%\\",\\"padding_bottom\\":\\"5\\",\\"padding_left\\":\\"5\\",\\"padding_left_unit\\":\\"%\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"margin_right\\":\\"15\\",\\"margin_right_unit\\":\\"%\\",\\"border-type\\":\\"top\\",\\"font_color_type_h1\\":\\"font_color_h1_solid\\",\\"font_color_type_h2\\":\\"font_color_h2_solid\\",\\"font_color_type_h3\\":\\"font_color_h3_solid\\",\\"font_color_type_h4\\":\\"font_color_h4_solid\\",\\"font_color_type_h5\\":\\"font_color_h5_solid\\",\\"font_color_type_h6\\":\\"font_color_h6_solid\\",\\"checkbox_dropcap_padding_apply_all\\":\\"1\\",\\"checkbox_dropcap_margin_apply_all\\":\\"1\\",\\"dropcap_border-type\\":\\"top\\"}}},{\\"mod_name\\":\\"buttons\\",\\"element_id\\":\\"6282c3b\\",\\"mod_settings\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"text_align\\":\\"right\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"checkbox_padding_link_apply_all\\":\\"1\\",\\"checkbox_link_margin_apply_all\\":\\"1\\",\\"link_border-type\\":\\"top\\",\\"breakpoint_mobile\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"text_align\\":\\"left\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"margin_left\\":\\"5\\",\\"margin_left_unit\\":\\"%\\",\\"border-type\\":\\"top\\",\\"checkbox_padding_link_apply_all\\":\\"1\\",\\"checkbox_link_margin_apply_all\\":\\"1\\",\\"link_border-type\\":\\"top\\"},\\"buttons_size\\":\\"normal\\",\\"buttons_shape\\":\\"squared\\",\\"display\\":\\"buttons-horizontal\\",\\"content_button\\":[{\\"label\\":\\"See Works\\",\\"link\\":\\"#portfolio\\",\\"link_options\\":\\"regular\\",\\"icon_alignment\\":\\"left\\",\\"button_color_bg\\":\\"tb_default_color\\"}]}}]},{\\"element_id\\":\\"55c9ffe\\",\\"grid_class\\":\\"col4-2\\"}],\\"styling\\":{\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_slider_speed\\":\\"2000\\",\\"background_video_options\\":\\"mute\\",\\"background_image\\":\\"https://themify.me/demo/themes/ultra-portfolio\\\\/files\\\\/2018\\\\/10\\\\/intro-bg.jpg\\",\\"background_repeat\\":\\"fullcover\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-top\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"background_repeat_inner\\":\\"repeat\\",\\"background_attachment_inner\\":\\"scroll\\",\\"background_position_inner\\":\\"center-center\\",\\"checkbox_padding_inner_apply_all\\":\\"1\\",\\"border_inner-type\\":\\"top\\",\\"top-frame_type\\":\\"top-presets\\",\\"bottom-frame_type\\":\\"bottom-presets\\",\\"left-frame_type\\":\\"left-presets\\",\\"right-frame_type\\":\\"right-presets\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"row_anchor\\":\\"hello\\",\\"custom_css_id\\":\\"hello\\",\\"row_scroll_direction\\":\\"module_row_section\\"}},{\\"element_id\\":\\"a8abec0\\",\\"cols\\":[{\\"element_id\\":\\"5d22010\\",\\"grid_class\\":\\"col4-2\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"23fca6a\\",\\"mod_settings\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"checkbox_title_margin_apply_all\\":\\"1\\",\\"checkbox_i_t_p_apply_all\\":\\"1\\",\\"checkbox_i_t_m_apply_all\\":\\"1\\",\\"i_t_b-type\\":\\"top\\",\\"checkbox_c_p_apply_all\\":\\"1\\",\\"style_image\\":\\"image-top\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-portfolio\\\\/files\\\\/2018\\\\/10\\\\/about-bw.jpg\\",\\"width_image\\":\\"417\\",\\"height_image\\":\\"534\\",\\"param_image\\":\\"regular\\",\\"custom_parallax_scroll_speed\\":\\"1\\",\\"custom_parallax_scroll_reverse\\":\\"reverse\\"}}]},{\\"element_id\\":\\"5fef5e0\\",\\"grid_class\\":\\"col4-2\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"gtpm548\\",\\"mod_settings\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"font_size\\":\\"26\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"margin_right\\":\\"-66\\",\\"border-type\\":\\"top\\",\\"font_color_type_h1\\":\\"font_color_h1_solid\\",\\"font_color_type_h2\\":\\"font_color_h2_solid\\",\\"font_size_h2_unit\\":\\"em\\",\\"font_color_type_h3\\":\\"font_color_h3_solid\\",\\"font_color_type_h4\\":\\"font_color_h4_solid\\",\\"font_color_type_h5\\":\\"font_color_h5_solid\\",\\"font_color_type_h6\\":\\"font_color_h6_solid\\",\\"checkbox_dropcap_padding_apply_all\\":\\"1\\",\\"checkbox_dropcap_margin_apply_all\\":\\"1\\",\\"dropcap_border-type\\":\\"top\\",\\"breakpoint_mobile\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"font_size\\":\\"20\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"font_color_type_h1\\":\\"font_color_h1_solid\\",\\"font_color_type_h2\\":\\"font_color_h2_solid\\",\\"font_size_h2_unit\\":\\"em\\",\\"font_color_type_h3\\":\\"font_color_h3_solid\\",\\"font_color_type_h4\\":\\"font_color_h4_solid\\",\\"font_color_type_h5\\":\\"font_color_h5_solid\\",\\"font_color_type_h6\\":\\"font_color_h6_solid\\",\\"checkbox_dropcap_padding_apply_all\\":\\"1\\",\\"checkbox_dropcap_margin_apply_all\\":\\"1\\",\\"dropcap_border-type\\":\\"top\\"},\\"content_text\\":\\"<h2>About<\\\\/h2>\\"}},{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"6997c6c\\",\\"mod_settings\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"border-type\\":\\"top\\",\\"font_color_type_h1\\":\\"font_color_h1_solid\\",\\"font_color_type_h2\\":\\"font_color_h2_solid\\",\\"font_color_type_h3\\":\\"font_color_h3_solid\\",\\"font_color_type_h4\\":\\"font_color_h4_solid\\",\\"font_color_type_h5\\":\\"font_color_h5_solid\\",\\"font_color_type_h6\\":\\"font_color_h6_solid\\",\\"dropcap_border-type\\":\\"top\\",\\"content_text\\":\\"<p>Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium quae ab illo inventore dicta<\\\\/p>\\"}},{\\"mod_name\\":\\"buttons\\",\\"element_id\\":\\"ig4j548\\",\\"mod_settings\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"checkbox_padding_link_apply_all\\":\\"1\\",\\"checkbox_link_margin_apply_all\\":\\"1\\",\\"link_border-type\\":\\"top\\",\\"buttons_size\\":\\"normal\\",\\"buttons_shape\\":\\"squared\\",\\"display\\":\\"buttons-horizontal\\",\\"content_button\\":[{\\"label\\":\\"Contact\\",\\"link\\":\\"#contact\\",\\"link_options\\":\\"regular\\",\\"icon_alignment\\":\\"left\\",\\"button_color_bg\\":\\"tb_default_color\\"}]}}],\\"styling\\":{\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_slider_speed\\":\\"2000\\",\\"background_video_options\\":\\"mute\\",\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"background_repeat_inner\\":\\"repeat\\",\\"background_attachment_inner\\":\\"scroll\\",\\"background_position_inner\\":\\"center-center\\",\\"checkbox_padding_inner_apply_all\\":\\"1\\",\\"border_inner-type\\":\\"top\\",\\"top-frame_type\\":\\"top-presets\\",\\"bottom-frame_type\\":\\"bottom-presets\\",\\"left-frame_type\\":\\"left-presets\\",\\"right-frame_type\\":\\"right-presets\\",\\"text_align\\":\\"right\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"breakpoint_mobile\\":{\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"background_repeat_inner\\":\\"repeat\\",\\"background_attachment_inner\\":\\"scroll\\",\\"background_position_inner\\":\\"center-center\\",\\"checkbox_padding_inner_apply_all\\":\\"1\\",\\"border_inner-type\\":\\"top\\",\\"top-frame_type\\":\\"top-presets\\",\\"bottom-frame_type\\":\\"bottom-presets\\",\\"left-frame_type\\":\\"left-presets\\",\\"right-frame_type\\":\\"right-presets\\",\\"text_align\\":\\"left\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"margin_top\\":\\"10\\",\\"margin_top_unit\\":\\"%\\",\\"border-type\\":\\"top\\"}}}],\\"desktop_dir\\":\\"rtl\\",\\"tablet_dir\\":\\"rtl\\",\\"tablet_landscape_dir\\":\\"rtl\\",\\"styling\\":{\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_slider_speed\\":\\"2000\\",\\"background_video_options\\":\\"mute\\",\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"background_repeat_inner\\":\\"repeat\\",\\"background_attachment_inner\\":\\"scroll\\",\\"background_position_inner\\":\\"center-center\\",\\"checkbox_padding_inner_apply_all\\":\\"1\\",\\"border_inner-type\\":\\"top\\",\\"top-frame_type\\":\\"top-presets\\",\\"bottom-frame_type\\":\\"bottom-presets\\",\\"left-frame_type\\":\\"left-presets\\",\\"right-frame_type\\":\\"right-presets\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"row_anchor\\":\\"about\\",\\"custom_css_id\\":\\"about\\",\\"row_scroll_direction\\":\\"module_row_section\\",\\"breakpoint_mobile\\":{\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"background_repeat_inner\\":\\"repeat\\",\\"background_attachment_inner\\":\\"scroll\\",\\"background_position_inner\\":\\"center-center\\",\\"checkbox_padding_inner_apply_all\\":\\"1\\",\\"border_inner-type\\":\\"top\\",\\"top-frame_type\\":\\"top-presets\\",\\"bottom-frame_type\\":\\"bottom-presets\\",\\"left-frame_type\\":\\"left-presets\\",\\"right-frame_type\\":\\"right-presets\\",\\"padding_top\\":\\"6\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom\\":\\"6\\",\\"padding_bottom_unit\\":\\"%\\",\\"border-type\\":\\"top\\"}}},{\\"element_id\\":\\"f86e0d0\\",\\"cols\\":[{\\"element_id\\":\\"3927b6d\\",\\"grid_class\\":\\"col3-2\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"3pzy549\\",\\"mod_settings\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"font_size\\":\\"27\\",\\"text_align\\":\\"right\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"margin_right\\":\\"-44\\",\\"border-type\\":\\"top\\",\\"font_color_type_h1\\":\\"font_color_h1_solid\\",\\"font_color_type_h2\\":\\"font_color_h2_solid\\",\\"font_size_h2_unit\\":\\"em\\",\\"font_color_type_h3\\":\\"font_color_h3_solid\\",\\"font_color_type_h4\\":\\"font_color_h4_solid\\",\\"font_color_type_h5\\":\\"font_color_h5_solid\\",\\"font_color_type_h6\\":\\"font_color_h6_solid\\",\\"checkbox_dropcap_padding_apply_all\\":\\"1\\",\\"checkbox_dropcap_margin_apply_all\\":\\"1\\",\\"dropcap_border-type\\":\\"top\\",\\"content_text\\":\\"<h2>Services<\\\\/h2>\\",\\"custom_parallax_scroll_zindex\\":\\"1\\",\\"breakpoint_mobile\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"font_size\\":\\"20\\",\\"text_align\\":\\"right\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"font_color_type_h1\\":\\"font_color_h1_solid\\",\\"font_color_type_h2\\":\\"font_color_h2_solid\\",\\"font_size_h2_unit\\":\\"em\\",\\"font_color_type_h3\\":\\"font_color_h3_solid\\",\\"font_color_type_h4\\":\\"font_color_h4_solid\\",\\"font_color_type_h5\\":\\"font_color_h5_solid\\",\\"font_color_type_h6\\":\\"font_color_h6_solid\\",\\"checkbox_dropcap_padding_apply_all\\":\\"1\\",\\"checkbox_dropcap_margin_apply_all\\":\\"1\\",\\"dropcap_border-type\\":\\"top\\"}}},{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"337f083\\",\\"mod_settings\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"font_color_title\\":\\"#ffffff\\",\\"font_size_title\\":\\"3\\",\\"font_size_title_unit\\":\\"em\\",\\"text_transform_title\\":\\"uppercase\\",\\"font_title_bold\\":\\"bold\\",\\"checkbox_title_margin_apply_all\\":\\"1\\",\\"checkbox_i_t_p_apply_all\\":\\"1\\",\\"checkbox_i_t_m_apply_all\\":\\"1\\",\\"i_t_b-type\\":\\"top\\",\\"b_c_c\\":\\"#d34141_0.80\\",\\"b_c_c_h\\":\\"#d34141_0.50\\",\\"f_c_c_h\\":\\"#ffffff\\",\\"checkbox_c_p_apply_all\\":\\"1\\",\\"style_image\\":\\"image-full-overlay\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-portfolio\\\\/files\\\\/2018\\\\/10\\\\/design.jpg\\",\\"width_image\\":\\"496\\",\\"auto_fullwidth\\":\\"1\\",\\"height_image\\":\\"363\\",\\"title_image\\":\\"Design\\",\\"param_image\\":\\"regular\\",\\"breakpoint_mobile\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"font_size_title\\":\\"1\\",\\"font_size_title_unit\\":\\"em\\",\\"text_transform_title\\":\\"uppercase\\",\\"font_title_bold\\":\\"bold\\",\\"checkbox_title_margin_apply_all\\":\\"1\\",\\"checkbox_i_t_p_apply_all\\":\\"1\\",\\"checkbox_i_t_m_apply_all\\":\\"1\\",\\"i_t_b-type\\":\\"top\\",\\"checkbox_c_p_apply_all\\":\\"1\\"}}}],\\"grid_width\\":\\"60\\"},{\\"element_id\\":\\"2f22f2d\\",\\"grid_class\\":\\"col3-1\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"99yk550\\",\\"mod_settings\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"margin_left\\":\\"-30\\",\\"border-type\\":\\"top\\",\\"font_color_title\\":\\"#ffffff\\",\\"font_size_title\\":\\"3\\",\\"font_size_title_unit\\":\\"em\\",\\"text_transform_title\\":\\"uppercase\\",\\"font_title_bold\\":\\"bold\\",\\"checkbox_title_margin_apply_all\\":\\"1\\",\\"checkbox_i_t_p_apply_all\\":\\"1\\",\\"checkbox_i_t_m_apply_all\\":\\"1\\",\\"i_t_b-type\\":\\"top\\",\\"b_c_c\\":\\"#ff6f00_0.80\\",\\"b_c_c_h\\":\\"#ff6600_0.50\\",\\"f_c_c_h\\":\\"#ffffff\\",\\"checkbox_c_p_apply_all\\":\\"1\\",\\"style_image\\":\\"image-full-overlay\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-portfolio\\\\/files\\\\/2018\\\\/10\\\\/web.jpg\\",\\"width_image\\":\\"346\\",\\"height_image\\":\\"276\\",\\"title_image\\":\\"Web\\",\\"param_image\\":\\"regular\\",\\"breakpoint_mobile\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"margin_left\\":\\"0\\",\\"border-type\\":\\"top\\",\\"font_size_title\\":\\"1\\",\\"font_size_title_unit\\":\\"em\\",\\"text_transform_title\\":\\"uppercase\\",\\"font_title_bold\\":\\"bold\\",\\"checkbox_title_margin_apply_all\\":\\"1\\",\\"checkbox_i_t_p_apply_all\\":\\"1\\",\\"checkbox_i_t_m_apply_all\\":\\"1\\",\\"i_t_b-type\\":\\"top\\",\\"checkbox_c_p_apply_all\\":\\"1\\"}}},{\\"element_id\\":\\"df1ad66\\",\\"cols\\":[{\\"element_id\\":\\"045e622\\",\\"grid_class\\":\\"col4-2\\",\\"modules\\":[{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"dc7z551\\",\\"mod_settings\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"font_color_title\\":\\"#ffffff\\",\\"font_size_title\\":\\"3\\",\\"font_size_title_unit\\":\\"em\\",\\"text_transform_title\\":\\"uppercase\\",\\"font_title_bold\\":\\"bold\\",\\"checkbox_title_margin_apply_all\\":\\"1\\",\\"checkbox_i_t_p_apply_all\\":\\"1\\",\\"checkbox_i_t_m_apply_all\\":\\"1\\",\\"i_t_b-type\\":\\"top\\",\\"b_c_c\\":\\"#1d74ba_0.80\\",\\"b_c_c_h\\":\\"#1d74ba_0.50\\",\\"f_c_c_h\\":\\"#ffffff\\",\\"checkbox_c_p_apply_all\\":\\"1\\",\\"style_image\\":\\"image-full-overlay\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-portfolio\\\\/files\\\\/2018\\\\/10\\\\/seo.jpg\\",\\"width_image\\":\\"168\\",\\"height_image\\":\\"143\\",\\"title_image\\":\\"Seo\\",\\"param_image\\":\\"regular\\",\\"breakpoint_mobile\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"font_size_title\\":\\"1\\",\\"font_size_title_unit\\":\\"em\\",\\"text_transform_title\\":\\"uppercase\\",\\"font_title_bold\\":\\"bold\\",\\"checkbox_title_margin_apply_all\\":\\"1\\",\\"checkbox_i_t_p_apply_all\\":\\"1\\",\\"checkbox_i_t_m_apply_all\\":\\"1\\",\\"i_t_b-type\\":\\"top\\",\\"checkbox_c_p_apply_all\\":\\"1\\"}}}]},{\\"element_id\\":\\"50f52f9\\",\\"grid_class\\":\\"col4-2\\"}],\\"column_alignment\\":\\"col_align_top\\"},{\\"mod_name\\":\\"image\\",\\"element_id\\":\\"oelm551\\",\\"mod_settings\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"font_color_title\\":\\"#ffffff\\",\\"font_size_title\\":\\"3\\",\\"font_size_title_unit\\":\\"em\\",\\"text_transform_title\\":\\"uppercase\\",\\"font_title_bold\\":\\"bold\\",\\"checkbox_title_margin_apply_all\\":\\"1\\",\\"checkbox_i_t_p_apply_all\\":\\"1\\",\\"checkbox_i_t_m_apply_all\\":\\"1\\",\\"i_t_b-type\\":\\"top\\",\\"b_c_c\\":\\"#617b14_0.80\\",\\"b_c_c_h\\":\\"#617b14_0.50\\",\\"f_c_c_h\\":\\"#ffffff\\",\\"checkbox_c_p_apply_all\\":\\"1\\",\\"style_image\\":\\"image-full-overlay\\",\\"url_image\\":\\"https://themify.me/demo/themes/ultra-portfolio\\\\/files\\\\/2018\\\\/10\\\\/marketing.jpg\\",\\"width_image\\":\\"344\\",\\"height_image\\":\\"167\\",\\"title_image\\":\\"Marketing\\",\\"param_image\\":\\"regular\\",\\"breakpoint_mobile\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"font_size_title\\":\\"1\\",\\"font_size_title_unit\\":\\"em\\",\\"text_transform_title\\":\\"uppercase\\",\\"font_title_bold\\":\\"bold\\",\\"checkbox_title_margin_apply_all\\":\\"1\\",\\"checkbox_i_t_p_apply_all\\":\\"1\\",\\"checkbox_i_t_m_apply_all\\":\\"1\\",\\"i_t_b-type\\":\\"top\\",\\"checkbox_c_p_apply_all\\":\\"1\\"}}}],\\"grid_width\\":\\"40\\"}],\\"gutter\\":\\"gutter-none\\",\\"styling\\":{\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_slider_speed\\":\\"2000\\",\\"background_video_options\\":\\"mute\\",\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"background_color\\":\\"#95e3cc\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"background_repeat_inner\\":\\"repeat\\",\\"background_attachment_inner\\":\\"scroll\\",\\"background_position_inner\\":\\"center-center\\",\\"checkbox_padding_inner_apply_all\\":\\"1\\",\\"border_inner-type\\":\\"top\\",\\"top-frame_type\\":\\"top-presets\\",\\"bottom-frame_type\\":\\"bottom-presets\\",\\"left-frame_type\\":\\"left-presets\\",\\"right-frame_type\\":\\"right-presets\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom_unit\\":\\"%\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"row_anchor\\":\\"services\\",\\"custom_css_id\\":\\"services\\",\\"row_scroll_direction\\":\\"module_row_section\\",\\"breakpoint_mobile\\":{\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"background_repeat_inner\\":\\"repeat\\",\\"background_attachment_inner\\":\\"scroll\\",\\"background_position_inner\\":\\"center-center\\",\\"checkbox_padding_inner_apply_all\\":\\"1\\",\\"border_inner-type\\":\\"top\\",\\"top-frame_type\\":\\"top-presets\\",\\"bottom-frame_type\\":\\"bottom-presets\\",\\"left-frame_type\\":\\"left-presets\\",\\"right-frame_type\\":\\"right-presets\\",\\"padding_top\\":\\"9\\",\\"padding_top_unit\\":\\"%\\",\\"padding_bottom_unit\\":\\"%\\",\\"border-type\\":\\"top\\"}}},{\\"element_id\\":\\"bac5ddf\\",\\"cols\\":[{\\"element_id\\":\\"99626e3\\",\\"grid_class\\":\\"col-full\\",\\"modules\\":[{\\"mod_name\\":\\"portfolio\\",\\"element_id\\":\\"6ca8cdd\\",\\"mod_settings\\":{\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"layout_portfolio\\":\\"grid3\\",\\"type_query_portfolio\\":\\"category\\",\\"category_portfolio\\":\\"0|multiple\\",\\"portfolio_content_layout\\":\\"overlay\\",\\"post_filter\\":\\"no\\",\\"portfolio_gutter\\":\\"no-gutter\\",\\"post_per_page_portfolio\\":\\"6\\",\\"order_portfolio\\":\\"desc\\",\\"orderby_portfolio\\":\\"date\\",\\"display_portfolio\\":\\"excerpt\\",\\"img_width_portfolio\\":\\"500\\",\\"img_height_portfolio\\":\\"500\\",\\"hide_post_date_portfolio\\":\\"yes\\"}}]}],\\"styling\\":{\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_slider_speed\\":\\"2000\\",\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"background_repeat_inner\\":\\"repeat\\",\\"background_attachment_inner\\":\\"scroll\\",\\"background_position_inner\\":\\"center-center\\",\\"checkbox_padding_inner_apply_all\\":\\"1\\",\\"border_inner-type\\":\\"top\\",\\"top-frame_type\\":\\"top-presets\\",\\"bottom-frame_type\\":\\"bottom-presets\\",\\"left-frame_type\\":\\"left-presets\\",\\"right-frame_type\\":\\"right-presets\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"row_width\\":\\"fullwidth-content\\",\\"row_anchor\\":\\"portfolio\\",\\"custom_css_id\\":\\"portfolio\\",\\"row_scroll_direction\\":\\"module_row_section\\"}},{\\"element_id\\":\\"4baf482\\",\\"cols\\":[{\\"element_id\\":\\"ef9b042\\",\\"grid_class\\":\\"col3-2\\",\\"modules\\":[{\\"mod_name\\":\\"contact\\",\\"element_id\\":\\"60f54a9\\",\\"mod_settings\\":{\\"background_color\\":\\"#e1e2ce\\",\\"font_color_type\\":\\"font_color_solid\\",\\"padding_top\\":\\"8\\",\\"padding_top_unit\\":\\"%\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"margin_top\\":\\"17\\",\\"border-type\\":\\"top\\",\\"border_inputs-type\\":\\"top\\",\\"border_send-type\\":\\"top\\",\\"checkbox_padding_success_message_apply_all\\":\\"1\\",\\"checkbox_margin_success_message_apply_all\\":\\"1\\",\\"border_success_message-type\\":\\"top\\",\\"checkbox_padding_error_message_apply_all\\":\\"1\\",\\"checkbox_margin_error_message_apply_all\\":\\"1\\",\\"border_error_message-type\\":\\"top\\",\\"layout_contact\\":\\"animated-label\\",\\"gdpr_label\\":\\"I consent to my submitted data being collected and stored\\",\\"field_name_label\\":\\"Your Name\\",\\"field_email_label\\":\\"Your Email\\",\\"field_subject_label\\":\\"Subject\\",\\"field_subject_require\\":\\"yes\\",\\"field_subject_active\\":\\"yes\\",\\"field_message_label\\":\\"Message\\",\\"field_extra\\":\\"{ \\\\\\\\\\\\\\"fields\\\\\\\\\\\\\\": [] }\\",\\"field_sendcopy_label\\":\\"Send a copy to myself\\",\\"field_order\\":\\"{}\\",\\"field_send_label\\":\\"Send\\",\\"field_send_align\\":\\"left\\",\\"field_email_active\\":\\"yes\\",\\"field_name_active\\":\\"yes\\",\\"field_message_active\\":\\"yes\\",\\"field_sendcopy_active\\":\\"\\",\\"field_captcha_active\\":\\"\\",\\"field_email_require\\":\\"\\",\\"field_name_require\\":\\"\\",\\"contact_sent_from\\":\\"enable\\",\\"post_author\\":\\"\\",\\"send_to_admins\\":\\"true\\"}}],\\"styling\\":{\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_slider_speed\\":\\"2000\\",\\"background_video_options\\":\\"mute\\",\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"background_repeat_inner\\":\\"repeat\\",\\"background_attachment_inner\\":\\"scroll\\",\\"background_position_inner\\":\\"center-center\\",\\"checkbox_padding_inner_apply_all\\":\\"1\\",\\"border_inner-type\\":\\"top\\",\\"top-frame_type\\":\\"top-presets\\",\\"bottom-frame_type\\":\\"bottom-presets\\",\\"left-frame_type\\":\\"left-presets\\",\\"right-frame_type\\":\\"right-presets\\",\\"right-frame_layout\\":\\"slant2\\",\\"right-frame_color\\":\\"#f4f3f3\\",\\"padding_top\\":\\"4\\",\\"padding_top_unit\\":\\"%\\",\\"padding_right\\":\\"3\\",\\"padding_right_unit\\":\\"%\\",\\"padding_bottom\\":\\"4\\",\\"padding_bottom_unit\\":\\"%\\",\\"padding_left\\":\\"3\\",\\"padding_left_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\"}},{\\"element_id\\":\\"caedd54\\",\\"grid_class\\":\\"col3-1\\",\\"modules\\":[{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"dd7fa2e\\",\\"mod_settings\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"font_color\\":\\"#000000_0.98\\",\\"font_gradient_color-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"font_size\\":\\"23\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"margin_left\\":\\"-140\\",\\"border-type\\":\\"top\\",\\"font_color_type_h1\\":\\"font_color_h1_solid\\",\\"font_color_type_h2\\":\\"font_color_h2_solid\\",\\"font_size_h2_unit\\":\\"em\\",\\"font_color_type_h3\\":\\"font_color_h3_solid\\",\\"font_color_type_h4\\":\\"font_color_h4_solid\\",\\"font_color_type_h5\\":\\"font_color_h5_solid\\",\\"font_color_type_h6\\":\\"font_color_h6_solid\\",\\"checkbox_dropcap_padding_apply_all\\":\\"1\\",\\"checkbox_dropcap_margin_apply_all\\":\\"1\\",\\"dropcap_border-type\\":\\"top\\",\\"breakpoint_mobile\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"font_size\\":\\"20\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"margin_left\\":\\"0\\",\\"border-type\\":\\"top\\",\\"font_color_type_h1\\":\\"font_color_h1_solid\\",\\"font_color_type_h2\\":\\"font_color_h2_solid\\",\\"font_size_h2_unit\\":\\"em\\",\\"font_color_type_h3\\":\\"font_color_h3_solid\\",\\"font_color_type_h4\\":\\"font_color_h4_solid\\",\\"font_color_type_h5\\":\\"font_color_h5_solid\\",\\"font_color_type_h6\\":\\"font_color_h6_solid\\",\\"checkbox_dropcap_padding_apply_all\\":\\"1\\",\\"checkbox_dropcap_margin_apply_all\\":\\"1\\",\\"dropcap_border-type\\":\\"top\\"},\\"content_text\\":\\"<h2>Contact<\\\\/h2>\\"}},{\\"mod_name\\":\\"text\\",\\"element_id\\":\\"b27d822\\",\\"mod_settings\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"font_gradient_color-gradient\\":\\"0% rgb(0, 0, 0)|100% rgb(255, 255, 255)\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"font_color_type_h1\\":\\"font_color_h1_solid\\",\\"font_color_type_h2\\":\\"font_color_h2_solid\\",\\"font_color_type_h3\\":\\"font_color_h3_solid\\",\\"font_color_type_h4\\":\\"font_color_h4_solid\\",\\"font_color_type_h5\\":\\"font_color_h5_solid\\",\\"font_color_type_h6\\":\\"font_color_h6_solid\\",\\"checkbox_dropcap_padding_apply_all\\":\\"1\\",\\"checkbox_dropcap_margin_apply_all\\":\\"1\\",\\"dropcap_border-type\\":\\"top\\",\\"content_text\\":\\"<p>Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium<\\\\/p>\\"}},{\\"mod_name\\":\\"icon\\",\\"element_id\\":\\"1ec816d\\",\\"mod_settings\\":{\\"background_repeat\\":\\"repeat\\",\\"background_position\\":\\"left-top\\",\\"font_color_type\\":\\"font_color_solid\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"icon_size\\":\\"xlarge\\",\\"icon_style\\":\\"circle\\",\\"icon_position\\":\\"icon_position_left\\",\\"icon_arrangement\\":\\"icon_horizontal\\",\\"content_icon\\":[{\\"icon\\":\\"fa-facebook\\",\\"icon_color_bg\\":\\"transparent\\",\\"link\\":\\"https:\\\\/\\\\/themify.me\\\\/\\",\\"link_options\\":\\"regular\\"},{\\"icon\\":\\"fa-twitter\\",\\"icon_color_bg\\":\\"transparent\\",\\"link\\":\\"https:\\\\/\\\\/themify.me\\\\/\\",\\"link_options\\":\\"regular\\"},{\\"icon\\":\\"fa-linkedin\\",\\"icon_color_bg\\":\\"transparent\\",\\"link\\":\\"https:\\\\/\\\\/themify.me\\\\/\\",\\"link_options\\":\\"regular\\"}]}}],\\"styling\\":{\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_slider_speed\\":\\"2000\\",\\"background_video_options\\":\\"mute\\",\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"background_color\\":\\"#f4f3f3\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"background_repeat_inner\\":\\"repeat\\",\\"background_attachment_inner\\":\\"scroll\\",\\"background_position_inner\\":\\"center-center\\",\\"checkbox_padding_inner_apply_all\\":\\"1\\",\\"border_inner-type\\":\\"top\\",\\"top-frame_type\\":\\"top-presets\\",\\"bottom-frame_type\\":\\"bottom-presets\\",\\"left-frame_type\\":\\"left-presets\\",\\"right-frame_type\\":\\"right-presets\\",\\"link_color\\":\\"#000000\\",\\"padding_top\\":\\"8\\",\\"padding_top_unit\\":\\"%\\",\\"padding_right\\":\\"5\\",\\"padding_right_unit\\":\\"%\\",\\"padding_bottom\\":\\"8\\",\\"padding_bottom_unit\\":\\"%\\",\\"padding_left_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"breakpoint_mobile\\":{\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"background_repeat_inner\\":\\"repeat\\",\\"background_attachment_inner\\":\\"scroll\\",\\"background_position_inner\\":\\"center-center\\",\\"checkbox_padding_inner_apply_all\\":\\"1\\",\\"border_inner-type\\":\\"top\\",\\"top-frame_type\\":\\"top-presets\\",\\"bottom-frame_type\\":\\"bottom-presets\\",\\"left-frame_type\\":\\"left-presets\\",\\"right-frame_type\\":\\"right-presets\\",\\"padding_top_unit\\":\\"%\\",\\"padding_right\\":\\"5\\",\\"padding_right_unit\\":\\"%\\",\\"padding_bottom_unit\\":\\"%\\",\\"padding_left\\":\\"5\\",\\"padding_left_unit\\":\\"%\\",\\"checkbox_margin_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\"}}}],\\"gutter\\":\\"gutter-none\\",\\"styling\\":{\\"background_slider_size\\":\\"large\\",\\"background_slider_mode\\":\\"fullcover\\",\\"background_slider_speed\\":\\"2000\\",\\"background_repeat\\":\\"repeat\\",\\"background_attachment\\":\\"scroll\\",\\"background_position\\":\\"center-center\\",\\"cover_color-type\\":\\"color\\",\\"cover_color_hover-type\\":\\"hover_color\\",\\"background_repeat_inner\\":\\"repeat\\",\\"background_attachment_inner\\":\\"scroll\\",\\"background_position_inner\\":\\"center-center\\",\\"checkbox_padding_inner_apply_all\\":\\"1\\",\\"border_inner-type\\":\\"top\\",\\"top-frame_type\\":\\"top-presets\\",\\"bottom-frame_type\\":\\"bottom-presets\\",\\"left-frame_type\\":\\"left-presets\\",\\"right-frame_type\\":\\"right-presets\\",\\"checkbox_padding_apply_all\\":\\"1\\",\\"border-type\\":\\"top\\",\\"row_width\\":\\"fullwidth-content\\",\\"row_anchor\\":\\"contact\\",\\"custom_css_id\\":\\"contact\\",\\"row_scroll_direction\\":\\"module_row_section\\"}}]',
  ),
  'tax_input' => 
  array (
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 45,
  'post_date' => '2018-10-23 14:33:28',
  'post_date_gmt' => '2018-10-23 14:33:28',
  'post_content' => 'Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Phasellus hendrerit. Pellentesque aliquet nibh nec urna. In nisi neque, aliquet vel, dapibus id, mattis vel, nisi. Sed pretium, ligula sollicitudin laoreet viverra, tortor libero sodales leo, eget blandit nunc tortor eu nibh. Nullam mollis. Ut justo. Suspendisse potenti.

Sed egestas, ante et vulputate volutpat, eros pede semper est, vitae luctus metus libero eu augue. Morbi purus libero, faucibus adipiscing, commodo quis, gravida id, est. Sed lectus. Praesent elementum hendrerit tortor. Sed semper lorem at felis. Vestibulum volutpat, lacus a ultrices sagittis, mi neque euismod dui, eu pulvinar nunc sapien ornare nisl. Phasellus pede arcu, dapibus eu, fermentum et, dapibus sed, urna.

Morbi interdum mollis sapien. Sed ac risus. Phasellus lacinia, magna a ullamcorper laoreet, lectus arcu pulvinar risus, vitae facilisis libero dolor a purus. Sed vel lacus. Mauris nibh felis, adipiscing varius, adipiscing in, lacinia vel, tellus. Suspendisse ac urna. Etiam pellentesque mauris ut lectus. Nunc tellus ante, mattis eget, gravida vitae, ultricies ac, leo. Integer leo pede, ornare a, lacinia eu, vulputate vel, nisl.
',
  'post_title' => 'Stacy\'s Photo Set',
  'post_excerpt' => '',
  'post_name' => 'stacys-photo-set',
  'post_modified' => '2018-10-25 09:29:57',
  'post_modified_gmt' => '2018-10-25 09:29:57',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?post_type=portfolio&#038;p=45',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'project_date' => 'Oct 23, 2018',
    'project_client' => 'Stacy Meghan',
    'project_services' => 'Photography, Digital Imaging',
    'project_launch' => 'https://themify.me/demo/themes/ultra-portfolio/project/stacys-photo-set/',
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"d366cf9\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"369c8af\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'photography',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/model-two-tone.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 46,
  'post_date' => '2018-10-22 14:39:44',
  'post_date_gmt' => '2018-10-22 14:39:44',
  'post_content' => 'Sed egestas, ante et vulputate volutpat, eros pede semper est, vitae luctus metus libero eu augue. Morbi purus libero, faucibus adipiscing, commodo quis, gravida id, est. Sed lectus. Praesent elementum hendrerit tortor. Sed semper lorem at felis. Vestibulum volutpat, lacus a ultrices sagittis, mi neque euismod dui, eu pulvinar nunc sapien ornare nisl. Phasellus pede arcu, dapibus eu, fermentum et, dapibus sed, urna.

Morbi interdum mollis sapien. Sed ac risus. Phasellus lacinia, magna a ullamcorper laoreet, lectus arcu pulvinar risus, vitae facilisis libero dolor a purus. Sed vel lacus. Mauris nibh felis, adipiscing varius, adipiscing in, lacinia vel, tellus. Suspendisse ac urna. Etiam pellentesque mauris ut lectus. Nunc tellus ante, mattis eget, gravida vitae, ultricies ac, leo. Integer leo pede, ornare a, lacinia eu, vulputate vel, nisl.',
  'post_title' => 'Wireframe Packs',
  'post_excerpt' => '',
  'post_name' => 'wireframe-packs',
  'post_modified' => '2018-10-25 11:44:41',
  'post_modified_gmt' => '2018-10-25 11:44:41',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?post_type=portfolio&#038;p=46',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'project_date' => 'Oct 24, 2018',
    'project_client' => 'Warehouse LLC',
    'project_services' => 'Graphic Design, Sketch',
    'project_launch' => 'https://themify.me/demo/themes/ultra-portfolio/project/wireframe-packs/',
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"b8ec087\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"bbdb674\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'graphic-design',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/business-plan-skecth-1.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 47,
  'post_date' => '2018-10-21 14:40:15',
  'post_date_gmt' => '2018-10-21 14:40:15',
  'post_content' => 'Donec nec justo eget felis facilisis fermentum. Aliquam porttitor mauris sit amet orci. Aenean dignissim pellentesque felis.
Morbi in sem quis dui placerat ornare. Pellentesque odio nisi, euismod in, pharetra a, ultricies in, diam. Sed arcu. Cras consequat.
Praesent dapibus, neque id cursus faucibus, tortor neque egestas auguae, eu vulputate magna eros eu erat. Aliquam erat volutpat. Nam dui mi, tincidunt quis, accumsan porttitor, facilisis luctus, metus.
Phasellus ultrices nulla quis nibh. Quisque a lectus. Donec consectetuer ligula vulputate sem tristique cursus. Nam nulla quam, gravida non, commodo a, sodales sit amet, nisi.',
  'post_title' => 'Wedding Photo Shooting',
  'post_excerpt' => '',
  'post_name' => 'wedding-photo-shooting',
  'post_modified' => '2018-10-25 11:45:23',
  'post_modified_gmt' => '2018-10-25 11:45:23',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?post_type=portfolio&#038;p=47',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'project_date' => 'Oct 21, 2018',
    'project_client' => 'Ben and Maggie',
    'project_services' => 'Photography, Digital Imaging',
    'project_launch' => 'https://themify.me/demo/themes/ultra-portfolio/project/wedding-photo-shooting/',
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"4aebca9\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"8c23794\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'photography',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/wedding-day.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 48,
  'post_date' => '2018-10-19 14:41:01',
  'post_date_gmt' => '2018-10-19 14:41:01',
  'post_content' => 'Sodales ipsum potenti, nisl tristique vulputate viverra tortor. Dapibus cubilia proin, venenatis orci integer. Nec fermentum nostra arcu bibendum tortor. Etiam sociis euismod facilisi sem. Nisi volutpat feugiat neque adipiscing consectetur dui sed mauris risus quam tristique feugiat. Faucibus at porta facilisis cubilia nam. Etiam hendrerit at penatibus, ultrices porttitor erat augue ornare feugiat dictumst proin! Aliquet consectetur euismod.
Sed mauris risus quam tristique feugiat. Faucibus at porta facilisis cubilia nam. Etiam hendrerit at penatibus, ultrices porttitor erat augue ornare feugiat dictumst proin! Aliquet consectetur euismod.


',
  'post_title' => 'Perfect Lens App',
  'post_excerpt' => '',
  'post_name' => 'perfect-lens-app',
  'post_modified' => '2018-10-25 11:46:36',
  'post_modified_gmt' => '2018-10-25 11:46:36',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?post_type=portfolio&#038;p=48',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'project_date' => 'Oct 19, 2018',
    'project_client' => 'Photoview Inc',
    'project_services' => 'Apps, Web design',
    'project_launch' => 'https://themify.me/demo/themes/ultra-portfolio/project/perfect-lens-app/',
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"fac20c0\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"08cae48\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'photography',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/phothographer-lens.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 49,
  'post_date' => '2018-10-18 14:42:00',
  'post_date_gmt' => '2018-10-18 14:42:00',
  'post_content' => 'Donec accumsan ipsum nec dolor aliquam pretium. Suspendisse consequat condimentum augue, vel ultricies quam efficitur quis. Aenean sapien turpis, tincidunt congue risus ac, eleifend
condimentum nunc. Quisque porta nulla erat, et maximus nisi venenatis at. Aliquam arcu ante, sagittis eu rutrum a, efficitur eget nibh. In venenatis metus est, a sagittis turpis cursus quis. Fusce odio neque, placerat ut porttitor eget, congue vestibulum purus.',
  'post_title' => 'Work Space Design',
  'post_excerpt' => '',
  'post_name' => 'work-space-design',
  'post_modified' => '2018-10-25 11:47:42',
  'post_modified_gmt' => '2018-10-25 11:47:42',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?post_type=portfolio&#038;p=49',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'project_date' => 'Oct 18, 2018',
    'project_client' => 'Jigo Corp',
    'project_services' => 'Photography, Interior Design',
    'project_launch' => 'https://themify.me/demo/themes/ultra-portfolio/project/work-space-design/',
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"070455a\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"b91970a\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'interior-design',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/home-office.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 50,
  'post_date' => '2018-10-17 14:42:41',
  'post_date_gmt' => '2018-10-17 14:42:41',
  'post_content' => 'Praesent luctus, neque dictum feugiat maximus, sem enim maximus ipsum, sed ullamcorper est urna suscipit massa. Cras commodo eros nec eleifend vehicula. Praesent auctor augue in massa porta gravida. Nullam et ex eget diam mollis hendrerit id dignissim sem. Suspendisse viverra nibh a fringilla viverra.
Nulla facilisi. Praesent luctus, neque dictum feugiat maximus, sem enim maximus ipsum, sed ullamcorper est urna suscipit massa. Cras commodo eros nec eleifend vehicula. Praesent auctor augue in massa porta gravida. Nullam et ex eget diam mollis hendrerit id dignissim sem. Suspendisse viverra nibh a fringilla viverra.',
  'post_title' => 'Sunrise From The Peak',
  'post_excerpt' => '',
  'post_name' => 'sunrise-from-the-peak',
  'post_modified' => '2018-10-25 11:48:26',
  'post_modified_gmt' => '2018-10-25 11:48:26',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?post_type=portfolio&#038;p=50',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'project_date' => 'Oct 17, 2018',
    'project_client' => 'Hike and Fun',
    'project_services' => 'Photography, Digital Imaging',
    'project_launch' => 'https://themify.me/demo/themes/ultra-portfolio/project/sunrise-from-the-peak/',
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"9c5c2b4\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"2b90ab4\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'photography',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/girl-at-top-of-mountain.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 51,
  'post_date' => '2018-10-15 15:01:40',
  'post_date_gmt' => '2018-10-15 15:01:40',
  'post_content' => 'Vonec vitae volutpat erat. Donec non molestie lacus. Integer euismod leo euismod, fermentum tellus sed, consequat leo. Cras lobortis nisl non dapibus tempor. Donec a finibus tellus. Vivamus laoreet lacinia imperdiet. Fusce tincidunt metus ac sapien feugiat, sit amet laoreet lorem aliquam. Integer pharetra egestas mi vel aliquam.

Mauris pulvinar, massa eget semper imperdiet, sapien nisl vulputate mi, ut commodo mi erat et sapien. Interdum et malesuada fames ac ante ipsum primis in faucibus. Curabitur pellentesque augue nec nisl ultricies aliquet. Integer ipsum ante, interdum ac varius quis, ullamcorper vel ante. Donec eu mi vitae ex aliquam porttitor. Donec a mi in mauris finibus venenatis vitae at augue. Maecenas non pulvinar purus. Vestibulum posuere faucibus libero, eu dapibus magna mollis quis. Etiam non ante urna. Curabitur tincidunt ultrices sagittis. Donec quis felis leo. Cras ullamcorper, est eget convallis dapibus, diam lacus viverra magna, volutpat maximus lorem urna ac purus. Nam felis metus, eleifend ut fringilla a, sagittis nec mauris. In id congue justo.',
  'post_title' => 'F-Building Design',
  'post_excerpt' => '',
  'post_name' => 'f-building-design',
  'post_modified' => '2018-10-25 11:49:23',
  'post_modified_gmt' => '2018-10-25 11:49:23',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?post_type=portfolio&#038;p=51',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'project_date' => 'Oct 15, 2018',
    'project_client' => 'Krakatau Steel',
    'project_services' => 'Interior Design, Photography',
    'project_launch' => 'https://themify.me/demo/themes/ultra-portfolio/project/f-building-design/',
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"e45e41a\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"0c2affa\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'interior-design',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/Futuristic-building.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 54,
  'post_date' => '2018-10-14 15:02:44',
  'post_date_gmt' => '2018-10-14 15:02:44',
  'post_content' => 'Aliquam arcu ante, sagittis eu rutrum a, efficitur eget nibh. In venenatis metus est, a sagittis turpis cursus quis. Fusce odio neque, placerat ut porttitor eget, congue vestibulum purus. In pretium posuere elit sed lobortis. Praesent finibus ultrices augue, eget blandit mauris. Duis pulvinar, quam ut tristique euismod, velit turpis dignissim massa, et venenatis leo justo id urna.',
  'post_title' => 'Canoeing On The Lake',
  'post_excerpt' => '',
  'post_name' => 'canoeing-on-the-lake',
  'post_modified' => '2018-10-25 11:50:14',
  'post_modified_gmt' => '2018-10-25 11:50:14',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?post_type=portfolio&#038;p=54',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'project_date' => 'Oct 14, 2018',
    'project_client' => 'The adventurer',
    'project_services' => 'Photography, Digital Imaging',
    'project_launch' => 'https://themify.me/demo/themes/ultra-portfolio/project/canoeing-on-the-lake/',
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"66f5043\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"21d0b08\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'photography',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/canoing-at-lake.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 53,
  'post_date' => '2018-10-14 15:02:08',
  'post_date_gmt' => '2018-10-14 15:02:08',
  'post_content' => 'Mauris pulvinar, massa eget semper imperdiet, sapien nisl vulputate mi, ut commodo mi erat et sapien. Interdum et malesuada fames ac ante ipsum primis in faucibus. Curabitur pellentesque augue nec nisl ultricies aliquet. Integer ipsum ante, interdum ac varius quis, ullamcorper vel ante. Donec eu mi vitae ex aliquam porttitor. Donec a mi in mauris finibus venenatis vitae at augue. Maecenas non pulvinar purus. Vestibulum posuere faucibus libero, eu dapibus magna mollis quis. Etiam non ante urna. Curabitur tincidunt ultrices sagittis. Donec quis felis leo. Cras ullamcorper, est eget convallis dapibus, diam lacus viverra magna, volutpat maximus lorem urna ac purus. Nam felis metus, eleifend ut fringilla a, sagittis nec mauris. In id congue justo.
',
  'post_title' => 'D-House Interior Design',
  'post_excerpt' => '',
  'post_name' => 'd-house-interior-design',
  'post_modified' => '2018-10-25 11:50:51',
  'post_modified_gmt' => '2018-10-25 11:50:51',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?post_type=portfolio&#038;p=53',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'project_date' => 'Oct 14, 2018',
    'project_client' => 'Art Deco Inc',
    'project_services' => 'Interior Design, Photography',
    'project_launch' => 'https://themify.me/demo/themes/ultra-portfolio/project/d-house-interior-design/',
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"b557172\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"0a174a5\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'interior-design',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/cozy-place-design-interior.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 55,
  'post_date' => '2018-10-13 15:05:30',
  'post_date_gmt' => '2018-10-13 15:05:30',
  'post_content' => 'Aliquam arcu ante, sagittis eu rutrum a, efficitur eget nibh. In venenatis metus est, a sagittis turpis cursus quis. Fusce odio neque, placerat ut porttitor eget, congue vestibulum purus. In pretium posuere elit sed lobortis. Praesent finibus ultrices augue, eget blandit mauris. Duis pulvinar, quam ut tristique euismod, velit turpis dignissim massa, et venenatis leo justo id urna.
',
  'post_title' => 'Note App',
  'post_excerpt' => '',
  'post_name' => 'note-app',
  'post_modified' => '2018-10-25 11:51:44',
  'post_modified_gmt' => '2018-10-25 11:51:44',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?post_type=portfolio&#038;p=55',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'project_date' => 'Oct 13, 2018',
    'project_client' => 'Jigo Corp',
    'project_services' => 'Apps, Web design',
    'project_launch' => 'https://themify.me/demo/themes/ultra-portfolio/project/note-app/',
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"acef853\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"c7f425a\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'graphic-design',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/business-note.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 56,
  'post_date' => '2018-10-11 15:06:34',
  'post_date_gmt' => '2018-10-11 15:06:34',
  'post_content' => 'In pretium posuere elit sed lobortis. Praesent finibus ultrices augue, eget blandit mauris. Duis pulvinar, quam ut tristique euismod, velit turpis dignissim massa, et venenatis leo justo id urna. Aliquam arcu ante, sagittis eu rutrum a, efficitur eget nibh. In venenatis metus est, a sagittis turpis cursus quis. Fusce odio neque, placerat ut porttitor eget, congue vestibulum purus.

',
  'post_title' => 'Stationery Design',
  'post_excerpt' => '',
  'post_name' => 'stationery-design',
  'post_modified' => '2018-10-25 11:52:20',
  'post_modified_gmt' => '2018-10-25 11:52:20',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?post_type=portfolio&#038;p=56',
  'menu_order' => 0,
  'post_type' => 'portfolio',
  'meta_input' => 
  array (
    'project_date' => 'Oct 11, 2018',
    'project_client' => 'Warehouse LLC',
    'project_services' => 'Interior Design, Photography',
    'project_launch' => 'https://themify.me/demo/themes/ultra-portfolio/project/stationery-design/',
    '_themify_builder_settings_json' => '[{\\"element_id\\":\\"7de3281\\",\\"row_order\\":\\"0\\",\\"cols\\":[{\\"element_id\\":\\"b947708\\",\\"column_order\\":\\"0\\",\\"grid_class\\":\\"col-full\\"}]}]',
  ),
  'tax_input' => 
  array (
    'portfolio-category' => 'graphic-design',
  ),
  'thumb' => 'https://themify.me/demo/themes/ultra-portfolio/files/2018/10/businessmen-desk.jpg',
);
themify_process_post_import( $post );


$post = array (
  'ID' => 111,
  'post_date' => '2018-10-25 07:21:45',
  'post_date_gmt' => '2018-10-25 07:21:45',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '111',
  'post_modified' => '2018-10-25 07:21:49',
  'post_modified_gmt' => '2018-10-25 07:21:49',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=111',
  'menu_order' => 1,
  'post_type' => 'nav_menu_item',
  'xfn' => '',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '8',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home',
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 112,
  'post_date' => '2018-10-25 07:21:45',
  'post_date_gmt' => '2018-10-25 07:21:45',
  'post_content' => '',
  'post_title' => 'About',
  'post_excerpt' => '',
  'post_name' => 'about',
  'post_modified' => '2018-10-25 07:21:49',
  'post_modified_gmt' => '2018-10-25 07:21:49',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=112',
  'menu_order' => 2,
  'post_type' => 'nav_menu_item',
  'xfn' => '',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '112',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#about',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home',
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 113,
  'post_date' => '2018-10-25 07:21:45',
  'post_date_gmt' => '2018-10-25 07:21:45',
  'post_content' => '',
  'post_title' => 'Services',
  'post_excerpt' => '',
  'post_name' => 'services',
  'post_modified' => '2018-10-25 07:21:49',
  'post_modified_gmt' => '2018-10-25 07:21:49',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=113',
  'menu_order' => 3,
  'post_type' => 'nav_menu_item',
  'xfn' => '',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '113',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#services',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home',
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 114,
  'post_date' => '2018-10-25 07:21:45',
  'post_date_gmt' => '2018-10-25 07:21:45',
  'post_content' => '',
  'post_title' => 'Portfolio',
  'post_excerpt' => '',
  'post_name' => 'portfolio',
  'post_modified' => '2018-10-25 07:21:49',
  'post_modified_gmt' => '2018-10-25 07:21:49',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=114',
  'menu_order' => 4,
  'post_type' => 'nav_menu_item',
  'xfn' => '',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '114',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#portfolio',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home',
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 115,
  'post_date' => '2018-10-25 07:21:45',
  'post_date_gmt' => '2018-10-25 07:21:45',
  'post_content' => '',
  'post_title' => 'Contact',
  'post_excerpt' => '',
  'post_name' => 'contact',
  'post_modified' => '2018-10-25 07:21:49',
  'post_modified_gmt' => '2018-10-25 07:21:49',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=115',
  'menu_order' => 5,
  'post_type' => 'nav_menu_item',
  'xfn' => '',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '115',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => '#contact',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'home',
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 119,
  'post_date' => '2018-10-25 07:26:13',
  'post_date_gmt' => '2018-10-25 07:26:13',
  'post_content' => ' ',
  'post_title' => '',
  'post_excerpt' => '',
  'post_name' => '119',
  'post_modified' => '2018-10-26 00:58:13',
  'post_modified_gmt' => '2018-10-26 00:58:13',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=119',
  'menu_order' => 1,
  'post_type' => 'nav_menu_item',
  'xfn' => '',
  'meta_input' => 
  array (
    '_menu_item_type' => 'post_type',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '8',
    '_menu_item_object' => 'page',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-navigation',
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 116,
  'post_date' => '2018-10-25 07:26:13',
  'post_date_gmt' => '2018-10-25 07:26:13',
  'post_content' => '',
  'post_title' => 'About',
  'post_excerpt' => '',
  'post_name' => 'about-2',
  'post_modified' => '2018-10-26 00:58:13',
  'post_modified_gmt' => '2018-10-26 00:58:13',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=116',
  'menu_order' => 2,
  'post_type' => 'nav_menu_item',
  'xfn' => '',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '116',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => 'https://themify.me/demo/themes/ultra-portfolio/#about',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-navigation',
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 117,
  'post_date' => '2018-10-25 07:26:13',
  'post_date_gmt' => '2018-10-25 07:26:13',
  'post_content' => '',
  'post_title' => 'Services',
  'post_excerpt' => '',
  'post_name' => 'services-2',
  'post_modified' => '2018-10-26 00:58:13',
  'post_modified_gmt' => '2018-10-26 00:58:13',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=117',
  'menu_order' => 3,
  'post_type' => 'nav_menu_item',
  'xfn' => '',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '117',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => 'https://themify.me/demo/themes/ultra-portfolio/#services',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-navigation',
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 118,
  'post_date' => '2018-10-25 07:26:13',
  'post_date_gmt' => '2018-10-25 07:26:13',
  'post_content' => '',
  'post_title' => 'Portfolio',
  'post_excerpt' => '',
  'post_name' => 'portfolio-2',
  'post_modified' => '2018-10-26 00:58:13',
  'post_modified_gmt' => '2018-10-26 00:58:13',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=118',
  'menu_order' => 4,
  'post_type' => 'nav_menu_item',
  'xfn' => '',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '118',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => 'https://themify.me/demo/themes/ultra-portfolio/#portfolio',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-navigation',
  ),
);
themify_process_post_import( $post );


$post = array (
  'ID' => 120,
  'post_date' => '2018-10-25 07:26:13',
  'post_date_gmt' => '2018-10-25 07:26:13',
  'post_content' => '',
  'post_title' => 'Contact',
  'post_excerpt' => '',
  'post_name' => 'contact-2',
  'post_modified' => '2018-10-26 00:58:13',
  'post_modified_gmt' => '2018-10-26 00:58:13',
  'post_content_filtered' => '',
  'post_parent' => 0,
  'guid' => 'https://themify.me/demo/themes/ultra-portfolio/?p=120',
  'menu_order' => 5,
  'post_type' => 'nav_menu_item',
  'xfn' => '',
  'meta_input' => 
  array (
    '_menu_item_type' => 'custom',
    '_menu_item_menu_item_parent' => '0',
    '_menu_item_object_id' => '120',
    '_menu_item_object' => 'custom',
    '_menu_item_classes' => 
    array (
      0 => '',
    ),
    '_menu_item_url' => 'https://themify.me/demo/themes/ultra-portfolio/#contact',
  ),
  'tax_input' => 
  array (
    'nav_menu' => 'main-navigation',
  ),
);
themify_process_post_import( $post );



function themify_import_get_term_id_from_slug( $slug ) {
	$menu = get_term_by( "slug", $slug, "nav_menu" );
	return is_wp_error( $menu ) ? 0 : (int) $menu->term_id;
}

	$widgets = get_option( "widget_search" );
$widgets[1002] = array (
  'title' => '',
);
update_option( "widget_search", $widgets );

$widgets = get_option( "widget_recent-posts" );
$widgets[1003] = array (
  'title' => '',
  'number' => 5,
);
update_option( "widget_recent-posts", $widgets );

$widgets = get_option( "widget_recent-comments" );
$widgets[1004] = array (
  'title' => '',
  'number' => 5,
);
update_option( "widget_recent-comments", $widgets );

$widgets = get_option( "widget_archives" );
$widgets[1005] = array (
  'title' => '',
  'count' => 0,
  'dropdown' => 0,
);
update_option( "widget_archives", $widgets );

$widgets = get_option( "widget_categories" );
$widgets[1006] = array (
  'title' => '',
  'count' => 0,
  'hierarchical' => 0,
  'dropdown' => 0,
);
update_option( "widget_categories", $widgets );

$widgets = get_option( "widget_meta" );
$widgets[1007] = array (
  'title' => '',
);
update_option( "widget_meta", $widgets );

$widgets = get_option( "widget_themify-social-links" );
$widgets[1008] = array (
  'title' => '',
  'show_link_name' => NULL,
  'open_new_window' => NULL,
  'icon_size' => 'icon-medium',
  'orientation' => 'horizontal',
);
update_option( "widget_themify-social-links", $widgets );



$sidebars_widgets = array (
  'sidebar-main' => 
  array (
    0 => 'search-1002',
    1 => 'recent-posts-1003',
    2 => 'recent-comments-1004',
    3 => 'archives-1005',
    4 => 'categories-1006',
    5 => 'meta-1007',
  ),
  'social-widget' => 
  array (
    0 => 'themify-social-links-1008',
  ),
); 
update_option( "sidebars_widgets", $sidebars_widgets );

$menu_locations = array();
$menu = get_terms( "nav_menu", array( "slug" => "main-navigation" ) );
if( is_array( $menu ) && ! empty( $menu ) ) $menu_locations["main-nav"] = $menu[0]->term_id;
set_theme_mod( "nav_menu_locations", $menu_locations );


$homepage = get_posts( array( 'name' => 'home', 'post_type' => 'page' ) );
			if( is_array( $homepage ) && ! empty( $homepage ) ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $homepage[0]->ID );
			}
			
	ob_start(); ?>a:114:{s:21:"setting-webfonts_list";s:11:"recommended";s:22:"setting-default_layout";s:8:"sidebar1";s:27:"setting-default_post_layout";s:9:"list-post";s:19:"setting-post_filter";s:2:"no";s:23:"setting-disable_masonry";s:3:"yes";s:19:"setting-post_gutter";s:6:"gutter";s:30:"setting-default_layout_display";s:7:"content";s:25:"setting-default_more_text";s:4:"More";s:21:"setting-index_orderby";s:4:"date";s:19:"setting-index_order";s:4:"DESC";s:30:"setting-default_media_position";s:5:"above";s:31:"setting-image_post_feature_size";s:5:"blank";s:32:"setting-default_page_post_layout";s:8:"sidebar1";s:37:"setting-default_page_post_layout_type";s:7:"classic";s:42:"setting-default_page_single_media_position";s:5:"above";s:38:"setting-image_post_single_feature_size";s:5:"blank";s:36:"setting-search-result_layout_display";s:7:"content";s:36:"setting-search-result_media_position";s:5:"above";s:27:"setting-default_page_layout";s:8:"sidebar1";s:40:"setting-custom_post_tglobal_style_single";s:8:"sidebar1";s:38:"setting-default_portfolio_index_layout";s:12:"sidebar-none";s:43:"setting-default_portfolio_index_post_layout";s:5:"grid3";s:29:"setting-portfolio_post_filter";s:3:"yes";s:33:"setting-portfolio_disable_masonry";s:3:"yes";s:24:"setting-portfolio_gutter";s:6:"gutter";s:39:"setting-default_portfolio_index_display";s:4:"none";s:50:"setting-default_portfolio_index_post_meta_category";s:3:"yes";s:49:"setting-default_portfolio_index_unlink_post_image";s:3:"yes";s:39:"setting-default_portfolio_single_layout";s:12:"sidebar-none";s:54:"setting-default_portfolio_single_portfolio_layout_type";s:9:"fullwidth";s:50:"setting-default_portfolio_single_unlink_post_image";s:3:"yes";s:49:"setting-default_portfolio_single_image_post_width";s:4:"1400";s:50:"setting-default_portfolio_single_image_post_height";s:3:"600";s:22:"themify_portfolio_slug";s:7:"project";s:53:"setting-customizer_responsive_design_tablet_landscape";s:4:"1280";s:43:"setting-customizer_responsive_design_tablet";s:3:"768";s:43:"setting-customizer_responsive_design_mobile";s:3:"680";s:33:"setting-mobile_menu_trigger_point";s:3:"900";s:24:"setting-gallery_lightbox";s:8:"lightbox";s:27:"setting-page_builder_expiry";s:1:"2";s:21:"setting-header_design";s:14:"header-overlay";s:28:"setting-exclude_site_tagline";s:2:"on";s:27:"setting-exclude_search_form";s:2:"on";s:19:"setting_search_form";s:11:"live_search";s:19:"setting-exclude_rss";s:2:"on";s:22:"setting-header_widgets";s:17:"headerwidget-3col";s:21:"setting-footer_design";s:15:"footer-left-col";s:22:"setting-use_float_back";s:2:"on";s:24:"setting-revealing_footer";s:2:"on";s:22:"setting-footer_widgets";s:17:"footerwidget-3col";s:23:"setting-mega_menu_posts";s:1:"5";s:29:"setting-mega_menu_image_width";s:3:"180";s:30:"setting-mega_menu_image_height";s:3:"120";s:27:"setting-imagefilter_applyto";s:12:"featuredonly";s:29:"setting-color_animation_speed";s:1:"5";s:29:"setting-relationship_taxonomy";s:8:"category";s:37:"setting-relationship_taxonomy_entries";s:1:"3";s:45:"setting-relationship_taxonomy_display_content";s:4:"none";s:30:"setting-single_slider_autoplay";s:3:"off";s:27:"setting-single_slider_speed";s:6:"normal";s:28:"setting-single_slider_effect";s:5:"slide";s:28:"setting-single_slider_height";s:4:"auto";s:18:"setting-more_posts";s:8:"infinite";s:19:"setting-entries_nav";s:8:"numbered";s:25:"setting-img_php_base_size";s:5:"large";s:27:"setting-global_feature_size";s:5:"blank";s:22:"setting-link_icon_type";s:9:"font-icon";s:32:"setting-link_type_themify-link-0";s:10:"image-icon";s:33:"setting-link_title_themify-link-0";s:7:"Twitter";s:31:"setting-link_img_themify-link-0";s:109:"https://themify.me/demo/themes/ultra-portfolio/wp-content/themes/themify-ultra/themify/img/social/twitter.png";s:32:"setting-link_type_themify-link-1";s:10:"image-icon";s:33:"setting-link_title_themify-link-1";s:8:"Facebook";s:31:"setting-link_img_themify-link-1";s:110:"https://themify.me/demo/themes/ultra-portfolio/wp-content/themes/themify-ultra/themify/img/social/facebook.png";s:32:"setting-link_type_themify-link-2";s:10:"image-icon";s:33:"setting-link_title_themify-link-2";s:7:"Google+";s:31:"setting-link_img_themify-link-2";s:113:"https://themify.me/demo/themes/ultra-portfolio/wp-content/themes/themify-ultra/themify/img/social/google-plus.png";s:32:"setting-link_type_themify-link-3";s:10:"image-icon";s:33:"setting-link_title_themify-link-3";s:7:"YouTube";s:31:"setting-link_img_themify-link-3";s:109:"https://themify.me/demo/themes/ultra-portfolio/wp-content/themes/themify-ultra/themify/img/social/youtube.png";s:32:"setting-link_type_themify-link-4";s:10:"image-icon";s:33:"setting-link_title_themify-link-4";s:9:"Pinterest";s:31:"setting-link_img_themify-link-4";s:111:"https://themify.me/demo/themes/ultra-portfolio/wp-content/themes/themify-ultra/themify/img/social/pinterest.png";s:32:"setting-link_type_themify-link-6";s:9:"font-icon";s:33:"setting-link_title_themify-link-6";s:8:"Facebook";s:32:"setting-link_link_themify-link-6";s:28:"https://facebook.com/themify";s:33:"setting-link_ficon_themify-link-6";s:11:"fa-facebook";s:35:"setting-link_ficolor_themify-link-6";s:7:"#000000";s:32:"setting-link_type_themify-link-5";s:9:"font-icon";s:33:"setting-link_title_themify-link-5";s:7:"Twitter";s:32:"setting-link_link_themify-link-5";s:27:"https://twitter.com/themify";s:33:"setting-link_ficon_themify-link-5";s:10:"fa-twitter";s:35:"setting-link_ficolor_themify-link-5";s:7:"#000000";s:32:"setting-link_type_themify-link-8";s:9:"font-icon";s:33:"setting-link_title_themify-link-8";s:7:"YouTube";s:32:"setting-link_link_themify-link-8";s:38:"https://www.youtube.com/user/themifyme";s:33:"setting-link_ficon_themify-link-8";s:10:"fa-youtube";s:35:"setting-link_ficolor_themify-link-8";s:7:"#000000";s:32:"setting-link_type_themify-link-7";s:9:"font-icon";s:33:"setting-link_title_themify-link-7";s:7:"Google+";s:32:"setting-link_link_themify-link-7";s:45:"https://plus.google.com/109280316400365629341";s:33:"setting-link_ficon_themify-link-7";s:14:"fa-google-plus";s:35:"setting-link_ficolor_themify-link-7";s:7:"#000000";s:32:"setting-link_type_themify-link-9";s:9:"font-icon";s:33:"setting-link_title_themify-link-9";s:9:"Pinterest";s:33:"setting-link_ficon_themify-link-9";s:12:"fa-pinterest";s:22:"setting-link_field_ids";s:341:"{"themify-link-0":"themify-link-0","themify-link-1":"themify-link-1","themify-link-2":"themify-link-2","themify-link-3":"themify-link-3","themify-link-4":"themify-link-4","themify-link-6":"themify-link-6","themify-link-5":"themify-link-5","themify-link-8":"themify-link-8","themify-link-7":"themify-link-7","themify-link-9":"themify-link-9"}";s:23:"setting-link_field_hash";s:2:"10";s:30:"setting-page_builder_is_active";s:6:"enable";s:41:"setting-page_builder_animation_appearance";s:4:"none";s:42:"setting-page_builder_animation_parallax_bg";s:4:"none";s:46:"setting-page_builder_animation_parallax_scroll";s:6:"mobile";s:44:"setting-page_builder_animation_sticky_scroll";s:4:"none";s:4:"skin";s:9:"portfolio";s:13:"import_images";s:2:"on";}<?php $themify_data = unserialize( ob_get_clean() );

	// Fix how "skin" option used to save
	if( isset( $themify_data['skin'] ) ) {
		if ( filter_var( $themify_data['skin'], FILTER_VALIDATE_URL ) ) {
			$parsed_skin = parse_url( $value, PHP_URL_PATH );
			$themify_data['skin'] = basename( dirname( $parsed_skin ) );
		}
	}

	themify_set_data( $themify_data );
	
}
themify_do_demo_import();