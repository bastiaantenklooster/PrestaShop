<?php
/**
 * Copyright (c) 2012-2018, Mollie B.V.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * - Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 * - Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR AND CONTRIBUTORS ``AS IS'' AND ANY
 * EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE AUTHOR OR CONTRIBUTORS BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY
 * OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH
 * DAMAGE.
 *
 * @author     Mollie B.V. <info@mollie.nl>
 * @copyright  Mollie B.V.
 * @license    Berkeley Software Distribution License (BSD-License 2) http://www.opensource.org/licenses/bsd-license.php
 * @category   Mollie
 * @package    Mollie
 * @link       https://www.mollie.nl
 */

if (!defined('_PS_VERSION_')) {
    die('No direct script access');
}

/**
 * Class MollieReturnModuleFrontController
 * @method setTemplate
 *
 * @property mixed  context
 * @property Mollie module
 */
class MollieWebhookModuleFrontController extends ModuleFrontController
{
    // @codingStandardsIgnoreStart
    /** @var bool $ssl */
    public $ssl = true;
    /** @var bool $display_column_left */
    public $display_column_left = false;
    /** @var bool $display_column_right */
    public $display_column_right = false;
    // @codingStandardsIgnoreEnd

    /**
     * Prevent displaying the maintenance page
     *
     * @return void
     */
    protected function displayMaintenancePage()
    {
    }

    /**
     * @throws Adapter_Exception
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function initContent()
    {
        die($this->executeWebhook());
    }

    /**
     * @return string
     * @throws Adapter_Exception
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function executeWebhook()
    {
        if (Tools::getValue('testByMollie')) {
            if (Configuration::get(Mollie::MOLLIE_DEBUG_LOG) == Mollie::DEBUG_LOG_ERRORS) {
                Logger::addLog(__METHOD__.' said: Mollie webhook tester successfully communicated with the shop.', Mollie::NOTICE);
            }

            return 'OK';
        }

        $transactionId = Tools::getValue('id');

        if (empty($transactionId)) {
            if (Configuration::get(Mollie::MOLLIE_DEBUG_LOG) == Mollie::DEBUG_LOG_ERRORS) {
                Logger::addLog(__METHOD__.' said: Received webhook request without proper transaction ID.', Mollie::WARNING);
            }

            return 'NO ID';
        }

        try {
            /** @var Mollie_API_Object_Payment $apiPayment */
            $apiPayment = $this->module->api->payments->get($transactionId);
            $transactionId = $apiPayment->id;
        } catch (Exception $e) {
            if (Configuration::get(Mollie::MOLLIE_DEBUG_LOG) == Mollie::DEBUG_LOG_ERRORS) {
                Logger::addLog(__METHOD__.' said: Could not retrieve payment details for transaction_id "'.$transactionId.'". Reason: '.$e->getMessage(), Mollie::WARNING);
            }

            return 'NOT OK';
        }

        $psPayment = $this->module->getPaymentBy('transaction_id', $transactionId);

        $this->setCountryContextIfNotSet($apiPayment);

        $orderId = (int) Order::getOrderByCartId($apiPayment->metadata->cart_id);
        if ($apiPayment->metadata->cart_id) {
            if (in_array($apiPayment->status, array(Mollie_API_Object_Payment::STATUS_CHARGED_BACK, Mollie_API_Object_Payment::STATUS_REFUNDED))) {
                $this->module->setOrderStatus($orderId, Mollie_API_Object_Payment::STATUS_REFUNDED);
            } elseif ($psPayment['method'] == 'banktransfer' &&
                $psPayment['bank_status'] === Mollie_API_Object_Payment::STATUS_OPEN &&
                $apiPayment->status === Mollie_API_Object_Payment::STATUS_PAID
            ) {
                $this->module->setOrderStatus($orderId, $apiPayment->status);
            } elseif ($psPayment['method'] != 'banktransfer' &&
                $psPayment['bank_status'] === Mollie_API_Object_Payment::STATUS_OPEN &&
                $apiPayment->status === Mollie_API_Object_Payment::STATUS_PAID
            ) {
                $this->module->validateOrder((int) $apiPayment->metadata->cart_id,
                    $this->module->statuses[$apiPayment->status],
                    $this->convertEuroToCartCurrency($apiPayment->amount, (int) $apiPayment->metadata->cart_id),
                    isset(Mollie::$methods[$apiPayment->method]) ? Mollie::$methods[$apiPayment->method] : 'Mollie',
                    null,
                    array(),
                    null,
                    false,
                    $apiPayment->metadata->secure_key
                );

                $orderId = Order::getOrderByCartId($apiPayment->metadata->cart_id);
            }
        }

        // Store status in database

        $this->saveOrderTransactionData($apiPayment->id, $apiPayment->method, $orderId);

        if (!$this->savePaymentStatus($transactionId, $apiPayment->status, $orderId)) {
            if (Configuration::get(Mollie::MOLLIE_DEBUG_LOG) == Mollie::DEBUG_LOG_ERRORS) {
                Logger::addLog(__METHOD__.' said: Could not save Mollie payment status for transaction "'.$transactionId.'". Reason: '.Db::getInstance()->getMsgError(), Mollie::WARNING);
            }
        }

        // Log successful webhook requests in extended log mode only
        if (Configuration::get(Mollie::MOLLIE_DEBUG_LOG) == Mollie::DEBUG_LOG_ALL) {
            Logger::addLog(__METHOD__.' said: Received webhook request for order '.(int) $orderId.' / transaction '.$transactionId, Mollie::NOTICE);
        }

        return 'OK';
    }

    /**
     * Retrieves the OrderPayment object, created at validateOrder. And add transaction data.
     *
     * @param string $molliePaymentId
     * @param string $molliePaymentMethod
     * @param int    $orderId
     *
     * @return void
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function saveOrderTransactionData($molliePaymentId, $molliePaymentMethod, $orderId)
    {
        // retrieve ALL payments of order.
        // in the case of a cancel or expired on banktransfer, this will fire too.
        // if no OrderPayment objects is retrieved in the collection, do nothing.
        $order = new Order((int) $orderId);
        $collection = OrderPayment::getByOrderReference($order->reference);
        if (count($collection) > 0) {
            $orderPayment = $collection[0];

            // for older versions (1.5) , we check if it hasn't been filled yet.
            if (!$orderPayment->transaction_id) {
                $orderPayment->transaction_id = $molliePaymentId;
                $orderPayment->payment_method = $molliePaymentMethod;
                $orderPayment->update();
            }
        }
    }


    /**
     * @param string $transactionId
     * @param int    $status
     * @param int    $orderId
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function savePaymentStatus($transactionId, $status, $orderId)
    {
        $data = array(
            'updated_at'  => date("Y-m-d H:i:s"),
            'bank_status' => $status,
            'order_id'    => (int) $orderId,
        );

        return Db::getInstance()->update('mollie_payments', $data,
            '`transaction_id` = \''.pSQL($transactionId).'\'');
    }

    /**
     * Transforms euro prices from mollie back to the currency of the Cart (order)
     *
     * @param float $amount in euros
     * @param int   $cartId
     *
     * @return float in the currency of the cart
     * @throws PrestaShopException
     */
    private function convertEuroToCartCurrency($amount, $cartId)
    {
        $cart = new Cart($cartId);
        $currencyEuro = Currency::getIdByIsoCode('EUR');

        if (!$currencyEuro) {
            // No Euro currency available!
            if (Configuration::get(Mollie::MOLLIE_DEBUG_LOG) == Mollie::DEBUG_LOG_ERRORS) {
                Logger::addLog(__METHOD__.' said: In order to use this module, you need to enable Euros as currency. Cart ID: '.$cartId, Mollie::CRASH);
            }
            die($this->module->lang['This payment method is only available for Euros.']);
        }

        if ($cart->id_currency !== $currencyEuro) {
            // Convert euro currency to cart currency
            $amount = Tools::convertPriceFull($amount, Currency::getCurrencyInstance($currencyEuro),
                Currency::getCurrencyInstance($cart->id_currency));
        }

        return round($amount, 2);
    }

    /**
     * (Re)sets the controller country context.
     * When Prestashop receives a call from Mollie (without context)
     * Prestashop allways has default context to fall back on, so context->country
     * is allways Set before executing any controller methods
     *
     * @param Mollie_API_Object_Payment $payment
     */
    private function setCountryContextIfNotSet(Mollie_API_Object_Payment $payment)
    {
        if (empty($this->context->country) || !$this->context->country->active) {
            if ($payment->metadata->cart_id) {
                $cart = new Cart((int) $payment->metadata->cart_id);
                if (!empty($cart)) {
                    $address = new Address($cart->id_address_delivery);
                    if (!empty($address)) {
                        $country = new Country($address->id_country);
                        if (!empty($country)) {
                            $this->context->country = $country;
                        }
                    }
                }
            }
        }
    }
}
