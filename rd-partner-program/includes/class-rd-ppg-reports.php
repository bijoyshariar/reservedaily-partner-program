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

class RD_PPG_Reports {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 60 );
		add_action( 'admin_post_rd_ppg_entry_action', array( __CLASS__, 'handle_entry_action' ) );
		add_action( 'admin_post_rd_ppg_export', array( __CLASS__, 'handle_export' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'edit.php?post_type=rd_partner',
			__( 'Partner Program Reports', 'rd-partner-program' ),
			__( 'Partner Reports', 'rd-partner-program' ),
			'manage_woocommerce',
			'rd-ppg-reports',
			array( __CLASS__, 'render' )
		);
	}

	private static function current_filters() {
		return array(
			'merchant' => isset( $_GET['merchant'] ) ? absint( $_GET['merchant'] ) : 0,
			'from'     => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'to'       => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
			'coach'    => isset( $_GET['coach'] ) ? RD_PPG_Referral::sanitize_code( wp_unslash( $_GET['coach'] ) ) : '',
			'status'   => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '',
		);
	}

	/**
	 * Fetches commission entries for the given filters and decorates each row with
	 * classification, refund flag, and needs settlement flag.
	 */
	private static function fetch_rows( $filters ) {
		global $wpdb;
		$table = RD_PPG_Commission::table();

		$where  = array( 'merchant_id = %d' );
		$params = array( $filters['merchant'] );

		if ( $filters['coach'] ) {
			$where[]  = 'code = %s';
			$params[] = $filters['coach'];
		}
		if ( $filters['from'] && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $filters['from'] . ' 00:00:00';
		}
		if ( $filters['to'] && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $filters['to'] . ' 23:59:59';
		}

		$sql  = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY user_id ASC, created_at ASC';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		foreach ( $rows as $row ) {
			$order              = wc_get_order( $row->order_id );
			$row->order_exists  = (bool) $order;
			$refunded           = $order ? (float) $order->get_total_refunded() : 0;
			$order_refund_state = $order && ( $refunded > 0 || 'refunded' === $order->get_status() );
			$row->refund_flag   = $order_refund_state;

			if ( 'voided' === $row->status ) {
				$row->classification = 'voided';
			} elseif ( 1 === (int) $row->counted ) {
				$row->classification = 'counted';
			} elseif ( 'Outside commission window' === (string) $row->note ) {
				$row->classification = 'outside';
			} else {
				$row->classification = 'overflow';
			}

			$net = $order ? round( (float) $order->get_total() - $refunded, 2 ) : (float) $row->paid_value;
			$row->needs_settlement = ( 'counted' === $row->classification && $order_refund_state && abs( $net - (float) $row->paid_value ) > 0.005 );
		}

		if ( 'needs' === $filters['status'] ) {
			$rows = array_values( array_filter( $rows, function( $row ) {
				return $row->needs_settlement;
			} ) );
		} elseif ( in_array( $filters['status'], array( 'counted', 'overflow', 'voided', 'outside' ), true ) ) {
			$rows = array_values( array_filter( $rows, function( $row ) use ( $filters ) {
				return $row->classification === $filters['status'];
			} ) );
		}

		return $rows;
	}

	private static function classification_label( $row ) {
		switch ( $row->classification ) {
			case 'counted':
				return sprintf( __( 'Counted, slot %d', 'rd-partner-program' ), (int) $row->slot_number );
			case 'overflow':
				return __( 'Overflow', 'rd-partner-program' );
			case 'outside':
				return __( 'Outside window', 'rd-partner-program' );
			case 'voided':
				return __( 'Voided', 'rd-partner-program' );
		}
		return $row->classification;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rd-partner-program' ) );
		}

		$filters   = self::current_filters();
		$merchants = get_posts(
			array(
				'post_type'      => 'rd_partner',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		if ( ! $filters['merchant'] && ! empty( $merchants ) ) {
			$filters['merchant'] = $merchants[0]->ID;
		}

		$partner = $filters['merchant'] ? RD_PPG_Partner::get( $filters['merchant'] ) : null;
		$rows    = $partner ? self::fetch_rows( $filters ) : array();

		$summary = array(
			'gross'     => 0.0,
			'owed'      => 0.0,
			'customers' => array(),
			'counted'   => 0,
			'needs'     => 0,
		);
		$groups = array();
		foreach ( $rows as $row ) {
			$groups[ $row->user_id ][] = $row;
			$summary['customers'][ $row->user_id ] = true;
			if ( 'counted' === $row->classification ) {
				$summary['gross'] += (float) $row->paid_value;
				$summary['owed']  += (float) $row->commission_amount;
				$summary['counted']++;
			}
			if ( $row->needs_settlement ) {
				$summary['needs']++;
			}
		}

		$base_url = add_query_arg( array( 'post_type' => 'rd_partner', 'page' => 'rd-ppg-reports' ), admin_url( 'edit.php' ) );
		?>
		<div class="wrap rd-ppg-admin">
			<style>
				.rd-ppg-admin h1 { border-left: 4px solid #085E83; padding-left: 10px; }
				.rd-ppg-summary { display: flex; gap: 16px; flex-wrap: wrap; margin: 16px 0; }
				.rd-ppg-summary .card { background: #fff; border-left: 4px solid #085E83; padding: 12px 16px; min-width: 160px; }
				.rd-ppg-summary .card strong { display: block; font-size: 18px; color: #085E83; }
				.rd-ppg-customer-head th { background: #f0f6f9; }
				.rd-ppg-flag { color: #b32d2e; font-weight: 600; }
				.rd-ppg-actions form { display: inline-block; margin-right: 6px; }
				.rd-ppg-actions input[type="number"], .rd-ppg-actions input[type="text"] { width: 90px; }
				.rd-ppg-breakdown td { background: #fafcfd; color: #555; padding-top: 2px; }
			</style>
			<h1><?php esc_html_e( 'Partner Program Reports', 'rd-partner-program' ); ?></h1>

			<?php if ( isset( $_GET['rd_ppg_msg'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( self::action_message( sanitize_key( $_GET['rd_ppg_msg'] ) ) ); ?></p></div>
			<?php endif; ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
				<input type="hidden" name="post_type" value="product" />
				<input type="hidden" name="page" value="rd-ppg-reports" />
				<select name="merchant">
					<?php foreach ( $merchants as $merchant_post ) : ?>
						<option value="<?php echo esc_attr( $merchant_post->ID ); ?>" <?php selected( $filters['merchant'], $merchant_post->ID ); ?>><?php echo esc_html( $merchant_post->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>" />
				<input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>" />
				<select name="coach">
					<option value=""><?php esc_html_e( 'All coaches', 'rd-partner-program' ); ?></option>
					<?php if ( $partner ) : ?>
						<option value="<?php echo esc_attr( $partner['parent_code'] ); ?>" <?php selected( $filters['coach'], $partner['parent_code'] ); ?>><?php echo esc_html( $partner['parent_code'] ); ?></option>
						<?php foreach ( $partner['coach_codes'] as $coach ) : ?>
							<option value="<?php echo esc_attr( strtoupper( $coach['code'] ) ); ?>" <?php selected( $filters['coach'], strtoupper( $coach['code'] ) ); ?>><?php echo esc_html( $coach['label'] . ', ' . strtoupper( $coach['code'] ) ); ?></option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
				<select name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'rd-partner-program' ); ?></option>
					<option value="counted" <?php selected( $filters['status'], 'counted' ); ?>><?php esc_html_e( 'Counted', 'rd-partner-program' ); ?></option>
					<option value="overflow" <?php selected( $filters['status'], 'overflow' ); ?>><?php esc_html_e( 'Overflow', 'rd-partner-program' ); ?></option>
					<option value="outside" <?php selected( $filters['status'], 'outside' ); ?>><?php esc_html_e( 'Outside window', 'rd-partner-program' ); ?></option>
					<option value="voided" <?php selected( $filters['status'], 'voided' ); ?>><?php esc_html_e( 'Voided', 'rd-partner-program' ); ?></option>
					<option value="needs" <?php selected( $filters['status'], 'needs' ); ?>><?php esc_html_e( 'Needs settlement', 'rd-partner-program' ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'rd-partner-program' ); ?></button>
			</form>

			<?php if ( $partner && $partner['commission_paused'] ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Commissions are paused for this partner. New orders will not generate commission entries until un-paused.', 'rd-partner-program' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $partner ) :
				$stats       = RD_PPG_Stats::totals_by_code( $filters['merchant'], $filters['from'], $filters['to'] );
				$code_labels = array( $partner['parent_code'] => __( 'Parent link', 'rd-partner-program' ) );
				foreach ( $partner['coach_codes'] as $coach ) {
					$code_labels[ strtoupper( $coach['code'] ) ] = $coach['label'];
				}
				if ( $filters['coach'] ) {
					$stats = array_values( array_filter( $stats, function( $stat ) use ( $filters ) {
						return $stat->code === $filters['coach'];
					} ) );
				}
				$total_visits  = 0;
				$total_signups = 0;
				foreach ( $stats as $stat ) {
					$total_visits  += (int) $stat->visits;
					$total_signups += (int) $stat->signups;
				}
				?>
				<h2><?php esc_html_e( 'Traffic and Signups', 'rd-partner-program' ); ?></h2>
				<?php if ( empty( $stats ) ) : ?>
					<p><?php esc_html_e( 'No referral link traffic recorded yet for this affiliate partner and date range. Tracking starts from plugin version 1.1.0. Any page URL carrying a valid partner code counts, cached pages included.', 'rd-partner-program' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="max-width: 720px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Code', 'rd-partner-program' ); ?></th>
								<th><?php esc_html_e( 'Coach', 'rd-partner-program' ); ?></th>
								<th><?php esc_html_e( 'Visits', 'rd-partner-program' ); ?></th>
								<th><?php esc_html_e( 'Signups', 'rd-partner-program' ); ?></th>
								<th><?php esc_html_e( 'Conversion', 'rd-partner-program' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $stats as $stat ) : ?>
								<tr>
									<td><code><?php echo esc_html( $stat->code ); ?></code></td>
									<td><?php echo esc_html( isset( $code_labels[ $stat->code ] ) ? $code_labels[ $stat->code ] : __( 'Removed code', 'rd-partner-program' ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $stat->visits ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $stat->signups ) ); ?></td>
									<td><?php echo esc_html( $stat->visits > 0 ? number_format_i18n( 100 * $stat->signups / $stat->visits, 1 ) . '%' : '0%' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
						<tfoot>
							<tr>
								<th><?php esc_html_e( 'Total', 'rd-partner-program' ); ?></th>
								<th></th>
								<th><?php echo esc_html( number_format_i18n( $total_visits ) ); ?></th>
								<th><?php echo esc_html( number_format_i18n( $total_signups ) ); ?></th>
								<th><?php echo esc_html( $total_visits > 0 ? number_format_i18n( 100 * $total_signups / $total_visits, 1 ) . '%' : '0%' ); ?></th>
							</tr>
						</tfoot>
					</table>
					<p class="description"><?php esc_html_e( 'Visits count each landing on a URL carrying the code, with bots and social link preview fetchers filtered out. Signups count customers tagged through that code. Daily counts, filterable by the date range above.', 'rd-partner-program' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Commission', 'rd-partner-program' ); ?></h2>
			<div class="rd-ppg-summary">
				<div class="card"><strong><?php echo wp_kses_post( wc_price( $summary['gross'] ) ); ?></strong><?php esc_html_e( 'Gross commissionable value', 'rd-partner-program' ); ?></div>
				<div class="card"><strong><?php echo wp_kses_post( wc_price( $summary['owed'] ) ); ?></strong><?php esc_html_e( 'Commission owed, net of voided', 'rd-partner-program' ); ?></div>
				<div class="card"><strong><?php echo esc_html( count( $summary['customers'] ) ); ?></strong><?php esc_html_e( 'Customers', 'rd-partner-program' ); ?></div>
				<div class="card"><strong><?php echo esc_html( $summary['counted'] ); ?></strong><?php esc_html_e( 'Counted orders', 'rd-partner-program' ); ?></div>
				<div class="card"><strong><?php echo esc_html( $summary['needs'] ); ?></strong><?php esc_html_e( 'Needs settlement', 'rd-partner-program' ); ?></div>
			</div>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 12px;">
				<input type="hidden" name="action" value="rd_ppg_export" />
				<input type="hidden" name="merchant" value="<?php echo esc_attr( $filters['merchant'] ); ?>" />
				<input type="hidden" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>" />
				<input type="hidden" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>" />
				<input type="hidden" name="coach" value="<?php echo esc_attr( $filters['coach'] ); ?>" />
				<input type="hidden" name="status" value="<?php echo esc_attr( $filters['status'] ); ?>" />
				<?php wp_nonce_field( 'rd_ppg_export' ); ?>
				<button type="submit" class="button button-primary" style="background: #085E83; border-color: #085E83;"><?php esc_html_e( 'Export CSV', 'rd-partner-program' ); ?></button>
			</form>

			<?php if ( empty( $groups ) ) : ?>
				<p><?php esc_html_e( 'No commission entries match the current filters.', 'rd-partner-program' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order', 'rd-partner-program' ); ?></th>
							<th><?php esc_html_e( 'Date', 'rd-partner-program' ); ?></th>
							<th><?php esc_html_e( 'Code', 'rd-partner-program' ); ?></th>
							<th><?php esc_html_e( 'Paid Value', 'rd-partner-program' ); ?></th>
							<th><?php esc_html_e( 'Rate', 'rd-partner-program' ); ?></th>
							<th><?php esc_html_e( 'Commission', 'rd-partner-program' ); ?></th>
							<th><?php esc_html_e( 'Type', 'rd-partner-program' ); ?></th>
							<th><?php esc_html_e( 'Status', 'rd-partner-program' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'rd-partner-program' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $groups as $user_id => $entries ) :
							$user       = get_userdata( $user_id );
							$signup_ts  = RD_PPG_Partner::user_signup_ts( $user_id );
							$window_end = $partner ? RD_PPG_Partner::effective_window_end( $user_id, $partner ) : 0;
							$used       = $partner ? count( RD_PPG_Commission::used_slots( $filters['merchant'], $user_id ) ) : 0;
							$cap        = $partner && $partner['commission_order_cap'] > 0 ? $partner['commission_order_cap'] : __( 'Unlimited', 'rd-partner-program' );
							?>
							<tr class="rd-ppg-customer-head">
								<th colspan="9">
									<?php
									printf(
										'%s (ID %d), %s %s, %s %s, %s %s',
										esc_html( $user ? $user->display_name : __( 'Deleted user', 'rd-partner-program' ) ),
										absint( $user_id ),
										esc_html__( 'signup', 'rd-partner-program' ),
										esc_html( $signup_ts ? date_i18n( 'Y-m-d', $signup_ts ) : '?' ),
										esc_html__( 'window ends', 'rd-partner-program' ),
										esc_html( $window_end ? date_i18n( 'Y-m-d', $window_end ) : '?' ),
										esc_html__( 'slots', 'rd-partner-program' ),
										esc_html( $used . ' / ' . $cap )
									);
									?>
								</th>
							</tr>
							<?php foreach ( $entries as $row ) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $row->order_id ) . '&action=edit' ) ); ?>">#<?php echo absint( $row->order_id ); ?></a>
										<?php if ( $row->needs_settlement ) : ?>
											<span class="rd-ppg-flag"><?php esc_html_e( 'Needs settlement', 'rd-partner-program' ); ?></span>
										<?php elseif ( $row->refund_flag ) : ?>
											<span class="rd-ppg-flag"><?php esc_html_e( 'Refunded', 'rd-partner-program' ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $row->created_at ) ); ?></td>
									<td><?php echo esc_html( $row->code ); ?></td>
									<td><?php echo wp_kses_post( wc_price( $row->paid_value ) ); ?></td>
									<?php $lines = RD_PPG_Commission::breakdown_lines( $row ); ?>
									<td>
										<?php echo esc_html( $row->rate . '%' ); ?>
										<?php if ( $lines ) : ?>
											<br /><small><?php esc_html_e( 'blended, per item', 'rd-partner-program' ); ?></small>
										<?php endif; ?>
									</td>
									<td><?php echo wp_kses_post( wc_price( $row->commission_amount ) ); ?></td>
									<td><?php echo esc_html( self::classification_label( $row ) ); ?></td>
									<td>
										<?php echo esc_html( ucfirst( $row->status ) ); ?>
										<?php if ( $row->note ) : ?>
											<br /><em><?php echo esc_html( $row->note ); ?></em>
										<?php endif; ?>
									</td>
									<td class="rd-ppg-actions">
										<?php if ( 'active' === $row->status ) : ?>
											<?php self::action_form( $row->id, 'void', __( 'Void', 'rd-partner-program' ), $base_url ); ?>
											<?php if ( 1 === (int) $row->counted ) : ?>
												<?php self::action_form( $row->id, 'adjust', __( 'Adjust', 'rd-partner-program' ), $base_url, 'number', __( 'New paid value', 'rd-partner-program' ), $row->paid_value ); ?>
											<?php elseif ( 'overflow' === $row->classification ) : ?>
												<?php self::action_form( $row->id, 'promote', __( 'Promote', 'rd-partner-program' ), $base_url ); ?>
											<?php endif; ?>
										<?php endif; ?>
										<?php self::action_form( $row->id, 'note', __( 'Note', 'rd-partner-program' ), $base_url, 'text', __( 'Note', 'rd-partner-program' ), $row->note ); ?>
									</td>
								</tr>
								<?php if ( $lines ) : ?>
									<tr class="rd-ppg-breakdown">
										<td></td>
										<td colspan="8">
											<small>
												<?php foreach ( $lines as $line ) : ?>
													<?php echo esc_html( self::breakdown_text( $line ) ); ?><br />
												<?php endforeach; ?>
											</small>
										</td>
									</tr>
								<?php endif; ?>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr style="background: #f0f6f9; font-weight: 600;">
							<td colspan="3"><?php esc_html_e( 'Period Total', 'rd-partner-program' ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $summary['gross'] ) ); ?></td>
							<td></td>
							<td><?php echo wp_kses_post( wc_price( $summary['owed'] ) ); ?></td>
							<td colspan="3">
								<?php
								printf(
									'%d %s, %d %s',
									$summary['counted'],
									esc_html__( 'counted', 'rd-partner-program' ),
									count( $summary['customers'] ),
									esc_html__( 'customers', 'rd-partner-program' )
								);
								?>
							</td>
						</tr>
					</tfoot>
				</table>
			<?php endif; ?>
			<p class="description" style="margin-top: 24px; border-top: 1px solid #ddd; padding-top: 12px;">
				<?php echo wp_kses_post( __( 'ReserveDaily Partner Program, developed by <a href="https://shariarbijoy.dev" target="_blank" rel="noopener">Shariar Bijoy</a>.', 'rd-partner-program' ) ); ?>
			</p>
		</div>
		<?php
	}

	private static function action_form( $entry_id, $act, $label, $redirect, $input_type = '', $placeholder = '', $value = '' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="rd_ppg_entry_action" />
			<input type="hidden" name="entry_id" value="<?php echo esc_attr( $entry_id ); ?>" />
			<input type="hidden" name="act" value="<?php echo esc_attr( $act ); ?>" />
			<input type="hidden" name="redirect" value="<?php echo esc_attr( rawurlencode( $redirect . '&' . http_build_query( self::current_filters() ) ) ); ?>" />
			<?php wp_nonce_field( 'rd_ppg_entry_action_' . $entry_id ); ?>
			<?php if ( 'number' === $input_type ) : ?>
				<input type="number" step="0.01" min="0" name="value" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" required />
			<?php elseif ( 'text' === $input_type ) : ?>
				<input type="text" name="value" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
			<?php endif; ?>
			<button type="submit" class="button button-small"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * One readable line per breakdown item, for the report table and the CSV.
	 * Example: Gut16 x1, RM500.00 at 10% = RM50.00 (category rate)
	 */
	private static function breakdown_text( $line ) {
		$sources = array(
			'product'  => __( 'product rate', 'rd-partner-program' ),
			'category' => __( 'category rate', 'rd-partner-program' ),
			'default'  => __( 'default rate', 'rd-partner-program' ),
		);
		$source = isset( $line['source'], $sources[ $line['source'] ] ) ? $sources[ $line['source'] ] : '';
		return sprintf(
			'%s x%d, %s at %s%% = %s%s',
			isset( $line['name'] ) ? $line['name'] : '',
			isset( $line['qty'] ) ? (int) $line['qty'] : 1,
			html_entity_decode( wp_strip_all_tags( wc_price( isset( $line['value'] ) ? (float) $line['value'] : 0 ) ), ENT_QUOTES, 'UTF-8' ),
			isset( $line['rate'] ) ? rtrim( rtrim( number_format( (float) $line['rate'], 2, '.', '' ), '0' ), '.' ) : '0',
			html_entity_decode( wp_strip_all_tags( wc_price( isset( $line['amount'] ) ? (float) $line['amount'] : 0 ) ), ENT_QUOTES, 'UTF-8' ),
			$source ? ' (' . $source . ')' : ''
		);
	}

	private static function action_message( $key ) {
		$messages = array(
			'voided'   => __( 'Entry voided. The slot has been freed.', 'rd-partner-program' ),
			'adjusted' => __( 'Entry adjusted.', 'rd-partner-program' ),
			'promoted' => __( 'Overflow entry promoted into a free slot.', 'rd-partner-program' ),
			'noted'    => __( 'Note saved.', 'rd-partner-program' ),
			'failed'   => __( 'The action could not be completed. Check the entry state, window, and free slots.', 'rd-partner-program' ),
		);
		return isset( $messages[ $key ] ) ? $messages[ $key ] : '';
	}

	public static function handle_entry_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'rd-partner-program' ) );
		}
		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		check_admin_referer( 'rd_ppg_entry_action_' . $entry_id );

		$act      = isset( $_POST['act'] ) ? sanitize_key( $_POST['act'] ) : '';
		$value    = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';
		$admin_id = get_current_user_id();
		$result   = false;
		$message  = 'failed';

		switch ( $act ) {
			case 'void':
				$result  = RD_PPG_Commission::void_entry( $entry_id, $admin_id );
				$message = $result ? 'voided' : 'failed';
				break;
			case 'adjust':
				$result  = RD_PPG_Commission::adjust_entry( $entry_id, floatval( $value ), $admin_id );
				$message = $result ? 'adjusted' : 'failed';
				break;
			case 'promote':
				$result  = RD_PPG_Commission::promote_entry( $entry_id, $admin_id );
				$message = $result ? 'promoted' : 'failed';
				break;
			case 'note':
				$result  = RD_PPG_Commission::note_entry( $entry_id, $value, $admin_id );
				$message = $result ? 'noted' : 'failed';
				break;
		}

		$redirect = isset( $_POST['redirect'] ) ? rawurldecode( wp_unslash( $_POST['redirect'] ) ) : '';
		if ( ! $redirect || 0 !== strpos( $redirect, admin_url() ) ) {
			$redirect = add_query_arg( array( 'post_type' => 'rd_partner', 'page' => 'rd-ppg-reports' ), admin_url( 'edit.php' ) );
		}
		wp_safe_redirect( add_query_arg( 'rd_ppg_msg', $message, $redirect ) );
		exit;
	}

	public static function handle_export() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'rd-partner-program' ) );
		}
		check_admin_referer( 'rd_ppg_export' );

		$filters = self::current_filters();
		$partner = $filters['merchant'] ? RD_PPG_Partner::get( $filters['merchant'] ) : null;
		if ( ! $partner ) {
			wp_die( esc_html__( 'Select an affiliate partner before exporting.', 'rd-partner-program' ) );
		}
		$rows = self::fetch_rows( $filters );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=rd-partner-commission-' . $filters['merchant'] . '-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array(
			'Customer Name', 'User ID', 'Signup Date', 'Window End', 'Slots Used', 'Order ID', 'Entry Date',
			'Code', 'Paid Value', 'Rate', 'Commission', 'Type', 'Status', 'Refund Flag', 'Needs Settlement', 'Note', 'Item Breakdown',
		) );

		$total_gross = 0.0;
		$total_owed  = 0.0;
		$total_count = 0;

		foreach ( $rows as $row ) {
			$user       = get_userdata( $row->user_id );
			$signup_ts  = RD_PPG_Partner::user_signup_ts( $row->user_id );
			$window_end = RD_PPG_Partner::effective_window_end( $row->user_id, $partner );
			$used       = count( RD_PPG_Commission::used_slots( $filters['merchant'], $row->user_id ) );

			$commission_val = 'voided' === $row->status ? 0.00 : (float) $row->commission_amount;
			if ( 1 === (int) $row->counted && 'voided' !== $row->status ) {
				$total_gross += (float) $row->paid_value;
				$total_owed  += $commission_val;
				$total_count++;
			}

			fputcsv( $out, array(
				self::csv_safe( $user ? $user->display_name : 'Deleted user' ),
				$row->user_id,
				$signup_ts ? gmdate( 'Y-m-d', $signup_ts ) : '',
				$window_end ? gmdate( 'Y-m-d', $window_end ) : '',
				$used . ' / ' . ( $partner['commission_order_cap'] > 0 ? $partner['commission_order_cap'] : 'Unlimited' ),
				$row->order_id,
				$row->created_at,
				$row->code,
				$row->paid_value,
				$row->rate,
				'voided' === $row->status ? '0.00' : $row->commission_amount,
				self::classification_label( $row ),
				$row->status,
				$row->refund_flag ? 'yes' : 'no',
				$row->needs_settlement ? 'yes' : 'no',
				self::csv_safe( (string) $row->note ),
				self::csv_safe( implode( '; ', array_map( array( __CLASS__, 'breakdown_text' ), RD_PPG_Commission::breakdown_lines( $row ) ) ) ),
			) );
		}

		fputcsv( $out, array() );
		fputcsv( $out, array(
			'PERIOD TOTAL', '', '', '', '', '', '', '',
			number_format( $total_gross, 2 ),
			'',
			number_format( $total_owed, 2 ),
			$total_count . ' counted orders',
			'', '', '', '', '',
		) );

		fclose( $out );
		exit;
	}

	/**
	 * Prevents spreadsheet formula injection for cells containing user influenced text.
	 */
	private static function csv_safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}
}
