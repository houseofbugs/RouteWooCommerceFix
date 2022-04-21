<?php

/**
 * WooCommerce Routeapp Cron Schedules
 *
 * @link       https://route.com/
 * @since      1.0.0
 *
 * @package    Routeapp
 * @subpackage Routeapp/includes
 */

class Routeapp_Cron_Schedules
{

    private $routeapp_webhooks;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
   public function __construct() {
        $this->routeapp_webhooks = new Routeapp_Webhooks();
        add_filter('cron_schedules', array($this, 'wpcron_schedule'));
        add_action('routeapp_check_for_missing_orders', array($this, 'routeapp_validation_worker'));
        add_action('routeapp_check_for_weekly_missing_shipments', array($this, 'routeapp_weekly_missing_shipments_worker'));
        add_action('routeapp_check_for_missing_shipments', array($this, 'routeapp_missing_shipments_worker'));
        add_action('routeapp_check_for_invalid_webhooks', array($this, 'routeapp_webhooks_validator'));
        $this->init();
   }

   /**
    * Add wp_cron Minutely and Biminutely Interval Schedules if not is scheduled
    *
    * @since    1.0.3
    * @return   void
    */
   public function init() {
      $this->set_schedule_event('routeapp_daily', 'routeapp_check_for_missing_orders');
      $this->set_schedule_event('routeapp_5_hours', 'routeapp_check_for_missing_shipments');
      $this->set_schedule_event('routeapp_daily', 'routeapp_check_for_weekly_missing_shipments');
      $this->set_schedule_event('routeapp_5_hours', 'routeapp_check_for_invalid_webhooks');
   }

    public function set_schedule_event($event, $action) {
        if (!wp_next_scheduled($action)) {
            wp_schedule_event(time(), $event, $action);
        };
    }

    /**
     * @param $routeapp_client
     * @param array $trackingPayload
     * @param $order
     * @param $params
     * @return bool
     */
    public static function resend_shipment(array $trackingPayload)
    {
        $routeapp_client = Routeapp_API_Client::getInstance();

        try {
            $response = $routeapp_client->create_shipment($trackingPayload['tracking_number'], $trackingPayload);

            if (!is_wp_error($response) &&
                (wp_remote_retrieve_response_code($response) == 409 ||
                 wp_remote_retrieve_response_code($response) == 201)
            ) {
                return true;
            }

            return false;

        } catch (Exception $exception) {
            self::get_route_public_instance()->routeapp_log($exception, [
                'endpoint' => 'shipments',
                'params' => $trackingPayload,
                'method' => 'POST'
            ]);
            return false;
        }
    }


    /**
    * Add wp_cron Daily, Daily Interval Schedules
    *
    * @since    1.0.3
    * @param    array $schedules
    * @return   array $schedules
    */

    public function wpcron_schedule($schedules) {

        $schedules['routeapp_daily'] = array(
        'interval'  => 60 * 60 * 24,
        'display'   => __('Once Daily', 'routeapp')
        );

        $schedules['routeapp_5_hours'] = array(
           'interval'  =>  60 * 60 * 5,
           'display'   => __('Every 5 hours', 'routeapp')
        );

        return $schedules;
   }

   /**
   *
   * Check if the order is created on Route API.
   * 
   */
   public static function has_route_order_id( $order_id ) {
    return get_post_meta( $order_id, '_routeapp_order_id', true );
   }

  /**
   *
   * Get the Route Public class instance
   * 
   */
   public static function get_route_public_instance() {
       global $routeapp_public;
       return $routeapp_public;
   }

   /**
   *
   * Set the 'routeapp_shipment_cron_api_called' as a flag to avoid multiple requests to Route
   * 
   */
   public static function has_reconcile_shipping_triggered( $order_id ) {
      return get_post_meta( $order_id, 'routeapp_shipment_cron_api_called', true ) == 'success';
   }

   /**
   *
   * Get the 'routeapp_shipment_cron_api_called' as a flag see if the order has already be sent to Route
   * 
   */
   public static function set_reconcile_shipping_triggered( $order_id ) {
      update_post_meta( $order_id, 'routeapp_shipment_cron_api_called', 'success' );
   }

    /**
     * Periodic Worker Triggering Missing Shippings on Route
     *
     * @return void
     */
    public static function routeapp_reconcile_shippings($date_query)
    {
        $args = array(
            'post_type' => 'shop_order',
            'posts_per_page' => -1,
            'post_status' => array_keys(wc_get_order_statuses()),
            'date_query' => $date_query,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'routeapp_shipment_tracking_number',
                    'value' => '',
                    'compare' => '!='
                )
            )
        );

        $query = new WP_Query($args);
        $orders = $query->get_posts();
        $routeapp_client = Routeapp_API_Client::getInstance();

        if (count($orders) > 0) {
            foreach ($orders as $order) {

                $order = wc_get_order($order->ID);

                if (!$order || self::has_reconcile_shipping_triggered($order->get_id()) || !self::has_route_order_id($order->get_id())) {
                    continue;
                }

                try {

                    $trackingPayloads = self::format_shipping_data_api($order->get_id());
                    $tracking_numbers = array();
                    foreach ($trackingPayloads as $trackingPayload){
                        $shipmentResponse = $routeapp_client->get_shipment($trackingPayload['tracking_number'], $order->get_id());
                        $tracking_numbers[] = $trackingPayload['tracking_number'];
                        if (is_wp_error($shipmentResponse) || (isset($shipmentResponse['response']['code']) && $shipmentResponse['response']['code'] == 200)) {
                            continue;
                        }

                        self::resend_shipment($trackingPayload);
                    }

                    //save on post_meta the tracking ids
                    if (!empty($tracking_numbers)) {
                        $tracking_numbers = implode(Routeapp_WooCommerce_Tracking_Provider::SEPARATOR_PIPE, $tracking_numbers);
                        update_post_meta( $order->get_id(), 'routeapp_shipment_tracking_number', $tracking_numbers );
                    }

                    self::set_reconcile_shipping_triggered($order->get_id());

                } catch (Exception $exception) {
                    continue;
                }
            }
        }
    }


    /**
     * create shipping data sending for API
     * @since    1.0.0
     * @param  integer $order_id
     */
    public static function format_shipping_data_api($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) return [];

        $tracking_number = get_post_meta($order->get_id(), 'routeapp_shipment_tracking_number', true);
        $routeapp_shipment_tracking = new Routeapp_Shipment_Tracking();
        $courier_id = $routeapp_shipment_tracking->get_shipping_provider_name($order_id);
        $product_ids = array();

        foreach ($order->get_items() as $order_item) {
            for ($i = 1; $i <= $order_item->get_quantity(); $i++) {
                if ($order_item->get_product() instanceof WC_Product) {
                    array_push($product_ids, $order_item->get_product()->get_id());
                }
            }
        }

        if (strpos($tracking_number, Routeapp_WooCommerce_Tracking_Provider::SEPARATOR_PIPE) > 0) {
           $tracking_numbers = explode(Routeapp_WooCommerce_Tracking_Provider::SEPARATOR_PIPE, $tracking_number);
        } elseif (strpos($tracking_number, Routeapp_WooCommerce_Tracking_Provider::SEPARATOR_COMMA) > 0) {
           $tracking_numbers = explode(Routeapp_WooCommerce_Tracking_Provider::SEPARATOR_COMMA, $tracking_number);
        }else{
           $tracking_numbers = [$tracking_number];
        }

        $trackings = [];

        foreach ($tracking_numbers as $tracking){
            if ($tracking!='') {
                $trackings[] = [
                    'source_order_id' => $order->get_id(),
                    'source_product_ids' => $product_ids,
                    'courier_id' => is_string($courier_id) ? $courier_id : $courier_id[$tracking],
                    'tracking_number' => $tracking,
                ];
            }
        }
        if(empty($trackings[0])) {
            //we don't have shipping on our post_meta yet, try to get the shipping data from third party module
            $trackings = $routeapp_shipment_tracking->get_shipping_info($order_id);
        }

        return $trackings;
    }


    /**
    * Periodic Worker Triggering Transaction Validation Jobs on Route
    *
    * @return void
    */
    function routeapp_validation_worker() {
        $after = get_option('_routeapp_last_install_date');
        $date_query = array(
           'column' => 'post_modified_gmt',
           'before' => '-5 hour',
           'after' => $after,
           'inclusive' => true,
        );

        $args = array(
            'post_type' => 'shop_order',
            'posts_per_page' => -1,
            'post_status' => array_keys( wc_get_order_statuses() ),
            'date_query' => $date_query,
            'meta_query' => array(
                array(
                    'key' => '_routeapp_order_id',
                    'compare' => 'NOT EXISTS'
                ),
            )
        );

        $query = new WP_Query( $args );

        if (count($query->get_posts()) > 0) {
            foreach ($query->get_posts() as $post) {
                $order = wc_get_order( $post->ID );

                //check if order exists on Route side
                $getOrderResponse = Routeapp_API_Client::getInstance()->get_order($post->ID);

                if (is_wp_error($getOrderResponse) || !in_array($getOrderResponse['response']['code'], [200, 404])) {
                    continue;
                }

                if ($getOrderResponse['response']['code'] == 200 ) {
                    //order was found, save route order id in db
                    $routeOrder = json_decode($getOrderResponse['body'], true);
                    update_post_meta( $order->get_id(), '_routeapp_order_id', $routeOrder['id'] );
                    $routeCharge = $routeOrder['insured_status'] == 'insured_selected' ? $routeOrder['paid_to_insure'] : '';
                    update_post_meta( $order->get_id(), '_routeapp_route_charge', $routeCharge );
                    $protected = !empty($routeCharge) ? 1 : 0;
                    update_post_meta( $order->get_id(), '_routeapp_route_protection', $protected );
                } else {
                    //order not found, create from our side
                    $routeapp_public = self::get_route_public_instance();
                    if (!$routeapp_public->routeapp_is_shipping_method_allowed(false, $order)) continue;
                    $orderData = $routeapp_public->create_order_data_api($post->ID);
                    $response = Routeapp_API_Client::getInstance()->create_order($orderData);
                    if (!is_wp_error( $response )) {
                        $body = json_decode( $response['body'] );
                        try {
                            if ($response['response']['code'] > 201 && $response['response']['code'] < 409) {
                                $note = 'Route API Error while posting data. Errorcode- '.$response['response']['code'];
                                throw new Exception($note);
                            } else if (!empty($body->id) && !empty($body->order_number) || $response['response']['code'] == 409) {
                                update_post_meta( $order->get_id(), '_routeapp_order_id', $body->id );
                                $routeCharge = $body->insured_status == 'insured_selected' ? $body->paid_to_insure : '';
                                update_post_meta( $order->get_id(), '_routeapp_route_charge', $routeCharge );
                                $protected = !empty($routeCharge) ? 1 : 0;
                                update_post_meta( $order->get_id(), '_routeapp_route_protection', $protected );
                            }
                        } catch(Exception $exception) {
                            $routeapp_public = self::get_route_public_instance();
                            $extraData = array(
                                'params' => $orderData,
                                'method' => 'POST',
                                'endpoint' => 'orders'
                            );
                            $routeapp_public->routeapp_log($exception, $extraData);
                        }
                    }
                }
            }
        }
    }

    /**
     * Periodic Worker that checks Route Webhooks validations
     *
     *
     * @return void
     */
    function routeapp_webhooks_validator() {
        $this->routeapp_webhooks->upsert_webhooks();
    }

    public function routeapp_missing_shipments_worker() {
        $after = get_option('_routeapp_last_install_date');
        $args = array(
            'column' => 'post_modified_gmt',
            'before' => '-5 hour',
            'after' => $after,
            'inclusive' => true,
        );

        $this->routeapp_reconcile_shippings($args);
    }

    public function routeapp_weekly_missing_shipments_worker() {
        $after = get_option('_routeapp_last_install_date');
        $args = array(
            'column' => 'post_modified_gmt',
            'before' => '-1 week',
            'after' => $after,
            'inclusive' => true,
        );

        $this->routeapp_reconcile_shippings($args);
    }
}
