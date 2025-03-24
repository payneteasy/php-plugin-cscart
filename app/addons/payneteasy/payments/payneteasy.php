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
     * Инициализация свойств, обязательных для обработки платежа
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

            // Устанавливаем order_id
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
                __FUNCTION__ . ' > VtbPay exception : %s;',
                $e->getMessage()
            ));

            throw $e;

        }
    }


    /**
     * Получаем и устанавливаем свойство order_id.
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
            $this->order_id = $_REQUEST['merchant_order'];
            if (!empty($order_info)) {
                $this->order_id = $order_info['order_id'] ?? '';
            }
        }

        if (empty($this->order_id)) {
            throw new \Exception(__('payneteasy_order_id_not_found'));
        }
    }


    /**
     * Получаем и устанавливаем свойство order_info.
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
     * Получаем и устанавливаем свойство processor_params.
     *
     * @param array $processor_data  Processor data, including payment parameters.
     *
     * @return void
     * @throws Exception
     */
    private function setProcessorParams(array $processor_data): void
    {
        $payment_id = db_get_field("SELECT payment_id FROM ?:orders WHERE order_id = ?i", $_REQUEST['merchant_order']??$this->order_id);
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
        try {
            // Если над заказом уже работает запрос, то останавливанм текущий
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

                // Если статус ошибки или возврата,
                // то очищаем корзину и редиректим на главную
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
     * Получение данные для закрытия платежа
     *
     * @return void
     */
    private function changePaymentStatus(): void
    {
        // Получаем актуальный статус платежа
        $payment_status_data = $this->getPaymentStatusData($this->order_id);
        $payment_status = trim($payment_status_data['status']);
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

//        if (
//            $payment_status !== 'refunded' &&
//            isset($payment_status_data['refund_amount'])
//        ) {
//            // Указываем статус возврата
//            $context['order_status'] = 'E';
//            $context['reason_text'] = __('payneteasy_payment_refunded');
//
//            throw new PaynetException($context['reason_text'], $context);
//        }

        $current_order_status = $this->getCurrentOrderStatus()?:$this->order_info['status'];

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
     * Отправляем запрос на возврат в PAYNET, возвращвем результат запроса и логируем входящие и выходящие данные.
     * Если запрос прошёл успешно, то и в CMS отображаем информацию о возврате.
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
     * Получение статуса заказа из PAYNET API.
     *
     * @return array
     */
    private function getPaymentStatusData($order_id): array
    {
        // Logging input
        $this->logger->debug(
            __FUNCTION__ . ' > getOrderInfo - INPUT: ', [
            'arguments' => [
                'order_id' => $this->order_id??$order_id,
            ]
        ]);

        $paynet_order_id = db_get_field('SELECT paynet_order_id FROM ?:payneteasy_payments WHERE merchant_order_id = ?s', $this->order_id??$order_id);

        $payment_id = db_get_field("SELECT payment_id FROM ?:orders WHERE order_id = ?i", $this->order_id??$order_id);
        if (!empty($payment_id) && $payment_method_data = fn_get_payment_method_data($payment_id)) {
            $this->processor_params = $payment_method_data['processor_params'];
        }

        $data = [
            'login' => $this->processor_params['login'],
            'client_orderid' => (string)$this->order_id?:$order_id,
            'orderid' => $paynet_order_id,
        ];
        $data['control'] = $this->signStatusRequest($data, $this->processor_params['login'], $this->processor_params['control_key']);

        $action_url = $this->processor_params['live_url'];
        if ($this->processor_params['sandbox'])
            $action_url = $this->processor_params['sandbox_url'];

        $response = $this->getPaynetApi()->status(
            $data,
            $this->processor_params['payment_method'],
            $this->processor_params['sandbox'],
            $action_url,
            $this->processor_params['endpoint_id']
        );

        // Logging output
        $this->logger->debug(
            __FUNCTION__ . ' > getOrderInfo - OUTPUT: ', [
            'response' => $response
        ]);

        return $response;
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
            $protocol = empty($_SERVER['HTTPS']) ? 'http' : 'https';
            $host = $_SERVER['HTTP_HOST'];
            $success_url = "{$protocol}://{$host}/index.php?dispatch=checkout.complete&order_id=".$this->order_id;
            $return_url = $this->getReturnUrl();
            // Check if the payment link was successfully retrieved.
            if (empty($pay_url_response)) {
                throw new \Exception(__('payneteasy_failed_get_payment_url'));
            }

            if (trim($payment_status_data['status']) == 'processing') {
                if ($this->processor_params['payment_method'] == 'form') {
                    fn_create_payment_form($pay_url_response['redirect-url'], [], 'Payneteasy', true, 'GET');
                } elseif ($this->processor_params['payment_method'] == 'direct') {
                    if ($this->processor_params['three_d_secure_payment']) {
                        echo $payment_status_data['html'];
                    }
                }
            } elseif (trim($payment_status_data['status']) == 'approved') {
                $pp_response = array(
                    'order_status' => 'C',
                    'reason_text' => trim($payment_status_data['status']),
                    'ip_address' => '',
                );
                fn_finish_payment($this->order_id, $pp_response);
                fn_order_placement_routines('route', $this->order_id, false);
            } elseif (trim($payment_status_data['status']) == 'error') {
                $pp_response = array(
                    'order_status' => 'F',
                    'reason_text' => trim($payment_status_data['status']),
                    'ip_address' => '',
                );
                fn_finish_payment($this->order_id, $pp_response);
                fn_order_placement_routines('route', $this->order_id, false);
                $this->unpleasantEnding(trim($payment_status_data['error-message']));
            } elseif (trim($payment_status_data['status']) == 'declined') {
                $pp_response = array(
                    'order_status' => 'F',
                    'reason_text' => trim($payment_status_data['status']),
                    'ip_address' => '',
                );
                fn_finish_payment($this->order_id, $pp_response);
                fn_order_placement_routines('route', $this->order_id, false);
                $this->unpleasantEnding(trim($payment_status_data['error-message']));
            }

        } catch (\Exception | PaynetException $e) {
            $this->executeErrorScenario($e);
        }
    }


    /**
     * Получает URL-адрес платежа для перенаправления.
     *
     * @return string URL-адрес платежа.
     */
    private function getPayUrl()
    {
        $amount = $this->getAmount();
        $return_url = $this->getReturnUrl();

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

        $protocol = empty($_SERVER['HTTPS']) ? 'http' : 'https';
        $host = $_SERVER['HTTP_HOST'];

        $success_url = "{$protocol}://{$host}/index.php?dispatch=checkout.complete&order_id=".$this->order_id;

        $data = [
            'client_orderid' => (string)$this->order_id,
            'order_desc' => 'Order # ' . $this->order_id,
            'amount' => $amount,
            'currency' => $this->order_info['secondary_currency'],
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
//            'redirect_success_url' => $return_url,
            'redirect_success_url' => $success_url,
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

        $order_data =
            array(
                'paynet_order_id' => $response['paynet-order-id'],
                'merchant_order_id' => $response['merchant-order-id'],
            );

        db_query('INSERT INTO ?:payneteasy_payments ?e', $order_data);

        return $response;
    }


    /**
     * Сценарий, выполняемый после отлова исключения
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

        // Finish payment and perform order placement routines.
        $this->setOrUpdateOrderStatus($pp_response);

        if ($this->mode === 'return') {
            $this->unpleasantEnding($pp_response['reason_text']);
        }
        elseif ($this->mode === 'webhook') {
            die('error');
        }
    }


    /**
     * Очищаем корзину, устанавливаем текст ошибки и редиректим на главную
     *
     * @param string $reason_text Текст ошибки
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
     * Устанавливаем или обновляем итоговый статус заказа
     *
     * @param array $pp_response Данный заказ для обновления
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
     * Получает текущий статус заказа
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
     * Calculate the payment amount based on order information and processor parameters.
     *
     * @return float The calculated payment amount.
     */
    private function getAmount(): float
    {
        $discount = $this->order_info['total'] - $this->order_info['subtotal'];
        if ($discount === $this->order_info['subtotal_discount']) {
            return $this->order_info['subtotal'];
        }
        return $this->order_info['total'];
    }


    /**
     * Generate and return the return URL for the payment.
     *
     * @return string The return URL for payment notification.
     */
    private function getReturnUrl(): string
    {
        $protocol = empty($_SERVER['HTTPS']) ? 'http' : 'https';
        $host = $_SERVER['HTTP_HOST'];

        return "{$protocol}://{$host}/index.php?dispatch=payment_notification.return&payment=payneteasy";
    }


    /**
     * Инициализация и настройка объекта класса PaynetLogger.
     *
     * Эта функция инициализирует и настраивает логгер, используемый плагином Paynet для ведения журнала.
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
        $base .= $data['amount'] * 100;
        $base .= $data['email'];

        return $this->signString($base, $merchantControl);
    }


    private function signString($s, $merchantControl)
    {
        return sha1($s . $merchantControl);
    }
}


$paynet = new Payneteasy(
    $order_id ?? '',
    $order_info ?? [],
    $processor_data ?? [],
    $mode ?? ''
);

if (defined('PAYMENT_NOTIFICATION')) {
    // Handle payment response based on the provided mode.
    $paynet->handleResponse();
}
else {
    // Initiate a payment request using the provided order ID, order information, and processor data.
    $paynet->sendForPayment();
}
