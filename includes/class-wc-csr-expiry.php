<?php
class WC_CSR_Expiry{
    public function __construct($client) {
//        add_action( 'wc_csr_adjust_cart_expiration', array( $this, 'adjust_cart_expiration' ), 10, 2 );
    }

    /**
     * Called by 'wc_csr_adjust_cart_expiration' action to adjust the expiration times of the cart
     *
     * @param string $time Time string to reset item(s) to.  Default: Initial value per item
     * @param string $cart_item_key Specific item to adjust expiration time on, Default: All items
     */
    public function adjust_cart_expiration( $time = null, $cart_item_key = null ) {
        if ( $cart = WC()->cart ) {
            // Did we modify the cart
            $updated = false;

            foreach ($cart->cart_contents as $cart_id => $item) {
                if (isset($item['csr_expire_time'])) {
                    if (isset($cart_item_key) && $cart_item_key !== $cart_id) {
                        continue;
                    }
                    if ( empty( $time ) ) {
                        $time = $item['csr_expire_time_text'];
                    }
                    $cart->cart_contents[$cart_id]['csr_expire_time'] = strtotime($time);
                    $updated = true;
                }
            }
            if (true === $updated) {
                WC()->session->set('cart', $cart->cart_contents);
            }
        }
    }
}