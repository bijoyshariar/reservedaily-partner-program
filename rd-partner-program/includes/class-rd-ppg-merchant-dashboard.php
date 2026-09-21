<?php
/**
 * ReserveDaily Partner Program by Shariar Bijoy.
 *
 * @author Shariar Bijoy
 * @link https://shariarbijoy.dev
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RD_PPG_Merchant_Dashboard {

	const ENDPOINT = 'partner-dashboard';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_endpoint' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_items' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_non_partners' ) );
	}

	/**
	 * The menu tab is already hidden for non-linked users; this catches direct
	 * visits to the endpoint URL and sends them to the normal account page
	 * instead of a dead-end message.
	 */
	public static function redirect_non_partners() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::ENDPOINT ) ) {
			return;
		}
		if ( is_user_logged_in() && RD_PPG_Partner::for_dashboard_user( get_current_user_id() ) ) {
			return;
		}
		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
		exit;
	}

	public static function register_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * One time rewrite flush per plugin version so the endpoint resolves
	 * without a manual permalink save after updates.
	 */
	public static function maybe_flush_rewrite() {
		if ( get_option( 'rd_ppg_endpoint_version' ) !== RD_PPG_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'rd_ppg_endpoint_version', RD_PPG_VERSION );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	public static function menu_items( $items ) {
		if ( ! is_user_logged_in() || ! RD_PPG_Partner::for_dashboard_user( get_current_user_id() ) ) {
			return $items;
		}
		$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
		unset( $items['customer-logout'] );
		$items[ self::ENDPOINT ] = __( 'Partner Dashboard', 'rd-partner-program' );
		if ( $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	public static function assets() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		if ( ! is_user_logged_in() || ! RD_PPG_Partner::for_dashboard_user( get_current_user_id() ) ) {
			return;
		}
		$qr = RD_PPG_DIR . 'assets/qrcode.min.js';
		if ( file_exists( $qr ) ) {
			wp_enqueue_script( 'rd-ppg-qrcode', plugins_url( 'assets/qrcode.min.js', RD_PPG_FILE ), array(), RD_PPG_VERSION, false );
		}
	}

	private static function range_days() {
		$allowed = array( 7, 30, 90, 0 );
		$range   = isset( $_GET['rdrange'] ) ? absint( $_GET['rdrange'] ) : 0;
		return in_array( $range, $allowed, true ) ? $range : 0;
	}

	public static function render() {
		$partner = RD_PPG_Partner::for_dashboard_user( get_current_user_id() );
		if ( ! $partner ) {
			echo '<p>' . esc_html__( 'Your account is not linked to a partner profile. Contact the store team if you believe this is a mistake.', 'rd-partner-program' ) . '</p>';
			return;
		}

		$range = self::range_days();
		$from  = $range > 0 ? gmdate( 'Y-m-d', current_time( 'timestamp' ) - $range * DAY_IN_SECONDS ) : '';

		$visit_rows    = RD_PPG_Stats::totals_by_code( $partner['id'], $from, '' );
		$purchase_rows = RD_PPG_Commission::purchases_by_code( $partner['id'], $from );

		$codes = array( $partner['parent_code'] => __( 'Main link', 'rd-partner-program' ) );
		foreach ( $partner['coach_codes'] as $coach ) {
			$codes[ strtoupper( $coach['code'] ) ] = $coach['label'];
		}

		$stats = array();
		foreach ( array_keys( $codes ) as $code ) {
			$stats[ $code ] = array( 'visits' => 0, 'signups' => 0, 'purchases' => 0 );
		}
		foreach ( $visit_rows as $row ) {
			if ( ! isset( $stats[ $row->code ] ) ) {
				$stats[ $row->code ] = array( 'visits' => 0, 'signups' => 0, 'purchases' => 0 );
			}
			$stats[ $row->code ]['visits']  = (int) $row->visits;
			$stats[ $row->code ]['signups'] = (int) $row->signups;
		}
		foreach ( $purchase_rows as $row ) {
			if ( ! isset( $stats[ $row->code ] ) ) {
				$stats[ $row->code ] = array( 'visits' => 0, 'signups' => 0, 'purchases' => 0 );
			}
			$stats[ $row->code ]['purchases'] = (int) $row->purchases;
		}

		$totals = array( 'visits' => 0, 'signups' => 0, 'purchases' => 0 );
		foreach ( $stats as $line ) {
			$totals['visits']    += $line['visits'];
			$totals['signups']   += $line['signups'];
			$totals['purchases'] += $line['purchases'];
		}

		$dashboard_url = wc_get_account_endpoint_url( self::ENDPOINT );
		$has_qr        = wp_script_is( 'rd-ppg-qrcode', 'enqueued' ) || wp_script_is( 'rd-ppg-qrcode', 'done' );
		?>
		<div class="rd-ppg-dash">
			<style>
				.rd-ppg-dash h3 { margin-top: 28px; border-left: 4px solid #085E83; padding-left: 10px; }
				.rd-ppg-dash table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
				.rd-ppg-dash th, .rd-ppg-dash td { padding: 8px 10px; border-bottom: 1px solid #e5e5e5; text-align: left; }
				.rd-ppg-dash .rd-ppg-cards { display: flex; gap: 12px; flex-wrap: wrap; margin: 12px 0; }
				.rd-ppg-dash .rd-ppg-card { border: 1px solid #e5e5e5; border-left: 4px solid #085E83; padding: 10px 16px; min-width: 120px; }
				.rd-ppg-dash .rd-ppg-card strong { display: block; font-size: 20px; color: #085E83; }
				.rd-ppg-dash .rd-ppg-copy, .rd-ppg-dash .rd-ppg-qr-btn { cursor: pointer; margin-left: 6px; }
				.rd-ppg-dash .rd-ppg-link { word-break: break-all; font-family: monospace; font-size: 13px; }
				.rd-ppg-dash .rd-ppg-qr-box { margin: 10px 0; }
				.rd-ppg-dash .rd-ppg-badge { display: inline-block; background: #085E83; color: #fff; border-radius: 3px; padding: 1px 8px; font-size: 12px; }
			</style>

			<h3><?php esc_html_e( 'Your program', 'rd-partner-program' ); ?></h3>
			<?php if ( ! $partner['active'] ) : ?>
				<p><strong><?php esc_html_e( 'Your program is currently paused. Links keep working but rewards and tracking resume when the program is reactivated.', 'rd-partner-program' ); ?></strong></p>
			<?php endif; ?>
			<?php
			$deal_end_ts = RD_PPG_Partner::deal_end_ts( $partner );
			if ( $deal_end_ts && time() > $deal_end_ts ) : ?>
				<p><strong><?php esc_html_e( 'Your partnership period has ended. New signups and commission are no longer recorded. Contact the store team about renewing.', 'rd-partner-program' ); ?></strong></p>
			<?php endif; ?>
			<div class="rd-ppg-cards">
				<div class="rd-ppg-card"><strong><?php echo esc_html( $partner['points_multiplier'] . 'x' ); ?></strong><?php esc_html_e( 'Points multiplier for your customers', 'rd-partner-program' ); ?></div>
				<div class="rd-ppg-card"><strong><?php echo esc_html( number_format_i18n( $partner['signup_bonus'] ) ); ?></strong><?php esc_html_e( 'Signup bonus points', 'rd-partner-program' ); ?></div>
				<div class="rd-ppg-card"><strong><?php echo esc_html( $partner['commission_rate'] . '%' ); ?></strong><?php esc_html_e( 'Your commission rate', 'rd-partner-program' ); ?></div>
				<div class="rd-ppg-card"><strong><?php echo esc_html( $partner['commission_order_cap'] > 0 ? $partner['commission_order_cap'] : __( 'Unlimited', 'rd-partner-program' ) ); ?></strong><?php esc_html_e( 'Commissionable orders per customer', 'rd-partner-program' ); ?></div>
				<div class="rd-ppg-card"><strong><?php echo esc_html( $partner['window_months'] ); ?></strong><?php esc_html_e( 'Customer window, months', 'rd-partner-program' ); ?></div>
				<?php if ( ! empty( $partner['deal_end'] ) ) : ?>
					<div class="rd-ppg-card"><strong><?php echo esc_html( date_i18n( get_option( 'date_format' ), RD_PPG_Partner::deal_end_ts( $partner ) ) ); ?></strong><?php esc_html_e( 'Deal ends', 'rd-partner-program' ); ?></div>
				<?php endif; ?>
			</div>

			<h3><?php esc_html_e( 'Your referral links', 'rd-partner-program' ); ?></h3>
			<p><?php esc_html_e( 'Share these links anywhere. Customers who click and sign up are linked to you automatically. They can also type your code at signup or checkout.', 'rd-partner-program' ); ?></p>
			<table>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Code', 'rd-partner-program' ); ?></th>
						<th><?php esc_html_e( 'Link', 'rd-partner-program' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$link_base = ! empty( $partner['landing_url'] ) ? $partner['landing_url'] : home_url( '/' );
					foreach ( $codes as $code => $label ) :
						$url = add_query_arg( 'ref', $code, $link_base );
						$dom_id = 'rd-ppg-qr-' . sanitize_html_class( strtolower( $code ) );
						?>
						<tr>
							<td><span class="rd-ppg-badge"><?php echo esc_html( $code ); ?></span><br /><small><?php echo esc_html( $label ); ?></small></td>
							<td>
								<span class="rd-ppg-link" id="<?php echo esc_attr( $dom_id ); ?>-text"><?php echo esc_html( $url ); ?></span>
								<?php if ( $has_qr ) : ?>
									<div class="rd-ppg-qr-box" id="<?php echo esc_attr( $dom_id ); ?>" data-url="<?php echo esc_attr( $url ); ?>" style="display:none;"></div>
								<?php endif; ?>
							</td>
							<td>
								<button type="button" class="button rd-ppg-copy" data-target="<?php echo esc_attr( $dom_id ); ?>-text"><?php esc_html_e( 'Copy', 'rd-partner-program' ); ?></button>
								<?php if ( $has_qr ) : ?>
									<button type="button" class="button rd-ppg-qr-btn" data-target="<?php echo esc_attr( $dom_id ); ?>"><?php esc_html_e( 'QR', 'rd-partner-program' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Performance', 'rd-partner-program' ); ?></h3>
			<form method="get" action="<?php echo esc_url( $dashboard_url ); ?>">
				<label>
					<?php esc_html_e( 'Period', 'rd-partner-program' ); ?>
					<select name="rdrange" onchange="this.form.submit()">
						<option value="0" <?php selected( $range, 0 ); ?>><?php esc_html_e( 'All time', 'rd-partner-program' ); ?></option>
						<option value="7" <?php selected( $range, 7 ); ?>><?php esc_html_e( 'Last 7 days', 'rd-partner-program' ); ?></option>
						<option value="30" <?php selected( $range, 30 ); ?>><?php esc_html_e( 'Last 30 days', 'rd-partner-program' ); ?></option>
						<option value="90" <?php selected( $range, 90 ); ?>><?php esc_html_e( 'Last 90 days', 'rd-partner-program' ); ?></option>
					</select>
				</label>
			</form>
			<div class="rd-ppg-cards">
				<div class="rd-ppg-card"><strong><?php echo esc_html( number_format_i18n( $totals['visits'] ) ); ?></strong><?php esc_html_e( 'Link visits', 'rd-partner-program' ); ?></div>
				<div class="rd-ppg-card"><strong><?php echo esc_html( number_format_i18n( $totals['signups'] ) ); ?></strong><?php esc_html_e( 'Signups', 'rd-partner-program' ); ?></div>
				<div class="rd-ppg-card"><strong><?php echo esc_html( number_format_i18n( $totals['purchases'] ) ); ?></strong><?php esc_html_e( 'Purchases', 'rd-partner-program' ); ?></div>
			</div>
			<table>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Code', 'rd-partner-program' ); ?></th>
						<th><?php esc_html_e( 'Visits', 'rd-partner-program' ); ?></th>
						<th><?php esc_html_e( 'Signups', 'rd-partner-program' ); ?></th>
						<th><?php esc_html_e( 'Purchases', 'rd-partner-program' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $stats as $code => $line ) : ?>
						<tr>
							<td><span class="rd-ppg-badge"><?php echo esc_html( $code ); ?></span> <small><?php echo esc_html( isset( $codes[ $code ] ) ? $codes[ $code ] : '' ); ?></small></td>
							<td><?php echo esc_html( number_format_i18n( $line['visits'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $line['signups'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $line['purchases'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><small><?php esc_html_e( 'Visits count real people landing on your links, bots are filtered. Purchases count completed orders by your referred customers, refunded orders are removed.', 'rd-partner-program' ); ?></small></p>

			<h3><?php esc_html_e( 'Eligible items for your customers', 'rd-partner-program' ); ?></h3>
			<?php if ( empty( $partner['eligible_categories'] ) && empty( $partner['eligible_products'] ) ) : ?>
				<p><?php esc_html_e( 'No item pools are configured. Your customers earn multiplied points on everything they buy.', 'rd-partner-program' ); ?></p>
			<?php else : ?>
				<table>
					<thead>
						<tr>
							<th><?php esc_html_e( 'Item', 'rd-partner-program' ); ?></th>
							<th><?php esc_html_e( 'Type', 'rd-partner-program' ); ?></th>
							<th><?php esc_html_e( 'Customer benefit', 'rd-partner-program' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $partner['eligible_categories'] as $row ) :
							$term = isset( $row['term_id'] ) ? get_term( absint( $row['term_id'] ), 'product_cat' ) : null;
							if ( ! $term || is_wp_error( $term ) ) {
								continue;
							}
							$rate = isset( $row['rate'] ) ? (float) $row['rate'] : 0;
							?>
							<tr>
								<td><?php echo esc_html( $term->name ); ?></td>
								<td><?php esc_html_e( 'Category', 'rd-partner-program' ); ?></td>
								<td><?php echo esc_html( $rate > 0 ? sprintf( __( '%s%% automatic discount', 'rd-partner-program' ), $rate ) : __( 'Program eligible', 'rd-partner-program' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php foreach ( $partner['eligible_products'] as $row ) :
							$product = isset( $row['product_id'] ) ? wc_get_product( absint( $row['product_id'] ) ) : null;
							if ( ! $product ) {
								continue;
							}
							$rate = isset( $row['rate'] ) ? (float) $row['rate'] : 0;
							?>
							<tr>
								<td><?php echo esc_html( $product->get_name() ); ?></td>
								<td><?php esc_html_e( 'Product', 'rd-partner-program' ); ?></td>
								<td><?php echo esc_html( $rate > 0 ? sprintf( __( '%s%% automatic discount', 'rd-partner-program' ), $rate ) : __( 'Program eligible', 'rd-partner-program' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( ! empty( $partner['excluded_products'] ) ) :
					$excluded_names = array();
					foreach ( $partner['excluded_products'] as $product_id ) {
						$product = wc_get_product( $product_id );
						if ( $product ) {
							$excluded_names[] = $product->get_name();
						}
					}
					if ( $excluded_names ) : ?>
						<p><small><?php echo esc_html( sprintf( __( 'Always full price: %s', 'rd-partner-program' ), implode( ', ', $excluded_names ) ) ); ?></small></p>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<script>
		(function() {
			document.querySelectorAll( '.rd-ppg-copy' ).forEach( function( btn ) {
				btn.addEventListener( 'click', function() {
					var el = document.getElementById( btn.getAttribute( 'data-target' ) );
					if ( ! el ) { return; }
					var text = el.textContent.trim();
					var done = function() {
						var old = btn.textContent;
						btn.textContent = <?php echo wp_json_encode( __( 'Copied', 'rd-partner-program' ) ); ?>;
						setTimeout( function() { btn.textContent = old; }, 1500 );
					};
					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( text ).then( done );
					} else {
						var ta = document.createElement( 'textarea' );
						ta.value = text;
						document.body.appendChild( ta );
						ta.select();
						document.execCommand( 'copy' );
						document.body.removeChild( ta );
						done();
					}
				} );
			} );
			document.querySelectorAll( '.rd-ppg-qr-btn' ).forEach( function( btn ) {
				btn.addEventListener( 'click', function() {
					var box = document.getElementById( btn.getAttribute( 'data-target' ) );
					if ( ! box || typeof QRCode === 'undefined' ) { return; }
					if ( ! box.dataset.rendered ) {
						new QRCode( box, { text: box.getAttribute( 'data-url' ), width: 160, height: 160 } );
						box.dataset.rendered = '1';
					}
					box.style.display = box.style.display === 'none' ? 'block' : 'none';
				} );
			} );
		})();
		</script>
		<?php
	}
}
