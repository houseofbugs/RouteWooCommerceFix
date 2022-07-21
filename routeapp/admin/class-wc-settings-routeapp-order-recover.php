<?php
/**
 * Class WC_Settings_Routeapp_Order_Recover file.
 *
 * @package Routeapp\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WC_Settings_Routeapp_Order_Recover' ) ) :

    class WC_Settings_Routeapp_Order_Recover extends WC_Settings_Page {

        public function __construct() {
            $this->id    = 'routeapp_order_recover';
            $this->label = __( 'Route Orders Sync', 'routeapp' );
            add_filter( 'woocommerce_settings_tabs_array',        array( $this, 'add_settings_page' ), 20 );
            add_action( 'woocommerce_settings_' . $this->id,      array( $this, 'output' ) );
            add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );
        }

        public static function get_route_public_instance(){
            global $routeapp_public;
            return $routeapp_public;
        }

        public function get_settings() {

            $settings = apply_filters( 'routeapp_order_recover_settings', array(
                array(
                    'name' => __( 'Route - Orders Sync', 'routeapp_order_recover' ),
                    'type' => 'title',
                    'desc' => '',
                    'id'   => 'routeapp_route_insurance_order_recover',
                ),
                array(
                    'name' => __( 'From', 'routeapp' ),
                    'type' => 'text',
                    'desc' => "Select starting date for triggering the order reconcile",
                    'id'   => 'routeapp_order_recover_from',
                    'class' => 'route-datepicker',
                ),
                array(
                    'name' => __( 'To', 'routeapp' ),
                    'type' => 'text',
                    'desc' => "Select ending date for triggering the order reconcile",
                    'id'   => 'routeapp_order_recover_to',
                    'class' => 'route-datepicker',
                ),
                array(
                    'name' => __( 'Reconcile existing orders', 'routeapp' ),
                    'type' => 'checkbox',
                    'desc' => __( 'When enabled, will update Route Charge and Route Protection on Orders section', 'routeapp'),
                    'id'   => 'routeapp_order_recover_reconcile_backend',
                    'default' => 'no'
                ),
                array(
                    'type' => 'sectionend',
                    'id'   => 'route_insurance_order_recover'
                ),
            ) );
            return apply_filters( 'woocommerce_get_settings_' . $this->id, $settings );

        }

        public function output() {
            wp_enqueue_style('route-order-sync-spinner-css', plugin_dir_url(dirname(__FILE__)) . 'public/css/jquery-spinner.min.css', array(), 1, false);
            wp_enqueue_script('route-order-sync-spinner', plugin_dir_url(dirname(__FILE__)) . 'public/js/jquery-spinner.min.js', array('jquery'), 1, false);
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_script('route-order-sync-custom', plugin_dir_url(dirname(__FILE__)) . 'public/js/routeapp-order-sync.js', array('jquery'), 1, false);
            
            $settings = $this->get_settings();
            WC_Admin_Settings::output_fields( $settings );

        }

        public function save() {
            $settings = $this->get_settings();
            if (isset($_POST) && isset($_POST['routeapp_order_recover_from']) && isset($_POST['routeapp_order_recover_to'])) {
                $args = [
                    'limit' => -1,
                    'date_created' => '<=' . $_POST['routeapp_order_recover_to'],
                    'date_created' => '>=' . $_POST['routeapp_order_recover_from'],
                ];
                $orders = wc_get_orders( $args );

                echo '<div id="route-order-sync-query-count" style="display:none;">'.count($orders).'</div>';

                if (isset($_POST['routeapp_order_recover_reconcile_backend'])) {
                    $this->updateOrderPostMeta($orders);
                } else {
                    $this->massiveOrderSave($orders);
                }
            }
            WC_Admin_Settings::save_fields( $settings );
        }

        /**
         * Trigger save for all the selected orders, which will trigger the webhook for order.update
         *
         * @param $orders
         * @return void
         */
        public function massiveOrderSave($orders) {
            foreach ($orders as $order) {
                $order->save();
            }
        }

        /**
         * Update order post_meta with route order data
         *
         * @param $orders
         * @return void
         */
        public function updateOrderPostMeta($orders) {
            foreach ($orders as $order) {
                if (!get_post_meta( $order->get_id(), '_routeapp_order_id')) {
                    //check if order exists on Route side
                    $getOrderResponse = Routeapp_API_Client::getInstance()->get_order($order->get_id());
                    if (is_wp_error($getOrderResponse) || $getOrderResponse['response']['code'] != 200) {
                        continue;
                    }
                    Routeapp_Cron_Schedules::updateOrderPostMeta($order, $getOrderResponse);
                }
            }
        }
    }
endif;
