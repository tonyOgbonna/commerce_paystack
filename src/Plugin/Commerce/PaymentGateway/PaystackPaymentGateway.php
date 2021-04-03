<?php

namespace Drupal\commerce_paystack\Plugin\Commerce\PaymentGateway;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * TODO: class docs.
 *
 * @CommercePaymentGateway(
 *   id = "commerce_paystack_paystack_payment_gateway",
 *   label = @Translation("Paystack (Off-site redirect)"),
 *   display_label = @Translation("Paystack"),
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_paystack\PluginForm\OffsiteRedirect\PaymentOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa",
 *   },
 *   requires_billing_information = FALSe,
 * )
 */
class PaystackPaymentGateway extends PaymentGatewayBase {

  const PAYSTACK_INITIALIZE_URL = 'https://api.paystack.co/transaction/initialize';
  const PAYSTACK_VERIFY_URL = "https://api.paystack.co/transaction/verify/";

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManagerWrapper;

  /**
   * The commerce_payment storage handler.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $commercePaymentStorage;

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Creates a PaystackPaymentGateway instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   *   The payment type manager.
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   *   The payment method type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager_wrapper
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityStorageInterface $commerce_payment_storage
   *   The commerce_payment storage handler.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    PaymentTypeManager $payment_type_manager,
    PaymentMethodTypeManager $payment_method_type_manager,
    TimeInterface $time,
    EntityTypeManagerInterface $entity_manager_wrapper,
    EntityStorageInterface $commerce_payment_storage,
    MessengerInterface $messenger
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);
    $this->entityManagerWrapper = $entity_manager_wrapper;
    $this->commercePaymentStorage = $commerce_payment_storage;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('entity_type.manager'),
      $container->get('entity_type.manager')->getStorage('commerce_payment'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'live_public_key' => '',
      'live_secret_key' => '',
      'flow' => 'standard',
      'test_public_key' => '',
      'test_secret_key' => '',
      'currency_code' => 'NGN',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['flow'] = [
      '#type' => 'radios',
      '#title' => $this->t('Payment flow'),
      '#options' => ['inline' => $this->t('Inline'), 'standard' => $this->t('Standard')],
      '#default_value' => $this->getFlow(),
      '#required' => TRUE,
    ];

    $form['live_public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live Public key'),
      '#description' => $this->t('Live Paystack Public Key.'),
      '#default_value' => $this->getLivePublicKey(),
      '#required' => TRUE,
    ];

    $form['live_secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live Secret key'),
      '#description' => $this->t('Live Paystack Secret Key.'),
      '#default_value' => $this->getLiveSecretKey(),
      '#required' => TRUE,
    ];

    $form['test_public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Public key'),
      '#description' => $this->t('Test Paystack Public Key.'),
      '#default_value' => $this->getTestPublicKey(),
      '#required' => TRUE,
    ];

    $form['test_secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Secret key'),
      '#description' => $this->t('Test Paystack Secret Key.'),
      '#default_value' => $this->getTestSecretKey(),
      '#required' => TRUE,
    ];

    $form['currency_code'] = [
      '#type' => 'radios',
      '#title' => $this->t('Currency Code'),
      '#description' => $this->t('Currency Code.'),
      '#options' => ['NGN' => $this->t('NGN')],
      '#default_value' => $this->getCurrencyCode(),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['live_public_key'] = $values['live_public_key'];
      $this->configuration['live_secret_key'] = $values['live_secret_key'];
      $this->configuration['flow'] = $values['flow'];
      $this->configuration['test_public_key'] = $values['test_public_key'];
      $this->configuration['test_secret_key'] = $values['test_secret_key'];
      $this->configuration['currency_code'] = $values['currency_code'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getLiveSecretKey() {
    return $this->configuration['live_secret_key'];
  }

  /**
   * {@inheritdoc}
   */
  public function getLivePublicKey() {
    return $this->configuration['live_public_key'];
  }

  /**
   * {@inheritdoc}
   */
  public function getTestSecretKey() {
    return $this->configuration['test_secret_key'];
  }

  /**
   * {@inheritdoc}
   */
  public function getTestPublicKey() {
    return $this->configuration['test_public_key'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFlow() {
    return $this->configuration['flow'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrencyCode() {
    return $this->configuration['currency_code'];
  }

  /**
   * {@inheritdoc}
   */
  public function getPaystackInitializeUrl() {
    return self::PAYSTACK_INITIALIZE_URL;
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    // @todo Add examples of request validation.
    $logger = \Drupal::logger('commerce_paystack');
    $curl = curl_init();

    if (!$reference = $request->query->get('reference')) {
      $this->messenger->addStatus('No reference supplied');
      $logger->error('Error: No reference supplied: ' . $request->query);
    }

    $mode = $this->getMode();
    $live_secret_key = $this->getLiveSecretKey();
    $test_secret_key = $this->getTestSecretKey();

    if ($mode == 'live') {
      $secret_key = $live_secret_key;
    }
    else {
      $secret_key = $test_secret_key;
    }

    curl_setopt_array($curl, [
      CURLOPT_URL => self::PAYSTACK_VERIFY_URL . rawurlencode($reference),
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_HTTPHEADER => [
        "accept: application/json",
        "authorization: Bearer " . $secret_key,
        "cache-control: no-cache",
      ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);

    if ($err) {
      $logger->error('Error: Curl returned error: ' . $err);
      die('Curl returned error: ' . $err);
    }

    $transaction = json_decode($response);

    if (!$transaction->status) {
      $logger->error('API returned error: ' . $transaction->message);
      die('API returned error: ' . $transaction->message);
    }

    if ('success' == $transaction->data->status) {
      $payment_storage = $this->entity_type_manager->getStorage('commerce_payment');
      $payment = $payment_storage->loadByRemoteId($reference);
      if ($payment && $payment->getState() == 'pending') {
        $payment->setState('completed');
        $payment->setRemoteState($transaction->data->status);
        $payment->save();
      }
      else {
        $payment = $payment_storage->create([
          'state' => 'completed',
          'amount' => $order->getTotalPrice(),
          'payment_gateway' => $this->parentEntity->id(),
          'order_id' => $order->id(),
          'remote_id' => $reference,
          'remote_state' => $transaction->data->status,
          'authorized' => $this->time->getCurrentTime(),
        ]);
        $payment->save();
      }
      $logger->info('Saving Payment information. Transaction reference: ' . $reference);
      $this->messenger->addStatus($this->t('Your payment was successful with Order id : @orderid and Transaction reference : @transaction_id', ['@orderid' => $order->id(), '@transaction_id' => $reference]));
      $this->messenger->addMessage($this->t('Your application form submission has been completed successfully. We will get back to you with further details. Thank you.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    $status = $request->get('status');
    $this->messenger->addStatus($this->t('Payment @status on @gateway but may resume the checkout process here when you are ready.', [
      '@status' => $status,
      '@gateway' => $this->getDisplayLabel(),
    ]), 'error');
  }

  /**
   * The onNotify method.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The json response.
   */
  public function onNotify(Request $request) {
    // $response = urldecode($request->query->get('data'));
    $body = $request->content;
    $logger = \Drupal::logger('commerce_paystack');
    $logger->info('Paystack charge.success call: ' . $request);

    if (!$signature = $request->server->get('HTTP_X_PAYSTACK_SIGNATURE')) {
      $logger->error('Bad Paystack call signature: ' . $request);
      // Throw new PaymentGatewayException('An unexpected error occurred.');.
      exit();
    }

    $mode = $this->getMode();
    $live_secret_key = $this->getLiveSecretKey();
    $test_secret_key = $this->getTestSecretKey();

    if ($mode == 'live') {
      $secret_key = $live_secret_key;
    }
    else {
      $secret_key = $test_secret_key;
    }

    // Confirm the event's signature.
    if ($signature !== hash_hmac('sha512', $body, $secret_key)) {
      // Silently forget this ever happened.
      exit();
    }

    // http_response_code(200);
    // parse event (which is json string) as object
    // Give value to your customer but don't give any output
    // Remember that this is a call from Paystack's servers and
    // Your customer is not seeing the response here at all.
    $event = json_decode($body);
    switch ($event->event) {
      // charge.success.
      case 'charge.success':
        // TIP: you may still verify the transaction
        // before giving value.
        $remote_id = $event->data->reference;
        $remote_state = $event->data->status;

        $payment_storage = $this->entity_type_manager->getStorage('commerce_payment');
        /** @var \Drupal\commerce_payment\Entity\Payment $payment */
        $payment = $payment_storage->loadByRemoteId($remote_id);
        if ($payment) {
          $payment->setState('completed');
          $payment->setRemoteState($remote_state);
          $payment->save();
        }
        else {
          // Create a new payment.
          $amount = $event->data->amount / 100;
          $payment = $payment_storage->create([
            'state' => 'completed',
            'amount' => $amount,
            'payment_gateway' => $this->parentEntity->id(),
            // 'order_id' => $order->id(),
            'remote_id' => $event->data->reference,
            'remote_state' => $event->data->status,
            'authorized' => $this->time->getCurrentTime(),
          ]);
          $payment->save();
        }
        break;
    }
    // exit();
    return new JsonResponse('OK');
  }

}
