<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Checks GitHub Releases for a newer tag and wires the result into WordPress's
 * native plugin-update UI. Supports a private repo via a fine-grained PAT:
 * the API request needs an Authorization header, and the zip must be
 * downloaded via the asset's API url (not browser_download_url), which also
 * requires the auth header — browser_download_url needs a logged-in session
 * and returns HTML instead of a zip for a private repo.
 */
class TR_GitHub_Updater {
	const RELEASE_TRANSIENT = 'tangnest_robotics_latest_release';

	private string $plugin_file;
	private string $plugin_basename;
	private string $github_user;
	private string $github_repo;
	private string $github_token;
	private string $version;

	public function __construct(
		string $plugin_file,
		string $github_user,
		string $github_repo,
		string $github_token = ''
	) {
		$this->plugin_file    = $plugin_file;
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->github_user    = $github_user;
		$this->github_repo    = $github_repo;
		$this->github_token   = $github_token;

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data          = get_plugin_data( $plugin_file, false, false );
		$this->version = $data['Version'] ?? '0.0.0';

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_folder_name' ], 10, 4 );
		add_filter( 'http_request_args', [ $this, 'authorize_asset_download' ], 10, 2 );
	}

	private function get_latest_release() {
		$cached = get_site_transient( self::RELEASE_TRANSIENT );
		if ( false !== $cached ) {
			return $cached;
		}

		$url     = "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest";
		$headers = [
			'User-Agent' => 'Tangnest-Robotics-Updater/' . $this->version,
		];
		if ( '' !== $this->github_token ) {
			$headers['Authorization'] = 'token ' . $this->github_token;
		}

		$response = wp_remote_get( $url, [
			'timeout' => 10,
			'headers' => $headers,
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! is_object( $release ) ) {
			return false;
		}

		set_site_transient( self::RELEASE_TRANSIENT, $release, 6 * HOUR_IN_SECONDS );

		return $release;
	}

	private function find_zip_asset( $release ) {
		if ( empty( $release->assets ) ) {
			return null;
		}
		foreach ( $release->assets as $asset ) {
			if ( isset( $asset->name ) && substr( $asset->name, -4 ) === '.zip' ) {
				return $asset;
			}
		}
		return null;
	}

	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( false === $release || empty( $release->tag_name ) ) {
			return $transient;
		}

		$remote_version = ltrim( $release->tag_name, 'v' );
		if ( ! version_compare( $remote_version, $this->version, '>' ) ) {
			return $transient;
		}

		$asset = $this->find_zip_asset( $release );
		if ( null === $asset ) {
			return $transient;
		}

		$package = ( '' !== $this->github_token )
			? $asset->url
			: ( $asset->browser_download_url ?? '' );

		if ( '' === $package ) {
			return $transient;
		}

		$item = (object) [
			'id'          => "{$this->github_user}/{$this->github_repo}",
			'slug'        => dirname( $this->plugin_basename ),
			'plugin'      => $this->plugin_basename,
			'new_version' => $remote_version,
			'url'         => "https://github.com/{$this->github_user}/{$this->github_repo}",
			'package'     => $package,
			'tested'      => get_bloginfo( 'version' ),
		];

		$transient->response[ $this->plugin_basename ] = $item;

		return $transient;
	}

	public function plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== dirname( $this->plugin_basename ) ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( false === $release ) {
			return $result;
		}

		$asset   = $this->find_zip_asset( $release );
		$package = null;
		if ( null !== $asset ) {
			$package = ( '' !== $this->github_token ) ? $asset->url : ( $asset->browser_download_url ?? null );
		}

		return (object) [
			'name'          => 'Tangnest Robotics — Class & Payment Manager',
			'slug'          => dirname( $this->plugin_basename ),
			'version'       => ltrim( $release->tag_name ?? '', 'v' ),
			'author'        => '<a href="https://frisoft.rw">Fri Soft Ltd</a>',
			'homepage'      => "https://github.com/{$this->github_user}/{$this->github_repo}",
			'sections'      => [
				'description' => wpautop( $release->body ?? '' ),
			],
			'download_link' => $package,
		];
	}

	public function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}

		$desired_slug = dirname( $this->plugin_basename );
		$desired_path = trailingslashit( $remote_source ) . $desired_slug;

		if ( trailingslashit( $source ) === trailingslashit( $desired_path ) ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $desired_path, true ) ) {
			return trailingslashit( $desired_path );
		}

		return $source;
	}

	public function authorize_asset_download( array $args, string $url ): array {
		if ( '' === $this->github_token ) {
			return $args;
		}
		if ( strpos( $url, "api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/assets/" ) === false ) {
			return $args;
		}
		$args['headers']['Authorization'] = 'token ' . $this->github_token;
		$args['headers']['Accept']        = 'application/octet-stream';
		return $args;
	}
}
