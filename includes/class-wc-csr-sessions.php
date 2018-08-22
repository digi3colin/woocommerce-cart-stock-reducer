<?php
if ( ! defined( 'ABSPATH' ) ){ exit; }

/**
 *
 * Class WC_CSR_Sessions
 * Read WC Session and turn it into reserved quantity
 *
 */
final class WC_CSR_Sessions  {
    private static $inst;
	public static function Instance(){
	    static::$inst = isset(static::$inst) ? static::$inst : new WC_CSR_Sessions();
	    return static::$inst;
    }

    const FILTER_AFTER_PARSE_SESSIONS = 'wc_csr_filter_after_parse_sessions';
    public $holds = [];

	private function __construct() {
        global $wpdb;
        $table      = "{$wpdb->prefix}csr_holds";

//        if($this->read_from_cache($table) === true) return; //already read from cache.. done!

        //cache outdated, sync from wc sessions
        $results = $wpdb->get_results( "SELECT session_value, session_expiry FROM {$wpdb->prefix}woocommerce_sessions", OBJECT );
        foreach ( $results as $result ) {
            $session_data = maybe_unserialize($result->session_value);

            if(isset($session_data['cart'])){
                $carts = maybe_unserialize($session_data['cart']);
                foreach($carts as $cart) {
                    if(!isset($cart['quantity'])){continue;}
                    $this->apply_cart_to_holds($cart['product_id'], $cart['quantity'], $result->session_expiry);
                    $this->apply_cart_to_holds($cart['variation_id'], $cart['quantity'], $result->session_expiry);
                }
            }
        }

        //loop holds to find children

        foreach ($this->holds as $pid => $item){
            wc_get_product( $pid );
        }
        $this->holds = apply_filters(WC_CSR_Sessions::FILTER_AFTER_PARSE_SESSIONS, $this->holds);

//        $this->save_holds($table);
	}

	private function read_from_cache($table){
	    global $wpdb;

        $current_date = time();
        $hold_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $expired    = $wpdb->get_var("SELECT COUNT(expiry) FROM {$table} WHERE expiry<{$current_date}");

        if($expired > 0 || $hold_count == 0){
            //cache expired or cache is empty.. do not read from cache.
            return false;
        }

        //load from cached db
        $results = $wpdb->get_results( "SELECT `pid`, `count`, `expiry` FROM {$table}", OBJECT );
        foreach ( $results as $result ) {
            $this->holds[$result->pid] = [
                'count' => $result->count,
                'expiry' => $result->expiry
            ];
        }

        return true;
    }

	private function apply_cart_to_holds($pid, $quantity, $expiry){
	    if($pid == 0)return;

        //set default quantity
        $this->holds[$pid] = isset($this->holds[$pid]) ? $this->holds[$pid] : ['expiry' => PHP_INT_MAX, 'count' => 0];
        $this->holds[$pid]['count'] += $quantity;
        $this->holds[$pid]['expiry'] = min($this->holds[$pid]['expiry'], $expiry);
    }

    private function save_holds($table){
        global $wpdb;

        $wpdb->query("TRUNCATE TABLE $table");
        foreach ($this->holds as $pid => $item){
            $wpdb->replace($table, [
                'pid'    => $pid,
                'count'  => $item['count'],
                'expiry' => $item['expiry'],
            ]);
        }
    }

	/**
	 * Search through all sessions and count quantity of $item in all carts
	 *
	 * @param int $item WooCommerce item ID
	 * @param string $field Which field to use to match. 'product_id'
	 *
	 * @return int|double Total number of items
	 */
	public function quantity_in_carts( $product_id ) {
        return isset($this->holds[$product_id]) ? $this->holds[$product_id]['count'] : 0;
	}


}
