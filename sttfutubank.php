<?php

if (!defined('_VALID_MOS') && !defined('_JEXEC'))
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');

if (!class_exists('vmPSPlugin')) require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
if (!class_exists('FutubankForm')) require(dirname(__FILE__) . '/futubank_core.php');

// На сайте платежной системы в разделе "Уведомления о транзакциях" выбрать Уведомления с помощью POST-запросов на выбранную страницу сайта и указать:
// http://www.вашсайт.ru/index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&futubank=1&tmpl=component

class plgVMPaymentSttFutubank extends vmPSPlugin
{
	public static $_this = false;
	public static $flag = false;

	function __construct(& $subject, $config)
	{
		parent::__construct($subject, $config);
		$this->_loggable = true;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id'; 
		$this->_tableId = 'id'; 
		$varsToPush = $this->getVarsToPush();
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}

    protected function getVmPluginCreateTableSQL()
    {
		return $this->createTableSQL('Payment Futubank Table');
    }
    
    function getTableSQLFields()
    {
		$SQLfields = array(
			'id' 							=> 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id' 			=> 'int(1) UNSIGNED', 
			'order_number'              	=> 'char(64)',
			'virtuemart_paymentmethod_id' 	=> 'mediumint(1) UNSIGNED',
			'payment_name' 					=> 'char(255) NOT NULL DEFAULT \'\' ',
			'payment_order_total'       	=> 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
			'payment_currency' 				=> 'char(3) ',
			'cost_per_transaction' 			=> 'decimal(10,2)',
			'cost_percent_total' 			=> 'char(10)',
			'tax_id' 						=> 'smallint(1)'
		);

		return $SQLfields;
    }
    
    function plgVmConfirmedOrder($cart, $order) 
    {
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id)))
		{
	    	return null;
		}
		if (!$this->selectedThisElement($method->payment_element))
		{
	    	return false;
		}
		
		$session = JFactory::getSession();
		$return_context = $session->getId();
		$this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');
		
		if (!class_exists('VirtueMartModelOrders'))
		{
	    	require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		}
	    if (!class_exists('VirtueMartModelCurrency'))
	    {
	    	require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
	    }	    
		$new_status = $method->status_pending;		    	
		$this->getPaymentCurrency($method);
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
		$db = JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
		$totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total,false), 2);
		$cnt = count($order['items']);
		if($cnt==1)	$prod = $order['items'][0]->order_item_name;
		else $prod = "Заказ № " . $order['details']['BT']->order_number;
		$success_url = JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&futubank=1';
		$fail_url = JURI::root().'index.php?option=com_virtuemart&view=cart';
		$cancel_url = $faul_url;

		
		$ff = new FutubankForm($method->login, $method->pass2, $method->test, 'SttFutubank', 'VirtueMart');
		$form = $ff->compose(
			$totalInPaymentCurrency,	// сумма заказа
			$currency_code_3,			// валюта заказа (поддерживается только "RUB")
			$order['details']['BT']->virtuemart_order_id,	// номер заказа
			$order['details']['BT']->email,					// e-mail клиента (может быть '')
			'',							// имя клиента (может быть '')
			'',							// телефон клиента (может быть '')
			$success_url,				// URL, куда направить клиента при успешной оплате
			$fail_url,					// URL, куда направить клиента при ошибке
			$cancel_url,				// URL текущей страницы
			mb_substr($prod, 0, 110)	// описание (необязательно)
			);
	   	$url = $ff->get_url();  	
	
		$dbValues = array();
		$this->_virtuemart_paymentmethod_id      = $order['details']['BT']->virtuemart_paymentmethod_id;
		$dbValues['payment_name']                = $this->renderPluginName($method);
		$dbValues['order_number']                = $order['details']['BT']->order_number;
		$dbValues['virtuemart_paymentmethod_id'] = $this->_virtuemart_paymentmethod_id;
		$dbValues['cost_per_transaction']        = $method->cost_per_transaction;
		$dbValues['cost_percent_total']          = $method->cost_percent_total;
		$dbValues['payment_currency']            = $currency_code_3 ;
		$dbValues['payment_order_total']         = $totalInPaymentCurrency;
		$dbValues['tax_id']                      = $method->tax_id;
		$this->storePSPluginInternalData($dbValues);

		$send_pending = 0;
		if(isset($method->send_pending)) $send_pending=$method->send_pending;
		
		$html = '<form action="' . $url . '" method="post" name="vm_futubank_form">'. FutubankForm::array_to_hidden_fields($form);
		$html.= '</form>';

		$html.= ' <script type="text/javascript">';
		$html.= ' document.vm_futubank_form.submit();';
		$html.= ' </script>';
		if($send_pending) {
			$modelOrder = VmModel::getModel ('orders');
			$order['order_status'] = $new_status;
			$order['customer_notified'] = 1;
			$order['comments'] = '';
			$modelOrder->updateStatusForOneOrder ($order['details']['BT']->virtuemart_order_id, $order, TRUE);
			JRequest::setVar ('html', $html);
		} else {
			$this->processConfirmedOrderPaymentResponse(2, $cart, $order, $html, $method->payment_name, $new_status);
		}
		return null;
    } 

	//Отображение информации о заказе в админке    
	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
	{
		if (!$this->selectedThisByMethodId($virtuemart_payment_id))
		{
	    	return null;
		}
    
		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject()))
		{
	    	vmWarn(500, $q . " " . $db->getErrorMsg());
	    	return '';
		}
		$this->getPaymentCurrency($paymentTable);

		$html = '<table class="adminlist table">' . "\n";
		$html .=$this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('VMPAYMENT_STT_FUTUBANK', $paymentTable->payment_name);
		$html .= '</table>' . "\n";
		return $html;
    }

	function getCosts (VirtueMartCart $cart, $method, $cart_prices) {

		if (preg_match ('/%$/', $method->cost_percent_total)) {
			$cost_percent_total = substr ($method->cost_percent_total, 0, -1);
		} else {
			$cost_percent_total = $method->cost_percent_total;
		}
		return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
	}

    protected function checkConditions($cart, $method, $cart_prices)
    {
		    return true;
    }

	//Возврат покупателя в интернет-магазин после успешной оплаты.    
    function plgVmOnPaymentResponseReceived(  &$html)
    {
		if(!JRequest::getInt('futubank',0)) {
			return NULL;
		}
		if(self::$flag) return null;
		$html = "Оплата прошла успешно";
		if (!class_exists('VirtueMartCart'))
		{
		    require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
		}
		$cart = VirtueMartCart::getCart();
		$cart->emptyCart();
		self::$flag = true; // а это, чтобы несколько раз одно и то же не делать
		return true;
    }

	function plgVmOnUserPaymentCancel()
    {
    	return null;
    }
	
	function plgVmOnPaymentNotification()
    {
		if(!JRequest::getInt('futubank',0)) {
			return NULL;
		}
		//Изменение статуса заказа на Confirmed после оплаты счета
		if (!class_exists('VirtueMartModelOrders'))
		{
	    	require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		}
		$pay_data = JRequest::get('post');
		$virtuemart_order_id = $pay_data['order_id'];
		if (!array_key_exists ('order_id', $pay_data) || !isset($pay_data['order_id'])) {
			return NULL; // Another method was selected, do nothing
		} 
		if(!$virtuemart_order_id) return;
		
		$payment = $this->getDataByOrderId($virtuemart_order_id);
		$method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);
		if (!isset($method->payment_currency) || !$method->payment_currency)
			$this->getPaymentCurrency($method);
		
		$h = new FutubankPaymentCallback($method);
		$h->show($pay_data);
		jexit();
		return true;
    }    
    
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
		return $this->onStoreInstallPluginTable($jplugin_id);
    }
    
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
    {
		return $this->OnSelectCheck($cart);
    }
    
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		$this->cart = $cart;
		return $this->displayListFE($cart, $selected, $htmlIn);
    }
	
	public function getPluginHtml($plugin, $selectedPlugin, $pluginSalesPrice){
		if(isset($this->cart->products) && count($this->cart->products) <= 0) return;
		$pluginmethod_id = $this->_idName;
		$pluginName = $this->_psType . '_name';
		if ($selectedPlugin == $plugin->$pluginmethod_id) {
			$checked = 'checked="checked"';
		} else {
			$checked = '';
		}

		if (!class_exists ('CurrencyDisplay')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
		}
		$currency = CurrencyDisplay::getInstance ();
		$costDisplay = "";
		if ($pluginSalesPrice) {
			$costDisplay = $currency->priceDisplay ($pluginSalesPrice);
			$costDisplay = '<span class="' . $this->_type . '_cost"> (' . JText::_ ('COM_VIRTUEMART_PLUGIN_COST_DISPLAY') . $costDisplay . ")</span>";
		}
		$s = '';

		$html = '<input type="radio" name="' . $pluginmethod_id . '" id="' . $this->_psType . '_id_' . $plugin->$pluginmethod_id . '"   value="' . $plugin->$pluginmethod_id . '" ' . $checked . ">\n"
			. '<label for="' . $this->_psType . '_id_' . $plugin->$pluginmethod_id . '">' . '<span class="' . $this->_type . '">' . $plugin->$pluginName . $costDisplay . $s. "</span></label>\n";

		return $html; 		
	}
	
    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }
    
    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {

		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id)))
		{
	    	return null;
		}
		if (!$this->selectedThisElement($method->payment_element))
		{
	    	return false;
		}
	 	$this->getPaymentCurrency($method);

		$paymentCurrencyId = $method->payment_currency;
    }
    
    function logingActions($params)
    {
    	jimport('joomla.error.log');
    	$options = array(
    		'format' => "{DATE}\t{TIME}\t{ORDER}\t{ACTION}"
		);
    	$log = JLog::getInstance('futubank_events.log.php', $options);
    	$log->addEntry(array('ORDER' => $params['ORDER'],'ACTION' => $params['ACTION']));
    }
    
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array())
    {
		return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }
    
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }
    
    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
		return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
		return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
		return $this->setOnTablePluginParams($name, $id, $table);
    }
	
}

class FutubankPaymentCallback extends AbstractFutubankCallbackHandler {
	private $method;
	function __construct($method)
	{
		$this->method = $method;
		if (!class_exists('CurrencyDisplay'))
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
	}	
	protected function get_futubank_form() {
		return new FutubankForm($this->method->login, $this->method->pass2, $this->method->test, 'SttFutubank', 'VirtueMart'); 
	}
	protected function load_order($virtuemart_order_id) {
		$order_model  = new VirtueMartModelOrders();
		$order_info   = $order_model->getOrder($virtuemart_order_id);
		return $order_info;
	}
	protected function get_order_currency($order) {
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $this->method->payment_currency . '" ';
		$db = JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		return $currency_code_3; 
	}
	protected function get_order_amount($order) {
		$paymentCurrency = CurrencyDisplay::getInstance($this->method->payment_currency);
		$totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($this->method->payment_currency, $order['details']['BT']->order_total, false), 2);
		return $totalInPaymentCurrency;
	}
	protected function is_order_completed($order) {
		$new_status = $this->method->status_success;
		return $order->order_status == $new_status; 
	}
	protected function mark_order_as_completed($order_info, array $data) {
		$new_status = $this->method->status_success;
		$modelOrder = VmModel::getModel('orders');
		if($order_info['details']['BT']->order_status!=$new_status) {
			$order = array();
			$order['order_status'] = $new_status;
			$order['customer_notified'] =1;
			$order['comments']='Futubank';
			$modelOrder->updateStatusForOneOrder($order_info['details']['BT']->virtuemart_order_id, $order, true);
		}
		return true;
	}
	protected function mark_order_as_error($order, array $data) {
		return;
	}
}
 
