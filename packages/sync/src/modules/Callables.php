<?php
/**
 * Callables sync module.
 *
 * @package automattic/jetpack-sync
 */

namespace Automattic\Jetpack\Sync\Modules;

use Automattic\Jetpack\Sync\Functions;
use Automattic\Jetpack\Sync\Defaults;
use Automattic\Jetpack\Sync\Settings;

/**
 * Class to handle sync for callables.
 */
class Callables extends Module {
	/**
	 * Name of the callables checksum option.
	 *
	 * @var string
	 */
	const CALLABLES_CHECKSUM_OPTION_NAME = 'jetpack_callables_sync_checksum';

	/**
	 * Name of the transient for locking callables.
	 *
	 * @var string
	 */
	const CALLABLES_AWAIT_TRANSIENT_NAME = 'jetpack_sync_callables_await';

	/**
	 * Whitelist for callables we want to sync.
	 *
	 * @access private
	 *
	 * @var array
	 */
	private $callable_whitelist;

	/**
	 * For some options, we should always send the change right away!
	 *
	 * @access public
	 *
	 * @var array
	 */
	const ALWAYS_SEND_UPDATES_TO_THESE_OPTIONS = array(
		'jetpack_active_modules',
		'home', // option is home, callable is home_url.
		'siteurl',
		'jetpack_sync_error_idc',
		'paused_plugins',
		'paused_themes',
	);

	/**
	 * For some options, the callable key differs from the option name/key
	 *
	 * @access public
	 *
	 * @var array
	 */
	const OPTION_NAMES_TO_CALLABLE_NAMES = array(
		// @TODO: Audit the other option names for differences between the option names and callable names.
		'home' => 'home_url',
	);

	/**
	 * Sync module name.
	 *
	 * @access public
	 *
	 * @return string
	 */
	public function name() {
		return 'functions';
	}

	/**
	 * Set module defaults.
	 * Define the callable whitelist based on whether this is a single site or a multisite installation.
	 *
	 * @access public
	 */
	public function set_defaults() {
		if ( is_multisite() ) {
			$this->callable_whitelist = array_merge( Defaults::get_callable_whitelist(), Defaults::get_multisite_callable_whitelist() );
		} else {
			$this->callable_whitelist = Defaults::get_callable_whitelist();
		}
	}

	/**
	 * Initialize callables action listeners.
	 *
	 * @access public
	 *
	 * @param callable $callable Action handler callable.
	 */
	public function init_listeners( $callable ) {
		add_action( 'jetpack_sync_callable', $callable, 10, 2 );
		add_action( 'current_screen', array( $this, 'set_plugin_action_links' ), 9999 ); // Should happen very late.

		foreach ( self::ALWAYS_SEND_UPDATES_TO_THESE_OPTIONS as $option ) {
			add_action( "update_option_{$option}", array( $this, 'unlock_sync_callable' ) );
			add_action( "delete_option_{$option}", array( $this, 'unlock_sync_callable' ) );
		}

		// Provide a hook so that hosts can send changes to certain callables right away.
		// Especially useful when a host uses constants to change home and siteurl.
		add_action( 'jetpack_sync_unlock_sync_callable', array( $this, 'unlock_sync_callable' ) );

		// get_plugins and wp_version
		// gets fired when new code gets installed, updates etc.
		add_action( 'upgrader_process_complete', array( $this, 'unlock_plugin_action_link_and_callables' ) );
		add_action( 'update_option_active_plugins', array( $this, 'unlock_plugin_action_link_and_callables' ) );
	}

	/**
	 * Initialize callables action listeners for full sync.
	 *
	 * @access public
	 *
	 * @param callable $callable Action handler callable.
	 */
	public function init_full_sync_listeners( $callable ) {
		add_action( 'jetpack_full_sync_callables', $callable );
	}

	/**
	 * Initialize the module in the sender.
	 *
	 * @access public
	 */
	public function init_before_send() {
		add_action( 'jetpack_sync_before_send_queue_sync', array( $this, 'maybe_sync_callables' ) );

		// Full sync.
		add_filter( 'jetpack_sync_before_send_jetpack_full_sync_callables', array( $this, 'expand_callables' ) );
	}

	/**
	 * Perform module cleanup.
	 * Deletes any transients and options that this module uses.
	 * Usually triggered when uninstalling the plugin.
	 *
	 * @access public
	 */
	public function reset_data() {
		delete_option( self::CALLABLES_CHECKSUM_OPTION_NAME );
		delete_transient( self::CALLABLES_AWAIT_TRANSIENT_NAME );

		$url_callables = array( 'home_url', 'site_url', 'main_network_site_url' );
		foreach ( $url_callables as $callable ) {
			delete_option( Functions::HTTPS_CHECK_OPTION_PREFIX . $callable );
		}
	}

	/**
	 * Set the callable whitelist.
	 *
	 * @access public
	 *
	 * @param array $callables The new callables whitelist.
	 */
	public function set_callable_whitelist( $callables ) {
		$this->callable_whitelist = $callables;
	}

	/**
	 * Get the callable whitelist.
	 *
	 * @access public
	 *
	 * @return array The callables whitelist.
	 */
	public function get_callable_whitelist() {
		return $this->callable_whitelist;
	}

	/**
	 * Retrieve all callables as per the current callables whitelist.
	 *
	 * @access public
	 *
	 * @return array All callables.
	 */
	public function get_all_callables() {
		// get_all_callables should run as the master user always.
		$current_user_id = get_current_user_id();
		wp_set_current_user( \Jetpack_Options::get_option( 'master_user' ) );
		$callables = array_combine(
			array_keys( $this->get_callable_whitelist() ),
			array_map( array( $this, 'get_callable' ), array_values( $this->get_callable_whitelist() ) )
		);
		wp_set_current_user( $current_user_id );
		return $callables;
	}

	/**
	 * Invoke a particular callable.
	 * Used as a wrapper to standartize invocation.
	 *
	 * @access private
	 *
	 * @param callable $callable Callable to invoke.
	 * @return mixed Return value of the callable.
	 */
	private function get_callable( $callable ) {
		return call_user_func( $callable );
	}

	/**
	 * Enqueue the callable actions for full sync.
	 *
	 * @access public
	 *
	 * @param array   $config               Full sync configuration for this sync module.
	 * @param int     $max_items_to_enqueue Maximum number of items to enqueue.
	 * @param boolean $state                True if full sync has finished enqueueing this module, false otherwise.
	 * @return array Number of actions enqueued, and next module state.
	 */
	public function enqueue_full_sync_actions( $config, $max_items_to_enqueue, $state ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		/**
		 * Tells the client to sync all callables to the server
		 *
		 * @since 4.2.0
		 *
		 * @param boolean Whether to expand callables (should always be true)
		 */
		do_action( 'jetpack_full_sync_callables', true );

		// The number of actions enqueued, and next module state (true == done).
		return array( 1, true );
	}

	/**
	 * Retrieve an estimated number of actions that will be enqueued.
	 *
	 * @access public
	 *
	 * @param array $config Full sync configuration for this sync module.
	 * @return array Number of items yet to be enqueued.
	 */
	public function estimate_full_sync_actions( $config ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return 1;
	}

	/**
	 * Retrieve the actions that will be sent for this module during a full sync.
	 *
	 * @access public
	 *
	 * @return array Full sync actions of this module.
	 */
	public function get_full_sync_actions() {
		return array( 'jetpack_full_sync_callables' );
	}

	/**
	 * Unlock callables so they would be available for syncing again.
	 *
	 * @access public
	 */
	public function unlock_sync_callable() {
		delete_transient( self::CALLABLES_AWAIT_TRANSIENT_NAME );
	}

	/**
	 * Unlock callables and plugin action links.
	 *
	 * @access public
	 */
	public function unlock_plugin_action_link_and_callables() {
		delete_transient( self::CALLABLES_AWAIT_TRANSIENT_NAME );
		delete_transient( 'jetpack_plugin_api_action_links_refresh' );
		add_filter( 'jetpack_check_and_send_callables', '__return_true' );
	}

	/**
	 * Parse and store the plugin action links if on the plugins page.
	 *
	 * @uses \DOMDocument
	 * @uses libxml_use_internal_errors
	 * @uses mb_convert_encoding
	 *
	 * @access public
	 */
	public function set_plugin_action_links() {
		if (
			! class_exists( '\DOMDocument' ) ||
			! function_exists( 'libxml_use_internal_errors' ) ||
			! function_exists( 'mb_convert_encoding' )
		) {
			return;
		}

		$current_screeen = get_current_screen();

		$plugins_action_links = array();
		// Is the transient lock in place?
		$plugins_lock = get_transient( 'jetpack_plugin_api_action_links_refresh', false );
		if ( ! empty( $plugins_lock ) && ( isset( $current_screeen->id ) && 'plugins' !== $current_screeen->id ) ) {
			return;
		}
		$plugins = array_keys( Functions::get_plugins() );
		foreach ( $plugins as $plugin_file ) {
			/**
			 *  Plugins often like to unset things but things break if they are not able to.
			 */
			$action_links = array(
				'deactivate' => '',
				'activate'   => '',
				'details'    => '',
				'delete'     => '',
				'edit'       => '',
			);
			/** This filter is documented in src/wp-admin/includes/class-wp-plugins-list-table.php */
			$action_links = apply_filters( 'plugin_action_links', $action_links, $plugin_file, null, 'all' );
			/** This filter is documented in src/wp-admin/includes/class-wp-plugins-list-table.php */
			$action_links           = apply_filters( "plugin_action_links_{$plugin_file}", $action_links, $plugin_file, null, 'all' );
			$action_links           = array_filter( $action_links );
			$formatted_action_links = null;
			if ( ! empty( $action_links ) && count( $action_links ) > 0 ) {
				$dom_doc = new \DOMDocument();
				foreach ( $action_links as $action_link ) {
					// The @ is not enough to suppress errors when dealing with libxml,
					// we have to tell it directly how we want to handle errors.
					libxml_use_internal_errors( true );
					$dom_doc->loadHTML( mb_convert_encoding( $action_link, 'HTML-ENTITIES', 'UTF-8' ) );
					libxml_use_internal_errors( false );

					$link_elements = $dom_doc->getElementsByTagName( 'a' );
					if ( 0 === $link_elements->length ) {
						continue;
					}

					$link_element = $link_elements->item( 0 );
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( $link_element->hasAttribute( 'href' ) && $link_element->nodeValue ) {
						$link_url = trim( $link_element->getAttribute( 'href' ) );

						// Add the full admin path to the url if the plugin did not provide it.
						$link_url_scheme = wp_parse_url( $link_url, PHP_URL_SCHEME );
						if ( empty( $link_url_scheme ) ) {
							$link_url = admin_url( $link_url );
						}

						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$formatted_action_links[ $link_element->nodeValue ] = $link_url;
					}
				}
			}
			if ( $formatted_action_links ) {
				$plugins_action_links[ $plugin_file ] = $formatted_action_links;
			}
		}
		// Cache things for a long time.
		set_transient( 'jetpack_plugin_api_action_links_refresh', time(), DAY_IN_SECONDS );
		update_option( 'jetpack_plugin_api_action_links', $plugins_action_links );
	}

	/**
	 * Whether a certain callable should be sent.
	 *
	 * @access public
	 *
	 * @param array  $callable_checksums Callable checksums.
	 * @param string $name               Name of the callable.
	 * @param string $checksum           A checksum of the callable.
	 * @return boolean Whether to send the callable.
	 */
	public function should_send_callable( $callable_checksums, $name, $checksum ) {
		$idc_override_callables = array(
			'main_network_site',
			'home_url',
			'site_url',
		);
		if ( in_array( $name, $idc_override_callables, true ) && \Jetpack_Options::get_option( 'migrate_for_idc' ) ) {
			return true;
		}

		return ! $this->still_valid_checksum( $callable_checksums, $name, $checksum );
	}

	/**
	 * Sync the callables if we're supposed to.
	 *
	 * @access public
	 */
	public function maybe_sync_callables() {

		$callables = $this->get_all_callables();
		if ( ! apply_filters( 'jetpack_check_and_send_callables', false ) ) {
			if ( ! is_admin() ) {
				// If we're not an admin and we're not doing cron, don't sync anything.
				if ( ! Settings::is_doing_cron() ) {
					return;
				}
				// If we're not an admin and we are doing cron, sync the Callables that are always supposed to sync ( See https://github.com/Automattic/jetpack/issues/12924 ).
				$callables = $this->get_always_sent_callables();
			}
			if ( get_transient( self::CALLABLES_AWAIT_TRANSIENT_NAME ) ) {
				return;
			}
		}
		
		if ( empty( $callables ) ) {
			return;
		}

		set_transient( self::CALLABLES_AWAIT_TRANSIENT_NAME, microtime( true ), Defaults::$default_sync_callables_wait_time );

		$callable_checksums = (array) \Jetpack_Options::get_raw_option( self::CALLABLES_CHECKSUM_OPTION_NAME, array() );
		$has_changed        = false;
		// Only send the callables that have changed.
		foreach ( $callables as $name => $value ) {
			$checksum = $this->get_check_sum( $value );
			// Explicitly not using Identical comparison as get_option returns a string.
			if ( ! is_null( $value ) && $this->should_send_callable( $callable_checksums, $name, $checksum ) ) {
				/**
				 * Tells the client to sync a callable (aka function) to the server
				 *
				 * @since 4.2.0
				 *
				 * @param string The name of the callable
				 * @param mixed The value of the callable
				 */
				do_action( 'jetpack_sync_callable', $name, $value );
				$callable_checksums[ $name ] = $checksum;
				$has_changed                 = true;
			} else {
				$callable_checksums[ $name ] = $checksum;
			}
		}
		if ( $has_changed ) {
			\Jetpack_Options::update_raw_option( self::CALLABLES_CHECKSUM_OPTION_NAME, $callable_checksums );
		}

	}

	/**
	 * Get the callables that should always be sent, e.g. on cron.
	 *
	 * @return array Callables that should always be sent
	 */
	protected function get_always_sent_callables() {
		$callables      = $this->get_all_callables();
		$cron_callables = array();
		foreach ( self::ALWAYS_SEND_UPDATES_TO_THESE_OPTIONS as $option_name ) {
			if ( array_key_exists( $option_name, $callables ) ) {
				$cron_callables[ $option_name ] = $callables[ $option_name ];
				continue;
			}

			// Check for the Callable name/key for the option, if different from option name.
			if ( array_key_exists( $option_name, self::OPTION_NAMES_TO_CALLABLE_NAMES ) ) {
				$callable_name = self::OPTION_NAMES_TO_CALLABLE_NAMES[ $option_name ];
				if ( array_key_exists( $callable_name, $callables ) ) {
					$cron_callables[ $callable_name ] = $callables[ $callable_name ];
				}
			}
		}
		return $cron_callables;
	}

	/**
	 * Expand the callables within a hook before they are serialized and sent to the server.
	 *
	 * @access public
	 *
	 * @param array $args The hook parameters.
	 * @return array $args The hook parameters.
	 */
	public function expand_callables( $args ) {
		if ( $args[0] ) {
			$callables           = $this->get_all_callables();
			$callables_checksums = array();
			foreach ( $callables as $name => $value ) {
				$callables_checksums[ $name ] = $this->get_check_sum( $value );
			}
			\Jetpack_Options::update_raw_option( self::CALLABLES_CHECKSUM_OPTION_NAME, $callables_checksums );
			return $callables;
		}

		return $args;
	}
}
