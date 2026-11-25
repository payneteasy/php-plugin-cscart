<?php
// Preventing direct access to the script, because it must be included by the "include" directive.
defined('BOOTSTRAP') or die('Access denied');

use Tygh\Enum\OrderStatuses,
    Tygh\Registry,
    Src\Classes\Api\PaynetApi,
    Src\Classes\Common\PaynetLogger,
    Src\Classes\Exception\PaynetException;


class Payneteasy
{
    private string $order_id;
    private array $order_info;
    private array $processor_params;
    public string $mode;
    private PaynetLogger $logger;


    public function __construct(
        string $order_id,
        array $order_info,
        array $processor_data,
        string $mode
    ) {
        $this->mode = $mode ?: '';

        $this->setPaynetLogger();

        $this->initProperties(
            $order_id,
            $order_info,
            $processor_data
        );
    }


    /**
     * Initializes the properties required to process the payment
     *
     * @param string  $order_id        The order ID.
     * @param array   $order_info      Order information, including order details.
     * @param array   $processor_data  Processor data, including payment parameters.
     *
     * @return void
     */
    private function initProperties(
        string $order_id,
        array $order_info,
        array $processor_data
    ) {
        try {

            // Set order_id
            $this->setOrderId($order_id, $order_info);

            $this->setOrderInfo($order_info);

            $this->setProcessorParams($processor_data);

            // Logging request data
            if (in_array($this->mode, ['return', 'webhook'])) $this->logger->debug(
                __FUNCTION__ . ' > ' . $this->mode . ': ',
                ($this->mode === 'return') ? ['request_data' => $_REQUEST] : ['php_input' => json_decode(file_get_contents('php://input'))]
            );

        } catch (\Exception $e) {
            // Handle exception and log error
            $this->logger->error(sprintf(
                __FUNCTION__ . ' > PaynetEasy exception : %s;',
                $e->getMessage()
            ));

            throw $e;

        }
    }


    /**
     * Gets and sets the order_id property.
     *
     * @param string  $order_id    The order ID.
     * @param array   $order_info  Order information, including order details.
     *
     * @return void
     */
    private function setOrderId(string $order_id, array $order_info): void
    {
        $this->order_id = $order_id ?: '';
        if (empty($this->order_id)) {
            $this->order_id = (string) ($_REQUEST['merchant_order'] ?? $_REQUEST['client_orderid'] ?? '');
            if (!empty($order_info)) {
                $this->order_id = (string) ($order_info['order_id'] ?? '');
            }
        }

        if (!ctype_digit($this->order_id)) {
            $this->order_id = '';
        }

        if (empty($this->order_id)) {
            throw new \Exception(__('payneteasy_order_id_not_found'));
        }
    }


    /**
     * Gets and sets the order_info property.
     *
     * @param array $order_info  Order information, including order details.
     *
     * @return void
     * @throws Exception
     */
    private function setOrderInfo(array $order_info): void
    {
        $this->order_info = $order_info ?: fn_get_order_info($this->order_id);

        // Retrieve order information based on the order ID.
        if (empty($this->order_info)) {
            throw new \Exception(__('payneteasy_order_not_found'));
        }
    }


    /**
     * Gets and sets the processor_params property.
     *
     * @param array $processor_data  Processor data, including payment parameters.
     *
     * @return void
     * @throws Exception
     */
    private function setProcessorParams(array $processor_data): void
    {
        $payment_id = db_get_field("SELECT payment_id FROM ?:orders WHERE order_id = ?i", $this->order_id);

        // Notifications name the order themselves: only accept orders paid with this processor.
        $processor_script = db_get_field(
            'SELECT p.processor_script FROM ?:payments AS m'
            . ' INNER JOIN ?:payment_processors AS p ON p.processor_id = m.processor_id'
            . ' WHERE m.payment_id = ?i',
            $payment_id
        );
        if ($processor_script !== 'payneteasy.php') {
            throw new \Exception(__('payneteasy_empty_processor_params'));
        }

        if (empty($this->processor_params) && !empty($payment_id) && $payment_method_data = fn_get_payment_method_data($payment_id)) {
            $this->processor_params = $payment_method_data['processor_params'];
        }

        if (empty($this->processor_params)) {
            throw new \Exception(__('payneteasy_empty_processor_params'));
        }
    }


    /**
     * Handle the payment response.
     *
     * Handle the payment response logic, including error handling.
     * This function checks and processes the payment response based on the provided $mode.
     * If an error occurs during response handling, an exception is thrown with an error message.
     *
     * @return void
     * @throws Exception If an error occurs during response handling, an exception is thrown with an error message.
     */
    public function handleResponse(): void
    {
        // The gateway returns the customer with a cross-site POST, which carries
        // no SameSite=Lax session cookie. Answering it would replace the
        // customer's session with an empty one and send them to the login page.
        // Re-issue the request as a same-site GET without a new session cookie.
        if ($this->mode === 'return' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            header_remove('Set-Cookie');
            header('Location: ' . fn_url(
                'payment_notification.return?payment=payneteasy&merchant_order=' . (int) $this->order_id,
                'C',
                'current'
            ), true, 303);
            exit;
        }

        try {
            // If another request is already processing the order, stop this one
            $maxAttempts = 5;
            for ($i = 0; $i < $maxAttempts; $i++) {
                $this->setOrderInfo([]);
                if (!($this->order_info['payment_info']['paynet_query_running'] ?? false)) {
                    break;
                }
                sleep(1);
            }
            fn_update_order_payment_info($this->order_id, [
                'paynet_query_running' => 1
            ]);

            $this->changePaymentStatus();

            fn_update_order_payment_info($this->order_id, [
                'paynet_query_running' => 0
            ]);

            if ($this->mode === 'return') {
                $current_order_status = $this->getCurrentOrderStatus()?:$this->order_info['status'];

                // On an error or refund status
                // clear the cart and redirect to the home page
                switch ($current_order_status) {
                  case $this->processor_params['statuses']['failed']:
                  case 'E':
                      $this->unpleasantEnding($this->order_info['payment_info']['reason_text']);
                      break;
                  default:
                      fn_order_placement_routines('route', $this->order_id, false);
                      break;
                }
            }
            elseif ($this->mode === 'webhook') {
                die('ok');
            }

        } catch (\Exception | PaynetException $e) {
            fn_update_order_payment_info($this->order_id, [
                'paynet_query_running' => 0
            ]);
            $this->executeErrorScenario($e);
        }
    }


    /**
     * Gets the data for closing the payment
     *
     * @return void
     */
    private function changePaymentStatus(): void
    {
        // Get the current payment status
        $payment_status_data = $this->getPaymentStatusData($this->order_id);
        $payment_status = trim($payment_status_data['status'] ?? '');
        $payment_id = db_get_field("SELECT payment_id FROM ?:orders WHERE order_id = ?i", $this->order_id);
        if (empty($this->processor_params) && !empty($payment_id) && $payment_method_data = fn_get_payment_method_data($payment_id)) {
            $this->processor_params = $payment_method_data['processor_params'];
        }
        $paid_status = $this->processor_params['statuses']['paid'];
        $failed_status = $this->processor_params['statuses']['failed'];

        $available_statuses = [
            'processing' => [
                'order_status' => 'A',
                'reason_text' => __('payneteasy_payment_received_but_not_confirmed')
            ],
            'approved' => [
                'order_status' => $paid_status,
                'reason_text' => __('approved')
            ],
            'error' => [
                'order_status' => $failed_status,
                'reason_text' => trim($payment_status_data['error-message'])
            ],
            'declined' => [
                'order_status' => $failed_status,
                'reason_text' => trim($payment_status_data['error-message'])
            ],
            'refunded' => [
                'order_status' => 'E',
                'reason_text' => __('payneteasy_payment_refunded')
            ]
        ];

        $order_status_data = $available_statuses[$payment_status] ?? [];

        // An approved sale counts only for the amount and currency of this order.
        if ($payment_status === 'approved' && !$this->isPaidInFull($payment_status_data)) {
            $this->logger->error(
                __FUNCTION__ . ' > approved amount does not match the order, order not changed: ', [
                'order_id' => $this->order_id,
                'amount' => $payment_status_data['amount'] ?? '',
                'currency' => $payment_status_data['currency'] ?? ''
            ]);
            // Keep the order visible to the merchant (Open), but not paid.
            $current_order_status = $this->getCurrentOrderStatus()?:$this->order_info['status'];
            if ($current_order_status !== $paid_status) {
                $this->setOrUpdateOrderStatus([
                    'order_status' => 'O',
                    'reason_text' => 'Paid amount does not match the order'
                ]);
            }
            return;
        }

        // No final answer from the gateway (transport error, unknown order,
        // unknown status): this is not a decline, leave the order as it is.
        if (empty($order_status_data)) {
            $this->logger->error(
                __FUNCTION__ . ' > no usable payment status, order not changed: ', [
                'order_id' => $this->order_id,
                'status' => $payment_status
            ]);
            return;
        }

//        if (
//            $payment_status !== 'refunded' &&
//            isset($payment_status_data['refund_amount'])
//        ) {
//            // Set the refund status
//            $context['order_status'] = 'E';
//            $context['reason_text'] = __('payneteasy_payment_refunded');
//
//            throw new PaynetException($context['reason_text'], $context);
//        }

        $current_order_status = $this->getCurrentOrderStatus()?:$this->order_info['status'];

        // Never downgrade a paid order, except for a refund.
        if (
            $current_order_status === $paid_status &&
            $payment_status !== 'approved' &&
            $payment_status !== 'refunded'
        ) {
            return;
        }

        if (!empty($order_status_data)) {
            if ($current_order_status !== $order_status_data['order_status']) {
                $this->setOrUpdateOrderStatus([
                    'order_status' => $order_status_data['order_status'],
                    'reason_text' => $order_status_data['reason_text']
                ]);

//                if (isset($order_status_data['fnc'])) $order_status_data['fnc']();
            }
        } elseif ($current_order_status !== $failed_status) {
            $this->setOrUpdateOrderStatus([
                'order_status' => $failed_status,
                'reason_text' => __('payneteasy_payment_failed')
            ]);

            // Logging unsuccessful payment
            $this->logger->error(
                __FUNCTION__ . ' > getOrderInfo. Payment not paid: ', [
                'order_id' => $this->order_id
            ]);
        }
    }


    /**
     * Sends a refund request to PAYNET, returns the result and logs the request and response data.
     * If the request succeeds, the refund is also shown in the CMS.
     *
     * @return array
     */
    private function makeChargebackInPaynet(): array
    {
        // Logging input
        $this->logger->debug(
            __FUNCTION__ . ' > setRefunds - INPUT: ', [
            'arguments' => [
                'order_id' => $this->order_id
            ]
        ]);

        $paynet_order_id = db_get_field('SELECT paynet_order_id FROM ?:payneteasy_payments WHERE merchant_order_id = ?s', $this->order_id);

        $data = [
            'login' => $this->processor_params['login'],
            'client_orderid' => $this->order_id,
            'orderid' => $paynet_order_id,
            'comment' => 'Order cancel '
        ];

        $data['control'] = $this->signPaymentRequest($data, $this->endpoint_id, $this->control_key);

        $action_url = $this->processor_params['live_url'];
        if ($this->processor_params['sandbox'])
            $action_url = $this->processor_params['sandbox_url'];

        $response = $this->getPaynetApi()->return(
            $data,
            $this->processor_params['payment_method'],
            $this->processor_params['sandbox'],
            $action_url,
            $this->processor_params['endpoint_id']
        );

        // Logging output
        $this->logger->debug(
            __FUNCTION__ . ' > setRefunds - OUTPUT: ', [
            'response' => $response
        ]);

        return $response;
    }


    /**
     * Gets the order status from the PAYNET API.
     *
     * @return array
     */
    private function getPaymentStatusData($order_id): array
    {
        $order_id = $this->order_id ?? $order_id;

        // Logging input
        $this->logger->debug(
            __FUNCTION__ . ' > getOrderInfo - INPUT: ', [
            'arguments' => [
                'order_id' => $order_id,
            ]
        ]);

        $payment_id = db_get_field("SELECT payment_id FROM ?:orders WHERE order_id = ?i", $order_id);
        if (!empty($payment_id) && $payment_method_data = fn_get_payment_method_data($payment_id)) {
            $this->processor_params = $payment_method_data['processor_params'];
        }

        $action_url = $this->processor_params['live_url'];
        if ($this->processor_params['sandbox'])
            $action_url = $this->processor_params['sandbox_url'];

        // A customer may pay again after a declined attempt, or in another tab:
        // check all attempts of the order, newest first. An approved attempt wins,
        // then a processing one, otherwise the latest attempt.
        $this->ensurePaymentsTable();
        $attempts = db_get_fields(
            'SELECT paynet_order_id FROM ?:payneteasy_payments WHERE merchant_order_id = ?i ORDER BY id DESC LIMIT 20',
            $order_id
        );

        $latest = null;
        $processing = null;
        $response = [];

        foreach ($attempts ?: [''] as $paynet_order_id) {
            $data = [
                'login' => $this->processor_params['login'],
                'client_orderid' => (string)$order_id,
                'orderid' => (string)$paynet_order_id,
            ];
            $data['control'] = $this->signStatusRequest($data, $this->processor_params['login'], $this->processor_params['control_key']);

            $attempt = $this->getPaynetApi()->status(
                $data,
                $this->processor_params['payment_method'],
                $this->processor_params['sandbox'],
                $action_url,
                $this->processor_params['endpoint_id']
            );

            // Logging output
            $this->logger->debug(
                __FUNCTION__ . ' > getOrderInfo - OUTPUT: ', [
                'paynet_order_id' => $paynet_order_id,
                'response' => $attempt
            ]);

            // The gateway answers for any transaction id: use only answers for this order
            if (isset($attempt['merchant-order-id']) && trim((string)$attempt['merchant-order-id']) !== (string)$order_id) {
                continue;
            }

            $status = trim((string)($attempt['status'] ?? ''));
            if ($status === 'approved') {
                return $attempt;
            }
            if ($status === 'processing' && $processing === null) {
                $processing = $attempt;
            }
            if ($latest === null) {
                $latest = $attempt;
            }
        }

        return $processing ?? $latest ?? $response;
    }


    /**
     * Initiate a payment request.
     *
     * Initiate a payment request, including error handling.
     * This function prepares and sends a payment request based on the provided order details and processor parameters.
     * If an error occurs during payment initiation, an exception is thrown with an error message.
     *
     * @return void
     * @throws Exception If an error occurs during payment initiation, an exception is thrown with an error message.
     */
    public function sendForPayment(): void
    {
        try {
            $pay_url_response = $this->getPayUrl();
            $payment_status_data = $this->getPaymentStatusData($this->order_id);
            // Check if the payment link was successfully retrieved.
            if (empty($pay_url_response)) {
                throw new \Exception(__('payneteasy_failed_get_payment_url'));
            }

            $status = trim($payment_status_data['status'] ?? '');

            if ($status === 'processing') {
                if ($this->processor_params['payment_method'] == 'form') {
                    fn_create_payment_form($pay_url_response['redirect-url'], [], 'Payneteasy', true, 'GET');
                } elseif (!empty($payment_status_data['html'])) {
                    // 3-D Secure page from the gateway
                    echo $payment_status_data['html'];
                } else {
                    // Still in progress: the callback or the return sets the final status.
                    fn_order_placement_routines('route', $this->order_id, false);
                }
            } elseif (in_array($status, ['approved', 'declined', 'error'], true)) {
                // Same rules as for the callback: configured statuses, amount check.
                $this->changePaymentStatus();
                $current_order_status = $this->getCurrentOrderStatus() ?: $this->order_info['status'];
                if ($current_order_status === $this->processor_params['statuses']['failed']) {
                    $this->unpleasantEnding(trim($payment_status_data['error-message'] ?? ''));
                } else {
                    fn_order_placement_routines('route', $this->order_id, false);
                }
            }

        } catch (\Exception | PaynetException $e) {
            $this->executeErrorScenario($e);
        }
    }


    /**
     * Gets the payment URL for the redirect.
     *
     * @return string Payment URL.
     */
    private function getPayUrl()
    {
        $amount = $this->getAmount();
        $return_url = $this->getReturnUrl();

        // Remember what the customer is asked to pay, to check the approved sale later.
        fn_update_order_payment_info($this->order_id, [
            'paynet_amount' => $amount,
            'paynet_currency' => $this->getCurrency(),
            'paynet_base_total' => number_format((float) $this->order_info['total'], 2, '.', ''),
        ]);
        $this->order_info['payment_info']['paynet_amount'] = $amount;
        $this->order_info['payment_info']['paynet_currency'] = $this->getCurrency();
        $this->order_info['payment_info']['paynet_base_total'] = number_format((float) $this->order_info['total'], 2, '.', '');

        $payneteasy_card_number       = $this->order_info['payment_info']['card_number']??'';
        $payneteasy_card_expiry_month = $this->order_info['payment_info']['expiry_month']??'';
        $payneteasy_card_expiry_year  = $this->order_info['payment_info']['expiry_year']??'';
        $payneteasy_card_name         = $this->order_info['payment_info']['cardholder_name']??'';
        $payneteasy_card_cvv          = $this->order_info['payment_info']['cvv2']??'';

        $card_data = [
            'credit_card_number' => $payneteasy_card_number??'',
            'card_printed_name' => $payneteasy_card_name??'',
            'expire_month' => $payneteasy_card_expiry_month??'',
            'expire_year' => $payneteasy_card_expiry_year??'',
            'cvv2' => $payneteasy_card_cvv??'',
        ];

        // The card is used for this request only: before it is sent, the stored
        // copy keeps the last four digits and no CVV, whatever the result is.
        if ($payneteasy_card_number !== '' || $payneteasy_card_cvv !== '') {
            $digits = preg_replace('/\D/', '', (string) $payneteasy_card_number);
            fn_update_order_payment_info($this->order_id, [
                'card_number' => $digits === '' ? '' : str_repeat('X', max(strlen($digits) - 4, 0)) . substr($digits, -4),
                'cvv2' => $payneteasy_card_cvv === '' ? '' : 'XXX',
            ]);
        }


        $data = [
            'client_orderid' => (string)$this->order_id,
            'order_desc' => 'Order # ' . $this->order_id,
            'amount' => $amount,
            'currency' => $this->getCurrency(),
            'address1' => $this->order_info['b_address']?:$this->order_info['s_address'],
            'city' => $this->order_info['b_city']?:$this->order_info['s_city'],
            'zip_code' => $this->order_info['b_zipcode']?:$this->order_info['s_zipcode'],
            'country' => $this->order_info['b_country']?:$this->order_info['s_country'],
            'phone'      => $this->order_info['phone'],
            'email'      => $this->order_info['email'],
            'ipaddress' => $_SERVER['REMOTE_ADDR'],
            'cvv2' => $card_data['cvv2'],
            'credit_card_number' => $card_data['credit_card_number'],
            'card_printed_name' => $card_data['card_printed_name'],
            'expire_month' => $card_data['expire_month'],
            'expire_year' => $card_data['expire_year'],
            'first_name' => $this->order_info['firstname'],
            'last_name'  => $this->order_info['lastname'],
            // Return through the plugin, which asks the gateway for the status and
            // then opens the order page, so the order is paid even without a callback.
            'redirect_success_url' => $return_url,
            'redirect_fail_url' => $return_url,
            'redirect_url' => $return_url,
            'server_callback_url' => $return_url,
        ];
        $data['control'] = $this->signPaymentRequest($data, $this->processor_params['endpoint_id'], $this->processor_params['control_key']);

        // Logging input
        $this->logger->debug(
            __FUNCTION__ . ' > getOrderLink - INPUT: ', [
            'arguments' => [
                'order_id' => $this->order_id,
                'email' => $this->order_info['email'],
                'timestamp' => $this->order_info['timestamp'],
                'amount' => $amount,
                'return_url' => $return_url,
            ]
        ]);

        $action_url = $this->processor_params['live_url'];
        if ($this->processor_params['sandbox'])
            $action_url = $this->processor_params['sandbox_url'];


        if ($this->processor_params['payment_method'] == 'form') {
            $response = $this->getPaynetApi()->saleForm(
                $data,
                $this->processor_params['payment_method'],
                $this->processor_params['sandbox'],
                $action_url,
                $this->processor_params['endpoint_id']
            );
        } elseif ($this->processor_params['payment_method'] == 'direct') {
            $response = $this->getPaynetApi()->saleDirect(
                $data,
                $this->processor_params['payment_method'],
                $this->processor_params['sandbox'],
                $action_url,
                $this->processor_params['endpoint_id']
            );
        }

        // Logging output
        $this->logger->debug(
            __FUNCTION__ . ' > getOrderLink - OUTPUT: ', [
            'response' => $response
        ]);

        // A refused request has no gateway payment id: nothing to remember
        $paynet_order_id = trim((string)($response['paynet-order-id'] ?? ''));
        if (ctype_digit($paynet_order_id)) {
            $this->ensurePaymentsTable();
            db_query('INSERT INTO ?:payneteasy_payments ?e', [
                'paynet_order_id' => $paynet_order_id,
                'merchant_order_id' => (int)$this->order_id,
            ]);
        }

        return $response;
    }


    /**
     * Runs after an exception is caught
     *
     * @return void
     */
    private function executeErrorScenario($e): void
    {
        // Handle exception and log error
        $context = [];
        if (method_exists($e, 'getContext')) $context = $e->getContext();

        // Handle exception and log error
        $this->logger->error(sprintf(
            __FUNCTION__ . ' > Paynet exception : %s; Order id: %s;',
            $e->getMessage(),
            $this->order_id ?: ''
        ), $context);

        // Set payment response for failure.
        $pp_response['order_status'] = $context['order_status'] ?? OrderStatuses::FAILED;
        $pp_response['reason_text'] = $context['reason_text'] ?? $e->getMessage();

        // A failed notification (bad order id, foreign order, gateway or network
        // error) says nothing about the payment: only fail the order while
        // placing it, never from a notification request of any mode.
        if (!defined('PAYMENT_NOTIFICATION')) {
            $this->setOrUpdateOrderStatus($pp_response);
        }

        if ($this->mode === 'return') {
            $this->unpleasantEnding($pp_response['reason_text']);
        }
        elseif ($this->mode === 'webhook') {
            die('error');
        }
    }


    /**
     * Clears the cart, sets the error text and redirects to the home page
     *
     * @param string $reason_text Error text
     *
     * @return void
     */
    private function unpleasantEnding(string $reason_text): void
    {
        fn_clear_cart(Tygh::$app['session']['cart']);
        fn_set_notification('E', __('error'), $reason_text);
        fn_redirect(fn_url('', 'C', 'http'));
    }


    /**
     * Sets or updates the final order status
     *
     * @param array $pp_response Order data for the update
     *
     * @return void
     */
    private function setOrUpdateOrderStatus(array $pp_response): void
    {
        $current_order_status = $this->getCurrentOrderStatus()?:$this->order_info['status'];
        $this->logger->debug(
            __FUNCTION__ . ' - INPUT: ', [
            'order_status' => $current_order_status,
            'order_id' => $this->order_id,
            'pp_response' => $pp_response
        ]);
        if (empty($current_order_status)) {
            fn_finish_payment($this->order_id, $pp_response);
        } else {
            fn_change_order_status(
                $this->order_id,
                $pp_response['order_status'],
                $current_order_status
            );
            fn_update_order_payment_info($this->order_id, $pp_response);
        }
    }


    /**
     * Gets the current order status
     *
     * @return string
     */
    private function getCurrentOrderStatus(): string
    {
          $this->setOrderInfo([]);
          return $this->order_info['payment_info']['order_status'] ?? '';
    }


    /**
     * Create and return an instance of the PaynetApi class for Payneteasy integration.
     *
     * @return PaynetApi An instance of the PaynetApi class.
     */
    private function getPaynetApi(): PaynetApi
    {
        return new PaynetApi(
            $this->processor_params['login'],
            $this->processor_params['control_key'],
            $this->processor_params['endpoint_id'],
            $this->processor_params['payment_method'],
            (bool) $this->processor_params['sandbox']
        );
    }


    /**
     * Order total in the order currency (secondary_currency), the one sent to the gateway.
     *
     * CS-Cart keeps order totals in the primary currency.
     *
     * @return string The payment amount with two decimals.
     */
    private function getAmount(): string
    {
        $total = (float) $this->order_info['total'];
        $currency = $this->getCurrency();
        if ($currency !== CART_PRIMARY_CURRENCY) {
            $total = (float) fn_format_price_by_currency($total, CART_PRIMARY_CURRENCY, $currency);
        }
        return number_format($total, 2, '.', '');
    }


    /**
     * Gateway ids do not fit into int: widen the column of tables created by older versions.
     */
    private function ensurePaymentsTable(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $column = db_get_row("SHOW COLUMNS FROM ?:payneteasy_payments LIKE 'paynet_order_id'");
        if (!empty($column) && stripos($column['Type'], 'bigint') !== 0) {
            db_query('ALTER TABLE ?:payneteasy_payments MODIFY paynet_order_id bigint unsigned NOT NULL');
        }

        // Attempts are read newest first: give every row an id
        if (!db_get_row("SHOW COLUMNS FROM ?:payneteasy_payments LIKE 'id'")) {
            db_query('ALTER TABLE ?:payneteasy_payments ADD id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST, ADD INDEX merchant_order_id (merchant_order_id)');
        }
    }


    private function getCurrency(): string
    {
        return $this->order_info['secondary_currency'] ?? CART_PRIMARY_CURRENCY;
    }


    /**
     * Whether the gateway sale covers this order: same currency and amount.
     *
     * The amount sent to the gateway is used while the order total is unchanged,
     * so a later exchange rate change does not matter.
     */
    private function isPaidInFull(array $payment_status_data): bool
    {
        $payment_info = $this->order_info['payment_info'] ?? [];
        $total = number_format((float) $this->order_info['total'], 2, '.', '');

        if (
            isset($payment_info['paynet_amount'], $payment_info['paynet_currency'], $payment_info['paynet_base_total']) &&
            $payment_info['paynet_base_total'] === $total
        ) {
            $expected_amount = $payment_info['paynet_amount'];
            $expected_currency = $payment_info['paynet_currency'];
        } else {
            $expected_amount = $this->getAmount();
            $expected_currency = $this->getCurrency();
        }

        $paid_amount = trim((string) ($payment_status_data['amount'] ?? ''));
        $paid_currency = strtoupper(trim((string) ($payment_status_data['currency'] ?? '')));

        return is_numeric($paid_amount)
            && $paid_currency === strtoupper($expected_currency)
            && (int) round((float) $paid_amount * 100) === (int) round((float) $expected_amount * 100);
    }


    /**
     * Generate and return the return URL for the payment.
     *
     * @return string The return URL for payment notification.
     */
    private function getReturnUrl(): string
    {
        return fn_url('payment_notification.return?payment=payneteasy', 'C', 'current');
    }


    /**
     * Creates and configures the PaynetLogger object.
     *
     * This function sets up the logger the Paynet plugin writes its log with.
     *
     * @return void
     */
    private function setPaynetLogger(): void
    {
        $mode_key = $this->mode . '-' . rand(1111, 9999);
        $logger = PaynetLogger::getInstance();
        $this->logger = $logger
                        ->setOption('additionalCommonText', $mode_key)
                        ->setLogFilePath(
                            fn_get_files_dir_path() . 'Payneteasy-' . date('d-m-Y') . '.log'
                        )->setCustomRecording(function($message) use ($logger) {
                            if (
                                isset($this->processor_params['logging']) &&
                                $this->processor_params['logging']
                            ) $logger->writeToFile($message);
                        }, PaynetLogger::LOG_LEVEL_DEBUG);
    }


    private function signStatusRequest($requestFields, $login, $merchantControl)
    {
        $base = '';
        $base .= $login;
        $base .= $requestFields['client_orderid'];
        $base .= $requestFields['orderid'];

        return $this->signString($base, $merchantControl);
    }


    private function signPaymentRequest($data, $endpointId, $merchantControl)
    {
        $base = '';
        $base .= $endpointId;
        $base .= $data['client_orderid'];
        $base .= (int) round($data['amount'] * 100);
        $base .= $data['email'];

        return $this->signString($base, $merchantControl);
    }


    private function signString($s, $merchantControl)
    {
        return sha1($s . $merchantControl);
    }
}


try {
    $paynet = new Payneteasy(
        $order_id ?? '',
        $order_info ?? [],
        $processor_data ?? [],
        $mode ?? ''
    );
} catch (\Exception $e) {
    // Unknown order, an order of another payment method, missing settings:
    // the error is already logged. Do not answer a notification with a 500.
    if (!defined('PAYMENT_NOTIFICATION')) {
        throw $e;
    }
    if (($mode ?? '') === 'return') {
        fn_set_notification('E', __('error'), __('payneteasy_order_not_found'));
        fn_redirect(fn_url('', 'C', 'current'));
    }
    http_response_code(400);
    die('error');
}

if (defined('PAYMENT_NOTIFICATION')) {
    // Handle payment response based on the provided mode.
    $paynet->handleResponse();
}
else {
    // Initiate a payment request using the provided order ID, order information, and processor data.
    $paynet->sendForPayment();
}
