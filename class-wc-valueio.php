<?php
/**
 * ValueIO Payments Gateway Class
 *
 * @author Garett Shulman <garett@inspirecommerce.com
 **/
class WC_ValueIO extends WC_Payment_Gateway {

  public function __construct() {

    global $woocommerce;

    // Necessary properties
    $this->id   = 'valueio';
    $this->icon = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/cards.png';
    $this->has_fields = true;
    $this->method_title = __('ValueIO', 'wc_valueio');

    $this->supports = array(
      'products', 
      'subscriptions',
      'subscription_cancellation', 
      'subscription_suspension', 
      'subscription_reactivation',
      'subscription_amount_changes',
      'subscription_date_changes',
      'subscription_payment_method_change',
      'refunds'
   );

    // Load the form fields.
    $this->init_form_fields();

    // Load the settings.
    $this->init_settings();

    // Define user set variables
    $this->title                    = $this->settings['title'];
    $this->description              = $this->settings['description'];
    $this->valueio_account          = $this->settings['valueio_account'];
    $this->valueio_write_only_token = $this->settings['valueio_write_only_token'];
    $this->valueio_admin_token      = $this->settings['valueio_admin_token'];
    $this->vault_enabled            = $this->settings['vault_enabled'];
    $this->test_mode                = $this->settings['test_mode'];
    $this->payment_destination      = $this->settings['payment_destination'];

    if ($this->test_mode == 'yes') {
      $this->live_base_url = 'https://api-staging.value.io';
    } else {
      $this->live_base_url = 'https://api.value.io';
    }
    //$this->live_base_url = 'http://localhost:3000';
    $this->valueio_base_url = $this->live_base_url;
    $this->valueio_api_url = "{$this->valueio_base_url}/v1/";
    $this->valueio_api_auth_headers = array(
      'Authorization' =>
        'Basic '.base64_encode($this->valueio_account.':'.$this->valueio_admin_token)
   );

    // Hooks

    // checks for availability of the plugin
    add_action('admin_notices', array(&$this, 'checks'));

    // handle subscriptions
    add_action(
      'scheduled_subscription_payment_valueio',
      array(&$this, 'process_scheduled_subscription_payment'),
      0,
      3
    );

    // Save admin options
    if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
      add_action(
        'woocommerce_update_options_payment_gateways_'.$this->id,
        array(&$this, 'process_admin_options')
      );
    } else {
      add_action(
        'woocommerce_update_options_payment_gateways',
        array(&$this, 'process_admin_options')
      );
    }

    // Payment form hook
    add_action('woocommerce_receipt_valueio', array(&$this, 'receipt_page'));

    //set enable property if ValueIO is supported for the user country
    if (!$this->is_available()) $this->enabled = false;

    //handle pay method change
    if ($_REQUEST['change_payment_method'] != null) {
      try {
        $account = $this->valueio_account;
        $base_url = $this->valueio_base_url;
        $order = new WC_Order($_REQUEST['order_id']);

        if ($_REQUEST['payment_method'] == null) {
          $write_only_token = $this->valueio_write_only_token;
          WC_ValueIO::update_payment_method($order, $account, $base_url, $write_only_token);
        } elseif ($_REQUEST['payment_method'] == 'valueio') {
          $use_stored = $_REQUEST['valueio-use-stored-payment-info'];
          if ($use_stored == null || $use_stored == 'no') {
            $single_use_credit_card_token = $_REQUEST['valueio_token'];
            $admin_token = $this->valueio_admin_token;
            $_REQUEST['change_payment_method'] = null;
            $wc_valueio = new WC_ValueIO;
            $card_response_data = $this->valueio_request(array(
              'order' => $order,
              'method' => 'get',
              'resource' => "credit_cards/{$single_use_credit_card_token}"
            ));
            update_post_meta(
              $order->id,
              'valueio_vault_id',
              $card_response_data['credit_card']['id']
            );
          } else {
            $customer_vault_ids = WC_ValueIO::get_user_vault_ids($order->user_id);
            $customer_vault_id_index = WC_ValueIO::get_post('valueio-payment-method');
            $credit_card_id = $customer_vault_ids[$customer_vault_id_index];
            update_post_meta($order->id, 'valueio_vault_id', $credit_card_id);
          }
        }
      }catch(Exception $e) {
        $this->handle_error(
          "Error while changing payment method: ".$e->getMessage(),
          array('order' => $order)
        );
      }
    }
  }

  function handle_error($message, $options = array()) {
    global $woocommerce;

    $throw = $options['throw'];
    if ($throw == null)
      $throw = false;

    $order = $options['order'];

    $message = __($message, 'woocommerce');

    if ($order != null)
      $order->add_order_note($message);

    $sanitized_message = explode('{', $message);
    $sanitized_message = $sanitized_message[0];
    wc_add_notice( $sanitized_message.'.  Please contact the store administrator', $notice_type = 'error' );
    trigger_error($sanitized_message);
  }

 /**
   * Process a refund if supported
   * @param  int $order_id
   * @param  float $amount
   * @param  string $reason
   * @return  bool|wp_error True or false based on success, or a WP_Error object
   */
  public function process_refund($order_id, $amount = null, $reason = '') {
    try {
      $order = wc_get_order($order_id);

      $payment_id = null;

      $args = array(
          'post_id' => $order->id,
          'approve' => 'approve',
          'type' => ''
   )  ;
 
      remove_filter('comments_clauses', array('WC_Comments', 'exclude_order_comments'));
 
      $comments = get_comments($args);
 
      foreach ($comments as $comment) {
        if (strpos($comment->comment_content, 'Payment ID: ') !== false) {
          $exploded_comment = explode(": ", $comment->comment_content);
          $payment_id = $exploded_comment[1];
        }
      }
 
      add_filter('comments_clauses', array('WC_Comments', 'exclude_order_comments'));

      if (! $order || ! $payment_id) {
        return false;
      }

      $params = array(
        'payment' => array(
          'kind' => 'refund',
          'refunded_payment' => $payment_id
       )
   )  ;

      if (! is_null($amount)) {
        $params['payment']['amount'] = $amount;
      } else {
        $params['payment']['amount'] = $order->get_total();
      }

      $response_data = $this->valueio_request(array(
        'order' => $order,
        'params' => $params
      ));

      // Check response
      if ($response_data['status'] == 200) {
        // Success
        $order->add_order_note(
          'ValueIO refund success. Refund ID: '.$response_data['payment']['id']
       );
        return true;
      } else {
        // Failure
        $this->handle_error(
          'ValueIO refund error. Response data: '.$response_data['body'],
          array('order' => $order)
       );
        return false;
      }
    }catch(Exception $e) {
      $this->handle_error(
        "Error while processing refund: ".$e->getMessage(),
        array('order' => $order)
      );
      return false;
    }
  }

  function update_payment_method($order, $account, $base_url, $write_only_token) {
    wp_enqueue_style('value.css', $this->valueio_base_url . '/assets/value.css');

    $vault = "true";

    echo "
      <script src='{$base_url}/assets/value.js'></script>
      <script>
        window.valueio_account = '{$account}';
        window.valueio_write_only_token = '{$write_only_token}';
        window.valueio_amount = '0.00'; 
        window.valueio_first_name = '{$order->billing_first_name}'; 
        window.valueio_last_name = '{$order->billing_last_name}'; 
        window.valueio_vault = '{$vault}'; 
        window.valueio_resource = 'credit_cards';
        window.valueio_secure_form_title_1 = '{$this->title}';
        window.valueio_secure_form_title_2 = '{$this->description}';
        window.valueio_form_selector = '#order_review';
        window.valueio_skip_iframe = function(){
          use_valueio = jQueryValueJS('#payment_method_valueio').prop('checked');
          use_new_payment_info = jQueryValueJS('#valueio-use-stored-payment-info-no').prop('checked');

          if (use_valueio) {
            if (use_new_payment_info == undefined || use_new_payment_info == true) {
              return false;
            } else {
              return true;
            }
          } else {
            return true;
          }
        };
      </script>
    ";
  }

  // Check that required fields for configuring the gateway are populated
  function checks() {
    global $woocommerce;

    if ($this->enabled == 'no')
      return;

    // Check required fields
    if (! $this->valueio_account) {

      echo '<div class="error"><p>' . sprintf(__('ValueIO error: Please enter your ValueIO account name <a href="%s">here</a>', 'wc_valueio'), admin_url('admin.php?page=woocommerce&tab=payment_gateways&subtab=gateway-valueio')) . '</p></div>';

      return;

    } elseif (! $this->valueio_write_only_token) {

      echo '<div class="error"><p>' . sprintf(__('ValueIO error: Please enter your ValueIO write only token <a href="%s">here</a>', 'wc_valueio'), admin_url('admin.php?page=woocommerce&tab=payment_gateways&subtab=gateway-valueio')) . '</p></div>';

      return;

    } elseif (! $this->valueio_admin_token) {

      echo '<div class="error"><p>' . sprintf(__('ValueIO error: Please enter your ValueIO admin token <a href="%s">here</a>', 'wc_valueio'), admin_url('admin.php?page=woocommerce&tab=payment_gateways&subtab=gateway-valueio')) . '</p></div>';

      return;

    }

  }

  /**
   * Check if this gateway is enabled and available in the user's country
   */
  function is_available() {
    return ($this->enabled === "yes" and $this->is_available_for_currency());
  }

  /**
   * Check if this gateway is enabled and available in the user's country
   */
  function is_available_for_currency() {
    return (in_array(get_woocommerce_currency(), array('USD')));
  }

  /**
   * Admin Panel Options
   * - Options for bits like 'title' and availability on a country-by-country basis
   *
   * @since 1.0.0
   */
  public function admin_options() {

?>
      <h3><?php _e('ValueIO', 'wc_valueio'); ?></h3>
      <p><?php _e('ValueIO uses an iframe to seamlessly integrate a secure payment form into the checkout process.', 'wc_valueio'); ?></p>
      <table class="form-table">
      <?php
    //if available then only generate the form
    if ($this->is_available_for_currency()) {

      // Generate the HTML For the settings form.
      $this->generate_settings_html();

    } else {

?>
                <div class="inline error"><p><strong><?php _e('Gateway Disabled', 'wc_valueio'); ?></strong>: <?php _e('ValueIO does not support your store currency.', 'wc_valueio'); ?></p></div>
            <?php

    }
?>
    <tr valign="top">
      <td colspan="2">

      </td>
    </tr>
    </table><!--/.form-table-->
      <?php
  } // End admin_options()

  /**
   * Initialise Gateway Settings Form Fields
   */
  function init_form_fields() {

    $this->form_fields = array(
	    'enabled'     => array(
	      'title'       => __('Enable/Disable', 'woothemes'),
	      'label'       => __('Enable ValueIO', 'woothemes'),
	      'type'        => 'checkbox',
	      'description' => '',
	      'default'     => 'no'
	   ),
      'title' => array(
        'title' => __('Title', 'wc_valueio'),
        'type' => 'text',
        'description' => __('This controls the title which the user sees during checkout.', 'wc_valueio'),
        'default' => __('ValueIO Payment', 'wc_valueio')
     ),
      'description' => array(
        'title' => __('Description', 'wc_valueio'),
        'type' => 'text',
        'description' => __('This controls the description which the user sees during checkout.', 'wc_valueio'),
        'default' => __('ValueIO Secure Payment', 'wc_valueio')
     ),
      'valueio_account' => array(
        'title' => __('ValueIO Account Name', 'wc_valueio'),
        'type' => 'text',
        'description' => __('Enter your ValueIO account name that you created when you registered the account.', 'wc_valueio'),
        'default' => ''
     ),
      'valueio_write_only_token' => array(
        'title' => __('ValueIO Write Only Token', 'wc_valueio'),
        'type' => 'text',
        'description' => __('Enter your ValueIO Write Only Token.', 'wc_valueio'),
        'default' => ''
     ),
      'valueio_admin_token' => array(
        'title' => __('ValueIO Admin Token', 'wc_valueio'),
        'type' => 'text',
        'description' => __('Enter your ValueIO Admin Token.', 'wc_valueio'),
        'default' => ''
     ),
      'vault_enabled' => array(
        'title' => __('ValueIO Card Vault', 'wc_valueio'),
        'label' => __('Enable Card Vault', 'wc_valueio'),
        'type' => 'checkbox',
        'description' => __('Allow customers to save billing information for future use', 'wc_valueio'),
        'default' => 'no'
     ),
      'test_mode' => array(
        'title' => __('Test Mode', 'wc_valueio'),
        'type' => 'checkbox',
        'label' => __('Enable ValueIO Test Mode', 'wc_valueio'),
        'description' => __('Enabling this will direct traffic to the staging valueio api instead of the default production valueio api', 'wc_valueio'),
        'default' => 'yes'
     ),
      'payment_destination' => array(
        'title' => __('Payment Destination (Optional)', 'wc_valueio'),
        'type' => 'text',
        'description' => __('The identifier for the ValueIO payment destination you would like payments to be transacted against. If blank the first payment destination in your ValueIO account will be used.', 'wc_valueio'),
        'default' => ''
     )
   );

  } // End init_form_fields()

  /**
   * Show description and stored cards if present
   **/
  function payment_fields() {
    if ($this->description) echo wpautop(wptexturize($this->description));
    $user = wp_get_current_user();
    if (count(WC_ValueIO::get_user_vault_ids($user->ID)) != 0) {
      ?>
        <fieldset  style="padding-left: 40px;">
          <fieldset>
            <input type="radio" name="valueio-use-stored-payment-info" id="valueio-use-stored-payment-info-yes" value="yes" checked="checked" onclick="document.getElementById('valueio-stored-info').style.display='block'"; />
            <label for="valueio-use-stored-payment-info-yes" style="display: inline;">
              <?php _e('Use a stored credit card', 'woocommerce') ?>
            </label>
            <div id="valueio-stored-info" style="padding: 10px 0 0 40px; clear: both;">
              <?php
              $i = 0;
              $method = $this->get_payment_method($i);
              while(! empty($method['credit_card'])) {
              ?>
                <p>
                <input type="radio" name="valueio-payment-method" id="<?php echo $i; ?>" value="<?php echo $i; ?>" <?php
                if ($i == 0) {
                  echo 'checked="checked"';
                }
                ?>onclick="document.getElementById('valueio-use-stored-payment-info-yes').click()" /> &nbsp;
                  <?php echo $method['credit_card']['number']; ?> <?php echo $method['credit_card']['month']; ?> / <?php echo $method['credit_card']['year']; ?>
                <br />
                </p>
              <?php
                  $method = $this->get_payment_method(++$i);
                } 
              ?>
            </div>
          </fieldset>
          <fieldset>
            <p>
              <input type="radio" name="valueio-use-stored-payment-info" id="valueio-use-stored-payment-info-no" value="no" onclick="document.getElementById('valueio-stored-info').style.display='none';" />
              <label for="valueio-use-stored-payment-info-no"  style="display: inline;"><?php _e('Use a new payment method', 'woocommerce') ?></label>
            </p>
          </fieldset>
        </fieldset>
      <?php
    }
  }

  /**
   * Get Value.IO credit_card id's
   */
  private static function get_user_vault_ids($user_id) {
    $vault_ids = get_user_meta($user_id, 'customer_valueio_vault_ids', true);
    if ($vault_ids == '') {
      return array();
    } else {
      return $vault_ids;
    }
  }

  /**
   * Get details of a payment method for the current user from the Customer Vault
   */
  function get_payment_method($payment_method_number) {
    if($payment_method_number < 0)
      die('Invalid payment method: ' . $payment_method_number);

    $user = wp_get_current_user();

    $customer_vault_ids = WC_ValueIO::get_user_vault_ids($user->ID);
    if($payment_method_number >= count($customer_vault_ids)) return null;

    $vault_id = $customer_vault_ids[$payment_method_number];

    $card_response_data = $this->valueio_request(array(
      'order' => $order,
      'method' => 'get',
      'resource' => "credit_cards/{$vault_id}"
    ));

    return $card_response_data;
  }

  /**
   * Delete a stored billing method
   */
  function delete_payment_method($payment_method) {
    global $woocommerce;
    $user = wp_get_current_user();
    $customer_vault_ids = WC_ValueIO::get_user_vault_ids($user->ID);

    $vault_id = $customer_vault_ids[ $payment_method ];

    $card_response_data = $this->valueio_request(array(
      'method' => 'delete',
      'resource' => "credit_cards/{$vault_id}"
    ));

    // Update subscription references
    if(class_exists('WC_Subscriptions_Manager')) {
      $subscriptions = WC_Subscriptions_Manager::get_users_subscriptions($user->ID);
      foreach((array) ($subscriptions) as $subscription) {
        $subscription_payment_method = get_post_meta(
          $subscription['order_id'],
          'vauleio_vault_id',
          true
        );
        // Cancel subscriptions that were purchased with the deleted method
        if($subscription_payment_method == $vault_id) {
          delete_post_meta($subscription['order_id'], 'valueio_vault_id');
          WC_Subscriptions_Manager::cancel_subscription(
            $user->ID,
            WC_Subscriptions_Manager::get_subscription_key($subscription['order_id'])
          );
        }
      }
    }

    unset($customer_vault_ids[ $payment_method ]);
    update_user_meta(
      $user->ID,
      'customer_valueio_vault_ids',
      array_values($customer_vault_ids)
    );

    wc_add_notice( __('Successfully deleted your information!', 'woocommerce'), $notice_type = 'success' );
  }

  /**
   * Support for deleting vaulted cards
   */
  public static function add_payment_method_options_to_my_account() {

    wp_enqueue_script('edit_billing_details', PLUGIN_DIR . 'js/edit_billing_details.js', array('jquery'), 1.0);

    $wc_valueio = new WC_ValueIO;

    $user = wp_get_current_user();

    if (WC_ValueIO::get_user_vault_ids($user->id) == null) return;

    if(WC_ValueIO::get_post('delete') != null) {

      $method_to_delete = WC_ValueIO::get_post('delete');
      $response = $wc_valueio->delete_payment_method($method_to_delete);

    } else if(WC_ValueIO::get_post('update') != null) {

      $method_to_update = WC_ValueIO::get_post('update');
      $customer_vault_ids = WC_ValueIO::get_user_vault_ids($user->ID);
      $vault_id = $customer_vault_ids[$method_to_update];

    }

    ?>

    <h2>Saved Payment Methods</h2>
    <p>This information is stored to save time at checkout and to pay for subscriptions.</p>

    <?php
      $i = 0;
      $current_method = $wc_valueio->get_payment_method($i);
      while($current_method != null) {
    ?>

      <header class="title">

        <?php echo "{$current_method['credit_card']['number']} {$current_method['credit_card']['month']}/{$current_method['credit_card']['year']}"; ?>
        <button style="float:right" class="button" id="unlock-delete-button-<?php echo $i; ?>"><?php _e('Delete', 'woocommerce'); ?></button>

        <p>

          <form action="<?php echo get_permalink(woocommerce_get_page_id('myaccount')) ?>" method="post" style="float:right" >
            <input type="submit" value="<?php _e('Yes', 'woocommerce'); ?>" class="button alt" id="delete-button-<?php echo $i; ?>" style="display:none">
            <button style="float:right; display:none" class="button" id="cancel-delete-button-<?php echo $i; ?>" onclick='return false;'><?php _e('No', 'woocommerce'); ?></button>
            <input type="hidden" name="delete" value="<?php echo $i ?>">
          </form>
          <span id="delete-confirm-msg-<?php echo $i; ?>" style="float:left_; display:none">Are you sure you want to delete this card? (Subscriptions purchased with this card will be canceled.)&nbsp;</span>
        </p>

      </header><?php

      $current_method = $wc_valueio->get_payment_method(++$i);

    }

  }

  /**
   * process_payment
   **/
  function process_payment($order_id) {
    try {
      $order = new WC_Order($order_id);
      
      if (WC_ValueIO::get_post('valueio-use-stored-payment-info') == 'yes') {
        $valueio_args = $this->valueio_payment_args_for_order ($order);
        $valueio_args['payment']['transact'] = 'true';
        if ($this->payment_destination != '') {
          $valueio_args['payment']['destination'] = $this->payment_destination;
        }

        $customer_vault_ids = WC_ValueIO::get_user_vault_ids($order->user_id);
        $customer_vault_id_index = WC_ValueIO::get_post('valueio-payment-method');
        $credit_card_id = $customer_vault_ids[$customer_vault_id_index];
        $valueio_args['payment']['credit_card'] = $credit_card_id;

        $response_data = $this->valueio_payment_request(array(
          'order' => $order,
          'params' => $valueio_args
        ));

        if (WC_ValueIO::is_subscription($order)) {
          update_post_meta($order->id, 'valueio_vault_id', $credit_card_id);
        }

        $redirect_url = $this->get_return_url($order);
        return array(
          'result'   => 'success',
          'redirect'  => $redirect_url
        );
      }

      return array(
        'result'   => 'success',
        'redirect'  => add_query_arg(
          'order',
          $order->id,
          add_query_arg(
            'key',
            $order->order_key,
            get_permalink(woocommerce_get_page_id('pay'))
          )
        )
      );

    }catch(Exception $e) {
      $this->handle_error(
        "Error while processing payment: ".$e->getMessage(),
        array('order' => $order)
      );
    }
  }

  function valueio_payment_request($options) {
    global $woocommerce;

    $order = $options['order'];
    if ($order == null)
      throw new Exception('valueio_payment_request options must include order');

    $method = $options['method'];
    if ($method == null)
      $method = 'post';

    $resource = $options['resource'];
    if ($resource == null)
      $resource = 'payments';

    $params = $options['params'];
    if ($params == null)
      $params = array();

    $throw_response_error = $options['throw_response_error'];
    if ($throw_response_error == null)
      $throw_response_error = true;

    $response_data = $this->valueio_request(array(
      'order' => $order,
      'method' => $method,
      'resource' => $resource,
      'params' => $params
    ));

    if($order->order_total != $response_data['payment']['amount']) {
      throw new Exception('Error creating payment: payment total does not equal order total.');
    } else if($order->id != $response_data['payment']['order_id']) {
      throw new Exception('Error creating payment: payment order id does not equal order id.');
    }

    if($response_data['payment']['transacted']) {
      $order->add_order_note(
        'ValueIO payment success. Payment ID: '.$response_data['payment']['id']
      );

      if (WC_ValueIO::is_subscription($order)) {
        update_post_meta(
          $order->id,
          'valueio_vault_id',
          $response_data['credit_card']['id']
        );
      }

      $order->payment_complete();

      $woocommerce->cart->empty_cart();

      $redirect_url = $this->get_return_url($order);
      echo "
        <script>
          window.location = '{$redirect_url}';
        </script>
      ";
    }

    return $response_data;
  }

  function valueio_request($options) {
    global $woocommerce;

    $order = $options['order'];

    $method = $options['method'];
    if ($method == null)
      $method = 'post';

    $resource = $options['resource'];
    if ($resource == null)
      $resource = 'payments';

    $params = $options['params'];
    if ($params == null)
      $params = array();

    $throw_response_error = $options['throw_response_error'];
    if ($throw_response_error == null)
      $throw_response_error = true;

    $headers = $this->valueio_api_auth_headers;
    $url = "{$this->valueio_api_url}/{$resource}";

    if ($method == 'get') {
      $response = wp_remote_get($url, array('headers'=>$headers));
    } else if ($method == 'post') {
      $response = wp_remote_post($url, array('body'=>$params, 'headers'=>$headers));
    } else if ($method == 'delete') {
      $response = wp_remote_request(
        $url,
        array('method'=>'DELETE', 'body'=>$params, 'headers'=>$headers)
      );
    } else {
      throw new Exception("Error unknown http method: {$method}");
    }
        
    $response_data = array(
      'payment' => array(),
      'credit_card' => array(),
      'status' => wp_remote_retrieve_response_code($response),
      'body' => is_wp_error($response) ? $response->get_error_message() : $response['body']
    );

    
    $post_error_situation =
      ($method == 'post' and (
        is_wp_error($response) or
        ($response_data['status'] != 200 and $response_data['status'] != 202)
      ));
    
    if($post_error_situation and $throw_response_error){
      throw new Exception('Error response received from Value.IO while attempting to create payment.  Status: '.$response_data['status'].' '.$response_data['body']);
    }
    
    $response_body = json_decode(wp_remote_retrieve_body($response));

    if(!is_object($response_body) and $method == 'post'){
      throw new Exception('Error response received from Value.IO while attempting to create payment: response body is not json. '.$response_data['body']);
    } else {

      $response_data['payment']['amount'] =
        $response_body->data->payment->amount;
      $response_data['payment']['order_id'] =
        $response_body->data->payment->order_id;
      $response_data['payment']['id'] =
        $response_body->data->payment->identifier;
      $response_data['payment']['transacted'] =
        $response_body->data->payment->transacted;
      $response_data['payment']['credit_card_id'] =
        $response_body->data->payment->credit_card_id;
      $response_data['credit_card']['number'] =
        $response_body->data->credit_card->number;
      $response_data['credit_card']['month'] =
        $response_body->data->credit_card->month;
      $response_data['credit_card']['year'] =
        $response_body->data->credit_card->year;
      $response_data['credit_card']['id'] =
        $response_body->data->credit_card->identifier;
      $response_data['credit_card']['vaulted'] =
        $response_body->data->credit_card->vaulted;

      $payment_card_empty = empty($response_data['payment']['credit_card_id']);
      if (empty($response_body->data->credit_card) and ! $payment_card_empty) {

        $card_response_data = $this->valueio_request(array(
          'order' => $order,
          'method' => 'get',
          'resource' => "credit_cards/{$response_data['payment']['credit_card_id']}"
        ));

        if ($card_response_data['status'] == 200) {
          $response_data['credit_card']['number'] =
            $card_response_data['credit_card']['number'];
          $response_data['credit_card']['month'] =
            $card_response_data['credit_card']['month'];
          $response_data['credit_card']['year'] =
            $card_response_data['credit_card']['year'];
          $response_data['credit_card']['id'] =
            $card_response_data['credit_card']['id'];
          $response_data['credit_card']['vaulted'] =
            $card_response_data['credit_card']['vaulted'];
        }
      }

      if ($response_data['credit_card']['vaulted']) {
        $customer_vault_ids = WC_ValueIO::get_user_vault_ids($order->user_id);
        if (! in_array($response_data['credit_card']['id'], $customer_vault_ids)) {
          $customer_vault_ids[] = $response_data['credit_card']['id'];
          update_user_meta(
            $order->user_id,
            'customer_valueio_vault_ids',
            $customer_vault_ids
          );
        }
      }
    }

    return $response_data;
  }

  /**
   * valueio_payment_args_for_order
   **/
  function valueio_payment_args_for_order ($order) {
    $valueio_args = array(
      'payment' => array(
        'valueio_account'     => $this->valueio_account,
        'amount'              => $order->order_total,
        'currency'            => get_woocommerce_currency(),

        'global_order_number' => $order->order_key,
        'order_id'            => $order->id,

        'email'               => $order->billing_email,
        'phone'               => $order->billing_phone,
        'company'             => $order->billing_company,

        'first_name'          => $order->billing_first_name,
        'last_name'           => $order->billing_last_name,
        'address1'            => $order->billing_address_1,
        'address2'            => $order->billing_address_2,
        'city'                => $order->billing_city,
        'state'               => $order->billing_state,
        'zip'                 => $order->billing_postcode,
        'country'             => $order->billing_country,

        'data' => array(
          'shipping_first_name' => $order->shipping_first_name,
          'shipping_last_name'  => $order->shipping_last_name,
          'shipping_address1'   => $order->shipping_address_1,
          'shipping_address2'   => $order->shipping_address_2,
          'shipping_city'       => $order->shipping_city,
          'shipping_state'      => $order->shipping_state,
          'shipping_zip'        => $order->shipping_postcode,
          'shipping_country'    => $order->shipping_country,
       )
     )
   );
    return $valueio_args;
  }

  /**
   * Get post data if set
   */
  private static function get_post($name) {
    if (isset($_POST[ $name ])) {
      return $_POST[ $name ];
    }
    return null;
  }

  /**
   * Check whether an order is a subscription
   */
  private static function is_subscription($order) {
    return class_exists('WC_Subscriptions_Order') and
      WC_Subscriptions_Order::order_contains_subscription($order);
  }

  /**
   * receipt_page shows value.io payment form and handles response
   **/
  function receipt_page($order_id) {

    wp_enqueue_style('value.css', $this->valueio_base_url . '/assets/value.css');

    global $woocommerce;
    
    $query_array = array();
    parse_str($_SERVER['QUERY_STRING'], $query_array);

    $order = new WC_Order($order_id);

    $payment_id = NULL;

    if ($payment_id == NULL or $payment_id == '') {
      $payment_id = $query_array['payment_id'];
    }

    if ($payment_id == '') {
      try {
        $valueio_args = $this->valueio_payment_args_for_order($order);
        $valueio_args['payment']['transact'] = 'false';
        if ($this->payment_destination != '') {
          $valueio_args['payment']['destination'] = $this->payment_destination;
        }

        $response_data = $this->valueio_payment_request(array(
          'order' => $order,
          'params' => $valueio_args
        ));

        $payment_id = $response_data['payment']['id'];

        if (! $response_data['payment']['transacted']) {
      
          $vault = "false";
          if ($this->vault_enabled == 'yes') {
            if (WC_ValueIO::is_subscription($order)) {
              $vault = "true";
            } else {
              $vault = "collect";
            }
          }

          echo "
            <script src='{$this->valueio_base_url}/assets/value.js'></script>
            <script>
              window.valueio_account = '{$this->valueio_account}';
              window.valueio_write_only_token = '{$this->valueio_write_only_token}';
              window.valueio_amount = '{$order->order_total}'; 
              window.valueio_first_name = '{$order->billing_first_name}'; 
              window.valueio_last_name = '{$order->billing_last_name}'; 
              window.valueio_vault = '{$vault}'; 
              window.valueio_resource = 'payments';
              window.valueio_secure_form_title_1 = '{$this->title}';
              window.valueio_secure_form_title_2 = '{$this->description}';
              window.valueio_payment_id = '{$payment_id}';
              window.valueio_on_success = function(){
                if (typeof window.valueio_payment_id !== 'undefined') {
                  window.location.search = window.location.search + '&payment_id=' + window.valueio_payment_id;
                } else {
                  alert('Payment ID not found.  You will now be redirected back to the checkout page.');
                  var checkout_url = '{$woocommerce->cart->get_checkout_url()}';
                  window.location = checkout_url;
                }
              };
              window.valueio_on_cancel = function(reason){
                if(reason != undefined){
                  alert(reason + ' You will now be redirected back to the checkout page.');
                }
                var checkout_url = '{$woocommerce->cart->get_checkout_url()}';
                window.location = checkout_url;
              };
              jQuery(function(){
                show_iframe = function(){
                  if (window.valueio_iframe == undefined){
                    setTimeout(show_iframe, 200);
                  } else {
                    window.valueio_iframe.show_iframe();
                  }
                };
                show_iframe();
              });
            </script>
          ";
        }
      }catch(Exception $e) {
        $this->handle_error(
          "Error while creating untransacted order: ".$e->getMessage(),
          array('order' => $order)
        );

        //Return to Checkout
        echo "
          <script>
            alert('Value.IO payment canceled.  {$e->getMessage()}  You will now be redirected back to the checkout page.');
            var checkout_url = '{$woocommerce->cart->get_checkout_url()}';
            window.location = checkout_url;
          </script>
        ";
      }
    } else {
      try {

        $response_data = $this->valueio_payment_request(array(
          'order' => $order,
          'method' => 'get',
          'resource' => "payments/{$payment_id}"
        ));

        if (!$response_data['payment']['transacted']) {
          throw new Exception('Error creating payment: payment was not transacted.');
        }

      }catch(Exception $e) {
        $this->handle_error(
          "Error while transacting payment: ".$e->getMessage(),
          array('order' => $order)
        );

        //Return to Checkout
        echo "
          <script>
            alert('Value.IO payment canceled.  {$e->getMessage()}  You will now be redirected back to the checkout page.');
            var checkout_url = '{$woocommerce->cart->get_checkout_url()}';
            window.location = checkout_url;
          </script>
        ";
      }
    }
  }

  /**
   * Process a payment for an ongoing subscription.
   */
//TODO: Test this!
  function process_scheduled_subscription_payment($amount_to_charge, $order, $product_id) {
    try {
      $user = new WP_User($order->user_id);
      $this->check_payment_method_conversion($user->user_login, $user->ID);
      $vault_id = get_post_meta($order->id, 'valudio_vault_id', true);


      $valueio_args = $this->valueio_payment_args_for_order($order);
      $valueio_args['payment']['credit_card'] = $vault_id;
      $valueio_args['payment']['transact'] = 'true';
      if ($this->payment_destination != '') {
        $valueio_args['payment']['destination'] = $this->payment_destination;
      }

      $response_data = $this->valueio_payment_request(array(
        'order' => $order,
        'params' => $valueio_args,
        'throw_response_error' => false
      ));

      $payment_id = $response_data['payment']['id'];
      $payment_transacted = $response_data['payment']['transacted'];

      if ($payment_transacted) {
        $order->add_order_note(__('ValueIO scheduled subscription payment success. Payment ID: ' , 'woocommerce') . $response_data['payment']['id']);
        WC_Subscriptions_Manager::process_subscription_payments_on_order($order);
      } else {
        $order->add_order_note(__("Inspire Commerce scheduled subscription payment failed: {$response_data['body']}", 'woocommerce'));
        WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($order);
      }
    }catch(Exception $e) {
      $this->handle_error(
        "Error while processing scheduled subscription payment: ".$e->getMessage(),
        array('order' => $order)
      );
    }
  }
}

/**
 * Add the gateway to WooCommerce
 **/
function add_valueio_gateway($methods) {
  $methods[] = 'WC_ValueIO'; return $methods;
}

add_filter('woocommerce_payment_gateways', 'add_valueio_gateway');

// support for deleting vaulted cards
add_action('woocommerce_before_my_account', 'WC_ValueIO::add_payment_method_options_to_my_account');
