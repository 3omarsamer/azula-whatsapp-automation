<?php
/**
 * Plugin Name: Azula WhatsApp Automation
 * Description: WooCommerce WhatsApp order confirmation and rescheduling automation for Azula Sportswear.
 * Version: 0.2.6
 * Author: Azula Sportswear
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

final class Azula_WhatsApp_Automation {
    const OPTION = 'azula_wa_settings';
    const PROCESSED_OPTION = 'azula_wa_processed_message_ids';
    const CRON_HOOK = 'azula_wa_poll_incoming';
    const SEND_HOOK = 'azula_wa_send_order_confirmation';
    const AUTO_SEND_MAX_AGE = 1800; // 30 minutes
    const MAX_SEND_ATTEMPTS = 5;

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('wp_ajax_azula_wa_test_connection', [__CLASS__, 'ajax_test_connection']);
        add_action('wp_ajax_azula_wa_reconnect', [__CLASS__, 'ajax_reconnect']);
        add_action('wp_ajax_azula_wa_link_code', [__CLASS__, 'ajax_link_code']);
        add_action('wp_ajax_azula_wa_test_send', [__CLASS__, 'ajax_test_send']);
        add_action('wp_ajax_azula_wa_poll_now', [__CLASS__, 'ajax_poll_now']);
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action(self::CRON_HOOK, [__CLASS__, 'poll_incoming']);
        add_action(self::SEND_HOOK, [__CLASS__, 'process_send_job'], 10, 2);

        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'checkout_order_processed'], 20, 3);
        add_action('woocommerce_payment_complete', [__CLASS__, 'payment_complete'], 20);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'status_processing'], 20);
        add_action('woocommerce_order_status_on-hold', [__CLASS__, 'status_on_hold'], 20);

        add_filter('woocommerce_order_actions', [__CLASS__, 'order_actions']);
        add_action('woocommerce_order_action_azula_wa_send_confirmation', [__CLASS__, 'manual_send_confirmation']);
        add_action('add_meta_boxes', [__CLASS__, 'add_order_metabox']);

        // WhatsApp state directly on both legacy and HPOS WooCommerce order lists.
        add_filter('manage_edit-shop_order_columns', [__CLASS__, 'add_orders_list_column'], 30);
        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'render_legacy_orders_list_column'], 30, 2);
        add_filter('manage_woocommerce_page_wc-orders_columns', [__CLASS__, 'add_orders_list_column'], 30);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [__CLASS__, 'render_hpos_orders_list_column'], 30, 2);
    }

    public static function activate() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'azula_wa_minute', self::CRON_HOOK);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function cron_schedules($schedules) {
        $schedules['azula_wa_minute'] = [
            'interval' => 60,
            'display' => 'Every minute (Azula WhatsApp)',
        ];
        return $schedules;
    }

    public static function defaults() {
        return [
            'enabled' => '0',
            'bridge_url' => '',
            'api_key' => '',
            'initial_template' => "Thank you for your order from Azula Sportswear. This is a confirmation message.\n\nOrder Details:\nOrder Number: #{order_number}\n\nItems: {items}\nSubtotal: {subtotal}\n\nAddress: {address}\n\n🚚 Note: Partial delivery of the order is not allowed. Please accept or refuse the full order.\n\n💳 Note: For Cash on Delivery (COD) orders, payment by card or mobile wallet at the door is not accepted.\n\nPlease confirm your order below. If you have any notes, just reply to this message.",
            'same_day_initial_template' => "Thank you for your order from Azula Sportswear. This is a confirmation message.\n\nOrder Details:\nOrder Number: #{order_number}\n\nItems: {items}\nSubtotal: {subtotal}\n\nAddress: {address}\n\n🚚 Note: Same Day Delivery is available for orders placed before 12 PM, and delivery will be after 6 PM.\n\nPartial delivery of the order is not allowed. Please accept or refuse the full order.\n\nPlease confirm your order below. If you have any notes, just reply to this message.",
            'paid_initial_template' => "Thank you for your order from Azula Sportswear.\n\nYour payment for Order #{order_number} has been received successfully. ✅\n\nItems: {items}\nSubtotal: {subtotal}\n\nAddress: {address}\n\nPlease confirm your order below. If you have any notes, just reply to this message.",
            'confirmed_template' => "Thank you for confirming! Your order is on the way 🚚.\nIf you have any issues with the delivery, feel free to contact us 📞",
            'reschedule_template' => "Your order has been rescheduled. Please let us know the new timing or any special requests, and we'll adjust accordingly.",
            'reschedule_received_template' => "Thank you! We saved your new timing/request: {customer_reply}",
            'invalid_template' => "",
        ];
    }

    public static function settings() {
        return wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
    }

    public static function register_settings() {
        register_setting('azula_wa_group', self::OPTION, [
            'sanitize_callback' => [__CLASS__, 'sanitize_settings']
        ]);
    }

    public static function sanitize_settings($input) {
        $old = self::settings();
        $out = [];
        $out['enabled'] = !empty($input['enabled']) ? '1' : '0';
        $out['bridge_url'] = isset($input['bridge_url']) ? untrailingslashit(esc_url_raw(trim($input['bridge_url']))) : '';
        $out['api_key'] = isset($input['api_key']) ? sanitize_text_field(trim($input['api_key'])) : $old['api_key'];
        foreach (['initial_template','same_day_initial_template','paid_initial_template','confirmed_template','reschedule_template','reschedule_received_template','invalid_template'] as $key) {
            $out[$key] = isset($input[$key]) ? sanitize_textarea_field($input[$key]) : $old[$key];
        }
        return $out;
    }

    public static function admin_menu() {
        add_menu_page('Azula WhatsApp','Azula WhatsApp','manage_woocommerce','azula-whatsapp',[__CLASS__, 'settings_page'],'dashicons-format-chat',56);
    }

    public static function settings_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $s = self::settings();
        $nonce = wp_create_nonce('azula_wa_admin');
        ?>
        <div class="wrap">
            <h1>Azula WhatsApp Automation</h1>
            <p><strong>Flow:</strong> WhatsApp poll with ✅ Yes, Confirm ✅ / 🔄 Order Rescheduled. The plugin never changes WooCommerce order status automatically.</p>
            <style>
                .azwa-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px;margin:18px 0;max-width:920px}
                .azwa-grid{display:grid;grid-template-columns:190px minmax(320px,1fr);gap:14px 18px;align-items:start}
                .azwa-grid>label{font-weight:600;padding-top:7px}
                .azwa-grid input[type=text],.azwa-grid input[type=password],.azwa-grid textarea{width:100%}
                .azwa-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px}
                .azwa-result{padding:5px 10px;background:#f0f0f1;border-radius:999px}
                .azwa-help{font-size:12px;color:#646970;margin-top:5px}
                .azwa-template-buttons{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
                .azwa-template-panel{margin-bottom:18px}
                .azwa-template-panel[hidden]{display:none}
            </style>
            <form method="post" action="options.php">
                <?php settings_fields('azula_wa_group'); ?>
                <div class="azwa-card">
                    <h2>Connection</h2>
                    <div id="azwa-live-status" style="display:flex;align-items:center;gap:10px;padding:12px 14px;margin:0 0 16px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px">
                        <span id="azwa-status-dot" style="width:12px;height:12px;border-radius:50%;background:#8c8f94;display:inline-block;flex:0 0 auto"></span>
                        <div><strong id="azwa-status-title">Checking WhatsApp…</strong><div id="azwa-status-detail" class="azwa-help" style="margin-top:2px">Reading the live bridge status.</div></div>
                    </div>
                    <div class="azwa-grid">
                        <label>Enable automation</label>
                        <div><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enabled]" value="1" <?php checked($s['enabled'],'1'); ?>> Enabled</label></div>
                        <label>Bridge URL</label>
                        <div>
                            <input type="text" name="<?php echo esc_attr(self::OPTION); ?>[bridge_url]" value="<?php echo esc_attr($s['bridge_url']); ?>" placeholder="https://bridge.example.com">
                            <div class="azwa-help">The public HTTPS URL of the VPS bridge.</div>
                        </div>
                        <label>API key</label>
                        <div><input type="password" name="<?php echo esc_attr(self::OPTION); ?>[api_key]" value="<?php echo esc_attr($s['api_key']); ?>" autocomplete="new-password"></div>
                    </div>
                    <div class="azwa-actions">
                        <button type="button" class="button" id="azwa-test-connection">Test connection</button>
                        <button type="button" class="button" id="azwa-poll-now">Poll replies now</button>
                        <button type="button" class="button" id="azwa-reconnect">Reconnect WhatsApp</button>
                        <button type="button" class="button" id="azwa-link-code">Generate linking code</button>
                        <span id="azwa-result" class="azwa-result">Not tested</span>
                    </div>
                </div>

                <div class="azwa-card">
                    <h2>Message templates</h2>
                    <p>Placeholders: <code>{first_name}</code>, <code>{order_number}</code>, <code>{items}</code>, <code>{subtotal}</code>, <code>{address}</code>, <code>{order_total}</code>, <code>{customer_reply}</code></p>
                    <?php $initial_templates = ['initial_template'=>'Normal Orders','same_day_initial_template'=>'Same Day Delivery','paid_initial_template'=>'Paid Orders']; ?>
                    <div class="azwa-template-buttons" aria-label="Order confirmation templates">
                        <?php foreach ($initial_templates as $key=>$label): ?>
                            <button type="button" class="button" data-azwa-template="<?php echo esc_attr($key); ?>" aria-controls="azwa-panel-<?php echo esc_attr($key); ?>" aria-pressed="false"><?php echo esc_html($label); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <?php foreach ($initial_templates as $key=>$label): ?>
                        <div class="azwa-grid azwa-template-panel" id="azwa-panel-<?php echo esc_attr($key); ?>">
                            <label for="azwa-<?php echo esc_attr($key); ?>">Order confirmation <span class="azwa-help">(<?php echo esc_html($label); ?>)</span></label>
                            <textarea rows="12" id="azwa-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr(self::OPTION); ?>[<?php echo esc_attr($key); ?>]"><?php echo esc_textarea($s[$key]); ?></textarea>
                        </div>
                    <?php endforeach; ?>
                    <div class="azwa-grid">
                        <label>After ✅ Yes, Confirm ✅</label>
                        <textarea rows="4" name="<?php echo esc_attr(self::OPTION); ?>[confirmed_template]"><?php echo esc_textarea($s['confirmed_template']); ?></textarea>
                        <label>After 🔄 Order Rescheduled</label>
                        <textarea rows="4" name="<?php echo esc_attr(self::OPTION); ?>[reschedule_template]"><?php echo esc_textarea($s['reschedule_template']); ?></textarea>
                        <label>After new date reply</label>
                        <textarea rows="4" name="<?php echo esc_attr(self::OPTION); ?>[reschedule_received_template]"><?php echo esc_textarea($s['reschedule_received_template']); ?></textarea>
                        <label>Other customer replies</label>
                        <div class="azwa-help" style="padding-top:8px">Other text replies are saved as an order note while the order stays waiting for a poll choice.</div>
                    </div>
                </div>
                <?php submit_button('Save settings'); ?>
            </form>

            <div class="azwa-card">
                <h2>Send test message</h2>
                <div class="azwa-grid">
                    <label>Phone</label><input id="azwa-phone" type="text" placeholder="2010xxxxxxxx">
                    <label>Message</label><textarea id="azwa-message" rows="3">Azula WhatsApp plugin test ✅</textarea>
                </div>
                <div class="azwa-actions"><button type="button" class="button button-primary" id="azwa-test-send">Send test</button></div>
            </div>
        </div>
        <script>
        (function(){
            const templateButtons=document.querySelectorAll('[data-azwa-template]');
            function selectTemplate(key){
                templateButtons.forEach(button=>{
                    const selected=button.dataset.azwaTemplate===key;
                    button.setAttribute('aria-pressed',String(selected));
                    button.classList.toggle('button-primary',selected);
                    document.getElementById(button.getAttribute('aria-controls')).hidden=!selected;
                });
            }
            templateButtons.forEach(button=>button.addEventListener('click',()=>selectTemplate(button.dataset.azwaTemplate)));
            selectTemplate('initial_template');
            const result=document.getElementById('azwa-result');
            const nonce=<?php echo wp_json_encode($nonce); ?>;
            async function call(action, extra={}){
                result.textContent='Working...';
                const body=new URLSearchParams(Object.assign({action,nonce},extra));
                try{
                    const r=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
                    const j=await r.json();
                    const d=(j&&typeof j.data!=='undefined')?j.data:j;
                    result.textContent=(j.success?'✅ ':'❌ ')+(typeof d==='string'?d:JSON.stringify(d));
                }catch(e){ result.textContent='❌ '+e.message; }
            }
            function paintStatus(payload, ok=true){
                const dot=document.getElementById('azwa-status-dot');
                const title=document.getElementById('azwa-status-title');
                const detail=document.getElementById('azwa-status-detail');
                const d=(payload&&payload.data!==undefined)?payload.data:payload;
                const status=String((d&&((d.whatsapp_status)||(d.status)))||'').toUpperCase();
                const connected=ok && ['CONNECTED','INCHAT','ISLOGGED','QRREADSUCCESS'].includes(status);
                dot.style.background=connected?'#00a32a':(ok?'#dba617':'#d63638');
                title.textContent=connected?'WhatsApp connected':(ok?'WhatsApp needs attention':'Bridge unavailable');
                detail.textContent=connected?'Session is active and ready to send/receive.':(status?('Current status: '+status+'. Use Reconnect first. If WhatsApp requires a new login, generate a linking code.'):'Could not read the WhatsApp session status.');
            }
            async function refreshStatus(){
                try{
                    const body=new URLSearchParams({action:'azula_wa_test_connection',nonce});
                    const r=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
                    const j=await r.json(); paintStatus(j,j.success);
                }catch(e){ paintStatus(null,false); }
            }
            document.getElementById('azwa-test-connection').onclick=async()=>{ await call('azula_wa_test_connection'); refreshStatus(); };
            document.getElementById('azwa-poll-now').onclick=()=>call('azula_wa_poll_now');
            document.getElementById('azwa-reconnect').onclick=async()=>{ await call('azula_wa_reconnect'); setTimeout(refreshStatus,2500); };
            document.getElementById('azwa-link-code').onclick=()=>call('azula_wa_link_code');
            refreshStatus();
            document.getElementById('azwa-test-send').onclick=()=>call('azula_wa_test_send',{
                phone:document.getElementById('azwa-phone').value,
                message:document.getElementById('azwa-message').value
            });
        })();
        </script>
        <?php
    }

    private static function verify_admin_ajax() {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Not allowed.', 403);
        check_ajax_referer('azula_wa_admin', 'nonce');
    }

    private static function bridge_request($method, $path, $body=null, $timeout=20) {
        $s=self::settings();
        if (empty($s['bridge_url']) || empty($s['api_key'])) return new WP_Error('azwa_config','Bridge URL or API key is missing.');
        $args=[
            'method'=>strtoupper($method),
            'timeout'=>$timeout,
            'headers'=>[
                'Authorization'=>'Bearer '.$s['api_key'],
                'Content-Type'=>'application/json',
                'Accept'=>'application/json',
            ],
        ];
        if ($body!==null) $args['body']=wp_json_encode($body);
        $response=wp_remote_request($s['bridge_url'].'/'.ltrim($path,'/'),$args);
        if (is_wp_error($response)) return $response;
        $code=wp_remote_retrieve_response_code($response);
        $raw=wp_remote_retrieve_body($response);
        $json=json_decode($raw,true);
        if ($code<200 || $code>=300) {
            $msg=is_array($json)&&!empty($json['error'])?$json['error']:'Bridge HTTP '.$code;
            return new WP_Error('azwa_http',$msg);
        }
        return is_array($json)?$json:['raw'=>$raw];
    }

    public static function ajax_test_connection() {
        self::verify_admin_ajax();
        $r=self::bridge_request('GET','/status');
        if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
        wp_send_json_success($r);
    }

    public static function ajax_reconnect() {
        self::verify_admin_ajax();
        // Bridge endpoint: reconnects/restarts the existing saved WhatsApp session.
        $r=self::bridge_request('POST','/reconnect',[],60);
        if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
        wp_send_json_success($r);
    }

    public static function ajax_link_code() {
        self::verify_admin_ajax();
        // Bridge endpoint: asks WPPConnect for a fresh phone linking/pairing code.
        $r=self::bridge_request('POST','/link-code',[],60);
        if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
        wp_send_json_success($r);
    }

    public static function ajax_poll_now() {
        self::verify_admin_ajax();
        wp_send_json_success(['processed_now'=>intval(self::poll_incoming())]);
    }

    public static function ajax_test_send() {
        self::verify_admin_ajax();
        $phone=isset($_POST['phone'])?sanitize_text_field(wp_unslash($_POST['phone'])):'';
        $message=isset($_POST['message'])?sanitize_textarea_field(wp_unslash($_POST['message'])):'';
        if (!$phone || !$message) wp_send_json_error('Phone and message are required.');
        $r=self::send_message($phone,$message);
        if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
        wp_send_json_success($r);
    }

    public static function checkout_order_processed($order_id,$posted_data,$order) {
        if (!$order instanceof WC_Order) $order=wc_get_order($order_id);
        if ($order && $order->get_payment_method()==='cod') self::enqueue_confirmation($order, 'checkout');
    }

    public static function payment_complete($order_id) {
        $o=wc_get_order($order_id);
        if($o) self::enqueue_confirmation($o, 'payment_complete');
    }

    public static function status_processing($order_id) {
        $o=wc_get_order($order_id);
        if($o) self::enqueue_confirmation($o, 'processing');
    }

    public static function status_on_hold($order_id) {
        $o=wc_get_order($order_id);
        if($o && $o->get_payment_method()==='cod') self::enqueue_confirmation($o, 'on_hold');
    }

    private static function order_age_seconds($order) {
        if(!$order instanceof WC_Order) return PHP_INT_MAX;
        $created=$order->get_date_created();
        if(!$created) return PHP_INT_MAX;
        return max(0, time() - $created->getTimestamp());
    }

    private static function is_terminal_order($order) {
        return !$order instanceof WC_Order || in_array($order->get_status(),['cancelled','failed','refunded','trash'],true);
    }

    private static function classify_send_error($message) {
        $m=strtolower((string)$message);
        if(strpos($m,'no lid for user')!==false || strpos($m,'not on whatsapp')!==false || strpos($m,'not registered')!==false) {
            return 'no_whatsapp';
        }
        return 'send_failed';
    }

    private static function schedule_send_job($order_id,$attempt,$delay) {
        $args=[intval($order_id),intval($attempt)];

        if(function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
            $next=as_next_scheduled_action(self::SEND_HOOK,$args,'azula-whatsapp');
            if(!$next) {
                as_schedule_single_action(time()+max(1,intval($delay)),self::SEND_HOOK,$args,'azula-whatsapp');
            }
            return;
        }

        if(!wp_next_scheduled(self::SEND_HOOK,$args)) {
            wp_schedule_single_event(time()+max(1,intval($delay)),self::SEND_HOOK,$args);
        }
    }

    public static function enqueue_confirmation($order,$source='automatic') {
        $s=self::settings();
        if($s['enabled']!=='1' || !$order instanceof WC_Order) return false;
        if($order->get_meta('_azula_wa_confirmation_sent')) return false;
        if(self::is_terminal_order($order)) return false;

        $age=self::order_age_seconds($order);
        if($age > self::AUTO_SEND_MAX_AGE) {
            // Never allow a late status/payment hook to send a stale confirmation hours/days later.
            if($order->get_meta('_azula_wa_state')==='queued' || $order->get_meta('_azula_wa_state')==='retrying') {
                $order->update_meta_data('_azula_wa_state','expired');
                $order->save();
                $order->add_order_note('Azula WhatsApp: automatic confirmation expired after 30 minutes and was not sent late.');
            }
            return false;
        }

        $state=(string)$order->get_meta('_azula_wa_state');
        if(in_array($state,['queued','retrying'],true)) return false;

        $order->update_meta_data('_azula_wa_state','queued');
        $order->update_meta_data('_azula_wa_send_attempts',0);
        $order->update_meta_data('_azula_wa_queue_started',current_time('mysql'));
        $order->update_meta_data('_azula_wa_queue_source',sanitize_text_field($source));
        $order->save();

        self::schedule_send_job($order->get_id(),0,2);
        return true;
    }

    public static function process_send_job($order_id,$attempt=0) {
        $order=wc_get_order($order_id);
        if(!$order instanceof WC_Order) return;
        if($order->get_meta('_azula_wa_confirmation_sent')) return;
        if(self::is_terminal_order($order)) return;

        $age=self::order_age_seconds($order);
        if($age > self::AUTO_SEND_MAX_AGE) {
            $order->update_meta_data('_azula_wa_state','expired');
            $order->save();
            $order->add_order_note('Azula WhatsApp: queued confirmation expired after 30 minutes and was not sent late.');
            return;
        }

        $attempt=max(0,intval($attempt));
        $order->update_meta_data('_azula_wa_send_attempts',$attempt+1);
        $order->update_meta_data('_azula_wa_last_attempt',current_time('mysql'));
        $order->update_meta_data('_azula_wa_state',$attempt===0?'sending':'retrying');
        $order->save();

        $result=self::maybe_send_confirmation($order,false);
        if($result===true) return;

        // Permanent errors such as a number without WhatsApp must not be retried.
        $state=(string)$order->get_meta('_azula_wa_state');
        if($state==='no_whatsapp') return;

        $next_attempt=$attempt+1;
        if($next_attempt>=self::MAX_SEND_ATTEMPTS) {
            $order->update_meta_data('_azula_wa_state','send_failed');
            $order->save();
            $order->add_order_note('Azula WhatsApp: confirmation failed after 5 attempts. Manual resend is available.');
            return;
        }

        // Retry at roughly +1, +3, +7, +15 minutes from the previous attempt.
        $delays=[60,180,420,900];
        $delay=$delays[min($next_attempt-1,count($delays)-1)];
        $order->update_meta_data('_azula_wa_state','retrying');
        $order->save();
        self::schedule_send_job($order->get_id(),$next_attempt,$delay);
    }

    private static function get_confirmation_template_for_order($order) {
        $s=self::settings();
        foreach($order->get_items('shipping') as $shipping) {
            // Match a semantic method ID, including IDs stored with an instance suffix.
            // Numeric instance IDs alone cannot identify a service across installations.
            $method_id=(string)$shipping->get_method_id();
            if(preg_match('/(?:^|[_:-])same[_-]?day(?:[_-]delivery)?(?:$|[_:-])/i',$method_id)) {
                return $s['same_day_initial_template'];
            }
            // Generic methods (e.g. flat_rate) need the title saved on this order.
            if(preg_match('/\bsame\s+day\s+delivery\b/i',(string)$shipping->get_method_title())) {
                return $s['same_day_initial_template'];
            }
        }
        // COD can be Processing/is_paid() without prepayment; use the final method.
        if($order->get_payment_method()==='cod') {
            return $s['initial_template'];
        }
        return $order->is_paid() ? $s['paid_initial_template'] : $s['initial_template'];
    }

    public static function maybe_send_confirmation($order,$manual=false) {
        $s=self::settings();
        if ($s['enabled']!=='1' || !$order instanceof WC_Order) return false;
        if ($order->get_meta('_azula_wa_confirmation_sent')) return false;
        if (self::is_terminal_order($order) && !$manual) return false;

        if(!$manual && self::order_age_seconds($order)>self::AUTO_SEND_MAX_AGE) {
            $order->update_meta_data('_azula_wa_state','expired');
            $order->save();
            return false;
        }

        $phone=self::normalize_order_phone($order);
        if(!$phone){
            $order->update_meta_data('_azula_wa_state','send_failed');
            $order->save();
            $order->add_order_note('Azula WhatsApp: confirmation not sent — billing phone missing/invalid.');
            return false;
        }

        $r=self::send_poll(
            $phone,
            self::render_template(self::get_confirmation_template_for_order($order),$order),
            ['✅ Yes, Confirm ✅','🔄 Order Rescheduled']
        );

        if(is_wp_error($r)){
            $state=self::classify_send_error($r->get_error_message());
            $order->update_meta_data('_azula_wa_state',$state);
            $order->update_meta_data('_azula_wa_last_error',$r->get_error_message());
            $order->save();
            $order->add_order_note('Azula WhatsApp: poll send failed — '.$r->get_error_message());
            return false;
        }

        $chat_id=self::extract_chat_id(isset($r['message_id'])?$r['message_id']:'');
        $order->update_meta_data('_azula_wa_confirmation_sent',current_time('mysql'));
        $order->update_meta_data('_azula_wa_state','awaiting_choice');
        $order->update_meta_data('_azula_wa_phone',$phone);
        $order->delete_meta_data('_azula_wa_last_error');
        if($chat_id) $order->update_meta_data('_azula_wa_chat_id',$chat_id);
        $order->save();
        $order->add_order_note('Azula WhatsApp confirmation poll sent. Waiting for ✅ Yes, Confirm ✅ or 🔄 Order Rescheduled.');
        return true;
    }

    public static function send_message($phone,$message) {
        $phone=self::normalize_phone($phone);
        if(!$phone || trim($message)==='') return new WP_Error('azwa_send','Invalid phone or empty message.');
        $r=self::bridge_request('POST','/send',['phone'=>$phone,'message'=>$message],30);
        if(is_wp_error($r)) return $r;
        if(isset($r['success']) && !$r['success']) return new WP_Error('azwa_send',!empty($r['error'])?$r['error']:'Bridge send failed.');
        return $r;
    }

    public static function send_poll($phone,$title,$options) {
        $phone=self::normalize_phone($phone);
        if(!$phone || trim($title)==='' || !is_array($options) || count($options)<2) {
            return new WP_Error('azwa_poll','Invalid phone, poll title, or options.');
        }
        $r=self::bridge_request('POST','/send-poll',[
            'phone'=>$phone,
            'title'=>$title,
            'options'=>array_values($options),
        ],30);
        if(is_wp_error($r)) return $r;
        if(isset($r['success']) && !$r['success']) return new WP_Error('azwa_poll',!empty($r['error'])?$r['error']:'Bridge poll send failed.');
        return $r;
    }

    public static function poll_incoming() {
        $s=self::settings();
        if($s['enabled']!=='1') return 0;
        $r=self::bridge_request('GET','/incoming-queue',null,30);
        if(is_wp_error($r) || empty($r['messages']) || !is_array($r['messages'])) return 0;

        $processed=(array)get_option(self::PROCESSED_OPTION,[]);
        $map=array_fill_keys($processed,true);
        $count=0;

        foreach($r['messages'] as $msg){
            $id=isset($msg['id'])?sanitize_text_field((string)$msg['id']):'';
            if(!$id || isset($map[$id])) continue;
            self::process_incoming_message($msg);
            $processed[]=$id; $map[$id]=true; $count++;
        }

        if(count($processed)>2000) $processed=array_slice($processed,-1500);
        update_option(self::PROCESSED_OPTION,array_values($processed),false);
        return $count;
    }

    private static function process_incoming_message($msg) {
        $from=isset($msg['from'])?sanitize_text_field((string)$msg['from']):'';
        $body=isset($msg['body'])?trim(wp_strip_all_tags((string)$msg['body'])):'';
        $type=isset($msg['type'])?sanitize_key((string)$msg['type']):'';
        if(!$from || $body==='') return;

        $order=self::find_active_order($from);
        if(!$order) return;

        $state=(string)$order->get_meta('_azula_wa_state');
        $current_response=(string)$order->get_meta('_azula_wa_customer_response');
        $s=self::settings();
        $n=strtolower(trim($body));

        $is_confirm = preg_match('/^1(?:\D|$)/u',$n) || in_array($n,['confirm','confirmed','yes','✅ yes, confirm ✅','yes, confirm'],true);
        $is_reschedule = preg_match('/^2(?:\D|$)/u',$n) || in_array($n,['reschedule','rescheduled','change date','🔄 order rescheduled','order rescheduled'],true);

        /*
         * Poll choices are reversible. The latest choice wins.
         * Repeated events for the same current choice are ignored so the
         * customer never receives duplicate follow-up messages.
         */
        if($type==='poll_response' || $is_confirm || $is_reschedule){
            if($is_confirm){
                if($current_response==='confirmed' && $state==='confirmed') return;

                $order->update_meta_data('_azula_wa_customer_response','confirmed');
                $order->update_meta_data('_azula_wa_state','confirmed');
                $order->update_meta_data('_azula_wa_response_raw',$body);
                $order->delete_meta_data('_azula_wa_requested_date');
                $order->save();
                $order->add_order_note('Azula WhatsApp: customer CONFIRMED the order. Latest poll choice: Confirmed.');
                self::send_to_order($order,self::render_template($s['confirmed_template'],$order,$body));
                return;
            }

            if($is_reschedule){
                if($current_response==='rescheduled' && $state==='rescheduled') return;

                $order->update_meta_data('_azula_wa_customer_response','rescheduled');
                $order->update_meta_data('_azula_wa_state','rescheduled');
                $order->update_meta_data('_azula_wa_response_raw',$body);
                $order->save();
                $order->add_order_note('Azula WhatsApp: customer selected RESCHEDULE. Latest poll choice: Rescheduled.');
                self::send_to_order($order,self::render_template($s['reschedule_template'],$order,$body));
                return;
            }
        }

        /* Free text after Rescheduled = requested timing / notes. */
        if($state==='rescheduled'){
            $order->update_meta_data('_azula_wa_requested_date',$body);
            $order->update_meta_data('_azula_wa_last_customer_note',$body);
            $order->save();
            $order->add_order_note('Azula WhatsApp: requested new delivery timing/message: '.$body);
            self::send_to_order($order,self::render_template($s['reschedule_received_template'],$order,$body));
            return;
        }

        /* Any other customer text is saved without changing the poll state. */
        $order->add_order_note('Azula WhatsApp customer note: '.$body);
        $order->update_meta_data('_azula_wa_last_customer_note',$body);
        $order->save();
    }

    private static function find_active_order($from) {
        $orders=wc_get_orders([
            'limit'=>100,'orderby'=>'date','order'=>'DESC',
            'status'=>array_keys(wc_get_order_statuses()),
            'meta_query'=>[[
                'key'=>'_azula_wa_state',
                'value'=>['awaiting_choice','confirmed','rescheduled','awaiting_reschedule_date','reschedule_date_received'],
                'compare'=>'IN'
            ]]
        ]);

        $from_phone=self::chat_id_to_phone($from);
        foreach($orders as $order){
            $chat=(string)$order->get_meta('_azula_wa_chat_id');
            if($chat && $chat===$from) return $order;

            if($from_phone){
                $phone=(string)$order->get_meta('_azula_wa_phone');
                if(!$phone) $phone=self::normalize_order_phone($order);
                if($phone && $phone===$from_phone) return $order;
            }
        }
        return false;
    }

    private static function send_to_order($order,$message){
        $phone=(string)$order->get_meta('_azula_wa_phone');
        if(!$phone) $phone=self::normalize_order_phone($order);
        return self::send_message($phone,$message);
    }

    private static function chat_id_to_phone($chat_id) {
        return preg_match('/^(\d+)@c\.us$/',$chat_id,$m)?$m[1]:'';
    }

    private static function extract_chat_id($message_id) {
        if(preg_match('/^true_(.+?@(?:lid|c\.us))_/',(string)$message_id,$m)) return $m[1];
        if(preg_match('/^false_(.+?@(?:lid|c\.us))_/',(string)$message_id,$m)) return $m[1];
        return '';
    }

    private static function normalize_order_phone($order) {
        return self::normalize_phone($order->get_billing_phone(),$order->get_billing_country());
    }

    private static function normalize_phone($phone,$country='') {
        $phone=preg_replace('/\D+/','',(string)$phone);
        if(!$phone) return '';
        if(strpos($phone,'00')===0) $phone=substr($phone,2);
        if(strlen($phone)===11 && strpos($phone,'0')===0) return '20'.substr($phone,1);
        if(strpos($phone,'0')===0 && $country && function_exists('WC')){
            $calling=WC()->countries->get_country_calling_code($country);
            if(is_array($calling)) $calling=reset($calling);
            $calling=preg_replace('/\D+/','',(string)$calling);
            if($calling) return $calling.substr($phone,1);
        }
        return $phone;
    }

    private static function render_template($template,$order,$reply='') {
        $items=[];
        foreach($order->get_items() as $item){
            $name=$item->get_name();
            $qty=$item->get_quantity();
            $items[]=$name.' × '.$qty;
        }

        $address_parts=array_filter([
            $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
            $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
            $order->get_shipping_city() ?: $order->get_billing_city(),
            $order->get_shipping_state() ?: $order->get_billing_state(),
        ]);

        $subtotal=0;
        foreach($order->get_items() as $item){
            $subtotal += (float)$item->get_subtotal();
        }
        $subtotal_text=wp_strip_all_tags(wc_price($subtotal,['currency'=>$order->get_currency()]));

        return strtr((string)$template,[
            '{first_name}'=>$order->get_billing_first_name(),
            '{order_number}'=>$order->get_order_number(),
            '{items}'=>implode("\n",$items),
            '{subtotal}'=>$subtotal_text,
            '{address}'=>implode(', ',$address_parts),
            '{order_total}'=>wp_strip_all_tags($order->get_formatted_order_total()),
            '{customer_reply}'=>$reply,
        ]);
    }

    public static function order_actions($actions) {
        $actions['azula_wa_send_confirmation']='Send Azula WhatsApp confirmation';
        return $actions;
    }

    public static function manual_send_confirmation($order) {
        if(!$order instanceof WC_Order) return;
        $order->delete_meta_data('_azula_wa_confirmation_sent');
        $order->delete_meta_data('_azula_wa_last_error');
        $order->update_meta_data('_azula_wa_state','manual_send');
        $order->save();
        self::maybe_send_confirmation($order,true);
    }

    public static function add_orders_list_column($columns) {
        $new=[];
        foreach($columns as $key=>$label){
            $new[$key]=$label;
            if($key==='order_status') $new['azula_wa_status']='WhatsApp';
        }
        if(!isset($new['azula_wa_status'])) $new['azula_wa_status']='WhatsApp';
        return $new;
    }

    private static function orders_list_status_html($order) {
        if(!$order instanceof WC_Order) return '—';
        $state=(string)$order->get_meta('_azula_wa_state');
        $sent=(string)$order->get_meta('_azula_wa_confirmation_sent');
        $response=(string)$order->get_meta('_azula_wa_customer_response');
        $labels=[
            'awaiting_choice'=>['🟡','Waiting reply'],
            'confirmed'=>['🟢','Confirmed'],
            'rescheduled'=>['🔵','Rescheduled'],
            'queued'=>['🟠','Queued'],
            'sending'=>['🟠','Sending'],
            'retrying'=>['🟠','Retrying'],
            'send_failed'=>['🔴','Send failed'],
            'no_whatsapp'=>['⚫','No WhatsApp'],
            'expired'=>['⚪','Expired'],
            'manual_send'=>['🟠','Manual send'],
        ];
        if(isset($labels[$state])) [$icon,$label]=$labels[$state];
        elseif($response==='confirmed') {$icon='🟢';$label='Confirmed';}
        elseif($response==='rescheduled') {$icon='🔵';$label='Rescheduled';}
        elseif($sent) {$icon='🟡';$label='Sent';}
        else {$icon='—';$label='Not sent';}
        $title=$sent ? 'Confirmation sent: '.$sent : 'No confirmation timestamp';
        return '<span title="'.esc_attr($title).'" style="white-space:nowrap">'.esc_html($icon.' '.$label).'</span>';
    }

    public static function render_legacy_orders_list_column($column,$post_id) {
        if($column!=='azula_wa_status') return;
        echo wp_kses_post(self::orders_list_status_html(wc_get_order($post_id)));
    }

    public static function render_hpos_orders_list_column($column,$order) {
        if($column!=='azula_wa_status') return;
        if(!$order instanceof WC_Order) $order=wc_get_order(is_object($order)&&method_exists($order,'get_id')?$order->get_id():$order);
        echo wp_kses_post(self::orders_list_status_html($order));
    }

    public static function add_order_metabox() {
        $screens=['shop_order'];
        if(function_exists('wc_get_page_screen_id')) $screens[]=wc_get_page_screen_id('shop-order');
        foreach(array_unique($screens) as $screen){
            add_meta_box('azula-wa-status','Azula WhatsApp',[__CLASS__,'render_order_metabox'],$screen,'side','default');
        }
    }

    public static function render_order_metabox($post_or_order) {
        $order=$post_or_order instanceof WC_Order?$post_or_order:wc_get_order(is_object($post_or_order)?$post_or_order->ID:0);
        if(!$order) return;
        $rows=[
            'State'=>$order->get_meta('_azula_wa_state'),
            'Response'=>$order->get_meta('_azula_wa_customer_response'),
            'Requested date'=>$order->get_meta('_azula_wa_requested_date'),
            'WhatsApp phone'=>$order->get_meta('_azula_wa_phone'),
            'Chat ID'=>$order->get_meta('_azula_wa_chat_id'),
            'Confirmation sent'=>$order->get_meta('_azula_wa_confirmation_sent'),
        ];
        echo '<table style="width:100%">';
        foreach($rows as $label=>$value){
            echo '<tr><td style="padding:4px 8px 4px 0"><strong>'.esc_html($label).'</strong></td><td style="word-break:break-word">'.esc_html($value?:'—').'</td></tr>';
        }
        echo '</table>';
    }
}

Azula_WhatsApp_Automation::init();
register_activation_hook(__FILE__, ['Azula_WhatsApp_Automation','activate']);
register_deactivation_hook(__FILE__, ['Azula_WhatsApp_Automation','deactivate']);
