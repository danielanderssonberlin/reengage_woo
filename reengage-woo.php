<?php
/**
 * Plugin Name: Reengage Woo
 * Plugin URI:  https://example.com/
 * Description: Erstellt nach Installation eine Tabelle mit allen WordPress-Usern, ihrer Email und Datum der letzten WooCommerce-Bestellung. Basis für Re-Engagement-Workflows (Coupons, Mails).
 * Version:     0.2.0
 * Author:      Daniel Andersson
 * Text Domain: reengage-woo
 */

if (!defined('ABSPATH')) {
    exit;
}

class Reengage_Woo {
    private static $table_name;
    private static $instance = null;

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
    }

    public function __construct() {
        global $wpdb;
        self::$table_name = $wpdb->prefix . 'reengage_customers';

        register_activation_hook(__FILE__, [ $this, 'on_activation' ]);
        register_deactivation_hook(__FILE__, [ $this, 'on_deactivation' ]);

        add_action('admin_menu', [ $this, 'admin_menu' ]);
        add_action('admin_post_reengage_export_csv', [ $this, 'handle_export_csv' ]);
        add_action('admin_post_reengage_refresh', [ $this, 'handle_refresh' ]);
        add_action('admin_post_reengage_delete', [ $this, 'handle_delete' ]);
        add_action('admin_post_reengage_delete_row', [ $this, 'handle_delete_row' ]);
        add_action('admin_post_reengage_generate_coupons', [ $this, 'handle_generate_coupons' ]); 
    }

    public function handle_refresh() {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung');
        }

        check_admin_referer('reengage_refresh');
        $this->populate_table();

        wp_redirect(admin_url('tools.php?page=reengage-woo&refreshed=1'));
        exit;
    }

    public function handle_delete() {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung');
        }
        check_admin_referer('reengage_delete');
        $this->delete_table();
        
        wp_redirect(admin_url('tools.php?page=reengage-woo&deleted=1'));
        exit;
    }

    public function handle_delete_row() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Keine Berechtigung' );
        }

        if ( ! isset( $_POST['id'] ) ) {
            wp_die( 'Ungültige Anfrage (keine ID).' );
        }

        $id = intval( $_POST['id'] );

        // Prüfe Nonce (per-row)
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'reengage_delete_row_' . $id ) ) {
            wp_die( 'Ungültige Anfrage (Nonce ungültig).' );
        }

        global $wpdb;
        $deleted = $wpdb->delete( self::$table_name, [ 'id' => $id ], [ '%d' ] );

        // besser wp_safe_redirect verwenden
        wp_safe_redirect( admin_url( 'tools.php?page=reengage-woo&row_deleted=' . ( $deleted ? 1 : 0 ) ) );
        exit;
    }

    public function handle_generate_coupons() {
        if ( ! current_user_can('manage_options') ) {
            wp_die('Keine Berechtigung');
        }

        check_admin_referer('reengage_generate_coupons');

        global $wpdb;
        $three_months_ago = date('Y-m-d H:i:s', strtotime('-3 months'));

        // Inaktive Kunden holen
        $inactive_customers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT first_name, last_name, email, last_order_date
                FROM " . self::$table_name . "
                WHERE (last_order_date IS NULL 
                OR last_order_date = '0000-00-00 00:00:00' 
                OR last_order_date < %s)",
                $three_months_ago
            )
        );

        $coupons = [];

        foreach ($inactive_customers as $c) {
            if (empty($c->email)) continue;

            // Unique Code
            $coupon_code = 'REENGAGE-' . strtoupper(substr(md5($c->email . time()), 0, 10));

            // WooCommerce Coupon erstellen
            $coupon = new WC_Coupon();
            $coupon->set_code($coupon_code);
            $coupon->set_discount_type('percent');
            $coupon->set_amount(20);
            $coupon->set_email_restrictions([$c->email]);
            $coupon->set_usage_limit(1);
            $coupon->set_date_expires(strtotime('+2 months'));
            $coupon->save();

            $coupons[] = [
                'first_name'      => $c->first_name,
                'last_name'       => $c->last_name,
                'email'           => $c->email,
                'last_order_date' => $c->last_order_date,
                'voucher'         => $coupon_code,
                
            ];
        }

        // Array im Backend speichern
        update_option('reengage_last_generated_coupons', $coupons);

        // Redirect zurück zur Admin-Seite mit Erfolgsmeldung
        wp_safe_redirect(admin_url('tools.php?page=reengage-woo&coupons_generated=' . count($coupons)));
        exit;
    }





    public function on_activation() {
        $this->create_table();
        $this->populate_table();
    }

    public function on_deactivation() {
        wp_clear_scheduled_hook('reengage_daily_event');
        $this->delete_table();
    }

    public function delete_table() {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE ". self::$table_name);
    }

    private function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS " . self::$table_name . " (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_key VARCHAR(191) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            email VARCHAR(191) NOT NULL,
            first_name VARCHAR(100) DEFAULT '',
            last_name VARCHAR(100) DEFAULT '',
            last_order_date DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY customer_key (customer_key),
            KEY user_id (user_id),
            KEY email (email)
        ) " . $charset_collate . ";";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Migration: drop unique index on email if it exists and ensure indexes
        // 1) Drop UNIQUE(email) if present
        $uniqueEmail = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM " . self::$table_name . " WHERE Key_name = %s AND Non_unique = 0", 'email'));
        if ($uniqueEmail !== null) {
            $wpdb->query("ALTER TABLE " . self::$table_name . " DROP INDEX email");
        }
        // 2) Ensure customer_key column exists (older installs)
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM " . self::$table_name . " LIKE %s", 'customer_key'));
        if ($col === null) {
            $wpdb->query("ALTER TABLE " . self::$table_name . " ADD COLUMN customer_key VARCHAR(191) NOT NULL AFTER id");
        }
        // 3) Ensure UNIQUE(customer_key)
        $idx = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM " . self::$table_name . " WHERE Key_name = %s", 'customer_key'));
        if ($idx === null) {
            $wpdb->query("CREATE UNIQUE INDEX customer_key ON " . self::$table_name . " (customer_key)");
        }
        // 4) Ensure non-unique index on email
        $idxEmail = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM " . self::$table_name . " WHERE Key_name = %s", 'email'));
        if ($idxEmail === null) {
            $wpdb->query("CREATE INDEX email ON " . self::$table_name . " (email)");
        }
    }



    private function populate_table() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        global $wpdb;
        // Clear table safely before re-populating
        $wpdb->query("TRUNCATE TABLE " . self::$table_name);

        $now = current_time('mysql');
        $emails_handled = [];

        // 1️⃣ Alle abgeschlossenen WooCommerce-Bestellungen holen
        $all_orders = $wpdb->get_results("
            SELECT 
                MAX(o.customer_id) AS customer_id,
                o.billing_email AS email,
                MAX(o.date_created_gmt) AS last_order_date,
                COALESCE(a.first_name, '') AS first_name,
                COALESCE(a.last_name, '') AS last_name
            FROM {$wpdb->prefix}wc_orders o
            LEFT JOIN {$wpdb->prefix}wc_order_addresses a 
                ON a.order_id = o.id AND a.address_type = 'billing'
            WHERE o.status = 'wc-completed'
            GROUP BY o.billing_email, COALESCE(a.first_name, ''), COALESCE(a.last_name, '')
        ");

        foreach ($all_orders as $o) {
            $customer_id = $o->customer_id ? intval($o->customer_id) : 0;

            $first_name = $o->first_name ?: '';
            $last_name = $o->last_name ?: '';
            if ($customer_id > 0) {
                $key = 'user:' . $customer_id;
            } else {
                // Separate guests even if email matches by hashing email + names
                $guest_hash = md5(strtolower(trim($o->email)) . '|' . strtolower(trim($first_name)) . '|' . strtolower(trim($last_name)));
                $key = 'guest:' . $guest_hash;
            }
            $this->upsert_customer($key, $customer_id, $o->email, $o->last_order_date, $now, $first_name, $last_name);
            
            $emails_handled[] = strtolower($o->email);
        }

        // 2️⃣ Registrierte WP-User ohne Bestellung
        // Request minimal fields for performance; names fetched via user_meta
        $users = get_users(['fields' => ['ID', 'user_email']]);
        foreach ($users as $u) {
            if (!in_array(strtolower($u->user_email), $emails_handled)) {
                $first_name = get_user_meta($u->ID, 'first_name', true) ?: '';
                $last_name  = get_user_meta($u->ID, 'last_name', true) ?: '';
                $key = 'user:' . intval($u->ID);
                $this->upsert_customer($key, intval($u->ID), $u->user_email, null, $now, $first_name, $last_name);
            }
        }
    }

    /**
     * Upsert Customer: unique by customer_key
     */
    private function upsert_customer($customer_key, $user_id, $email, $last_order_date, $now, $first_name = '', $last_name = '') {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO " . self::$table_name . " (customer_key, user_id, email, first_name, last_name, last_order_date, updated_at)
                VALUES (%s, %d, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE 
                    user_id = VALUES(user_id),
                    email = VALUES(email),
                    first_name = VALUES(first_name),
                    last_name = VALUES(last_name),
                    last_order_date = VALUES(last_order_date),
                    updated_at = VALUES(updated_at)",
                $customer_key, $user_id, $email, $first_name, $last_name, $last_order_date, $now
            )
        );
    }






    public function admin_menu() {
        add_submenu_page(
            'tools.php',
            'Reengage Woo',
            'Reengage Woo',
            'manage_options',
            'reengage-woo',
            [ $this, 'admin_page' ]
        );
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['refreshed']) && $_GET['refreshed'] == 1) {
            echo '<div class="notice notice-success is-dismissible">
                    <p>Tabelle erfolgreich aktualisiert!</p>
                </div>';
        }

        if (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
            echo '<div class="notice notice-success is-dismissible">
                    <p>Tabelle erfolgreich gelöscht!</p>
                </div>';
        }

        if (isset($_GET['row_deleted']) && $_GET['row_deleted'] == 1) {
            echo '<div class="notice notice-success is-dismissible"><p>Eintrag gelöscht.</p></div>';
        }

        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM " . self::$table_name . " ORDER BY COALESCE(last_order_date, '1970-01-01') ASC");
        ?>
        <div class="wrap">
            <h1>Reengage Woo – Benutzerübersicht</h1>
            <p>Diese Tabelle enthält alle Benutzer mit ihrer E-Mail, Vorname, Nachname und dem Datum der letzten WooCommerce-Bestellung.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('reengage_export', 'reengage_export_nonce'); ?>
                <input type="hidden" name="action" value="reengage_export_csv">
                <button type="submit" class="button">Export als CSV</button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('reengage_refresh'); ?>
                <input type="hidden" name="action" value="reengage_refresh">
                <button type="submit" class="button">Tabelle aktualisieren</button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('reengage_delete'); ?>
                <input type="hidden" name="action" value="reengage_delete">
                <button type="submit" class="button">Tabelle löschen</button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('reengage_generate_coupons'); ?>
                <input type="hidden" name="action" value="reengage_generate_coupons">
                <button type="submit" class="button">Gutscheine generieren (20% / 2 Monate)</button>
            </form>

            <button id="sendTestmail">Testmail versenden</button>

            <script>
            jQuery(document).ready(function($){
                $("#sendTestmail").on("click", function(){
                    $.post(ajaxurl, { action: "reengage_get_last_generated_coupons" }, function(response){
                        if(!response.success || !response.data.length){
                            alert("Keine Gutscheine gefunden. Bitte zuerst generieren.");
                            return;
                        }

                        var firstCustomer = response.data[8];
                        if (!confirm("Soll die Testmail an " + firstCustomer.email + " gesendet werden?")) return;

                        $.post(ajaxurl, {
                            action: "send_testmail_to_customer",
                            email: firstCustomer.email,
                            first_name: firstCustomer.first_name,
                            voucher: firstCustomer.voucher
                        }, function(resp){
                            if(resp.success){
                                alert("Testmail gesendet an " + firstCustomer.email);
                            } else {
                                alert("Fehler beim Senden");
                            }
                        });
                    });
                });
            });


            </script>


            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>Vorname</th>
                        <th>Nachname</th>
                        <th>E-Mail</th>
                        <th>Letzte Bestellung</th>
                        <th>Aktionen</th> <!-- 🔹 neue Spalte -->
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($rows)): foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r->first_name); ?></td>
                        <td><?php echo esc_html($r->last_name); ?></td>
                        <td><?php echo esc_html($r->email); ?></td>
                        <td><?php echo esc_html($r->last_order_date); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;">
                                <?php wp_nonce_field( 'reengage_delete_row_' . intval( $r->id ) ); ?>
                                <input type="hidden" name="action" value="reengage_delete_row">
                                <input type="hidden" name="id" value="<?php echo intval( $r->id ); ?>">
                                <button type="submit" class="button button-small" onclick="return confirm('Eintrag wirklich löschen?');">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6">Keine Einträge gefunden.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

        </div>
        <?php
    }

    


    public function handle_export_csv() {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung');
        }

        if (!isset($_POST['reengage_export_nonce']) || !wp_verify_nonce($_POST['reengage_export_nonce'], 'reengage_export')) {
            wp_die('Nonce fehlt');
        }

        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM " . self::$table_name . " ORDER BY COALESCE(last_order_date, '1970-01-01') ASC");

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=reengage-customers-' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['id', 'user_id', 'email', 'first_name', 'last_name', 'last_order_date', 'updated_at']);

        foreach ($rows as $r) {
            fputcsv($output, [ $r->id, $r->user_id, $r->email, $r->first_name, $r->last_name, $r->last_order_date, $r->updated_at ]);
        }

        fclose($output);
        exit;
    }
}

add_action('wp_ajax_reengage_get_last_generated_coupons', function(){
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Keine Berechtigung');

    $coupons = get_option('reengage_last_generated_coupons', []);
    wp_send_json_success($coupons);
});

add_action('wp_ajax_send_testmail_to_customer', function() {
    $email   = sanitize_email($_POST['email']);
    $name    = sanitize_text_field($_POST['first_name']);
    $voucher = sanitize_text_field($_POST['voucher']);

    $anrede = $name ? "Lieber {$name}" : "Liebe Kundin, lieber Kunde";

    $subject = "Dein persönlicher Gutschein von uns 🎁";
    $message = "
        <p>{$anrede},</p>
        <p>wir haben bemerkt, dass du länger nicht bei uns bestellt hast. 
        Deshalb möchten wir dich mit einem exklusiven Gutschein zurückholen:</p>
        <p><strong>{$voucher}</strong></p>
        <p>20% Rabatt auf deine nächste Bestellung.<br>
        Gültig für 2 Monate.</p>
        <p>Wir freuen uns auf dich!<br>
        Dein Kenorecords Team</p>
    ";

    add_filter('wp_mail_content_type', function() { return "text/html"; });
    $sent = wp_mail($email, $subject, $message);
    remove_filter('wp_mail_content_type', 'set_html_content_type');

    wp_send_json_success([ 'sent' => $sent ]);
});

Reengage_Woo::init();
