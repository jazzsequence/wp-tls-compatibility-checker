<?php
/**
 * TLS Compatibility Checker Admin Page
 * 
 * @package Pantheon_TLS_Compatibility_Checker
 */

namespace Pantheon\TLSChecker\Admin;

/**
 * Bootstrap the admin page.
 */
function bootstrap() {
	add_action( 'admin_menu', __NAMESPACE__ . '\\add_menu_page' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_scripts' );
	add_action( 'wp_ajax_pantheon_tls_checker_scan', __NAMESPACE__ . '\\handle_ajax_scan' );
}

/**
 * Enqueue admin scripts and styles.
 */
function enqueue_scripts() {
	$screen = get_current_screen();

	// Only load the css on our admin page.
	if ( $screen && $screen->base === 'tools_page_tls-compatibility-checker' ) {
		wp_enqueue_style( 'tls-compatibility-admin', TLS_CHECKER_ASSETS . 'admin.css', [], TLS_CHECKER_VERSION, 'screen' );
		wp_enqueue_script( 'tls-compatibility-scan', TLS_CHECKER_ASSETS . 'scan.js', [], TLS_CHECKER_VERSION, true );
		wp_localize_script( 'tls-compatibility-scan', 'tlsCheckerAjax', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'pantheon_tls_checker_scan_action' ),
		] );
	}
}

/**
 * Add the admin menu page.
 */
function add_menu_page() {
	add_submenu_page(
		'tools.php',
		__( 'TLS Compatibility Checker', 'wp-tls-compatibility-checker' ),
		__( 'TLS Compatibility', 'wp-tls-compatibility-checker' ),
		'manage_options',
		'tls-compatibility-checker',
		__NAMESPACE__ . '\\render_page'
	);
}

/**
 * Render the admin page.
 */
function render_page() {
	if ( isset( $_POST['pantheon_tls_checker_reset'] ) ) {
		check_admin_referer( 'pantheon_tls_checker_reset_action' );
		pantheon_tls_checker_reset_urls();
		add_action( 'admin_notices', __NAMESPACE__ . '\\reset_successful_notice' );
	}

	// Get passing and failing URLs.
	$failing_urls = pantheon_tls_checker_get_failing_urls();
	$passing_urls = pantheon_tls_checker_get_passing_urls();
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<div id="pantheon-tls-alert-container"></div>
		<p><?php esc_html_e( 'Check your codebase for outgoing connections to servers that support TLS 1.2/1.3', 'wp-tls-compatibility-checker' ); ?></p>
		<div class="failing-urls">
			<p>
				<?php echo wp_kses_post( 'The following URLs were found in your codebase that do <em>not</em> support TLS connections of 1.2 or higher.', 'wp-tls-compatibility-checker' ); ?>
			</p>
			<pre class="card">
<?php // phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect
	if ( empty( $failing_urls ) && empty( $passing_urls ) ) {
		// If both $failing_urls and $passing_urls are empty, we've probably never run the scan or we reset the data.
		esc_html_e( 'No URLs failing TLS 1.2/1.3 connections found. Try running a scan.', 'wp-tls-compatibility-checker' ); 
	} elseif ( empty( $failing_urls ) && ! empty( $passing_urls ) ) {
		// If $failing_urls is empty but $passing_urls is not, all URLs have passed.
		esc_html_e( 'All outgoing HTTP/HTTPS connections found are compatible with TLS 1.2/1.3.', 'wp-tls-compatibility-checker' );
	} else {
		// Loop through and display the failing URLs.
		foreach ( $failing_urls as $url ) {
			echo esc_url( $url ) . "\n";
		} 
	}
	?>
			</pre>
		</div>
		<div class="tls-scan">
			<h2><?php esc_html_e( 'Scan your site for outgoing TLS connections', 'wp-tls-compatibility-checker' ); ?></h2>

			<p>
				<?php esc_html_e( 'You can check your site for HTTP/HTTPS connections by using WP-CLI (see details below) or in the dashboard with the "Scan site for TLS 1.2/1.3 compatibility" button.', 'wp-tls-compatibility-checker' ); ?>
				<br />
				<?php
				echo wp_kses_post( sprintf( 
					'<a href="%1$s">%2$s</a>',
					'https://www.cloudflare.com/learning/ssl/transport-layer-security-tls/',
					__( 'Learn more about TLS.', 'wp-tls-compatibility-checker' ) 
				) );
				?>
			</p>
			<div class="tls-compatibility-actions">
				<form method="post">
					<?php wp_nonce_field( 'pantheon_tls_checker_reset_action' ); ?>
					<button type="submit" name="pantheon_tls_checker_reset" class="button button-secondary">
						<?php esc_html_e( 'Reset TLS Compatibility Data', 'wp-tls-compatibility-checker' ); ?>
					</button>
				</form>
				<form method="post">
					<?php wp_nonce_field( 'pantheon_tls_checker_scan_action' ); ?>
					<button type="submit" name="pantheon_tls_checker_scan" id="pantheon-tls-scan" class="button button-primary">
						<?php esc_html_e( 'Scan site for TLS 1.2/1.3 compatibility', 'wp-tls-compatibility-checker' ); ?>
					</button>
				</form>
			</div>
			<div id="pantheon-tls-scan-status"></div>
			<p class="description">
				<?php esc_html_e( 'Use the "Reset TLS Compatibility Data" button below to remove stored data from previous scans. This is not required and should only be done if you wish to re-run a scan from scratch. Subsequent scans will automatically skip checking any URLs that have already been tested and passed.', 'wp-tls-compatibility-checker' ); ?>
			</p>
		</div>
	</div>
	<?php
}

/**
 * Run an AJAX scan of the site for TLS compatibility.
 */
function handle_ajax_scan() {
	check_ajax_referer( 'pantheon_tls_checker_scan_action', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Unauthorized request', 'wp-tls-compatibility-checker' ) );
	}

	$batch_size = 10; // Process 10 URLs per batch.
	$offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
	$urls = pantheon_tls_checker_extract_urls( WP_CONTENT_DIR );
	$total_urls = count( $urls );
	$remaining_urls = max( 0, $total_urls - $offset );

	// Process only a batch.
	$batch = array_slice( $urls, $offset, $batch_size );
	$results = pantheon_tls_checker_scan( $batch );

	wp_send_json_success( [
		'progress' => $offset + count( $batch ),
		'total' => $total_urls,
		'remaining' => $remaining_urls,
		'batch_size' => $batch_size,
		'passing' => count( $results['passing'] ),
		'failing' => count( $results['failing'] ),
		'failing_urls' => $results['failing'],
	] );
}

// Kick it off.
bootstrap();
