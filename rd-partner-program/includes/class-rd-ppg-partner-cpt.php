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

class RD_PPG_Partner_CPT {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'add_meta_boxes_rd_partner', array( __CLASS__, 'register_metaboxes' ) );
		add_action( 'save_post_rd_partner', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_footer', array( __CLASS__, 'row_scripts' ) );
		add_action( 'admin_notices', array( __CLASS__, 'code_notices' ) );
		add_filter( 'manage_rd_partner_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_rd_partner_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_action_rd_ppg_duplicate_partner', array( __CLASS__, 'duplicate_partner' ) );
	}

	public static function register_cpt() {
		register_post_type(
			'rd_partner',
			array(
				'labels'       => array(
					'name'          => __( 'Affiliate Partners', 'rd-partner-program' ),
					'singular_name' => __( 'Affiliate Partner', 'rd-partner-program' ),
					'add_new'       => __( 'Add New', 'rd-partner-program' ),
					'add_new_item'  => __( 'Add New Affiliate Partner', 'rd-partner-program' ),
					'edit_item'     => __( 'Edit Affiliate Partner', 'rd-partner-program' ),
					'all_items'     => __( 'Affiliate Partners', 'rd-partner-program' ),
					'menu_name'     => __( 'Partner Program', 'rd-partner-program' ),
				),
				'public'       => false,
				'show_ui'      => true,
				// Own top-level sidebar menu (2.2.0). Reports and Settings hang
				// off this parent slug, so the menu reads: Affiliate Partners,
				// Add New, Partner Reports, Partner Settings.
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-groups',
				'menu_position' => 56,
				'supports'     => array( 'title' ),
				'capability_type' => 'post',
			)
		);
	}

	public static function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'rd_partner' !== $screen->post_type ) {
			return;
		}
		// Reports and Settings sit under the partner menu since 2.2.0 and share
		// its post type on the screen object; only the partner list and edit
		// screens need the WooCommerce pickers.
		if ( ! in_array( $screen->base, array( 'post', 'edit' ), true ) ) {
			return;
		}
		if ( class_exists( 'WooCommerce' ) ) {
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
	}

	public static function register_metaboxes() {
		add_meta_box( 'rd_ppg_program', __( 'Program Settings', 'rd-partner-program' ), array( __CLASS__, 'render_program_box' ), 'rd_partner', 'normal', 'high' );
		add_meta_box( 'rd_ppg_coaches', __( 'Coach Codes', 'rd-partner-program' ), array( __CLASS__, 'render_coaches_box' ), 'rd_partner', 'normal', 'default' );
		add_meta_box( 'rd_ppg_eligibility', __( 'Eligibility and Discounts', 'rd-partner-program' ), array( __CLASS__, 'render_eligibility_box' ), 'rd_partner', 'normal', 'default' );
		add_meta_box( 'rd_ppg_redemption_perk', __( 'Points Redemption Perk', 'rd-partner-program' ), array( __CLASS__, 'render_redemption_box' ), 'rd_partner', 'normal', 'default' );
	}

	private static function partner_meta( $post_id ) {
		$partner = RD_PPG_Partner::get( $post_id );
		if ( $partner ) {
			return $partner;
		}
		return array(
			'active'                   => true,
			'parent_code'              => '',
			'coach_codes'              => array(),
			'commission_rate'          => 0,
			'points_multiplier'        => 1.5,
			'signup_bonus'             => 0,
			'signup_bonus_expiry_days' => 0,
			'eligible_categories'      => array(),
			'eligible_products'        => array(),
			'excluded_products'        => array(),
			'window_months'            => 12,
			'deal_end'                 => '',
			'commission_order_cap'     => 0,
			'landing_url'              => '',
			'redemption_products'      => array(),
			'redemption_categories'    => array(),
			'redemption_mode'          => 'off',
			'premium_redemption_cap'   => 20,
			'allow_existing_customers' => false,
			'bonus_existing_customers' => false,
			'commission_paused'        => false,
			'dashboard_users'          => array(),
		);
	}

	public static function render_program_box( $post ) {
		$m = self::partner_meta( $post->ID );
		wp_nonce_field( 'rd_ppg_save_partner', 'rd_ppg_partner_nonce' );
		?>
		<style>
			#rd_ppg_program .form-table th { width: 240px; }
			.rd-ppg-rows td { vertical-align: middle; }
			.rd-ppg-rows .wc-product-search, .rd-ppg-rows .wc-category-search { min-width: 320px; }
			.postbox .hndle { border-left: 3px solid #085E83; }
		</style>
		<table class="form-table" role="presentation">
			<tr>
				<th><label><?php esc_html_e( 'Active', 'rd-partner-program' ); ?></label></th>
				<td><label><input type="checkbox" name="rd_ppg_active" value="1" <?php checked( $m['active'] ); ?> /> <?php esc_html_e( 'Program is active for this partner', 'rd-partner-program' ); ?></label></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Pause commissions', 'rd-partner-program' ); ?></label></th>
				<td>
					<label><input type="checkbox" name="rd_ppg_commission_paused" value="1" <?php checked( $m['commission_paused'] ); ?> /> <?php esc_html_e( 'Stop recording new commissions while keeping tagging, discounts, points, and the dashboard running', 'rd-partner-program' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="rd_ppg_parent_code"><?php esc_html_e( 'Parent code', 'rd-partner-program' ); ?></label></th>
				<td>
					<input type="text" id="rd_ppg_parent_code" name="rd_ppg_parent_code" value="<?php echo esc_attr( $m['parent_code'] ); ?>" class="regular-text" style="text-transform: uppercase;" />
					<p class="description"><?php esc_html_e( 'Uppercase, unique across partners, for example HOP.', 'rd-partner-program' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rd_ppg_commission_rate"><?php esc_html_e( 'Default commission rate, percent', 'rd-partner-program' ); ?></label></th>
				<td>
					<input type="number" step="0.01" min="0" max="100" id="rd_ppg_commission_rate" name="rd_ppg_commission_rate" value="<?php echo esc_attr( $m['commission_rate'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Applies to the whole order unless a category or product row below carries its own commission rate. When any row does, commission is worked out line by line and this default covers the items without their own rate. Set it to 0 to pay commission only on the listed items.', 'rd-partner-program' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rd_ppg_points_multiplier"><?php esc_html_e( 'Points multiplier', 'rd-partner-program' ); ?></label></th>
				<td><input type="number" step="0.1" min="0" id="rd_ppg_points_multiplier" name="rd_ppg_points_multiplier" value="<?php echo esc_attr( $m['points_multiplier'] ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="rd_ppg_signup_bonus"><?php esc_html_e( 'Welcome bonus points', 'rd-partner-program' ); ?></label></th>
				<td>
					<input type="number" step="1" min="0" id="rd_ppg_signup_bonus" name="rd_ppg_signup_bonus" value="<?php echo esc_attr( $m['signup_bonus'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Awarded once when a customer signs up through this partner, for example 2500 points = RM25. While these points are active the customer has no redemption cap.', 'rd-partner-program' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rd_ppg_signup_bonus_expiry_days"><?php esc_html_e( 'Welcome bonus expiry, days', 'rd-partner-program' ); ?></label></th>
				<td>
					<input type="number" step="1" min="1" max="365" id="rd_ppg_signup_bonus_expiry_days" name="rd_ppg_signup_bonus_expiry_days" value="<?php echo esc_attr( $m['signup_bonus_expiry_days'] > 0 ? $m['signup_bonus_expiry_days'] : '' ); ?>" placeholder="30" />
					<p class="description"><?php esc_html_e( 'Blank means 30 days.', 'rd-partner-program' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rd_ppg_window_months"><?php esc_html_e( 'Customer window, months', 'rd-partner-program' ); ?></label></th>
				<td>
					<input type="number" step="1" min="1" id="rd_ppg_window_months" name="rd_ppg_window_months" value="<?php echo esc_attr( $m['window_months'] ); ?>" />
					<p class="description"><?php esc_html_e( 'How long each customer stays special, counted from their own signup. Governs commission, discounts, the points multiplier, and the redemption override together.', 'rd-partner-program' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rd_ppg_deal_end"><?php esc_html_e( 'Deal end date', 'rd-partner-program' ); ?></label></th>
				<td>
					<input type="date" id="rd_ppg_deal_end" name="rd_ppg_deal_end" value="<?php echo esc_attr( $m['deal_end'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional partnership end date. Commission and customer perks stop on this date even when a customer\'s own window has time left, whichever comes first. New signups and visit tracking also stop. Blank means open ended. Extend the date to renew the deal.', 'rd-partner-program' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rd_ppg_commission_order_cap"><?php esc_html_e( 'Commission order cap', 'rd-partner-program' ); ?></label></th>
				<td>
					<input type="number" step="1" min="0" id="rd_ppg_commission_order_cap" name="rd_ppg_commission_order_cap" value="<?php echo esc_attr( $m['commission_order_cap'] ); ?>" />
					<p class="description"><?php esc_html_e( '0 or blank means unlimited, every in window order pays commission.', 'rd-partner-program' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rd_ppg_landing_url"><?php esc_html_e( 'Landing page URL', 'rd-partner-program' ); ?></label></th>
				<td>
					<input type="url" id="rd_ppg_landing_url" name="rd_ppg_landing_url" class="regular-text" value="<?php echo esc_attr( $m['landing_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional. The referral links and QR codes on this partner\'s dashboard point here, for example a campaign page carrying the Meta pixel. Blank means the homepage. Tagging works on any URL either way.', 'rd-partner-program' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Allow existing customers', 'rd-partner-program' ); ?></label></th>
				<td><label><input type="checkbox" name="rd_ppg_allow_existing_customers" value="1" <?php checked( $m['allow_existing_customers'] ); ?> /> <?php esc_html_e( 'Tag an existing logged in untagged customer who arrives with this partner code', 'rd-partner-program' ); ?></label></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Bonus for existing customers', 'rd-partner-program' ); ?></label></th>
				<td><label><input type="checkbox" name="rd_ppg_bonus_existing_customers" value="1" <?php checked( $m['bonus_existing_customers'] ); ?> /> <?php esc_html_e( 'Award signup bonus points when an existing customer is tagged via the partner link', 'rd-partner-program' ); ?></label></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Affiliate Partner accounts', 'rd-partner-program' ); ?></label></th>
				<td>
					<select class="wc-customer-search" multiple="multiple" name="rd_ppg_dashboard_users[]" data-placeholder="<?php esc_attr_e( 'Search for an existing user', 'rd-partner-program' ); ?>" data-action="woocommerce_json_search_customers" style="min-width: 320px;">
						<?php foreach ( $m['dashboard_users'] as $dash_user_id ) :
							$dash_user = get_userdata( $dash_user_id );
							if ( ! $dash_user ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $dash_user_id ); ?>" selected="selected"><?php echo esc_html( sprintf( '%s (#%d - %s)', $dash_user->display_name, $dash_user_id, $dash_user->user_email ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'These accounts see the Partner Dashboard tab in My Account: referral links with copy and QR, eligible items, and per code visits, signups, and purchases.', 'rd-partner-program' ); ?></p>
				</td>
			</tr>
			<?php if ( current_user_can( 'create_users' ) ) : ?>
			<tr>
				<th><label><?php esc_html_e( 'Create login account', 'rd-partner-program' ); ?></label></th>
				<td>
					<p>
						<input type="text" name="rd_ppg_new_account_name" value="" class="regular-text" placeholder="<?php esc_attr_e( 'Full name', 'rd-partner-program' ); ?>" />
					</p>
					<p>
						<input type="email" name="rd_ppg_new_account_email" value="" class="regular-text" placeholder="<?php esc_attr_e( 'Email address', 'rd-partner-program' ); ?>" />
					</p>
					<label>
						<input type="checkbox" name="rd_ppg_create_account" value="1" />
						<?php esc_html_e( 'Create this account on save and email a password setup link', 'rd-partner-program' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Creates a normal customer account, links it to this affiliate partner automatically, and sends the standard WordPress welcome email so they set their own password. If the email is already registered, link the existing account with the search field above instead.', 'rd-partner-program' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	public static function render_coaches_box( $post ) {
		$m = self::partner_meta( $post->ID );
		?>
		<table class="widefat rd-ppg-rows" id="rd-ppg-coach-rows">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label', 'rd-partner-program' ); ?></th>
					<th><?php esc_html_e( 'Code', 'rd-partner-program' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $m['coach_codes'] as $row ) : ?>
					<tr>
						<td><input type="text" name="rd_ppg_coach_label[]" value="<?php echo esc_attr( isset( $row['label'] ) ? $row['label'] : '' ); ?>" /></td>
						<td><input type="text" name="rd_ppg_coach_code[]" value="<?php echo esc_attr( isset( $row['code'] ) ? $row['code'] : '' ); ?>" style="text-transform: uppercase;" /></td>
						<td><button type="button" class="button rd-ppg-remove-row"><?php esc_html_e( 'Remove', 'rd-partner-program' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button rd-ppg-add-row" data-target="rd-ppg-coach-rows" data-template="rd-ppg-coach-template"><?php esc_html_e( 'Add coach code', 'rd-partner-program' ); ?></button></p>
		<p class="description"><?php esc_html_e( 'Every coach code rolls up to the parent code for tagging and totals, and is retained for per coach reporting. Example label John, code HOP-JOHN.', 'rd-partner-program' ); ?></p>
		<script type="text/html" id="rd-ppg-coach-template">
			<tr>
				<td><input type="text" name="rd_ppg_coach_label[]" value="" /></td>
				<td><input type="text" name="rd_ppg_coach_code[]" value="" style="text-transform: uppercase;" /></td>
				<td><button type="button" class="button rd-ppg-remove-row"><?php esc_html_e( 'Remove', 'rd-partner-program' ); ?></button></td>
			</tr>
		</script>
		<?php
	}

	public static function render_eligibility_box( $post ) {
		$m = self::partner_meta( $post->ID );
		?>
		<p class="description"><?php esc_html_e( 'Discount rate is what the customer gets off the price, 0 means program eligible with no price cut. Commission rate is what this partner earns on the item. Leave commission blank to use the partner default rate. A product row beats a category row when both match.', 'rd-partner-program' ); ?></p>
		<h4><?php esc_html_e( 'Eligible categories', 'rd-partner-program' ); ?></h4>
		<table class="widefat rd-ppg-rows" id="rd-ppg-cat-rows">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Category', 'rd-partner-program' ); ?></th>
					<th><?php esc_html_e( 'Discount rate, percent', 'rd-partner-program' ); ?></th>
					<th><?php esc_html_e( 'Commission rate, percent', 'rd-partner-program' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $m['eligible_categories'] as $row ) :
					$term_id = isset( $row['term_id'] ) ? absint( $row['term_id'] ) : 0;
					$term    = $term_id ? get_term( $term_id, 'product_cat' ) : null;
					?>
					<tr>
						<td>
							<select class="wc-category-search" name="rd_ppg_elig_cat_term[]" data-return_id="id" data-placeholder="<?php esc_attr_e( 'Search for a category', 'rd-partner-program' ); ?>" data-allow_clear="true">
								<?php if ( $term && ! is_wp_error( $term ) ) : ?>
									<option value="<?php echo esc_attr( $term_id ); ?>" selected="selected"><?php echo esc_html( $term->name ); ?></option>
								<?php endif; ?>
							</select>
						</td>
						<td><input type="number" step="0.01" min="0" max="100" name="rd_ppg_elig_cat_rate[]" value="<?php echo esc_attr( isset( $row['rate'] ) ? $row['rate'] : 0 ); ?>" /></td>
						<td><input type="number" step="0.01" min="0" max="100" name="rd_ppg_elig_cat_comm[]" value="<?php echo esc_attr( RD_PPG_Partner::row_has_commission( $row ) ? $row['commission'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Default', 'rd-partner-program' ); ?>" /></td>
						<td><button type="button" class="button rd-ppg-remove-row"><?php esc_html_e( 'Remove', 'rd-partner-program' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button rd-ppg-add-row" data-target="rd-ppg-cat-rows" data-template="rd-ppg-cat-template"><?php esc_html_e( 'Add category', 'rd-partner-program' ); ?></button></p>
		<script type="text/html" id="rd-ppg-cat-template">
			<tr>
				<td><select class="wc-category-search" name="rd_ppg_elig_cat_term[]" data-return_id="id" data-placeholder="<?php esc_attr_e( 'Search for a category', 'rd-partner-program' ); ?>" data-allow_clear="true"></select></td>
				<td><input type="number" step="0.01" min="0" max="100" name="rd_ppg_elig_cat_rate[]" value="0" /></td>
				<td><input type="number" step="0.01" min="0" max="100" name="rd_ppg_elig_cat_comm[]" value="" placeholder="<?php esc_attr_e( 'Default', 'rd-partner-program' ); ?>" /></td>
				<td><button type="button" class="button rd-ppg-remove-row"><?php esc_html_e( 'Remove', 'rd-partner-program' ); ?></button></td>
			</tr>
		</script>

		<h4><?php esc_html_e( 'Eligible products', 'rd-partner-program' ); ?></h4>
		<table class="widefat rd-ppg-rows" id="rd-ppg-prod-rows">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'rd-partner-program' ); ?></th>
					<th><?php esc_html_e( 'Discount rate, percent', 'rd-partner-program' ); ?></th>
					<th><?php esc_html_e( 'Commission rate, percent', 'rd-partner-program' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $m['eligible_products'] as $row ) :
					$product_id = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;
					$product    = $product_id ? wc_get_product( $product_id ) : null;
					?>
					<tr>
						<td>
							<select class="wc-product-search" name="rd_ppg_elig_prod_id[]" data-placeholder="<?php esc_attr_e( 'Search for a product', 'rd-partner-program' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true">
								<?php if ( $product ) : ?>
									<option value="<?php echo esc_attr( $product_id ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
								<?php endif; ?>
							</select>
						</td>
						<td><input type="number" step="0.01" min="0" max="100" name="rd_ppg_elig_prod_rate[]" value="<?php echo esc_attr( isset( $row['rate'] ) ? $row['rate'] : 0 ); ?>" /></td>
						<td><input type="number" step="0.01" min="0" max="100" name="rd_ppg_elig_prod_comm[]" value="<?php echo esc_attr( RD_PPG_Partner::row_has_commission( $row ) ? $row['commission'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Default', 'rd-partner-program' ); ?>" /></td>
						<td><button type="button" class="button rd-ppg-remove-row"><?php esc_html_e( 'Remove', 'rd-partner-program' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button rd-ppg-add-row" data-target="rd-ppg-prod-rows" data-template="rd-ppg-prod-template"><?php esc_html_e( 'Add product', 'rd-partner-program' ); ?></button></p>
		<script type="text/html" id="rd-ppg-prod-template">
			<tr>
				<td><select class="wc-product-search" name="rd_ppg_elig_prod_id[]" data-placeholder="<?php esc_attr_e( 'Search for a product', 'rd-partner-program' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true"></select></td>
				<td><input type="number" step="0.01" min="0" max="100" name="rd_ppg_elig_prod_rate[]" value="0" /></td>
				<td><input type="number" step="0.01" min="0" max="100" name="rd_ppg_elig_prod_comm[]" value="" placeholder="<?php esc_attr_e( 'Default', 'rd-partner-program' ); ?>" /></td>
				<td><button type="button" class="button rd-ppg-remove-row"><?php esc_html_e( 'Remove', 'rd-partner-program' ); ?></button></td>
			</tr>
		</script>

		<h4><?php esc_html_e( 'Excluded products', 'rd-partner-program' ); ?></h4>
		<table class="widefat rd-ppg-rows" id="rd-ppg-excl-rows">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'rd-partner-program' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $m['excluded_products'] as $product_id ) :
					$product = $product_id ? wc_get_product( $product_id ) : null;
					?>
					<tr>
						<td>
							<select class="wc-product-search" name="rd_ppg_excl_prod_id[]" data-placeholder="<?php esc_attr_e( 'Search for a product', 'rd-partner-program' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true">
								<?php if ( $product ) : ?>
									<option value="<?php echo esc_attr( $product_id ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
								<?php endif; ?>
							</select>
						</td>
						<td><button type="button" class="button rd-ppg-remove-row"><?php esc_html_e( 'Remove', 'rd-partner-program' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button rd-ppg-add-row" data-target="rd-ppg-excl-rows" data-template="rd-ppg-excl-template"><?php esc_html_e( 'Add excluded product', 'rd-partner-program' ); ?></button></p>
		<p class="description"><?php esc_html_e( 'Excluded products never receive the automatic discount and never count as discounted for points suppression.', 'rd-partner-program' ); ?></p>
		<script type="text/html" id="rd-ppg-excl-template">
			<tr>
				<td><select class="wc-product-search" name="rd_ppg_excl_prod_id[]" data-placeholder="<?php esc_attr_e( 'Search for a product', 'rd-partner-program' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true"></select></td>
				<td><button type="button" class="button rd-ppg-remove-row"><?php esc_html_e( 'Remove', 'rd-partner-program' ); ?></button></td>
			</tr>
		</script>
		<?php
	}

	public static function render_redemption_box( $post ) {
		$m    = self::partner_meta( $post->ID );
		$mode = $m['redemption_mode'];
		?>
		<p class="description"><?php esc_html_e( 'How much of the cart this partner\'s customers can pay with points.', 'rd-partner-program' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th style="width: 240px;"><?php esc_html_e( 'Perk', 'rd-partner-program' ); ?></th>
				<td>
					<p><label><input type="radio" name="rd_ppg_redemption_mode" value="off" <?php checked( 'off', $mode ); ?> /> <?php esc_html_e( 'Off, normal site cap', 'rd-partner-program' ); ?></label></p>
					<p><label><input type="radio" name="rd_ppg_redemption_mode" value="items" <?php checked( 'items', $mode ); ?> /> <?php esc_html_e( 'On selected items', 'rd-partner-program' ); ?></label></p>
					<p><label><input type="radio" name="rd_ppg_redemption_mode" value="all" <?php checked( 'all', $mode ); ?> /> <?php esc_html_e( 'On all items', 'rd-partner-program' ); ?></label></p>
				</td>
			</tr>
			<tr class="rd-ppg-perk-cap-row">
				<th><label for="rd_ppg_premium_redemption_cap"><?php esc_html_e( 'Cap, percent', 'rd-partner-program' ); ?></label></th>
				<td><input type="number" step="0.1" min="0" max="100" id="rd_ppg_premium_redemption_cap" name="rd_ppg_premium_redemption_cap" value="<?php echo esc_attr( $m['premium_redemption_cap'] ); ?>" /></td>
			</tr>
			<tr class="rd-ppg-perk-items-row">
				<th><label><?php esc_html_e( 'Products', 'rd-partner-program' ); ?></label></th>
				<td>
					<select class="wc-product-search" multiple="multiple" name="rd_ppg_redemption_products[]" data-placeholder="<?php esc_attr_e( 'Search for a product', 'rd-partner-program' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="min-width: 320px;">
						<?php
						foreach ( $m['redemption_products'] as $product_id ) :
							$product = wc_get_product( (int) $product_id );
							if ( ! $product ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $product_id ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr class="rd-ppg-perk-items-row">
				<th><label><?php esc_html_e( 'Categories', 'rd-partner-program' ); ?></label></th>
				<td>
					<select class="wc-category-search" multiple="multiple" name="rd_ppg_redemption_categories[]" data-return_id="id" data-placeholder="<?php esc_attr_e( 'Search for a category', 'rd-partner-program' ); ?>" style="min-width: 320px;">
						<?php
						foreach ( $m['redemption_categories'] as $term_id ) :
							$term = get_term( (int) $term_id, 'product_cat' );
							if ( ! $term || is_wp_error( $term ) ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $term_id ); ?>" selected="selected"><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>
		<script>
			jQuery( function( $ ) {
				function rdPpgPerkRows() {
					var mode = $( 'input[name="rd_ppg_redemption_mode"]:checked' ).val() || 'off';
					$( '.rd-ppg-perk-cap-row' ).toggle( 'off' !== mode );
					$( '.rd-ppg-perk-items-row' ).toggle( 'items' === mode );
				}
				$( document ).on( 'change', 'input[name="rd_ppg_redemption_mode"]', rdPpgPerkRows );
				rdPpgPerkRows();
			} );
		</script>
		<?php
	}

	public static function row_scripts() {
		$screen = get_current_screen();
		if ( ! $screen || 'rd_partner' !== $screen->id ) {
			return;
		}
		?>
		<script>
			jQuery( function( $ ) {
				$( document ).on( 'click', '.rd-ppg-add-row', function() {
					var target   = $( '#' + $( this ).data( 'target' ) + ' tbody' );
					var template = $( '#' + $( this ).data( 'template' ) ).html();
					target.append( template );
					$( document.body ).trigger( 'wc-enhanced-select-init' );
				} );
				$( document ).on( 'click', '.rd-ppg-remove-row', function() {
					$( this ).closest( 'tr' ).remove();
				} );
			} );
		</script>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['rd_ppg_partner_nonce'] ) || ! wp_verify_nonce( $_POST['rd_ppg_partner_nonce'], 'rd_ppg_save_partner' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, 'rd_ppg_active', empty( $_POST['rd_ppg_active'] ) ? 'no' : 'yes' );
		update_post_meta( $post_id, 'rd_ppg_commission_paused', empty( $_POST['rd_ppg_commission_paused'] ) ? 'no' : 'yes' );
		update_post_meta( $post_id, 'rd_ppg_allow_existing_customers', empty( $_POST['rd_ppg_allow_existing_customers'] ) ? 'no' : 'yes' );
		update_post_meta( $post_id, 'rd_ppg_bonus_existing_customers', empty( $_POST['rd_ppg_bonus_existing_customers'] ) ? 'no' : 'yes' );

		update_post_meta( $post_id, 'rd_ppg_commission_rate', round( max( 0, floatval( $_POST['rd_ppg_commission_rate'] ?? 0 ) ), 2 ) );
		update_post_meta( $post_id, 'rd_ppg_points_multiplier', round( max( 0, floatval( $_POST['rd_ppg_points_multiplier'] ?? 1.5 ) ), 2 ) );
		update_post_meta( $post_id, 'rd_ppg_signup_bonus', absint( $_POST['rd_ppg_signup_bonus'] ?? 0 ) );

		$bonus_expiry = trim( (string) wp_unslash( $_POST['rd_ppg_signup_bonus_expiry_days'] ?? '' ) );
		update_post_meta( $post_id, 'rd_ppg_signup_bonus_expiry_days', '' === $bonus_expiry ? '' : max( 1, min( 365, absint( $bonus_expiry ) ) ) );
		update_post_meta( $post_id, 'rd_ppg_window_months', max( 1, absint( $_POST['rd_ppg_window_months'] ?? 12 ) ) );

		$deal_end = sanitize_text_field( wp_unslash( $_POST['rd_ppg_deal_end'] ?? '' ) );
		update_post_meta( $post_id, 'rd_ppg_deal_end', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $deal_end ) ? $deal_end : '' );
		update_post_meta( $post_id, 'rd_ppg_commission_order_cap', absint( $_POST['rd_ppg_commission_order_cap'] ?? 0 ) );
		update_post_meta( $post_id, 'rd_ppg_landing_url', esc_url_raw( wp_unslash( $_POST['rd_ppg_landing_url'] ?? '' ) ) );

		$perk_mode = isset( $_POST['rd_ppg_redemption_mode'] ) ? sanitize_key( $_POST['rd_ppg_redemption_mode'] ) : 'off';
		if ( ! in_array( $perk_mode, array( 'off', 'items', 'all' ), true ) ) {
			$perk_mode = 'off';
		}
		update_post_meta( $post_id, 'rd_ppg_redemption_mode', $perk_mode );
		update_post_meta( $post_id, 'rd_ppg_premium_redemption_cap', round( min( 100, max( 0, floatval( $_POST['rd_ppg_premium_redemption_cap'] ?? 20 ) ) ), 2 ) );

		$redemption_products = isset( $_POST['rd_ppg_redemption_products'] ) ? array_map( 'absint', (array) $_POST['rd_ppg_redemption_products'] ) : array();
		update_post_meta( $post_id, 'rd_ppg_redemption_products', array_values( array_unique( array_filter( $redemption_products ) ) ) );

		$redemption_categories = isset( $_POST['rd_ppg_redemption_categories'] ) ? array_map( array( __CLASS__, 'resolve_category_id' ), (array) $_POST['rd_ppg_redemption_categories'] ) : array();
		update_post_meta( $post_id, 'rd_ppg_redemption_categories', array_values( array_unique( array_filter( $redemption_categories ) ) ) );

		$parent_code = RD_PPG_Referral::sanitize_code( wp_unslash( $_POST['rd_ppg_parent_code'] ?? '' ) );
		if ( '' !== $parent_code && self::code_taken( $parent_code, $post_id ) ) {
			set_transient( 'rd_ppg_code_error_' . get_current_user_id(), sprintf( __( 'Parent code %s is already used by another partner and was not saved.', 'rd-partner-program' ), $parent_code ), 60 );
		} else {
			update_post_meta( $post_id, 'rd_ppg_parent_code', $parent_code );
		}

		$coach_codes = array();
		$labels      = isset( $_POST['rd_ppg_coach_label'] ) ? (array) wp_unslash( $_POST['rd_ppg_coach_label'] ) : array();
		$codes       = isset( $_POST['rd_ppg_coach_code'] ) ? (array) wp_unslash( $_POST['rd_ppg_coach_code'] ) : array();
		foreach ( $codes as $i => $code ) {
			$code = RD_PPG_Referral::sanitize_code( $code );
			if ( '' === $code ) {
				continue;
			}
			if ( self::code_taken( $code, $post_id ) ) {
				set_transient( 'rd_ppg_code_error_' . get_current_user_id(), sprintf( __( 'Coach code %s is already used by another partner and was skipped.', 'rd-partner-program' ), $code ), 60 );
				continue;
			}
			$coach_codes[] = array(
				'label' => sanitize_text_field( $labels[ $i ] ?? '' ),
				'code'  => $code,
			);
		}
		update_post_meta( $post_id, 'rd_ppg_coach_codes', $coach_codes );

		$categories = array();
		$terms      = isset( $_POST['rd_ppg_elig_cat_term'] ) ? (array) $_POST['rd_ppg_elig_cat_term'] : array();
		$cat_rates  = isset( $_POST['rd_ppg_elig_cat_rate'] ) ? (array) $_POST['rd_ppg_elig_cat_rate'] : array();
		$cat_comms  = isset( $_POST['rd_ppg_elig_cat_comm'] ) ? (array) $_POST['rd_ppg_elig_cat_comm'] : array();
		foreach ( $terms as $i => $term_id ) {
			$term_id = self::resolve_category_id( $term_id );
			if ( ! $term_id ) {
				continue;
			}
			$categories[] = array(
				'term_id'    => $term_id,
				'rate'       => round( max( 0, floatval( $cat_rates[ $i ] ?? 0 ) ), 2 ),
				'commission' => self::commission_value( $cat_comms[ $i ] ?? '' ),
			);
		}
		update_post_meta( $post_id, 'rd_ppg_eligible_categories', $categories );

		$products   = array();
		$prod_ids   = isset( $_POST['rd_ppg_elig_prod_id'] ) ? (array) $_POST['rd_ppg_elig_prod_id'] : array();
		$prod_rates = isset( $_POST['rd_ppg_elig_prod_rate'] ) ? (array) $_POST['rd_ppg_elig_prod_rate'] : array();
		$prod_comms = isset( $_POST['rd_ppg_elig_prod_comm'] ) ? (array) $_POST['rd_ppg_elig_prod_comm'] : array();
		foreach ( $prod_ids as $i => $product_id ) {
			$product_id = absint( $product_id );
			if ( ! $product_id ) {
				continue;
			}
			$products[] = array(
				'product_id' => $product_id,
				'rate'       => round( max( 0, floatval( $prod_rates[ $i ] ?? 0 ) ), 2 ),
				'commission' => self::commission_value( $prod_comms[ $i ] ?? '' ),
			);
		}
		update_post_meta( $post_id, 'rd_ppg_eligible_products', $products );

		$excluded = array();
		$excl_ids = isset( $_POST['rd_ppg_excl_prod_id'] ) ? (array) $_POST['rd_ppg_excl_prod_id'] : array();
		foreach ( $excl_ids as $product_id ) {
			$product_id = absint( $product_id );
			if ( $product_id ) {
				$excluded[] = $product_id;
			}
		}
		update_post_meta( $post_id, 'rd_ppg_excluded_products', array_values( array_unique( $excluded ) ) );

		$dash_users = isset( $_POST['rd_ppg_dashboard_users'] ) ? array_map( 'absint', (array) $_POST['rd_ppg_dashboard_users'] ) : array();
		$dash_users = array_values( array_unique( array_filter( $dash_users ) ) );

		if ( ! empty( $_POST['rd_ppg_create_account'] ) && current_user_can( 'create_users' ) ) {
			$new_user_id = self::create_partner_account(
				sanitize_text_field( wp_unslash( $_POST['rd_ppg_new_account_name'] ?? '' ) ),
				sanitize_email( wp_unslash( $_POST['rd_ppg_new_account_email'] ?? '' ) )
			);
			if ( $new_user_id ) {
				$dash_users[] = $new_user_id;
			}
		}

		update_post_meta( $post_id, 'rd_ppg_dashboard_users', $dash_users );
	}

	/**
	 * A per item commission field left blank means "use the partner default"
	 * and is stored as null. Any number, including 0, is an explicit rate.
	 */
	private static function commission_value( $raw ) {
		$raw = is_scalar( $raw ) ? trim( (string) wp_unslash( $raw ) ) : '';
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return null;
		}
		return round( min( 100, max( 0, floatval( $raw ) ) ), 2 );
	}

	/**
	 * WooCommerce's category search select posts the term slug unless the field
	 * carries data-return_id, so a posted value can be a numeric id or a slug.
	 * Returns the product_cat term id, or 0 when the value matches no category.
	 */
	public static function resolve_category_id( $value ) {
		$value = is_scalar( $value ) ? trim( (string) wp_unslash( $value ) ) : '';
		if ( '' === $value ) {
			return 0;
		}
		if ( is_numeric( $value ) ) {
			$term = get_term( absint( $value ), 'product_cat' );
			return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
		}
		$term = get_term_by( 'slug', sanitize_title( $value ), 'product_cat' );
		return $term ? (int) $term->term_id : 0;
	}

	/**
	 * Creates a customer account for an affiliate partner and sends the standard
	 * password setup email. Returns the new user id or 0 on failure, with the
	 * outcome reported through an admin notice.
	 */
	private static function create_partner_account( $name, $email ) {
		if ( ! is_email( $email ) ) {
			self::add_notice( __( 'Account not created: a valid email address is required.', 'rd-partner-program' ), 'error' );
			return 0;
		}
		if ( email_exists( $email ) ) {
			self::add_notice( sprintf( __( 'Account not created: %s is already registered. Link the existing account with the search field instead.', 'rd-partner-program' ), $email ), 'error' );
			return 0;
		}

		$base     = sanitize_user( strtolower( strtok( $email, '@' ) ), true );
		$username = $base ? $base : 'partner';
		$suffix   = 1;
		while ( username_exists( $username ) ) {
			$suffix++;
			$username = $base . $suffix;
		}

		$user_id = wp_create_user( $username, wp_generate_password( 24 ), $email );
		if ( is_wp_error( $user_id ) ) {
			self::add_notice( sprintf( __( 'Account not created: %s', 'rd-partner-program' ), $user_id->get_error_message() ), 'error' );
			return 0;
		}

		$update = array( 'ID' => $user_id );
		if ( '' !== $name ) {
			$update['display_name'] = $name;
			$update['first_name']   = $name;
		}
		if ( get_role( 'customer' ) ) {
			$update['role'] = 'customer';
		}
		wp_update_user( $update );

		wp_new_user_notification( $user_id, null, 'user' );

		self::add_notice( sprintf( __( 'Account %1$s created and linked. A password setup email was sent to %2$s.', 'rd-partner-program' ), $username, $email ), 'success' );
		return $user_id;
	}

	private static function add_notice( $message, $type ) {
		set_transient( 'rd_ppg_admin_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), 60 );
	}

	/**
	 * Checks whether a code collides with any other partner parent code or coach code.
	 */
	private static function code_taken( $code, $exclude_post_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'rd_partner',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post__not_in'   => array( $exclude_post_id ),
			)
		);
		foreach ( $ids as $id ) {
			if ( strtoupper( (string) get_post_meta( $id, 'rd_ppg_parent_code', true ) ) === $code ) {
				return true;
			}
			$coach_codes = get_post_meta( $id, 'rd_ppg_coach_codes', true );
			if ( is_array( $coach_codes ) ) {
				foreach ( $coach_codes as $row ) {
					if ( isset( $row['code'] ) && strtoupper( $row['code'] ) === $code ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	public static function code_notices() {
		$message = get_transient( 'rd_ppg_code_error_' . get_current_user_id() );
		if ( $message ) {
			delete_transient( 'rd_ppg_code_error_' . get_current_user_id() );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
		$notice = get_transient( 'rd_ppg_admin_notice_' . get_current_user_id() );
		if ( $notice && is_array( $notice ) ) {
			delete_transient( 'rd_ppg_admin_notice_' . get_current_user_id() );
			$class = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( 'rd_partner' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$url = wp_nonce_url(
			admin_url( 'admin.php?action=rd_ppg_duplicate_partner&partner_id=' . $post->ID ),
			'rd_ppg_duplicate_' . $post->ID
		);
		$actions['rd_ppg_duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate', 'rd-partner-program' ) . '</a>';
		return $actions;
	}

	/**
	 * Copies a partner's deal settings into a new Draft. Codes, linked accounts,
	 * and the pause state are cleared on purpose: a copy must never tag customers
	 * or expose a dashboard until it is reviewed and published with its own codes.
	 * Stats and commissions stay with the original, they key on the post id.
	 */
	public static function duplicate_partner() {
		$source_id = isset( $_GET['partner_id'] ) ? absint( $_GET['partner_id'] ) : 0;
		check_admin_referer( 'rd_ppg_duplicate_' . $source_id );

		$source = get_post( $source_id );
		if ( ! $source || 'rd_partner' !== $source->post_type || ! current_user_can( 'edit_post', $source_id ) ) {
			wp_die( esc_html__( 'Affiliate Partner not found or no permission.', 'rd-partner-program' ) );
		}

		$new_id = wp_insert_post(
			array(
				'post_type'   => 'rd_partner',
				'post_status' => 'draft',
				'post_title'  => sprintf( __( '%s (Copy)', 'rd-partner-program' ), $source->post_title ),
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		$copy_keys = array(
			'rd_ppg_active',
			'rd_ppg_commission_rate',
			'rd_ppg_points_multiplier',
			'rd_ppg_signup_bonus',
			'rd_ppg_signup_bonus_expiry_days',
			'rd_ppg_eligible_categories',
			'rd_ppg_eligible_products',
			'rd_ppg_excluded_products',
			'rd_ppg_window_months',
			'rd_ppg_deal_end',
			'rd_ppg_commission_order_cap',
			'rd_ppg_landing_url',
			'rd_ppg_redemption_products',
			'rd_ppg_redemption_categories',
			'rd_ppg_redemption_mode',
			'rd_ppg_premium_redemption_cap',
			'rd_ppg_allow_existing_customers',
			'rd_ppg_bonus_existing_customers',
		);
		foreach ( $copy_keys as $key ) {
			$value = get_post_meta( $source_id, $key, true );
			update_post_meta( $new_id, $key, $value );
		}

		update_post_meta( $new_id, 'rd_ppg_parent_code', '' );
		update_post_meta( $new_id, 'rd_ppg_coach_codes', array() );
		update_post_meta( $new_id, 'rd_ppg_dashboard_users', array() );
		update_post_meta( $new_id, 'rd_ppg_commission_paused', 'no' );

		self::add_notice( sprintf( __( 'Duplicated %s as a draft. Set the new parent code and coach codes, then publish.', 'rd-partner-program' ), $source->post_title ), 'success' );

		wp_safe_redirect( get_edit_post_link( $new_id, 'raw' ) );
		exit;
	}

	public static function columns( $columns ) {
		$date = isset( $columns['date'] ) ? $columns['date'] : null;
		unset( $columns['date'] );
		$columns['rd_ppg_parent_code'] = __( 'Parent Code', 'rd-partner-program' );
		$columns['rd_ppg_active']      = __( 'Active', 'rd-partner-program' );
		$columns['rd_ppg_rate']        = __( 'Commission Rate', 'rd-partner-program' );
		$columns['rd_ppg_multiplier']  = __( 'Multiplier', 'rd-partner-program' );
		$columns['rd_ppg_customers']   = __( 'Counted Customers', 'rd-partner-program' );
		$columns['rd_ppg_owed']        = __( 'Commission Owed', 'rd-partner-program' );
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	public static function column_content( $column, $post_id ) {
		$partner = RD_PPG_Partner::get( $post_id );
		if ( ! $partner ) {
			return;
		}
		switch ( $column ) {
			case 'rd_ppg_parent_code':
				echo esc_html( $partner['parent_code'] );
				break;
			case 'rd_ppg_active':
				echo $partner['active'] ? esc_html__( 'Yes', 'rd-partner-program' ) : esc_html__( 'No', 'rd-partner-program' );
				break;
			case 'rd_ppg_rate':
				echo esc_html( $partner['commission_rate'] . '%' );
				break;
			case 'rd_ppg_multiplier':
				echo esc_html( $partner['points_multiplier'] . 'x' );
				break;
			case 'rd_ppg_customers':
				$totals = RD_PPG_Commission::merchant_totals( $post_id );
				echo esc_html( $totals['customers'] );
				break;
			case 'rd_ppg_owed':
				$totals = RD_PPG_Commission::merchant_totals( $post_id );
				echo wp_kses_post( wc_price( $totals['owed'] ) );
				break;
		}
	}
}
