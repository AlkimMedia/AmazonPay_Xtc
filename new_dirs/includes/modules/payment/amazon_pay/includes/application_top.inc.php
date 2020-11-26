<?php

include __DIR__ . '/../amazon_pay.php';
$configHelper                        = new \AlkimAmazonPay\ConfigHelper();
if (strpos($PHP_SELF, 'shopping_cart.php') !== false && !empty($_SESSION['payment']) && $_SESSION['payment'] === 'amazon_pay') {
    unset($_SESSION['payment']);
}

if (strpos($PHP_SELF, 'checkout_shipping.php') !== false) {
    $accountHelper = new \AlkimAmazonPay\AccountHelper();
    if (!empty($_GET['amazonCheckoutSessionId'])) {
        $checkoutHelper                      = new \AlkimAmazonPay\CheckoutHelper();
        $checkoutSessionId                   = $_GET['amazonCheckoutSessionId'];
        $_SESSION['amazon_checkout_session'] = $checkoutSessionId;
        $checkoutSession                     = $checkoutHelper->getCheckoutSession($checkoutSessionId);
        $needsMainAddress                    = false;
        if (!$accountHelper->isLoggedIn()) {
            $name        = $checkoutSession->getBuyer()->getName();
            $t           = explode(' ', $name);
            $lastNameKey = max(array_keys($t));
            $lastName    = $t[$lastNameKey];
            unset($t[$lastNameKey]);
            $firstName = implode(' ', $t);
            require_once DIR_FS_INC . 'xtc_create_password.inc.php';
            $password       = xtc_create_password(32);
            $sql_data_array = [
                'customers_status'             => DEFAULT_CUSTOMERS_STATUS_ID_GUEST,
                'customers_gender'             => '',
                'customers_firstname'          => $firstName,
                'customers_lastname'           => $lastName,
                'customers_dob'                => '0000-00-00 00:00:00',
                'customers_email_address'      => $checkoutSession->getBuyer()->getEmail(),
                'customers_default_address_id' => '0',
                'customers_telephone'          => '',
                'customers_password'           => $password,
                'customers_newsletter'         => 0,
                'customers_newsletter_mode'    => 0,
                'member_flag'                  => 0,
                'delete_user'                  => 1,
                'account_type'                 => 1
            ];

            xtc_db_perform(TABLE_CUSTOMERS, $sql_data_array);
            $_SESSION['customer_id'] = xtc_db_insert_id();
            $needsMainAddress        = true;
        }
        if ($shippingAddressId = $accountHelper->getAddressId($checkoutSession->getShippingAddress())) {
            $_SESSION["sendto"] = $shippingAddressId;
        } else {
            $_SESSION["sendto"] = $accountHelper->createAddress($checkoutSession->getShippingAddress());
        }

        if ($billingAddressId = $accountHelper->getAddressId($checkoutSession->getBillingAddress())) {
            $_SESSION["billto"] = $billingAddressId;
        } else {
            $_SESSION["billto"] = $accountHelper->createAddress($checkoutSession->getBillingAddress());
        }

        if ($needsMainAddress) {
            xtc_db_perform(TABLE_CUSTOMERS, ['customers_default_address_id' => $_SESSION['billto']], 'update', 'customers_id = ' . (int)$_SESSION['customer_id']);
        }

        $_SESSION['payment'] = $configHelper->getPaymentMethodName();
    } else {
        if ($accountHelper->isLoggedIn() && $accountHelper->getStatusId() !== (int)DEFAULT_CUSTOMERS_STATUS_ID_GUEST) {
            if ($accountHelper->isAccountComplete($_SESSION['customer_id']) === false) {
                xtc_redirect(xtc_href_link(FILENAME_ACCOUNT_EDIT));
            }
            if ($accountHelper->hasAddress($_SESSION['customer_id']) === false) {
                xtc_redirect(xtc_href_link(FILENAME_ADDRESS_BOOK_PROCESS));
            }
        }
    }
}

if (strpos($PHP_SELF, 'checkout_payment.php') !== false) {
    if (isset($_GET['_action']) && $_GET['_action'] === 'reset_payment') {
        unset($_SESSION['payment']);
    } elseif (!empty($_SESSION['payment']) && $_SESSION['payment'] === $configHelper->getPaymentMethodName()) {
        xtc_redirect(xtc_href_link('checkout_confirmation.php', '', 'SSL'));
    }
}

if (strpos($PHP_SELF, 'checkout_confirmation.php') !== false && !empty($_SESSION['payment']) && $_SESSION['payment'] === $configHelper->getPaymentMethodName()) {
    $_POST['conditions'] = 1;
    //$amazonPayHelper = new \AlkimAmazonPay\AmazonPayHelper();
    //var_dump($amazonPayHelper->getClient()->getCheckoutSession($_SESSION['amazon_checkout_session']));
}

if (strpos($PHP_SELF, 'checkout_process.php') !== false && !empty($_SESSION['payment']) && $_SESSION['payment'] === $configHelper->getPaymentMethodName()) {
    if (isset($_POST['checkout_confirmation_comments'])) {
        $_SESSION['comments'] = $_POST['checkout_confirmation_comments'];
    }
    $checkoutHelper = new \AlkimAmazonPay\CheckoutHelper();
    $configHelper   = new \AlkimAmazonPay\ConfigHelper();

    if (empty($_SESSION['amazon_checkout_session'])) {
        //TODO log
        xtc_redirect(xtc_href_link(FILENAME_SHOPPING_CART));
    }

    $checkoutSession = $checkoutHelper->getCheckoutSession($_SESSION['amazon_checkout_session']);

    if (!$checkoutSession->getCheckoutSessionId()) {
        //TODO log
        xtc_redirect(xtc_href_link(FILENAME_SHOPPING_CART));
    }

    if ($checkoutSession->getStatusDetails()->getState() === \AmazonPayExtendedSdk\Struct\StatusDetails::OPEN) {
        if ($checkoutSession->getWebCheckoutDetails()->getAmazonPayRedirectUrl() && !$checkoutSession->getConstraints() && !$checkoutSession->getBuyer()) {
            //do checkout
        } else {

            require_once DIR_WS_CLASSES . 'payment.php';
            require_once DIR_WS_CLASSES . 'shipping.php';
            $shipping_modules = new shipping($_SESSION['shipping']);
            require_once DIR_WS_CLASSES . 'order.php';
            $order = new order();
            require_once DIR_WS_CLASSES . 'order_total.php';
            $order_total_modules = new order_total();
            $order_totals        = $order_total_modules->process();

            $checkoutSessionUpdate = new \AmazonPayExtendedSdk\Struct\CheckoutSession();

            $webCheckoutDetails = new \AmazonPayExtendedSdk\Struct\WebCheckoutDetails();
            $webCheckoutDetails->setCheckoutResultReturnUrl($configHelper->getCheckoutResultReturnUrl());

            $paymentDetails = new \AmazonPayExtendedSdk\Struct\PaymentDetails();
            $paymentDetails
                ->setPaymentIntent('Authorize')
                ->setCanHandlePendingAuthorization(true)
                ->setChargeAmount(new \AmazonPayExtendedSdk\Struct\Price(['amount' => $order->info['total'], 'currencyCode' => $order->info['currency']]));

            $checkoutSessionUpdate
                ->setWebCheckoutDetails($webCheckoutDetails)
                ->setPaymentDetails($paymentDetails);
            //TODO platform & merchantmeta

            $updatedCheckoutSession = $checkoutHelper->updateCheckoutSession($checkoutSession->getCheckoutSessionId(), $checkoutSessionUpdate);
            if ($redirectUrl = $updatedCheckoutSession->getWebCheckoutDetails()->getAmazonPayRedirectUrl()) {
                xtc_redirect($redirectUrl);
            } else {
                //TODO log / show error info
                xtc_redirect(xtc_href_link(FILENAME_CHECKOUT_CONFIRMATION, 'amazon_pay_error', 'SSL'));
            }
        }
    } else {
        if ($checkoutSession->getStatusDetails()->getState() === \AmazonPayExtendedSdk\Struct\StatusDetails::CANCELED) {
            unset($_SESSION['payment']);
            xtc_redirect(xtc_href_link(FILENAME_CHECKOUT_PAYMENT, 'payment_error='.$configHelper->getPaymentMethodName()));
        } else {
            //TODO handle
            xtc_redirect(xtc_href_link(FILENAME_SHOPPING_CART, ''));
        }
    }
}

if (strpos($PHP_SELF, 'checkout_success.php') !== false) {

    $orders_query = xtc_db_query("SELECT o.payment_class, a.status
                                        FROM 
                                            " . TABLE_ORDERS . " o
                                            LEFT JOIN amazon_pay_transactions AS a ON (a.order_id = o.orders_id AND a.type = 'Charge')
                                        WHERE 
                                            customers_id = " . (int)$_SESSION['customer_id'] . "                                             
                                        ORDER BY
                                            orders_id DESC 
                                        LIMIT 1");

    if ($order = xtc_db_fetch_array($orders_query)) {
        if ($order["payment_class"] === $configHelper->getPaymentMethodName()
                &&
            $order['status'] === \AmazonPayExtendedSdk\Struct\StatusDetails::AUTHORIZATION_INITIATED
        ) {
            define('AMAZON_PAY_CHECKOUT_SUCCESS_INFORMATION', TEXT_AMAZON_PAY_PENDING);
        }
    }
    if(!defined('AMAZON_PAY_CHECKOUT_SUCCESS_INFORMATION')){
        define('AMAZON_PAY_CHECKOUT_SUCCESS_INFORMATION', '');
    }
}