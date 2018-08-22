<?php
/* coding for customer */
class WC_CSR_Customer{
    private $expiration_notice_added = false;
    private $item_expire_message = null;
    private $countdown_seconds   = array();
    private $language = null;
    private $num_expiring_items = 0;

    public function __construct($client, $model) {
        $this->client = $client;
        $this->model = $model;

        $this->expire_countdown = $client->get_option('expire_countdown');
        $this->expire_items     = $client->get_option('expire_items');

        // Define user set variables.
        /* strings */
        $this->stock_pending                    = $client->get_option( 'stock_pending' );
        $this->stock_pending_expire_time        = $client->get_option( 'stock_pending_expire_time' );
        $this->stock_pending_include_cart_items = $client->get_option( 'stock_pending_include_cart_items' );

        $this->plugins_url        = plugins_url( '/', dirname( __FILE__ ) );
        $this->plugin_dir         = realpath( dirname( __FILE__ ) . '/..' );

        wp_register_script( 'wc-csr-jquery-countdown', $this->plugins_url . 'assets/js/jquery-countdown/js/jquery.countdown.min.js', array( 'jquery', 'wc-csr-jquery-plugin' ), '2.0.2', true );
        wp_register_script( 'wc-csr-jquery-plugin', $this->plugins_url . 'assets/js/jquery-countdown/js/jquery.plugin.min.js', array( 'jquery' ), '2.0.2', true );
        wp_register_style( 'wc-csr-styles', $this->plugins_url . 'assets/css/woocommerce-cart-stock-reducer.css', array(), '2.10' );

        add_action( 'init', array( $this, 'load_plugin_textdomain' ) );

        // Actions/Filters related to cart item expiration
        if ( ! is_admin() || defined( 'DOING_AJAX' ) && ! defined( 'DOING_CRON' ) ) {
            add_action( 'woocommerce_add_to_cart', array( $this, 'add_to_cart' ), 10, 6 );
            add_filter( 'woocommerce_add_cart_item', array( $this, 'add_cart_item' ), 10, 2 );
            add_filter( 'woocommerce_notice_types', array( $this, 'add_countdown_to_notice' ), 10 );

            add_filter( 'wc_add_to_cart_message_html', array( $this, 'add_to_cart_message' ), 10, 2 );

            // Some Third-Party themes do not call 'woocommerce_before_main_content' action so let's call it on other likely actions
            add_action( 'woocommerce_before_single_product', array( $this, 'check_cart_items' ), 9 );
            add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_items' ), 9 );
            add_action( 'woocommerce_before_shop_loop', array( $this, 'check_cart_items' ), 9 );

            add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'check_expired_items' ), 10 );

            add_filter( 'woocommerce_get_undo_url', array( $this, 'get_undo_url' ), 10, 2 );

            add_filter( 'wc_csr_stock_pending_text', array( $this, 'replace_stock_pending_text' ), 10, 3 );
            add_filter( 'woocommerce_get_availability_text', array( $this, 'get_availability_text' ), 10, 2 );
        }
    }

    public function load_plugin_textdomain() {
        load_plugin_textdomain( 'woocommerce-cart-stock-reducer', false, plugin_basename( $this->plugin_dir ) . '/languages/' );
        $this->language = $this->find_countdown_language( apply_filters( 'wc_csr_countdown_locale', get_locale() ) );
        if ( $this->language ) {
            wp_register_script( 'wc-csr-jquery-countdown-locale', $this->plugins_url . "assets/js/jquery-countdown/js/jquery.countdown-{$this->language}.js", array( 'jquery',	'wc-csr-jquery-plugin',	'wc-csr-jquery-countdown' ), '2.0.2', true );
        }

    }

    /**
     * Search the countdown files for the closest localization match
     *
     * @param string $lang name to search
     *
     * @return null|string language name to use for countdown
     */
    public function find_countdown_language( $lang = null ) {
        if ( !empty( $lang ) ) {
            // jquery-countdown uses - as separator instead of _
            $lang = str_replace( '_', '-', $lang );
            $file = $this->plugin_dir . '/assets/js/jquery-countdown/js/jquery.countdown-' . $lang . '.js';
            if ( file_exists( $file ) ) {
                return $lang;
            } elseif ( $part = substr( $lang, 0, strpos( $lang, '-' ) ) ) {
                $file = $this->plugin_dir . '/assets/js/jquery-countdown/js/jquery.countdown-' . $part . '.js';
                if ( file_exists( $file ) ) {
                    return $part;
                }
            }
        }
        return null;
    }

    /**
     * Called from the 'woocommerce_add_to_cart' action, to add a message/countdown to the page
     *
     * @param $cart_item_key
     * @param $product_id
     * @param $quantity
     * @param $variation_id
     * @param $variation
     * @param $cart_item_data
     */
    public function add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        if ( in_array( $this->expire_countdown, array( 'always', 'addonly') ) ) {
            $earliest_expiration_time = null;
            $number_items_expiring = 0;
            $cart = WC()->cart;
            foreach ( $cart->cart_contents as $cart_id => $item ) {
                if ( isset( $item[ 'csr_expire_time' ] ) ) {
                    if ( $cart_item_key === $cart_id && ! $this->expire_notice_added() ) {
                        $item_expire_span = '<span class="wc-csr-countdown"></span>';
                        $this->countdown( $item['csr_expire_time'] );
                        $this->item_expire_message = apply_filters( 'wc_csr_expire_notice', sprintf( __( 'Please checkout within %s or this item will be removed from your cart.', 'woocommerce-cart-stock-reducer' ), $item_expire_span ), $item_expire_span, $item['csr_expire_time'], $item['csr_expire_time_text'] );
                    } else {
                        if ( null === $earliest_expiration_time || $earliest_expiration_time < $item['csr_expire_time'] ) {
                            $earliest_expiration_time = $item['csr_expire_time'];
                            $number_items_expiring ++;
                        }
                    }
                }

            }
            if ( $number_items_expiring > 0 ) {
                $this->item_expire_message .= apply_filters( 'wc_csr_expire_notice_additional', sprintf( _n( ' There is %d item expiring sooner.', ' There are %d items expiring sooner.', $number_items_expiring, 'woocommerce-cart-stock-reducer' ), $number_items_expiring ), $cart_item_key, $number_items_expiring );
            }
        }
    }

    /**
     * Called by 'woocommerce_add_cart_item' filter to add expiration time to cart items
     *
     * @param int $item Item ID
     * @param string $key Unique Cart Item ID
     *
     * @return mixed
     */
    public function add_cart_item( $item, $key ) {
        if ( isset( $item[ 'data' ] ) ) {
            $product = $item[ 'data' ];
            if ( 'yes' === $this->expire_items && $this->get_item_managing_stock( $product ) ) {
                $expire_time_text = null;
                if ( ! empty( $this->expire_time ) ) {
                    // Check global expiration time
                    $expire_time_text = $this->expire_time;
                }
                $expire_custom_key = apply_filters( 'wc_csr_expire_custom_key', 'csr_expire_time', $item, $key );
                if ( ! empty( $expire_custom_key ) ) {
                    // Check item specific expiration
                    $item_expire_time = get_post_meta( $item[ 'product_id' ], $expire_custom_key, true );
                    if ( ! empty( $item_expire_time ) ) {
                        $expire_time_text = $item_expire_time;
                    }
                }
                $expire_time_text = apply_filters( 'wc_csr_expire_time_text', $expire_time_text, $item, $key, $this );
                if ( null !== $expire_time_text && 'never' !== $expire_time_text ) {
                    $item[ 'csr_expire_time' ] = apply_filters( 'wc_csr_expire_time', strtotime( $expire_time_text ), $expire_time_text, $item, $key, $this );
                    $item[ 'csr_expire_time_text' ] = $expire_time_text;
                }
            }

        }
        return $item;
    }

    /**
     * Called by 'woocommerce_notice_types' filter to make sure any time a countdown is displayed the javascript is included
     * @param $type
     * @return mixed
     */
    public function add_countdown_to_notice( $type ) {
        if ( $this->expire_notice_added() ) {
            $expire_soonest = $this->expire_items();
            $this->countdown( $expire_soonest );
        }
        return $type;
    }

    /**
     * Called by the 'wc_add_to_cart_message' filter to append an internal message
     * @param string $message
     * @param int $product_id
     *
     * @return string
     */
    public function add_to_cart_message( $message, $product_id = null ) {
        if ( null != $this->item_expire_message ) {
            $message .= '  ' . $this->item_expire_message;
        }
        return $message;
    }

    /**
     * Called by 'woocommerce_check_cart_items' action to expire items from cart
     */
    public function check_cart_items( ) {
        $expire_soonest = $this->expire_items();
        if ( 'always' !== $this->expire_countdown || 'POST' === strtoupper( $_SERVER[ 'REQUEST_METHOD' ] ) ) {
            // Return quickly when we don't care about notices
            return;
        }
        if ( 0 !== $expire_soonest && !$this->expire_notice_added()  ) {
            $item_expire_span = '<span class="wc-csr-countdown"></span>';
            $expire_notice_text = sprintf( _n( 'Please checkout within %s to guarantee your item does not expire.', 'Please checkout within %s to guarantee your items do not expire.', $this->num_expiring_items, 'woocommerce-cart-stock-reducer' ), $item_expire_span );
            $expiring_cart_notice = apply_filters( 'wc_csr_expiring_cart_notice', $expire_notice_text, $item_expire_span, $expire_soonest, $this->num_expiring_items );
            // With WooCommerce 3.x they remove and re-add notices when cart is updated so we are now manually including our own notice on the cart page
            echo "<div class='wc-csr-info'>$expiring_cart_notice</div>";
            $this->countdown( $expire_soonest );
        } elseif ( 0 === $expire_soonest ) {
            // Make sure a countdown notice is removed if there is not an item expiring
            $this->remove_expire_notice();
        }
    }

    /**
     * Called by 'woocommerce_cart_loaded_from_session' action to expire items from cart
     */
    public function check_expired_items( ) {
        $this->expire_items();
    }


    /**
     * Expire items and returns the soonest time an item expires
     * @return int Time when an item expires
     */
    public function expire_items() {
        $expire_soonest = 0;
        $item_expiring_soonest = null;
        $num_expiring_items = 0;
        $cart = WC()->cart;
        if ( null === $cart ) {
            return;
        }
        $order_awaiting_payment = WC()->session->get( 'order_awaiting_payment', null );

        foreach ( $cart->cart_contents as $cart_id => $item ) {
            if ( isset( $item[ 'csr_expire_time' ] ) ) {
                if ( $this->is_expired( $item[ 'csr_expire_time' ], $order_awaiting_payment ) ) {
                    // Item has expired
                    $this->remove_expired_item( $cart_id, $cart );
                } elseif ( 0 === $expire_soonest || $item[ 'csr_expire_time' ] < $expire_soonest ) {
                    // Keep track of the soonest expiration so we can notify
                    $expire_soonest = $item[ 'csr_expire_time' ];
                    $item_expiring_soonest = $cart_id;
                    $num_expiring_items += $item[ 'quantity' ];
                } else {
                    $num_expiring_items += $item[ 'quantity' ];
                }
            }

        }
        $this->num_expiring_items = $num_expiring_items;
        return $expire_soonest;

    }

    /**
     * @param $cart_id
     * @param null $cart
     */
    protected function remove_expired_item( $cart_id, $cart = null ) {
        if ( null === $cart ) {
            // This should never happen, but better to be safe
            $cart = WC()->cart;
        }
        if ( !isset( $cart, $cart->cart_contents, $cart->cart_contents[ $cart_id ] ) ) {
            // If the cart items do not exist do not try to remove them.
            return;
        }
        if ( 'yes' === $this->expire_items ) {
            $item_description = $cart->cart_contents[ $cart_id ][ 'data' ]->get_title();
            if ( !empty( $cart->cart_contents[ $cart_id ][ 'variation_id' ] ) ) {
                $product = wc_get_product( $cart->cart_contents[ $cart_id ][ 'variation_id' ] );

                if ( method_exists( $product, 'wc_get_formatted_variation' ) ) {
                    $item_description .= ' (' . $product->wc_get_formatted_variation( true ) . ')';
                }

            } else {
                $product = wc_get_product( $cart->cart_contents[ $cart_id ][ 'product_id' ] );
            }
            // Include link to item removed during notice
            $item_description = '<a href="' . esc_url( $product->get_permalink() ) . '">' . $item_description . '</a>';
            $expired_cart_notice = apply_filters( 'wc_csr_expired_cart_notice', sprintf( __( "Sorry, '%s' was removed from your cart because you didn't checkout before the expiration time.", 'woocommerce-cart-stock-reducer' ), $item_description ), $cart_id, $cart );
            wc_add_notice( $expired_cart_notice, 'error' );
            do_action( 'wc_csr_before_remove_expired_item', $cart_id, $cart );
            $cart->remove_cart_item( $cart_id );
            WC()->session->set('cart', $cart->cart_contents);
        }

    }

    public function expire_notice_added() {
        if ( true === $this->expiration_notice_added ) {
            // Don't loop through notices if we already know it has been added
            return true;
        }
        foreach ( wc_get_notices() as $type => $notices ) {
            foreach ( $notices as $notice ) {
                if ( false !== strpos( $notice, 'wc-csr-countdown' ) ) {
                    $this->expiration_notice_added = true;
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Include a countdown timer
     *
     * @param int $time Time the countdown expires.  Seconds since the epoch
     */
    protected function countdown( $time, $class = 'wc-csr-countdown' ) {
        if ( isset( $time ) ) {
            if ( empty( $this->countdown_seconds ) ) {
                // Only run this once per execution, in case we need to add more later
                add_action('wp_footer', array($this, 'countdown_footer'), 25);
                wp_enqueue_style( 'wc-csr-styles' );
                wp_enqueue_script('wc-csr-jquery-countdown');

                if ( $this->language ) {
                    wp_enqueue_script( 'wc-csr-jquery-countdown-locale' );
                }
                $this->countdown_seconds[ $class ] = $time - time();
            }
        }

    }


    /**
     * Called by 'woocommerce_get_undo_url' filter to change URL if item is managed
     *
     * @param string $url Default Undo URL
     * @param null|string $cart_item_key Item key from users cart
     *
     * @return string URL for Undo link
     */
    public function get_undo_url( $url, $cart_item_key = null ) {
        if ( null === $cart_item_key ) {
            $args = wp_parse_args( parse_url( $url, PHP_URL_QUERY ) );
            if ( isset( $args, $args[ 'undo_item' ] ) ) {
                $cart_item_key = $args[ 'undo_item' ];
            }
        }

        $cart = WC()->cart;
        if ( isset( $cart_item_key, $cart, $cart->removed_cart_contents[ $cart_item_key ] ) ) {
            $cart_item = $cart->removed_cart_contents[ $cart_item_key ];
            if ( false !== $this->get_item_managing_stock( null, $cart_item[ 'product_id' ], $cart_item[ 'variation_id' ] ) ) {
                // Only replace the URL if the item has managed stock
                $product = wc_get_product( empty( $cart_item[ 'variation_id' ] ) ? $cart_item[ 'product_id' ] : $cart_item[ 'product_id' ] );
                $url = $product->get_permalink();
            }
        }

        return $url;
    }

    /**
     * Called from the 'wp_footer' action when we want to add a footer
     */
    public function countdown_footer() {
        if ( !empty( $this->countdown_seconds ) ) {
            // Don't add any more javascript code here, if it needs added to move it to an external file
            $code = '<script type="text/javascript">';
            $url = remove_query_arg( array( 'remove_item', 'removed_item', 'add-to-cart', 'added-to-cart' ) );
            foreach ( $this->countdown_seconds as $class => $time ) {
                // @TODO Fudge the count by one second, with recent optimizations it appears that the item expirations
                // are happening exactly on time and the items would display "Items expire in 0 seconds" and not refresh
                $time += 1;
                $code .= "jQuery('.{$class}').countdown({until: '+{$time}', format: 'dhmS', layout: '{d<}{dn} {dl} {d>}{h<}{hn} {hl} {h>}{m<}{mn} {ml} {m>}{s<}{sn} {sl}{s>}', expiryUrl: '{$url}'});";
            }
            $code .= '</script>';

            echo $code;
        }
    }

    public function remove_expire_notice() {
        $entries_removed = 0;
        $wc_notices = wc_get_notices();
        foreach ( $wc_notices as $type => $notices ) {
            foreach ( $notices as $id => $notice ) {
                if ( false !== strpos( $notice, 'wc-csr-countdown' ) ) {
                    $entries_removed++;
                    unset( $wc_notices[ $type ][ $id ] );
                }
            }
        }
        if ( $entries_removed > 0 ) {
            WC()->session->set( 'wc_notices', $wc_notices );
        }
        return $entries_removed;
    }

    public function get_availability_text( $text, $product ) {
        $stock = $this->get_virtual_stock_available( $product );
        if ( isset( $stock ) && $stock <= 0 ) {
            if ( $product->backorders_allowed() ) {
                // If there are items in stock but backorders are allowed.  Only let backorders happen after existing
                // purchases have been completed or expired.  Otherwise the situation is too complicated.
                $text = apply_filters( 'wc_csr_stock_backorder_pending_text', $this->stock_pending, array(), $product );
            } elseif ( $product->backorders_allowed() && $product->backorders_require_notification() ) {
                $text = apply_filters( 'wc_csr_stock_backorder_notify_text', __( 'Available on backorder', 'woocommerce' ), array(), $product );
            } elseif ( $product->backorders_allowed() ) {
                $text = apply_filters( 'wc_csr_stock_backorder_text', __( 'In stock', 'woocommerce' ), array(), $product );
            } elseif ( ! empty( $this->stock_pending ) ) {
                // Override text via configurable option
                $text = apply_filters( 'wc_csr_stock_pending_text', $this->stock_pending, $text, $product );
            }
        }

        return $text;
    }

    public function replace_stock_pending_text( $pending_text, $info = null, $product = null ) {

        if ( null != $product && $item = $this->get_item_managing_stock( $product ) ) {
            if ( !empty( $this->stock_pending_include_cart_items ) && $this->items_in_cart( $item ) ) {
                // Only append text if enabled and there are items actually in this users cart
                $pending_include_cart_items = str_ireplace( '%CSR_NUM_ITEMS%', $this->items_in_cart( $item ), $this->stock_pending_include_cart_items );
                $pending_text .= ' ' . $pending_include_cart_items;
            }

            if ( !empty( $this->stock_pending_expire_time ) && $this->expiration_time_cache( $item ) ) {
                if ( time() < $this->expiration_time_cache( $item ) ) {
                    // Only append text if enabled and there are items that will expire
                    $pending_expire_text = str_ireplace( '%CSR_EXPIRE_TIME%', human_time_diff( time(), $this->expiration_time_cache( $item ) ), $this->stock_pending_expire_time );
                    // Was really hoping to use the jquery countdown here but the default WooCommerce templates
                    // call esc_html so I can't easily include a class here :(
                    $pending_text .= ' ' . $pending_expire_text;
                }
            }
        }

        return $pending_text;
    }

    private function expiration_time_cache( $item_id ) {

        $earliest = false;
        if ( $items = $this->sessions->find_items_in_carts( $item_id ) ) {
            $customer_id = $this->get_customer_id();
            foreach ( $items as $id => $cart_item ) {
                if ( $customer_id == $id ) {
                    // Skip customers own items
                    continue;
                }
                if ( isset( $cart_item['csr_expire_time'] ) && ( false === $earliest || $cart_item['csr_expire_time'] < $earliest ) ) {
                    $earliest = $cart_item['csr_expire_time'];
                }
            }
        }

        return $earliest;
    }

    private function items_in_cart( $item_id ) {
        $count = 0;
        if ( $cart = WC()->cart ) {
            foreach ($cart->cart_contents as $cart_id => $item) {
                if ( $item_id === $item[ 'product_id' ] || $item_id === $item[ 'variation_id' ] ) {
                    $count += $item[ 'quantity' ];
                }
            }
        }
        return $count;
    }

    public function get_virtual_stock_available( $product = null, $ignore = false ){
        return $this->model->get_virtual_stock_available( $product, $ignore);
    }

    public function get_item_managing_stock( $product = null, $product_id = null, $variation_id = null ) {
        return $this->model->get_item_managing_stock( $product, $product_id, $variation_id );
    }
}