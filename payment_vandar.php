<?php
/**
 * Vandar payment plugin
 *
 * @publisher     Vandar
 * @package       J2Store
 * @subpackage    payment
 * @copyright (C) 2020 Vandar
 * @license       http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * https://vandar.io
 */
defined( '_JEXEC' ) or die( 'Restricted access' );

require_once( JPATH_ADMINISTRATOR . '/components/com_j2store/library/plugins/payment.php' );

class plgJ2StorePayment_vandar extends J2StorePaymentPlugin {
    /**
     * @var $_element  string  Should always correspond with the plugin's filename,
     *                         forcing it to be unique
     */
    var $_element    = 'payment_vandar';
    
    function __construct( & $subject, $config ) {
        parent::__construct( $subject, $config );
        $this->loadLanguage( 'com_j2store', JPATH_ADMINISTRATOR );
        $this->loadLanguage('plg_system_payment_vandar', JPATH_ADMINISTRATOR);
    }

    function onJ2StoreCalculateFees( $order ) {
        $payment_method = $order->get_payment_method();

        if ( $payment_method == $this->_element )
        {
            $total             = $order->order_subtotal + $order->order_shipping + $order->order_shipping_tax;
            $surcharge         = 0;
            $surcharge_percent = $this->params->get( 'surcharge_percent', 0 );
            $surcharge_fixed   = $this->params->get( 'surcharge_fixed', 0 );
            if ( ( float ) $surcharge_percent > 0 || ( float ) $surcharge_fixed > 0 )
            {
                // percentage
                if ( ( float ) $surcharge_percent > 0 )
                {
                    $surcharge += ( $total * ( float ) $surcharge_percent ) / 100;
                }

                if ( ( float ) $surcharge_fixed > 0 )
                {
                    $surcharge += ( float ) $surcharge_fixed;
                }

                $name         = $this->params->get( 'surcharge_name', JText::_( 'J2STORE_CART_SURCHARGE' ) );
                $tax_class_id = $this->params->get( 'surcharge_tax_class_id', '' );
                $taxable      = FALSE;
                if ( $tax_class_id && $tax_class_id > 0 )
                {
                    $taxable = TRUE;
                }
                if ( $surcharge > 0 )
                {
                    $order->add_fee( $name, round( $surcharge, 2 ), $taxable, $tax_class_id );
                }
            }
        }
    }
    
    /**
     * Prepares variables and
     * Renders the form for collecting payment info
     *
     * @return unknown_type
     */
    function _renderForm( $data )
    {
        $user = JFactory::getUser();
        $vars = new JObject();
        $vars->vandar = $this->translate( 'NAME' );
        $html = $this->_getLayout('form', $vars);
        return $html;
    }
    
    /**
     * Processes the payment form
     * and returns HTML to be displayed to the user
     * generally with a success/failed message
     *
     * @param $data     array       form post data
     * @return string   HTML to display
     */
    function _prePayment( $data ) {
        $app                       = JFactory::getApplication();
        $vars                      = new JObject();
        $vars->order_id            = $data['order_id'];
        $vars->orderpayment_id     = $data['orderpayment_id'];
        $vars->orderpayment_type   = $this->_element;
        $vars->button_text         = $this->params->get( 'button_text', 'J2STORE_PLACE_ORDER' );
        $vars->display_name        = $this->translate( 'OPTION_NAME' );
        $vars->api_key             = $this->params->get( 'api_key', '' );
        $vars->currency            = $this->params->get( 'currency', '' );
        $vars->orderpayment_amount = $vars->currency == 'rial' ? $data['orderpayment_amount'] : ($data['orderpayment_amount'] * 10);
    
        // Customer information
        $orderinfo = F0FTable::getInstance( 'Orderinfo', 'J2StoreTable' )->getClone();
        $orderinfo->load( [ 'order_id' => $data['order_id'] ] );

        $phone       = $orderinfo->billing_phone_2;
        if ( empty( $phone ) )
        {
            $phone = !empty( $orderinfo->billing_phone_1 ) ? $orderinfo->billing_phone_1 : '';
        }

        // Load order
        F0FTable::addIncludePath( JPATH_ADMINISTRATOR . '/components/com_j2store/tables' );
        $orderpayment = F0FTable::getInstance( 'Order', 'J2StoreTable' )->getClone();
        $orderpayment->load( $vars->orderpayment_id );

        if ( empty($vars->api_key) )
        {
            $msg         = $this->translate('ERROR_CONFIG');
            $vars->error = $msg;
            $orderpayment->add_history( $msg );
            $orderpayment->store();

            return $this->_getLayout( 'prepayment', $vars );
        }
        else
        {
            $api_key  = $vars->api_key;
            $amount   = round( $vars->orderpayment_amount, 0 );
            $desc     = $this->translate('PARAMS_DESC') . $vars->order_id;
            $callback = JRoute::_( JURI::root() . "index.php?option=com_j2store&view=checkout" ) . '&orderpayment_type=' . $vars->orderpayment_type . '&task=confirmPayment&factorNumber='. $vars->orderpayment_id;

            if ( empty( $amount ) )
            {
                $msg         = $this->translate('ERROR_PRICE');
                $vars->error = $msg;
                $orderpayment->add_history( $msg );
                $orderpayment->store();

                return $this->_getLayout( 'prepayment', $vars );
            }
    
            $data = [
                'api_key'		=> $api_key,
                'amount'		=> $amount,
                'callback_url'	=> $callback,
                'mobile_number'	=> $phone,
                'factorNumber'	=> $vars->orderpayment_id,
                'description'	=> $desc,
            ];
    
            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_URL, 'https://ipg.vandar.io/api/v3/send' );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
            curl_setopt( $ch, CURLOPT_POST,TRUE);
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
            $result      = curl_exec( $ch );
            $result      = json_decode( $result );
            $http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            curl_close( $ch );
    
            if ( $http_status != 200 || empty( $result ) || empty( $result->token ) || $result->status != 1 )
            {
                $msg = $this->translate('ERROR_PRICE');
                foreach ($result->errors as $err){
                    $msg .= $err . '<br>';
                }
                $vars->error = $msg;
                $orderpayment->add_history( $msg );
                $orderpayment->store();

                return $this->_getLayout( 'prepayment', $vars );
            }

            // Save transaction id
            $orderpayment->transaction_id = $result->token;
            $orderpayment->store();

            $vars->vandar = 'https://ipg.vandar.io/v3/' . $result->token;
            return $this->_getLayout( 'prepayment', $vars );
        }
    }

    function _postPayment( $data ) {
        $app      = JFactory::getApplication();
        $vars     = new JObject();
        $jinput   = $app->input;
        $status   = empty( $jinput->get->get( 'payment_status' ) ) ? NULL : $jinput->get->get( 'payment_status' );
        $token    = empty( $jinput->get->get( 'token' ) ) ? NULL : $jinput->get->get( 'token' );
        $order_id = empty( $jinput->get->get( 'factorNumber' ) ) ? NULL : $jinput->get->get( 'factorNumber' );
    
        F0FTable::addIncludePath( JPATH_ADMINISTRATOR . '/components/com_j2store/tables' );
        $orderpayment = F0FTable::getInstance( 'Order', 'J2StoreTable' )->getClone();

        if ( empty( $token ) || empty( $order_id ) )
        {
            $app->enqueueMessage( $this->translate('ERROR_EMPTY_PARAMS'), 'Error' );
            $vars->message = $this->translate('ERROR_EMPTY_PARAMS');
            return $this->return_result( $vars );
        }

        if ( ! $orderpayment->load( $order_id ) )
        {
            $app->enqueueMessage( $this->translate('ERROR_NOT_FOUND'), 'Error' );
            $vars->message = $this->translate('ERROR_NOT_FOUND');
            return $this->return_result( $vars );
        }

        // Check double spending.
        if ( $orderpayment->transaction_id != $token )
        {
            $app->enqueueMessage( $this->translate('ERROR_WRONG_PARAMS'), 'Error' );
            $vars->message = $this->translate('ERROR_WRONG_PARAMS');
            return $this->return_result( $vars );
        }

        if ( $orderpayment->get( 'transaction_status' ) == 'Processed' || $orderpayment->get( 'transaction_status' ) == 'Confirmed' )
        {
            $app->enqueueMessage( $this->translate('ERROR_ALREADY_COMPLETED'), 'Message' );
            $vars->message = $this->translate('ERROR_ALREADY_COMPLETED');
            return $this->return_result( $vars );
        }

        // Save transaction details based on posted data.
        $payment_details           = new JObject();
        $payment_details->status   = $status;
        $payment_details->id       = $token;
        $payment_details->order_id = $order_id;

        $orderpayment->transaction_details = json_encode( $payment_details );
        $orderpayment->store();
    
        if ( $status != 'OK' )
        {
            $orderpayment->add_history( $this->translate('ERROR_FAILED_PAYMENT') );
            $app->enqueueMessage( $this->translate('ERROR_FAILED_PAYMENT'), 'Error' );
            $vars->message = $this->translate('ERROR_FAILED_PAYMENT');
            // Set transaction status to 'Failed'
            $orderpayment->update_status(3);
            $orderpayment->store();
    
            return $this->return_result( $vars );
        }

        $api_key = $this->params->get( 'api_key', '' );
    
        $data = [
            'token'   => $token,
            'api_key' => $api_key,
        ];
    
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, 'https://ipg.vandar.io/api/v3/verify' );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json'] );
    
        $result      = curl_exec( $ch );
        $result      = json_decode( $result );
        $http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );
    
        if ( $http_status != 200 || $result->status != 1 )
        {
            $msg = $this->translate('ERROR_FAILED_VERIFY');
            foreach ($result->errors as $err){
                $msg .= '<br>'. $err;
            }
            $vars->message = $msg;
            $app->enqueueMessage( $msg, 'Error' );
            $orderpayment->add_history( $msg );
            // Set transaction status to 'Failed'
            $orderpayment->update_status(3);
            $orderpayment->store();
    
            return $this->return_result( $vars );
        }

        $verify_status   = empty( $result->status ) ? NULL : $result->status;
        $verify_track_id = empty( $result->transId ) ? NULL : $result->transId;
        $verify_amount   = empty( $result->amount ) ? NULL : $result->amount;

        // Update transaction details
        $orderpayment->transaction_details = json_encode( "<pre>". print_r($result, true) ."</pre>" );

        if ( empty( $verify_status ) || empty( $verify_track_id ) || empty( $verify_amount ) )
        {
            $msg = $this->translate('ERROR_FAILED_VERIFY');
            $vars->message = $msg;
            $orderpayment->add_history( $msg );
            $app->enqueueMessage( $msg, 'Error' );
            // Set transaction status to 'Failed'
            $orderpayment->update_status(3);
            $orderpayment->store();
        }
        else
        { // Payment is successful.
            $msg = $this->translate('SUCCESS_MESSAGE') . $verify_track_id;
            $vars->message = $msg;
            // Set transaction status to 'PROCESSED'
            $orderpayment->transaction_status = JText::_( 'J2STORE_PROCESSED' );
            $app->enqueueMessage( $msg, 'message' );
            $orderpayment->add_history( $msg );

            if ( $orderpayment->store() )
            {
                $orderpayment->payment_complete();
                $orderpayment->empty_cart();
            }
        }
        return $this->return_result( $vars );
    }

    protected function return_result($vars) {
        return $this->_getLayout( 'postpayment', $vars );
    }

    /**
     * translate plugin language files
     * @param $key
     * @return mixed
     */
    protected function translate($key)
    {
        return JText::_('PLG_J2STORE_VANDAR_' . strtoupper($key));
    }
}
