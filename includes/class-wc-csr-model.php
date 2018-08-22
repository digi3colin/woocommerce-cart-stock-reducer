<?php

class WC_CSR_Model{
    private $client;
    private $sessions;

    public function __construct($client, $sessions) {
        $this->client = $client;
        $this->sessions = $sessions;

        $this->expire_time         = $client->get_option( 'expire_time' );
        $this->ignore_status       = $client->get_option( 'ignore_status', array() );

        // Actions/Filters to setup WC_Integration elements
//        add_filter( 'woocommerce_update_cart_validation', array( $this, 'update_cart_validation' ), 10, 4 );
//        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'add_cart_validation' ), 10, 5 );
//        add_filter( 'woocommerce_quantity_input_args', array( $this, 'quantity_input_args' ), 10, 2 );


        add_filter( 'woocommerce_product_variation_get_stock_quantity', array( $this, 'product_get_stock_quantity' ), 10, 2 );
        add_filter( 'woocommerce_product_get_stock_quantity', array( $this, 'product_get_stock_quantity' ), 10, 2 );
        add_filter( 'woocommerce_product_variation_get_stock_status', array( $this, 'product_get_stock_status' ), 10, 2 );
        add_filter( 'woocommerce_product_get_stock_status', array( $this, 'product_get_stock_status' ), 10, 2 );

        //        add_filter( 'woocommerce_get_availability_class', array( $this, 'get_availability_class' ), 10, 2 );

//        add_filter( 'woocommerce_available_variation', array( $this, 'product_available_variation' ), 10, 3 );
    }


    /**
     * Called via the 'woocommerce_update_cart_validation' filter to validate if the quantity can be updated
     * The frontend should keep users from selecting a higher than allowed number, but don't trust those pesky users!
     *
     * @param $valid
     * @param string $cart_item_key Specific key for the row in users cart
     * @param array $values Item information
     * @param int $quantity Quantity of item to be added
     *
     * @return bool true if quantity change to cart is valid
     */
    public function update_cart_validation( $valid, $cart_item_key, $values, $quantity ) {
        $product = $values['data'];
        $available = $this->get_virtual_stock_available( $product, true );
        if ( is_numeric( $available ) && $available < $quantity ) {
            wc_add_notice( __( 'Quantity requested not available', 'woocommerce-cart-stock-reducer' ), 'error' );
            $valid = false;
        }
        return $valid;
    }

    /**
     * Called via the 'woocommerce_add_to_cart_validation' filter to validate if an item can be added to cart
     * This will likely only be called if someone hasn't refreshed the item page when an item goes unavailable
     *
     * @param $valid
     * @param int $product_id Item to be added
     * @param int $quantity Quantity of item to be added
     *
     * @return bool true if addition to cart is valid
     */
    public function add_cart_validation( $valid, $product_id, $quantity, $variation_id = null, $variations = array() ) {
        if ( $item = $this->get_item_managing_stock( null, $product_id, $variation_id ) ) {
            $product = wc_get_product( $item );
            $available = $this->get_virtual_stock_available( $product );
            $backorders_allowed = $product->backorders_allowed();

            $stock = $product->get_stock_quantity( 'edit' );

            if ( true === $backorders_allowed ) {
                if ( $available < $quantity && $stock > 0 ) {
                    $backorder_text = apply_filters( 'wc_csr_item_backorder_text', __( 'Item can not be backordered while there are pending orders', 'woocommerce-cart-stock-reducer' ), $product, $available, $stock );
                    wc_add_notice( $backorder_text, 'error' );
                    $valid = false;
                }
            } elseif ( $available < $quantity ) {
                if ( $available > 0 ) {
                    wc_add_notice( sprintf( __( 'Quantity requested (%d) is no longer available, only %d available', 'woocommerce-cart-stock-reducer' ), $quantity, $available ), 'error' );
                } else {
                    wc_add_notice( __( 'Item is no longer available', 'woocommerce-cart-stock-reducer' ), 'error' );
                }
                $valid = false;
            }

        }
        return $valid;
    }

    /**
     * Called from 'woocommerce_quantity_input_args' filter to adjust the maximum quantity of items a user can select
     *
     * @param array $args
     * @param object $product WC_Product type object
     *
     * @return array
     */
    public function quantity_input_args( $args, $product ) {
        if ( 'quantity' === $args[ 'input_name' ] ) {
            $ignore = false;
        } else {
            // Ignore users quantity when looking at pages like the shopping cart
            $ignore = true;
        }
        $args[ 'max_value' ] = $this->get_virtual_stock_available( $product, $ignore );

        return $args;
    }

    public function product_get_stock_quantity( $quantity, $product ) {
        // For WooCommerce 3.x we need to make sure we return the real quantity to these functions
        // otherwise they mark items as out of stock
        if ( $this->trace_contains( ['wc_reduce_stock_levels', 'render_product_columns'] ) ) {
            return $quantity;
        }

        if($this->get_item_managing_stock( $product ) === false){
            return $quantity;
        }

        return $this->get_virtual_stock_available( $product );
    }

    public function product_get_stock_status( $status, $product ) {
        //virtual and downloadable
        if($product->get_virtual() || $product->get_downloadable() || $product->get_stock_quantity() === null){
            return $status;
        }

        $virtual_stock = $this->get_virtual_stock_available( $product);
        if ( isset( $virtual_stock ) && $virtual_stock <= 0 ) {
            $status = 'outofstock';
        }
        return $status;
    }

    /**
     * Get the quantity available of a specific item
     *
     * @param int $item The item ID
     * @param object $product WooCommerce WC_Product based class, if not passed the item ID will be used to query
     * @param string $ignore Cart Item Key to ignore in the count
     *
     * @deprecated 3.0
     *
     * @return int Quantity of items in stock
     */
    public function get_stock_available( $product_id, $variation_id = null, $product = null, $ignore = false ) {
        $stock = 0;

        $id = $this->get_item_managing_stock( $product, $product_id, $variation_id );

        if ( false === $id ) {
            // Item is not a managed item, do not return quantity
            return null;
        }

        if ( null === $product ) {
            $product = wc_get_product( $id );
        }

        $stock = $product->get_stock_quantity();


        if ( $stock > 0 ) {
            if ( $id === $variation_id ) {
                $product_field = 'variation_id';
            } else {
                $product_field = 'product_id';
            }

            // The minimum quantity of stock to have in order to skip checking carts.  This should be higher than the amount you expect could sell before the carts expire.
            // Originally was a configuration variable, but this is such an advanced option I thought it would be better as a filter.
            // Plus you can use some math to make this decision
            $min_no_check = apply_filters( 'wc_csr_min_no_check', false, $id, $stock );
            if ( false != $min_no_check && $min_no_check < (int) $stock ) {
                // Don't bother searching through all the carts if there is more than 'min_no_check' quantity
                return $stock;
            }

            $in_carts = $this->sessions->quantity_in_carts( $id, $product_field, $ignore );
            if ( 0 < $in_carts ) {
                $stock = ( $stock - $in_carts );
            }
        }
        return $stock;
    }

    /*
     *
     */
    public function product_available_variation( $var, $product, $variation ) {

        $field = $this->get_field_managing_stock( $variation );
        if ( 'product_id' === $field ) {
            // Stock is managed by main item ID
            $max_qty = $this->get_virtual_stock_available( $product, false );
        } else {
            $max_qty = $this->get_virtual_stock_available( $variation, false );
        }

        if ( $max_qty >= 0 ) {
            $var['max_qty'] = $max_qty;
        }
        return $var;
    }



    /**
     * Get the quantity available of a specific item
     *
     * @param object $product WooCommerce WC_Product based class, if not passed the item ID will be used to query
     * @param string $ignore Cart Item Key to ignore in the count
     *
     * @return int Quantity of items in stock
     */
    private $cart; //current user's cart
    public function get_virtual_stock_available( $product ) {
        $quantity = $product->get_stock_quantity('edit');
        $pid = $product->get_stock_managed_by_id();
        $virtual_stock = $quantity - $this->sessions->quantity_in_carts($pid);

        if ( is_cart() || is_checkout() || $this->trace_contains( array( 'has_enough_stock' ) ) ){
            //virtual stock need to add the stock in cart
            $virtual_stock += $this->get_current_cart_quantity($pid);
        }
        return $virtual_stock;
    }


    /**
     * Determine which item is in control of managing the inventory
     * @param object $product
     * @param int $product_id
     * @param int $variation_id
     *
     * @return bool|int
     */
    public function get_item_managing_stock( $product = null, $product_id = null, $variation_id = null ) {
        $id = false;

        // WooCommerce 3.0 changed products over to CRUD, so we need to treat it differently
        if ( null !== $product ) {
            $managing_stock = $product->managing_stock();
            if ( 'parent' === $managing_stock ) {
                $id = $product->get_parent_id();
            } elseif ( true === $managing_stock ) {
                $id = $product->get_id();
            }
        } elseif ( ! empty( $variation_id ) ) {
            // First check variation
            $product        = wc_get_product( $variation_id );
            $managing_stock = $product->managing_stock();
            if ( 'parent' === $managing_stock ) {
                $id = $product->get_parent_id();
            } elseif ( true === $managing_stock ) {
                $id = $product->get_id();
            }
        } else {
            $product = wc_get_product( $product_id );
            if ( true === $product->managing_stock() ) {
                $id = $product->get_id();
            }
        }

        return $id;
    }

    /**
     * Determine which field in DB to use for checking stock
     * @param object $product
     * @return string
     */
    private function get_field_managing_stock( $product = null ) {
        $id = $this->get_item_managing_stock( $product );

        // @TODO verify this works on all variations
        $parent = $product->get_parent_id();
        if ( empty( $parent ) ) {
            $product_field = 'product_id';
        } else {
            if ( $id === $parent ) {
                $product_field = 'product_id';
            } else {
                $product_field = 'variation_id';
            }
        }

        return $product_field;
    }


    /**
     * This is an ugly hack to help us deal with the few edge cases where WooCommerce calls get_stock_quantity() but
     * we have no way of catching if we should give them real or virtual stock.
     * @param array $haystack
     *
     * @return bool
     */
    private function trace_contains( $haystack = array() ) {
        $trace = debug_backtrace();
        foreach ( $trace as $id => $frame ) {
            if ( in_array( $frame[ 'function' ], $haystack ) ) {
                return true;
            }
        }
        return false;
    }

    private function get_current_cart_quantity($pid){
        if(!isset($this->cart)){
            $this->cart = [];
            foreach(WC()->cart->cart_contents as $item){
                $key = ($item['variation_id'] !== 0) ? $item['variation_id'] : $item['product_id'];
                $this->cart[$key] = $item['quantity'];
            }
        }
        return isset($this->cart[$pid]) ? $this->cart[$pid] : 0;
    }
}