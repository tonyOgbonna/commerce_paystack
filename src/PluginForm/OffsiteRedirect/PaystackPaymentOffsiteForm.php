<?php

namespace Drupal\commerce_paystack\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * PaystackPaymentOffsiteForm.
 */
class PaystackPaymentOffsiteForm extends BasePaymentOffsiteForm {

  const PAYSTACK_STANDARD_FLOW_URL = 'https://api.paystack.co/transaction/initialize';

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    $logger = \Drupal::logger('commerce_paystack');
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    // $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $data = [];

    $plugin = $payment->getPaymentGateway()->getPlugin();
    $flow = $plugin->getConfiguration()['flow'];
    $mode = $plugin->getMode();
    // $live_public_key = $plugin->getConfiguration()['live_public_key'];
    $live_secret_key = $plugin->getConfiguration()['live_secret_key'];
    // $test_public_key = $plugin->getConfiguration()['test_public_key'];
    $test_secret_key = $plugin->getConfiguration()['test_secret_key'];
    // $currency_code = $plugin->getConfiguration()['currency_code'];
    $order = $payment->getOrder();
    $payment_amount = $payment->getAmount()->getNumber();
    $site_base_url = \Drupal::request()->getSchemeAndHttpHost();
    $callback_url = $site_base_url . '/checkout/' . $order->id() . '/complete/return';

    if ($mode == 'live') {
      $secret_key = $live_secret_key;
      // $public_key = $live_public_key;
    }
    else {
      $secret_key = $test_secret_key;
      // $public_key = $test_public_key;
    }

    if ($flow == 'standard') {
      $curl = curl_init();
      $email = $order->getEmail();
      $amount = $payment_amount * 100;

      curl_setopt_array($curl, [
        CURLOPT_URL => self::PAYSTACK_STANDARD_FLOW_URL,
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode([
          'amount' => $amount,
          'email' => $email,
          'callback_url' => $callback_url,
          'metadata' => [
            'order_id' => $order->id(),
          ],
        ]),
        CURLOPT_HTTPHEADER => [
          "authorization: Bearer " . $secret_key,
          "content-type: application/json",
          "cache-control: no-cache",
        ],
      ]);

      $response = curl_exec($curl);

      $err = curl_error($curl);

      if ($err) {
        $logger->error('Error: Curl returned error: ' . $err);
        die('Curl returned error: ' . $err);
      }

      $tranx = json_decode($response);

      if ($tranx->status) {
        $logger->info('Paystack: Authorization URL created: ' . $tranx->message);
        $auth_url = $tranx->data->authorization_url;
      }
    }
    else {
      \Drupal::messenger()->addMessage('OnSite method not implemented yet');
    }

    $form = $this->buildRedirectForm(
      $form,
      $form_state,
      $auth_url,
      $data,
      self::REDIRECT_POST
    );

    return $form;
  }

}
