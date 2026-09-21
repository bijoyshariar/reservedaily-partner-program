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

class RD_PPG_Eligibility {

	/**
	 * Single source of truth for program eligibility.
	 * An item qualifies when its product id is in eligible_products OR the product
	 * belongs to any term in eligible_categories, AND its id is not in excluded_products.
	 * Returns array with keys eligible (bool) and rate (float, 0 when eligible without discount).
	 */
	public static function resolve( $product_id, $partner ) {
		$result = array(
			'eligible' => false,
			'rate'     => 0.0,
		);

		if ( ! $partner || ! is_array( $partner ) ) {
			return $result;
		}

		$product_id = absint( $product_id );
		$product    = wc_get_product( $product_id );
		if ( ! $product ) {
			return $result;
		}

		$parent_id = $product->get_parent_id();
		$check_ids = array_filter( array_unique( array( $product_id, $parent_id ) ) );

		foreach ( $check_ids as $id ) {
			if ( in_array( $id, $partner['excluded_products'], true ) ) {
				return $result;
			}
		}

		foreach ( $partner['eligible_products'] as $row ) {
			$row_id = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;
			if ( $row_id && in_array( $row_id, $check_ids, true ) ) {
				$result['eligible'] = true;
				$result['rate']     = isset( $row['rate'] ) ? (float) $row['rate'] : 0.0;
				return $result;
			}
		}

		$category_target = $parent_id ? $parent_id : $product_id;
		foreach ( $partner['eligible_categories'] as $row ) {
			$term_id = isset( $row['term_id'] ) ? absint( $row['term_id'] ) : 0;
			if ( $term_id && has_term( $term_id, 'product_cat', $category_target ) ) {
				$result['eligible'] = true;
				$result['rate']     = isset( $row['rate'] ) ? (float) $row['rate'] : 0.0;
				return $result;
			}
		}

		return $result;
	}

	public static function is_eligible( $product_id, $partner ) {
		$resolved = self::resolve( $product_id, $partner );
		return $resolved['eligible'];
	}
}
