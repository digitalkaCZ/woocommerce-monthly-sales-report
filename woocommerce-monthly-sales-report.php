<?php
/*
Plugin Name: Woocommerce - Měsíční prodeje
Description: A plugin to automatically and manually export the sum of WooCommerce orders split by month, excluding cancelled orders, to a CSV file.
Version: 1.3
Author: Digitalka
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class WC_Monthly_Sales_Report {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wc_monthly_sales_report_cron_hook', array($this, 'export_csv'));
        register_activation_hook(__FILE__, array($this, 'activate_cron'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_cron'));
        add_action('admin_post_wc_manual_export_csv', array($this, 'manual_export_csv'));
    }

    public function add_admin_menu() {
        add_menu_page(
            'Report měsíčních prodejů', // Page title
            'Měsíční prodeje', // Menu title
            'manage_options', // Capability
            'wc-monthly-sales-report', // Menu slug
            array($this, 'report_page'), // Function to display the page content
            'dashicons-chart-line', // Icon
            56 // Position
        );
    }

    public function get_monthly_woocommerce_order_sums() {
        global $wpdb;

        // Query to get the sum of order totals grouped by year and month, excluding wc-cancelled orders
        $results = $wpdb->get_results("
            SELECT 
                YEAR(post_date) as year, 
                MONTH(post_date) as month, 
                SUM(meta_value) as total_sales
            FROM 
                {$wpdb->prefix}posts as posts
            INNER JOIN 
                {$wpdb->prefix}postmeta as meta 
                ON posts.ID = meta.post_id
            WHERE 
                posts.post_type = 'shop_order'
                AND posts.post_status NOT IN ('wc-cancelled')
                AND meta.meta_key = '_order_total'
            GROUP BY 
                YEAR(post_date), 
                MONTH(post_date)
            ORDER BY 
                year ASC, 
                month ASC
        ");

        // Prepare the results in an associative array
        $monthly_sales = [];
        foreach ($results as $result) {
            $year_month = $result->year . '-' . str_pad($result->month, 2, '0', STR_PAD_LEFT);
            $monthly_sales[$year_month] = $result->total_sales;
        }

        return $monthly_sales;
    }

    public function export_csv() {
        $monthly_sales = $this->get_monthly_woocommerce_order_sums();

        if (!empty($monthly_sales)) {
            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['basedir'] . '/woocommerce-monthly-sales-report.csv';

            $output = fopen($file_path, 'w');

            // Header row
            fputcsv($output, array('Month', 'Total Sales'));

            // Data rows
            foreach ($monthly_sales as $month => $total) {
                fputcsv($output, array($month, $total));
            }

            fclose($output);
        }
    }

    public function manual_export_csv() {
        // Check the nonce for security
        if (!isset($_POST['wc_manual_export_csv_nonce']) || !wp_verify_nonce($_POST['wc_manual_export_csv_nonce'], 'wc_manual_export_csv')) {
            wp_die('Security check failed');
        }

        // Export CSV
        $this->export_csv();

        // Redirect back to the report page with a success message
        wp_redirect(admin_url('admin.php?page=wc-monthly-sales-report&export=success'));
        exit;
    }

    public function report_page() {
        echo '<div class="wrap">';
        echo '<h1>Woocommerce - Měsíční prodeje</h1>';
        echo '<p>CSV soubor je pod URL /wp-content/uploads/woocommerce-monthly-sales-report.csv. Aktualizuje se jednou denně.</p>';

        // Manual Export Button
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wc_manual_export_csv">';
        wp_nonce_field('wc_manual_export_csv', 'wc_manual_export_csv_nonce');
        submit_button('Manuálně aktualizovat CSV');
        echo '</form>';

        if (isset($_GET['export']) && $_GET['export'] === 'success') {
            echo '<div class="notice notice-success"><p>CSV exported successfully!</p></div>';
        }

        $monthly_sales = $this->get_monthly_woocommerce_order_sums();

        if (!empty($monthly_sales)) {
            echo '<table class="widefat fixed" cellspacing="0">';
            echo '<thead><tr><th>Month</th><th>Total Sales</th></tr></thead>';
            echo '<tbody>';
            foreach ($monthly_sales as $month => $total) {
                echo '<tr><td>' . esc_html($month) . '</td><td>' . wc_price($total) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No sales data available.</p>';
        }

        echo '</div>';
    }

    public function activate_cron() {
        if (!wp_next_scheduled('wc_monthly_sales_report_cron_hook')) {
            wp_schedule_event(time(), 'daily', 'wc_monthly_sales_report_cron_hook');
        }
    }

    public function deactivate_cron() {
        $timestamp = wp_next_scheduled('wc_monthly_sales_report_cron_hook');
        wp_unschedule_event($timestamp, 'wc_monthly_sales_report_cron_hook');
    }
}

new WC_Monthly_Sales_Report();