<?php

/**
 * Handles log entries by writing to database.
 *
 * @class          WPF_Log_Handler
 */

class WPF_Log_Handler {

	/**
	 * Log Levels
	 *
	 * Description of levels:.
	 *     'error': Error conditions.
	 *     'warning': Warning conditions.
	 *     'notice': Normal but significant condition.
	 *     'info': Informational messages.
	 *
	 * @see @link {https://tools.ietf.org/html/rfc5424}
	 */
	const ERROR     = 'error';
	const WARNING   = 'warning';
	const NOTICE    = 'notice';
	const INFO      = 'info';

	/**
	 * Level strings mapped to integer severity.
	 *
	 * @var array
	 */
	protected static $level_to_severity = array(
		self::ERROR     => 500,
		self::WARNING   => 400,
		self::NOTICE    => 300,
		self::INFO      => 200
	);

	/**
	 * Severity integers mapped to level strings.
	 *
	 * This is the inverse of $level_severity.
	 *
	 * @var array
	 */
	protected static $severity_to_level = array(
		500 => self::ERROR,
		400 => self::WARNING,
		300 => self::NOTICE,
		200 => self::INFO
	);

	/**
	 * Constructor for the logger.
	 */
	public function __construct() {

		add_action( 'init', array( $this, 'init' ) );

	}

	/**
	 * Prepares logging functionalty if enabled
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		if( wp_fusion()->settings->get('enable_logging') != true ) {
			return;
		}

		add_filter( 'wpf_configure_sections', array( $this, 'configure_sections' ), 10, 2 );

		add_action( 'admin_menu', array($this, 'register_logger_subpage'));
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		$this->create_update_table();

	}

	/**
	 * Adds standalone log management page
	 *
	 * @access public
	 * @return void
	 */
 
	public function register_logger_subpage() {

	    add_submenu_page( 
	        'options-general.php',
	        'WP Fusion Activity Logs',
	        'WP Fusion Logs',
	        'manage_options',
	        'wpf-settings-logs',
	        array( $this, 'show_logs_section') );

	}

	/**
	 * Enqueues logger styles
	 *
	 * @access public
	 * @return void
	 */

	public function enqueue_scripts() {

		$screen = get_current_screen();

		if($screen->id != 'settings_page_wpf-settings-logs')
			return;

		wp_enqueue_style( 'wpf-options', WPF_DIR_URL . 'assets/css/wpf-options.css', array(), WP_FUSION_VERSION );
		wp_enqueue_style( 'wpf-admin', WPF_DIR_URL . 'assets/css/wpf-admin.css', array(), WP_FUSION_VERSION );


	}

	/**
	 * Adds logging tab to main settings for access
	 *
	 * @access public
	 * @return array Page
	 */

	public function configure_sections( $page, $options ) {

		$page['sections'] = wp_fusion()->settings->insert_setting_after( 'advanced', $page['sections'], array( 'logs' => array('title' => __( 'Logs', 'wp-fusion' ), 'slug' => 'wpf-settings-logs' ) ) );

		return $page;

	}

	/**
	 * Creates logging table if logging enabled
	 *
	 * @access public
	 * @return void
	 */

	public function create_update_table() {

		global $wpdb;
		$table_name = $wpdb->prefix . 'wpf_logging';

		if( $wpdb->get_var("show tables like '$table_name'") != $table_name) {

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

			$collate = '';

			if ( $wpdb->has_cap( 'collation' ) ) {
				$collate = $wpdb->get_charset_collate();
			}

			$sql = "CREATE TABLE " . $table_name . " (
				log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				timestamp datetime NOT NULL,
				level smallint(4) NOT NULL,
				user int(8) NOT NULL,
				source varchar(200) NOT NULL,
				message longtext NOT NULL,
				context longtext NULL,
				PRIMARY KEY (log_id),
				KEY level (level)
			) $collate;";

			dbDelta( $sql );

		}

	}

	/**
	 * Logging tab content
	 *
	 * @access public
	 * @return void
	 */

	public function show_logs_section() {

		include_once( WPF_DIR_PATH . 'includes/admin/logging/class-log-table-list.php' );

		// Flush
		if ( ! empty( $_REQUEST['flush-logs'] ) ) {
			self::flush();
		}

		// Bulk actions
		if ( isset( $_REQUEST['action'] ) && isset( $_REQUEST['log'] ) ) {
			self::log_table_bulk_actions();
		}

		$log_table_list = new WPF_Log_Table_List();
		$log_table_list->prepare_items(); 

		// Stop _wp_http_referer getting appended to the logs URL, so it doesn't get too long
		add_filter( 'removable_query_args', function( $query_args ) {

			$query_args[] = '_wp_http_referer';
			return $query_args;

		} );

		?>

		<div class="wrap">
	        <h1><?php _e( 'WP Fusion Activity Log', 'wp-fusion' ); ?></h1>

			<form method="get" id="mainform">

				<input type="hidden" name="page" value="wpf-settings-logs">

				<?php $log_table_list->display(); ?>

				<?php submit_button( __( 'Flush all logs', 'wp-fusion' ), 'delete', 'flush-logs' ); ?>
				<?php wp_nonce_field( 'wp-fusion-status-logs' ); ?>

			</form>
		</div>

		<?php

	}


	/**
	 * Validate a level string.
	 *
	 * @param string $level
	 * @return bool True if $level is a valid level.
	 */
	public static function is_valid_level( $level ) {
		return array_key_exists( strtolower( $level ), self::$level_to_severity );
	}

	/**
	 * Translate level string to integer.
	 *
	 * @param string $level emergency|alert|critical|error|warning|notice|info|debug
	 * @return int 100 (debug) - 800 (emergency) or 0 if not recognized
	 */
	public static function get_level_severity( $level ) {
		if ( self::is_valid_level( $level ) ) {
			$severity = self::$level_to_severity[ strtolower( $level ) ];
		} else {
			$severity = 0;
		}
		return $severity;
	}

	/**
	 * Translate severity integer to level string.
	 *
	 * @param int $severity
	 * @return bool|string False if not recognized. Otherwise string representation of level.
	 */
	public static function get_severity_level( $severity ) {
		if ( array_key_exists( $severity, self::$severity_to_level ) ) {
			return self::$severity_to_level[ $severity ];
		} else {
			return false;
		}
	}

	/**
	 * Handle a log entry.
	 *
	 * @param int $timestamp Log timestamp.
	 * @param string $level emergency|alert|critical|error|warning|notice|info|debug
	 * @param string $message Log message.
	 * @param array $context {
	 *     Additional information for log handlers.
	 *
	 *     @type string $source Optional. Source will be available in log table.
	 *                  If no source is provided, attempt to provide sensible default.
	 * }
	 *
	 * @see WPF_Log_Handler::get_log_source() for default source.
	 *
	 * @return bool False if value was not handled and true if value was handled.
	 */
	public function handle( $level, $user, $message, $context = array() ) {

		if( wp_fusion()->settings->get('enable_logging') != true ) {
			return;
		}

		if( wp_fusion()->settings->get('logging_errors_only') == true && $level != 'error' ) {
			return;
		}

		$timestamp = current_time( 'timestamp' );

		if ( isset( $context['source'] ) && $context['source'] ) {
			$source = $context['source'];
		} else {
			$source = $this->get_log_source();
		}

		// Filter out irrelevant meta fields
		if ( isset( $context['meta_array'] ) && $context['meta_array'] ) {

			$contact_fields = wp_fusion()->settings->get('contact_fields');

			foreach( $context['meta_array'] as $key => $data ) {

				if( !isset( $contact_fields[$key] ) || $contact_fields[$key]['active'] == false ) {
					unset($context['meta_array'][$key]);
				}

			}

		}

		// Don't log meta data pushes where no enabled fields are being synced
		if( isset( $context['meta_array'] ) && empty( $context['meta_array'] ) ) {
			return;
		}

		do_action( 'wpf_log_handled', $timestamp, $level, $user, $message, $source, $context );

		return $this->add( $timestamp, $level, $user, $message, $source, $context );
	}

	/**
	 * Add a log entry to chosen file.
	 *
	 * @param string $level emergency|alert|critical|error|warning|notice|info|debug
	 * @param string $message Log message.
	 * @param string $source Log source. Useful for filtering and sorting.
	 * @param array $context {
	 *     Context will be serialized and stored in database.
	 * }
	 *
	 * @return bool True if write was successful.
	 */
	protected static function add( $timestamp, $level, $user, $message, $source, $context ) {
		global $wpdb;

		$insert = array(
			'timestamp' => date( 'Y-m-d H:i:s', $timestamp ),
			'level' => self::get_level_severity( $level ),
			'user'	=> $user,
			'message' => $message,
			'source' => $source,
		);

		$format = array(
			'%s',
			'%d',
			'%d',
			'%s',
			'%s',
			'%s', // possible serialized context
		);

		if ( ! empty( $context ) ) {
			$insert['context'] = serialize( $context );
		}

		$result = $wpdb->insert( "{$wpdb->prefix}wpf_logging", $insert, $format );

		if( $result === false ) {
			return false;
		}

		$rowcount = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpf_logging");

		if( $rowcount > 10000 ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}wpf_logging ORDER BY log_id ASC LIMIT 1" );
		}

		return $result;
		
	}

	/**
	 * Clear all logs from the DB.
	 *
	 * @return bool True if flush was successful.
	 */
	public static function flush() {
		global $wpdb;

		return $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wpf_logging" );
	}

	/**
	 * Bulk DB log table actions.
	 *
	 * @since 3.0.0
	 */
	private function log_table_bulk_actions() {

		if ( empty( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'wp-fusion-status-logs' ) ) {
			wp_die( __( 'Action failed. Please refresh the page and retry.', 'wp-fusion' ) );
		}

		$log_ids = array_map( 'absint', (array) $_REQUEST['log'] );

		if ( 'delete' === $_REQUEST['action'] || 'delete' === $_REQUEST['action2'] ) {
			self::delete( $log_ids );
		}
	}

	/**
	 * Delete selected logs from DB.
	 *
	 * @param int|string|array Log ID or array of Log IDs to be deleted.
	 *
	 * @return bool
	 */
	public static function delete( $log_ids ) {
		global $wpdb;

		if ( ! is_array( $log_ids ) ) {
			$log_ids = array( $log_ids );
		}

		$format = array_fill( 0, count( $log_ids ), '%d' );

		$query_in = '(' . implode( ',', $format ) . ')';

		$query = $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}wpf_logging WHERE log_id IN {$query_in}",
			$log_ids
		);

		return $wpdb->query( $query );
	}


	/**
	 * Get appropriate source based on file name.
	 *
	 * Try to provide an appropriate source in case none is provided.
	 *
	 * @return string Text to use as log source. "" (empty string) if none is found.
	 */

	protected static function get_log_source() {

		static $ignore_files = array( 'class-log-handler' );

		/**
		 * PHP < 5.3.6 correct behavior
		 * @see http://php.net/manual/en/function.debug-backtrace.php#refsect1-function.debug-backtrace-parameters
		 */

		if ( defined( 'DEBUG_BACKTRACE_IGNORE_ARGS' ) ) {
			$debug_backtrace_arg = DEBUG_BACKTRACE_IGNORE_ARGS;
		} else {
			$debug_backtrace_arg = false;
		}

		$full_trace = debug_backtrace( $debug_backtrace_arg );

		$slugs = array( 'user-profile', 'class-api', 'access-control', 'class-auto-login', 'class-ajax' );

		foreach ( wp_fusion()->get_integrations() as $slug => $integration ) {
			$slugs[] = $slug;
		}

		$found_integrations = array();

		foreach ( $full_trace as $i => $trace ) {

			if ( isset( $trace['file'] ) ) {

				foreach ( $slugs as $slug ) {

					if ( empty( $slug ) ) {
						continue;
					}

					if ( strpos( $trace['file'], $slug ) !== false) {

						$found_integrations[] = $slug;
					}
				}
			}
		}

		// Figure out most likely integration

		if ( ! empty( $found_integrations ) ) {

			$source = serialize( array_reverse( array_unique( $found_integrations ) ) );

		} else {
			$source = 'unknown';
		}

		return $source;
	}


}