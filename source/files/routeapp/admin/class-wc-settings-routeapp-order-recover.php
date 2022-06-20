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
                    'name' => __( 'Show query', 'routeapp' ),
                    'type' => 'checkbox',
                    'desc' => __( 'When enabled, the query used for order sync will be displayed', 'routeapp'),
                    'id'   => 'routeapp_order_recover_show_query',
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
                //trigger order reconcile
                $date_query = array(
                    'column' => 'post_modified_gmt',
                    'before' => $_POST['routeapp_order_recover_to'],
                    'after' => $_POST['routeapp_order_recover_from'],
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

                if (isset($_POST['routeapp_order_recover_show_query'])) {
                    echo '<div id="route-order-sync-query" style="display:none;">';
                    echo '<h4>Query:</h4>';
                    echo $query->request;
                    echo '<h4>Total orders matching: '.count($query->get_posts()).'</h4>';
                    echo '</div>';
                }
                echo '<div id="route-order-sync-query-count" style="display:none;">'.count($query->get_posts()).'</div>';

                Routeapp_Cron_Schedules::routeapp_run_order_reconcile($query);
            }
            WC_Admin_Settings::save_fields( $settings );
        }
    }
endif;
