<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the
 * installation. You don't have to use the web site, you can
 * copy this file to "wp-config.php" and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://codex.wordpress.org/Editing_wp-config.php
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'rideordieart' );

/** MySQL database username */
define( 'DB_USER', 'root' );

/** MySQL database password */
define( 'DB_PASSWORD', '' );

/** MySQL hostname */
define( 'DB_HOST', 'localhost' );

/** Database Charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The Database Collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '^zn#Cy=GdCN$je!e7U 6mn$[)v[c.&)nKO0qm7qdW9X@SK)K`8z>1+U^VBuwYr.R' );
define( 'SECURE_AUTH_KEY',  'Dt#4{F8b5G8R@u:q|yoBkLtWFA|D{+q(CJ2?SUAbq874@w~p$sJ(QkH|&>n0/plc' );
define( 'LOGGED_IN_KEY',    '-)|`o /hoymhX;UMx|bsfme;3{biUj&9Bazo6sH5|OhhAX|s+wpTG(klSkha<+d<' );
define( 'NONCE_KEY',        '.<0QSnNI^U%#5Qr(aY?|p5r^d Zp4Jac*x8w+*d_mpyY}f@AAkB[A$Kd-iQ?h32H' );
define( 'AUTH_SALT',        '#z>og^ W!WDZ=K.V`izx{Vm,zy1[741,9;e|d+N-J9k+./;fu3;-xDJiHb+S}OjC' );
define( 'SECURE_AUTH_SALT', ';?gb{wOnM}WJx*^s^Y2.nCt.HU-8U!4I`>@%#O00=pIL(fsN808=*hj6?-;c9<o4' );
define( 'LOGGED_IN_SALT',   '45CVGn;=U_!7u5XM)l@V~e)B,Rdr?*:)o%iRkyS_lI~gY~(O9@>S5@<K:9B--c}y' );
define( 'NONCE_SALT',       'UAMwohd/s%PF}@q?,29B4*v!g)t{Rn;G0 YCM;~<aC((|bx(h~(/Oz5X;,cX!8vd' );

/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the Codex.
 *
 * @link https://codex.wordpress.org/Debugging_in_WordPress
 */
define( 'WP_DEBUG', false );

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __FILE__ ) . '/' );
}

/** Sets up WordPress vars and included files. */
require_once( ABSPATH . 'wp-settings.php' );
