<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Cart_Stock_Reducer extends WC_Integration {
    private static $inst;

	public function __construct() {
	    static::$inst = $this;

		$this->id                 = 'woocommerce-cart-stock-reducer';
		$this->method_title       = __( 'Cart Stock Reducer', 'woocommerce-cart-stock-reducer' );
		$this->method_description = __( 'Allow WooCommerce inventory stock to be reduced when adding items to cart', 'woocommerce-cart-stock-reducer' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

        add_action( 'woocommerce_update_options_integration_' .  $this->id, array( $this, 'process_admin_options' ) );

		add_filter( 'plugin_action_links', array( $this, 'action_links' ), 10, 2 );
	}

	public static function init(){
        $sessions = WC_CSR_Sessions::Instance();
        $model    = new WC_CSR_Model(static::$inst, $sessions);

        if(is_admin()){
            new WC_CSR_Admin( $sessions );
        }else{
            new WC_CSR_Customer(static::$inst, $model);
            new WC_CSR_Expiry(static::$inst);
        }
    }

	 /**
	  * Generate a direct link to settings page within WooCommerce
	  *
	  */
	public function action_links( $links, $file ) {
		if ( 'woocommerce-cart-stock-reducer/woocommerce-cart-stock-reducer.php' == $file ) {
			$settings_link = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=integration' ) . '">' . __( 'Settings', 'woocommerce-cart-stock-reducer' ) . '</a>';
			// make the 'Settings' link appear first
			array_unshift( $links, $settings_link );
		}
		return $links;
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'stock_pending' => array(
				'title'             => __( 'Pending Order Text', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'text',
				'description'       => __( 'Enter alternate text to be displayed when there are items in stock but held in an existing cart.', 'woocommerce-cart-stock-reducer' ),
				'desc_tip'          => true,
				'default'           => __( "This item is not available at this time due to pending orders.", 'woocommerce-cart-stock-reducer' ),
			),
			'stock_pending_expire_time' => array(
				'title'             => __( 'Append Expiration Time to Pending Order Text', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'text',
				'description'       => sprintf( __( 'Enter text to be appended when there are items in stock but held in an existing cart. %s will be replaced with a countdown to when items might be available.', 'woocommerce-cart-stock-reducer' ), '%CSR_EXPIRE_TIME%' ),
				'desc_tip'          => false,
				'default'           => sprintf( __( 'Check back in %s to see if items become available.', 'woocommerce-cart-stock-reducer' ), '%CSR_EXPIRE_TIME%' ),
			),
			'stock_pending_include_cart_items' => array(
				'title'             => __( 'Append Included Items to Pending Order Text', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'text',
				'description'       => sprintf( __( 'Enter text to be appended when the there are pending items in the users cart. %s will be replaced with the number of items in cart.', 'woocommerce-cart-stock-reducer' ), '%CSR_NUM_ITEMS%' ),
				'desc_tip'          => false,
				'default'           => sprintf( __( 'Pending orders include %s items already added to your cart.', 'woocommerce-cart-stock-reducer' ), '%CSR_NUM_ITEMS%' ),
			),
			'expire_items' => array(
				'title'             => __( 'Expire Items', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'checkbox',
				'label'             => __( 'Enable Item Expiration', 'woocommerce-cart-stock-reducer' ),
				'default'           => 'no',
				'description'       => __( "If checked, items that stock is managed for will expire from carts.  You MUST set an 'Expire Time' below if you use this option", 'woocommerce-cart-stock-reducer' ),
			),
			'expire_time' => array(
				'title'             => __( 'Expire Time', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'text',
				'description'       => __( 'How long before item expires from cart', 'woocommerce-cart-stock-reducer' ),
				'desc_tip'          => true,
				'placeholder'       => 'Examples: 10 minutes, 1 hour, 6 hours, 1 day',
				'default'           => ''
			),
			'expire_countdown' => array(
				'title'             => __( 'Expire Countdown', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'select',
				'label'             => __( 'Enable Expiration Countdown', 'woocommerce-cart-stock-reducer' ),
				'default'           => 'always',
				'options'           => array( 'always' => __( 'Always', 'woocommerce-cart-stock-reducer' ),
											  'addonly' => __( 'Only when items are added', 'woocommerce-cart-stock-reducer' ),
											  'never' => __( 'Never', 'woocommerce-cart-stock-reducer' ) ),
				'description'       => __( 'When to display a countdown to expiration', 'woocommerce-cart-stock-reducer' ),
			),
			'ignore_status' => array(
				'title'             => __( 'Ignore Order Status', 'woocommerce-cart-stock-reducer' ),
				'type'              => 'multiselect',
				'default'           => array(),
				'options'           => wc_get_order_statuses(),
				'description'       => __( '(Advanced Setting) WooCommerce order status that prohibit expiring items from cart', 'woocommerce-cart-stock-reducer' ),
			),
		);
	}

}
