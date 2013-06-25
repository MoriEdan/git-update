<?php
/*
Plugin Name: Git Updates
Plugin URI: https://github.com/kasparsd/git-update
GitHub URI: https://github.com/kasparsd/git-update
Description: Provides automatic updates for themes and plugins hosted at GitHub.
Author: Kaspars Dambis
Version: 1.2.5
*/


new GitUpdate;


class GitUpdate {

	static $instance;

	private $git_uris = array( 
		'github' => array(
			'header' => 'GitHub URI'
		) 
	);


	function GitUpdate() {

		// Use GitUpdate::$instance to interact with me
		self::$instance = $this;

		add_filter( 'extra_theme_headers', array( $this, 'enable_gitupdate_headers' ) );
		add_filter( 'extra_plugin_headers', array( $this, 'enable_gitupdate_headers' ) );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'update_check_plugins' ) );
		add_filter( 'pre_set_site_transient_update_themes', array( $this, 'update_check_themes' ) );

		add_action( 'core_upgrade_preamble', array( $this, 'show_gitupdate_log' ) );

	}


	/**
	 * Enable custom update URI headers in theme and plugin headers
	 * @param  array $headers Other custom headers
	 * @return array          Our custom headers
	 */
	function enable_gitupdate_headers( $headers ) {

		foreach ( $this->git_uris as $uri )
			if ( ! in_array( $uri['header'], $headers ) )
				$headers[] = $uri['header'];

		return $headers;

	}


	/**
	 * Check for theme updates
	 * @param  object $updates Update transient
	 * @return object          Modified transient with update responses from our APIs
	 */
	function update_check_themes( $updates ) {

		// Run only after WP has done its own API check
		if ( empty( $updates->checked ) )
			return $updates;

		return $this->update_check( $updates, $this->get_themes() );

	}


	/**
	 * Check for plugin updates
	 * @param  object $updates Update transient
	 * @return object          Modified transient with update responses from our APIs
	 */
	function update_check_plugins( $updates ) {

		// Run only after WP has done its own API check
		if ( empty( $updates->checked ) )
			return $updates;

		return $this->update_check( $updates, get_plugins() );
	
	}


	/**
	 * Check for updates from external APIs
	 * @param  object $updates    Update transient from WP core
	 * @param  array $extensions  Available plugins or themes
	 * @return object             Updated transient with information from our APIs
	 */
	function update_check( $updates, $extensions ) {

		$to_check = array();

		// Run only once an hour. This shouldn't be necessary but some other plugins might call us.
		//if ( ! isset( $updates->last_checked ) || ( time() - $updates->last_checked ) < 60 ) // HOUR_IN_SECONDS
		//	return $updates;

		// Filter out plugins/themes with known headers
		foreach ( $extensions as $item => $item_details )
			foreach ( $this->git_uris as $uri )
				if ( ! empty( $item_details[ $uri['header'] ] ) )
					$to_check[ $item ] = $item_details;

		if ( empty( $to_check ) )
			return $updates;

		foreach ( $to_check as $item => $item_details ) {
			$api_response = wp_remote_get( 
					sprintf( 
						'%s/tags', 
						str_replace( '//github.com/', '//api.github.com/repos/', rtrim( $item_details['GitHub URI'], '/' ) ) 
					) 
				);

			// Check if API responded correctly. Log as error, if not.
			if ( is_wp_error( $api_response ) || wp_remote_retrieve_response_code( $api_response ) != 200 ) {

				$logs = (array) get_site_option( 'git-update-response-error', array() );
				
				// Log only 10 latest error messages
				array_splice( $logs, 20 );

				// Prepend current error message on top
				array_unshift(
						$logs,
						array(
							'item' =>  $item,
							'time' => time(), 
							'response' => $api_response
						)
					);

				update_site_option( 'git-update-response-error', $logs );
				
				continue;
			}

			$response_json = json_decode( wp_remote_retrieve_body( $api_response ), true );

			// Make sure this repo has any tags
			if ( empty( $response_json ) || ! is_array( $response_json ) )
				continue;

			foreach ( $response_json as $tag )
				if ( version_compare( $tag['name'], $item_details['Version'], '>' ) )
					$updates->response[ $item ] = (object) array(
							'new_version' => $tag['name'],
							'slug' => dirname( $item ),
							'package' => $tag['zipball_url'],
							'url' => $item_details['PluginURI']
						);
		}
		
		return $updates;
	}


	/**
	 * Make this return the same structure as get_plugins()
	 * @return array Available themes and their meta data
	 */
	function get_themes() {
		$themes = array();

		$theme_headers = array(
			'Name'        => 'Theme Name',
			'ThemeURI'    => 'Theme URI',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'Version'     => 'Version',
			'Template'    => 'Template',
			'Status'      => 'Status',
			'Tags'        => 'Tags',
			'TextDomain'  => 'Text Domain',
			'DomainPath'  => 'Domain Path',
		);

		$extra_theme_headers = apply_filters( 'extra_theme_headers', array() );

		// Make keys and values equal
		$extra_theme_headers = array_combine( $extra_theme_headers, $extra_theme_headers );

		// Merge default headers with extra headers
		$theme_headers = apply_filters( 
				'extra_theme_headers', 
				array_merge( $theme_headers, $extra_theme_headers )
			);

		$themes_available = wp_get_themes();

		foreach ( $themes_available as $theme )
			foreach ( $theme_headers as $header_slug => $header_label )
				$themes[ $theme->get_template() ][ $header_slug ] = $theme->get( $header_slug );

		return $themes;
	}


	function show_gitupdate_log() {

		$log_rows = array();
		$log = get_site_option( 'git-update-response-error', array() );

		if ( empty( $log ) || ! is_array( $log ) )
			return;

		foreach ( $log as $log_item )
			$log_rows[] = sprintf( 
					'<tr>
						<td><strong>%s</strong><br/>%s</td>
						<td><pre>%s</pre></td>
					</tr>',
					$log_item['item'],
					date( 'r', $log_item['time'] ),
					print_r( $log_item['response']['body'], true )
				);

		if ( empty( $log_rows ) )
			$log_rows[] = sprintf( 
					'<tr>
						<td colspan="2">%s</td>
					</tr>',
					__( 'No logs found.', 'git-update' )
				);

		printf( 
			'<h3>%s</h3>
			<table class="widefat">
				%s
			</table>',
			__( 'Git Update Error Logs', 'git-update' ),
			implode( '', $log_rows ) 
		);

	}

}

