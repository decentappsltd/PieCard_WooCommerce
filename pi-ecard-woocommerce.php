<?php
/*
 * Plugin Name: Pi eCard for WooCommerce
 * Description: Take Pi eCard payments on your store.
 * Author: Decent Apps Ltd
 * Author URI: https://decentapps.co.uk
 * Version: 1.0.2
 */

/*
 * Register the PHP class as a WooCommerce payment gateway
 */
function piecard_add_gateway_class($gateways)
{
  $gateways[] = 'WC_PieCard_Gateway';
  return $gateways;
}
add_filter('woocommerce_payment_gateways', 'piecard_add_gateway_class');


/**
 * Custom currency and currency symbol
 */
add_filter( 'woocommerce_currencies', 'add_my_currency' );

function add_my_currency( $currencies ) {
     $currencies['Pi'] = __( 'Pi Network', 'piwoo' );
     return $currencies;
}

add_filter('woocommerce_currency_symbol', 'add_my_currency_symbol', 10, 2);

function add_my_currency_symbol( $currency_symbol, $currency ) {
     switch( $currency ) {
          case 'Pi': $currency_symbol = 'π'; break;
     }
     return $currency_symbol;
}


/*
 * Pi eCard Payment Gateway Class
 */
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
      $this->url = $this->get_option('url');
      $this->name = $this->get_option('name');
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
        'url' => array(
          'title' => 'URL',
          'type' => 'text',
          'description' => 'The URL of your live site. Note this must start with https:// and be correct for this plugin to work. Do not include trailing slash (/).',
        ),
        'name' => array(
          'title' => 'Store Name',
          'type' => 'text',
          'description' => 'The name of your store as you would like it to appear on the payment page. This is required',
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

      // Empty the cart to cancel any existing orders
      wc_empty_cart();

      // Data to be sent
      $data = [
        'amount' => doubleval($order->get_total()),
        'memo' => $this->name . ' Order #' . $order_id,  // IMPORTANT: DO NOT CHANGE THIS! This is used to identify the payment on your Pi eCard account
        'metadata' => [
          'orderId' => $order_id,
        ],
        'sandbox' => $this->testmode,
        'successURL' => $this->url . '/wc-api/complete?id=' . $order_id,
        'cancelURL' => $this->url . '/cart'
      ];

      // Headers
      $headers = [
        'clientid: ' . $this->publishable_key,
        'clientsecret: ' . $this->private_key,
        'accesstoken: ' . $this->access_token,
      ];

      // Make the request
      $curl = curl_init();

      curl_setopt_array(
        $curl,
        array(
          CURLOPT_URL => "https://api.piecard.app/payment",
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => "POST",
          CURLOPT_POSTFIELDS => json_encode($data),
          CURLOPT_HTTPHEADER => array_merge(array("Content-Type: application/json"), $headers)
        )
      );

      $response = curl_exec($curl);

      curl_close($curl);

      // Check the response
      $responseData = json_decode($response, true);

      if ($responseData['success'] == true) {
        // Create the redirect URL
        $url = 'https://piecard.app/pay/' . $responseData['data']['id'];

        return array(
          'result' => 'success',
          'redirect' => $url
        );
      } else {
        echo $responseData['data']['message'] ?? 'fail';
        return array(
          'result' => 'failed',
          'redirect' => false
        );
      }
    }

    /*
     * Webhook for complete payment
     */
    public function webhook()
    {

      // validate payment is completed with Pi eCard servers
      $data = [
        'id' => $_GET['id'],
        'name' => $this->name
      ];

      // Headers
      $headers = [
        'clientid: ' . $this->publishable_key,
        'clientsecret: ' . $this->private_key,
        'accesstoken: ' . $this->access_token,
      ];

      // Make the request
      $curl = curl_init();

      curl_setopt_array(
        $curl,
        array(
          CURLOPT_URL => "https://api.piecard.app/payment/woocommerce/verify",
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => "POST",
          CURLOPT_POSTFIELDS => json_encode($data),
          CURLOPT_HTTPHEADER => array_merge(array("Content-Type: application/json"), $headers)
        )
      );

      $response = curl_exec($curl);

      curl_close($curl);

      // Check the response
      $responseData = json_decode($response, true);

      if ($responseData['data']['payment'] == false) {
        echo $responseData['data']['message'] ?? 'fail';
        return;
      }

      $order = wc_get_order($_GET['id']);
      $order->payment_complete();
      $order->reduce_order_stock();

      // return redirect to complete page
      wp_redirect($this->url . '/checkout/order-received/' . $_GET['id'] . '?key=' . $order->get_order_key());
      exit;

    }
  }
}
add_action('plugins_loaded', 'piecard_init_gateway_class');