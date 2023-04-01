<?php
/*
 * Plugin Name: Pi eCard for WooCommerce
 * Description: Take Pi eCard payments on your store.
 * Author: Decent Apps Ltd
 * Author URI: https://decentapps.co.uk
 * Version: 0.1.0
 */

/*
 * Register the PHP class as a WooCommerce payment gateway
 */
add_filter('woocommerce_payment_gateways', 'piecard_add_gateway_class');
function piecard_add_gateway_class($gateways)
{
  $gateways[] = 'WC_PieCard_Gateway';
  return $gateways;
}

/*
 * Pi eCard Payment Gateway Class
 */
add_action('plugins_loaded', 'piecard_init_gateway_class');
function piecard_init_gateway_class()
{

  class WC_PieCard_Gateway extends WC_Payment_Gateway
  {

    /**
     * Class constructor
     */
    public function __construct()
    {

      $this->id = 'piecard';
      $this->icon = 'https://www.piecard.co.uk/logo.png';
      $this->has_fields = true;
      $this->method_title = 'Pi eCard';
      $this->method_description = 'Pay with PI';
      $this->supports = array(
        'products'
      );

      // method with all the options fields
      $this->init_form_fields();

      // load the settings.
      $this->init_settings();
      $this->title = 'Pi eCard';
      $this->description = 'Pay with PI';
      $this->enabled = $this->get_option('enabled');
      $this->testmode = 'yes' === $this->get_option('testmode');
      $this->private_key = $this->get_option('private_key');
      $this->publishable_key = $this->get_option('publishable_key');
      $this->access_token = $this->get_option('access_token');

      // save the settings
      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

      // register a webhook
      add_action('woocommerce_api_complete', array($this, 'webhook'));

    }

    /**
     * Plugin options
     */
    public function init_form_fields()
    {

      $this->form_fields = array(
        'enabled' => array(
          'title' => 'Enable/Disable',
          'label' => 'Enable Gateway',
          'type' => 'checkbox',
          'description' => '',
          'default' => 'no'
        ),
        'testmode' => array(
          'title' => 'Sandbox mode',
          'label' => 'Enable Sandbox Mode',
          'type' => 'checkbox',
          'description' => 'Place the payment gateway in test mode.',
          'default' => 'yes',
          'desc_tip' => true,
        ),
        'publishable_key' => array(
          'title' => 'Public Key',
          'type' => 'text'
        ),
        'private_key' => array(
          'title' => 'Private Key',
          'type' => 'password'
        ),
        'access_token' => array(
          'title' => 'Access Token',
          'type' => 'password'
        ),
      );

    }

    /*
     * Create payment on Pi eCard servers and redirect to payment page
     */
    public function process_payment($order_id)
    {

      global $woocommerce;

      $order = wc_get_order($order_id);

      $args = array(
        "amount" => $order->get_total(),
        "memo" => $order->get_id(),
        "sandbox" => $this->testmode,
        "metadata" => array(
          "order_id" => $order->get_id(),
          "customer_id" => $order->get_customer_id(),
        ),
        "successURL" => $this->get_return_url($order),
        "cancelURL" => "https://epimall.io/cart/",
      );

      $url = 'https://api.piecard.app/payment';

      $options = array(
        'http' => array(
          'header' => [
            "Content-type: application/json",
            "clientid: " . $this->publishable_key,
            "clientsecret: " . $this->private_key,
            "accesstoken: " . $this->access_token,
          ],
          'method' => 'POST',
          'content' => http_build_query($args)
        )
      );
      $context = stream_context_create($options);
      $result = file_get_contents($url, false, $context);
      if ($result === FALSE)
        return;

      $result = json_decode($result);

      if ($result->status == "success") {
        $order->update_status('on-hold', __('Awaiting Pi eCard payment', 'woocommerce'));
        $woocommerce->cart->empty_cart();
        return array(
          'result' => 'success',
          'redirect' => 'https://piecard.app/pay/' . $result->data->id
        );
      }

    }

    /*
     * Webhook for complete payment
     */
    public function webhook()
    {

      $opts = array(
        'http' => array(
          'method' => "GET",
          'header' => [
            "clientid: " . $this->publishable_key,
            "clientsecret: " . $this->private_key,
            "accesstoken: " . $this->access_token,
          ],
        )
      );

      $context = stream_context_create($opts);

      // Open the file using the HTTP headers set above
      $file = file_get_contents('https://api.piecard.app/payment/' . $_GET['id'], false, $context);

      $result = json_decode($file);

      if ($result->data->status === true) {
        $order = wc_get_order($_GET['id']);
        $order->payment_complete();
        $order->reduce_order_stock();
      } else return false;

    }
  }
}