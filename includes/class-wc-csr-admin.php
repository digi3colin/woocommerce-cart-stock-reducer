<?php
class WC_CSR_Admin{
    public function __construct( $sessions ) {
        $this->sessions = $sessions;

        add_filter( 'manage_product_posts_columns', array( $this, 'product_columns' ), 11, 1 );
        add_action( 'manage_product_posts_custom_column', array( $this, 'render_product_columns' ), 11, 1 );
    }

    /**
     * Define custom columns for products.
     *
     * @param  array $existing_columns
     *
     * @return array
     */
    public function product_columns( $existing_columns ) {
        return $this->array_insert_after( $existing_columns, 'is_in_stock', array( 'qty_in_carts' => __( 'in Carts', 'woocommerce-cart-stock-reducer' ) ) );
    }

    public function array_insert_after( $array, $after_key, $new = array() ) {
        $pos = array_search( $after_key, array_keys( $array ) );
        $pos++;
        return array_slice( $array, 0, $pos ) + $new + array_slice( $array, $pos );
    }

    /**
     * Output custom columns for products.
     *
     * @param string $column
     */
    public function render_product_columns( $column ) {
        global $post;

        if ( $column === 'qty_in_carts' ) {
            echo (int) $this->sessions->quantity_in_carts( $post->ID );
        }

    }
}