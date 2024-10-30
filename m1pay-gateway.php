<?php

include_once "includes/mpg_transaction_model.php";
include_once "includes/mpg_common_utils.php";
/*
 * Plugin Name: M1Pay Gateway
 * Plugin URI: https://mobilityone.com.my
 * Description: This is a plugin to handle online payments by MobilityOne Payment Gateway.
 * Author: Sahba Changizi
 * Author URI: https://mobilityone.com.my
 * Version: 1.5
*/

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

function mpg_gateway_icon($icon, $id)
{
    if ($id === 'm1') {
        $options = get_option('woocommerce_m1_settings');
        $customLogoPath = $options['custom_logo'] ? '<img style="max-height: 100%; width: 100%" src="' . $options['custom_logo'] . '"/>' : '';
        return '<img style="margin-bottom: 8px;" src="' . sprintf('%sassets/images/m1pay.png ', plugin_dir_url(__FILE__)) . '" > </br>' . $customLogoPath;
    } else {
        return $icon;
    }
}
add_filter('woocommerce_gateway_icon', 'mpg_gateway_icon', 10, 2);


function mpg_load()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    function mpg_add_gateway_class($gateways)
    {
        $gateways[] = 'MPG_Gateway';
        return $gateways;
    }
    add_filter('woocommerce_payment_gateways', 'mpg_add_gateway_class');

    class MPG_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'm1';
            $this->has_fields = true;
            $this->method_title = 'M1 Gateway';
            $this->method_description = 'M1 payment gateway';
            $this->supports = array(
                'products'
            );
            $this->mpg_init_form_fields();

            // Load the settings.
            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->client_id = $this->get_option('client_id');
            $this->secret_key = $this->get_option('secret_key');
            $this->private_key = $this->get_option('private_key');
            $this->public_key = $this->get_option('public_key');
            $this->sandbox = $this->get_option('sandbox');

            // This action hook saves the settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'mpg_send_to_bank'));
            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'mpg_return_from_bank'));
        }

        public function mpg_init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'label' => 'Enable M One Gateway',
                    'type' => 'checkbox',
                    'description' => 'Please send ' . get_bloginfo('wpurl') . '/wc-api/MPG_Gateway link as redirect URL of the gateway to M1 Pay support team',
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default' => 'M One Gateway',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default' => 'Pay with M One payment gateway.',
                ),
                'client_id' => array(
                    'title' => 'Client ID',
                    'type' => 'text'
                ),
                'custom_logo' => array(
                    'title' => 'Custom Logo',
                    'type' => 'text',
                    'description' => 'Enter the full URL to the custom logo which will be displayed at bottom of the M1Pay logo. Leave empty to only display default logo.',
                ),
                'secret_key' => array(
                    'title' => 'Secret Key',
                    'type' => 'text'
                ),
                'private_key' => array(
                    'title' => 'Private Key',
                    'type' => 'textarea',
                ),
                'public_key' => array(
                    'title' => 'Public Key',
                    'type' => 'textarea',
                ),
                'sandbox' => array(
                    'title' => 'SandBox Mode',
                    'type' => 'checkbox',
                    'label' => 'If active this option, gateway will connect to the sandbox API',
                    'default' => 'no',
                ),
            );
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url($order),
            );
        }


        public function mpg_send_to_bank($order_id)
        {
            $order = wc_get_order($order_id);
            $merchantID = $this->client_id;
            $privateKey = $this->private_key;
            $clientSecret = $this->secret_key;
            $isSandBox = $this->sandbox;
            $items = $order->get_items();
            $amount = number_format($order->get_total(), 2, ".", "");
            $mobile = $order->get_billing_phone() ?: "no mobile";
            $sellerName = get_bloginfo('name') ?: "Seller name";
            $item = array_shift(array_values($items));
            $description = $this->get_description($item);
            $data = "$description|$amount|$order_id|$order_id|MYR|null|$merchantID";
            $signature = '';
            try {
                $pKeyID = openssl_get_privatekey($privateKey);
                openssl_sign($data, $signature, $pKeyID, "sha1WithRSAEncryption");
                $signature = mpg_str_to_hex($signature);
            } catch (Exception $e) {
                wc_add_notice('Caught exception: ' . $e->getMessage(), 'error');
            }
            $token = mpg_get_token($merchantID, $clientSecret, $isSandBox);
            if ($token != null) {
                $transAction = new MPG_Transaction_Model();
                $transAction->token = $token;
                $transAction->merchantID = $merchantID;
                $transAction->description = $description;
                $transAction->mobile = $mobile;
                $transAction->merchantOrderID = $order_id;
                $transAction->sellerName = $sellerName;
                $transAction->signedData = $signature;
                $transAction->amount = $amount;
                WC()->session->set('mpg_order_id', $order_id);
                $gateway_url = mpg_get_transaction_id($transAction, $isSandBox);
                if ($gateway_url) {
                    $parts = parse_url($gateway_url);
                    parse_str($parts['query'], $query);
                    $transAction->transActionID = $query['transactionId'];
                    update_post_meta($order_id, 'mpg_transaction_id', $transAction->transActionID);
                    wp_redirect($gateway_url);
                } else {
                    wc_add_notice('Payment error, Please contact to M1Pay Support team.', 'error');
                }
            } else
                wc_add_notice('Authorization error, Please contact to M1Pay Support team.', 'error');
        }


        public function mpg_return_from_bank()
        {
            $order_id = absint(WC()->session->get('mpg_order_id'));
            $isSandBox = $this->sandbox;
            if (isset($order_id) && !empty($order_id)) {
                $order = wc_get_order($order_id);
                if ($order->get_status() !== 'completed') {
                    // Verifying transaction
                    $send_reference_id = get_post_meta($order_id, 'mpg_transaction_id', true);
                    try {
                        $merchantID = $this->client_id;
                        $clientSecret = $this->secret_key;
                        $token = mpg_get_token($merchantID, $clientSecret, $isSandBox);
                        $transactionStatus = mpg_check_transaction_status($token, @$send_reference_id, $isSandBox);
                        if ($transactionStatus == 'APPROVED' || $transactionStatus == 'CAPTURED' || $transactionStatus == 'SUCCESSFUL') {
                            wc_reduce_stock_levels($order_id);
                            WC()->cart->empty_cart();
                            WC()->session->delete_session('mpg_order_id');
                            $message = sprintf('Payment was successful');
                            $order->add_order_note($message);
                            $order->payment_complete();
                            $successful_page = add_query_arg('wc_status', 'success', $this->get_return_url($order));
                            wp_redirect($successful_page);
                            exit();
                        } else {
                            wc_add_notice(__('Payment error', 'm1'),
                                'error');
                            wp_safe_redirect(wc_get_checkout_url());
                            exit();
                        }
                    } catch (Exception $e) {
                        wc_add_notice('Payment error: Transaction verification error', 'error');
                        wp_safe_redirect($this->get_error_return_url());
                        exit();
                    }
                } else {
                    wc_add_notice('Payment error: Already completed order', 'error');
                    wp_safe_redirect($this->get_error_return_url());
                    exit();
                }
            } else {
                wc_add_notice('Payment error: Missing order ID', 'error');
                wp_safe_redirect(wc_get_checkout_url());
                //wp_safe_redirect($this->get_error_return_url());
                exit();
            }
        }

        /**
         * @param $item
         * @return string
         */
        public function get_description($item)
        {
            return strlen($item) > 0 ? substr(preg_replace('/[^\w\-\s]/', '', $item->get_name()), 0, 10) . '...' : 'No Name';
        }
    }
}
add_action('plugins_loaded', 'mpg_load', 0);
