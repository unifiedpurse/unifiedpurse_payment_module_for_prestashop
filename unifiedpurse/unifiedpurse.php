<?php
ini_set('display_errors',1);
ini_set('error_reporting',E_ALL);
define('UNIFIEDPURSE_MERCHANT_ID','0000');

class UnifiedPurse extends PaymentModule
{
	private $_postErrors = array();

	function __construct()
	{
		$this->name = 'unifiedpurse';
		$this->tab = 'payment';
		if(defined('_PS_VERSION_')&&_PS_VERSION_>'1.4')$this->tab = 'payments_gateways';
		$this->version = '1.0';
		$this->author = 'UNIFIEDPURSE LIMITED';
		$this->currencies = true;
		$this->currencies_mode = 'checkbox';
		$this->need_instance = 0;
		//$this->ps_versions_compliancy = array('min' => '1.4', 'max' => _PS_VERSION_);
		
		
		//$this->controllers = array('payment', 'validation');
		//$this->is_eu_compatible = 1;
		
		$this->currencies = true;
		$this->currencies_mode = 'checkbox';
		

        parent::__construct();

        /* The parent construct is required for translations */
		$this->page = basename(__FILE__, '.php');
        $this->displayName = $this->l('Bitcoin, Litecoin, Ethereum, 80+ alternatives (UnifiedPurse)');
        $this->description = $this->l('Accept payments through, Bitcoin, Litecoin, Ethereum and over 80 alternatives (with UnifiedPurse.com)');
		$this->confirmUninstall = $this->l('Are you sure you want to uninstall ?');
		//if(!Configuration::get('UNIFIEDPURSE')) $this->warning = $this->l('No name provided');
		if (!sizeof(Currency::checkPaymentCurrencies($this->id)))
			$this->warning = $this->l('No currency has been set for this module');
		
	}
	
	function install()
	{
		if(defined('_PS_VERSION_')&&_PS_VERSION_>'1.4')
		{
			if(Shop::isFeatureActive()) Shop::setContext(Shop::CONTEXT_ALL);
		}
		
		if (!parent::install() || 
		    !Configuration::updateValue('UNIFIEDPURSE_MERCHANT_ID', UNIFIEDPURSE_MERCHANT_ID) ||
			!$this->registerHook('payment')
			)
			return false;
			

		$sql="CREATE TABLE IF NOT EXISTS "._DB_PREFIX_."unifiedpurse(
				id INT NOT NULL AUTO_INCREMENT,PRIMARY KEY(id),
				cart_id INT NOT NULL DEFAULT 0,UNIQUE(cart_id),
				date_time DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
				transaction_id VARCHAR(48) NOT NULL DEFAULT '',
				customer_email VARCHAR(128) NOT NULL DEFAULT '',
				response_description VARCHAR(225) NOT NULL DEFAULT '',
				response_code TINYINT(1) NOT NULL DEFAULT 0,
				transaction_amount DOUBLE NOT NULL DEFAULT 0,
				currency VARCHAR(3) NOT NULL DEFAULT '',
				customer_id INT DEFAULT 0
				)";
		
		Db::getInstance()->execute($sql);
		return true;
	}

	function uninstall()
	{
		if (!parent::uninstall() ||
		    !Configuration::deleteByName('UNIFIEDPURSE_MERCHANT_ID')
			)
			return false;
		$sql="DROP TABLE IF EXISTS "._DB_PREFIX_."unifiedpurse";
		Db::getInstance()->execute($sql);
		return true;
	}

	private function _postValidation()
	{
		// Validate the configuration screen in the Back Office
	}

	private function _postProcess()
	{
		// Called after validated configuration screen submit in Back Office
	}

	public function displayUnifiedPurse()
	{
		$this->_html .= '
		<img src="../modules/unifiedpurse/unifiedpurse.gif" style="float:left; margin-right:15px;" />
		<b>'.$this->l('This module allows you to accept payments by UnifiedPurse.').'</b><br /><br />
		'.$this->l('If the client chooses this payment mode, your UnifiedPurse account will be automatically credited.').'<br />
		'.$this->l('You need to configure your UnifiedPurse account first before using this module.').'
		<br /><br /><br />';
	}

	public function displayFormSettings()
	{
		$conf = Configuration::getMultiple(array('UNIFIEDPURSE_MERCHANT_ID'));
		$merchant_id = array_key_exists('merchant_id', $_POST) ? $_POST['merchant_id'] : (array_key_exists('UNIFIEDPURSE_MERCHANT_ID', $conf) ? $conf['UNIFIEDPURSE_MERCHANT_ID'] : '');
		
		$this->_html .= '
		<form action="'.$_SERVER['REQUEST_URI'].'" method="post">
		<fieldset>
			<legend><img src="../img/admin/contact.gif" />'.$this->l('Settings').'</legend>
			<label>'.$this->l('UnifiedPurse Merchant ID').'</label>
			<div class="margin-form"><input type="text" size="25" name="merchant_id" value="'.$merchant_id.'" required /></div>
			<br /><center><input type="submit" name="submitUnifiedPurse" value="'.$this->l('Update settings').'" class="button" /></center>
		</fieldset>
		</form><br /><br />
		';
	}

	public function getContent()
	{
		$this->_html = '<h2>UnifiedPurse Web Payments - UnifiedPurse.com</h2>';
		if (isset($_POST['submitUnifiedPurse']))
		{
			if (empty($_POST['merchant_id']))
				$this->_postErrors[] = $this->l('UnifiedPurse Merchant ID is required.');
			if (!sizeof($this->_postErrors))
			{
				Configuration::updateValue('UNIFIEDPURSE_MERCHANT_ID', $_POST['merchant_id']);
				$this->_html .= '
							<div class="conf confirm">
								<img src="../img/admin/ok.gif" alt="'.$this->l('Confirmation').'" />
								'.$this->l('Settings updated').'
							</div>';
			}
			else
				$this->displayErrors();
		}

		$this->displayUnifiedPurse();
		$this->displayFormSettings();
		return $this->_html;
	}

	
	/*
		Register this transaction at UnifiedPurse and redirect to the payment page.
	*/
	function execPayment($cart)
	{
		global $cookie, $smarty;
		
		$conf = Configuration::getMultiple(array('UNIFIEDPURSE_MERCHANT_ID','PS_SHOP_NAME'));
		$invoice=new Address($cart->id_address_invoice);
		$customer = new Customer($cart->id_customer);
		$currency=new Currency($cookie->id_currency);
		$time=time();
		$cart_id=$cart->id;
		
		$resp_str='';
		
		$cresults = Db::getInstance()->ExecuteS("SELECT * FROM "._DB_PREFIX_."unifiedpurse WHERE cart_id='$cart_id' LIMIT 1");
		if(!empty($cresults))
		{
			$crow=$cresults[0];
			if($crow['response_code']==1)$resp_str="This cart $cart_id has already been processed.";
			else Db::getInstance()->execute("DELETE FROM "._DB_PREFIX_."unifiedpurse WHERE cart_id='$cart_id' LIMIT 1");
		}
		
		if($resp_str=='')
		{
				$date_time=date('Y-m-d H:i:s',$time);
				$total=number_format($cart->getOrderTotal(true, 3), 2, '.', '');
				$customer_id=$cart->id_customer; //$invoice->firstname, $invoice->lastname
				$email=addslashes($customer->email);
				$sql="INSERT INTO "._DB_PREFIX_."unifiedpurse(cart_id,transaction_id,date_time,transaction_amount,currency,customer_email,customer_id,response_code) 
				VALUES ('$cart_id','$time','$date_time','$total','$currency','$email','$customer_id','0')";
				$db_ins=Db::getInstance()->execute($sql);
					
				//	public function validateOrder($id_cart, $id_order_state, $amount_paid, $payment_method = 'Unknown',$message = null, $extra_vars = array(), 
				//$currency_special = null, $dont_touch_amount = false,$secure_key = false, Shop $shop = null)
				
				if(!$db_ins)$resp_str="Error; storing unifiedpurse transaction record in database.";
				else 
				{
					$merchant_id=$conf['UNIFIEDPURSE_MERCHANT_ID'];
					$payment_memo=$conf['PS_SHOP_NAME'].' Payment';
					
					$pending_status=Configuration::get('PS_OS_PREPARATION');
					$info="Transaction Id $response. Pending completion at UnifiedPurse";
					@$this->validateOrder($cart->id, $pending_status, $total, $this->displayName, $info);
					//$order = new Order($this->currentOrder);
					
					$return_url=(Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8').__PS_BASE_URI__.'modules/'.$this->name.'/validation.php';
					$history_url=(Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8').__PS_BASE_URI__.'modules/'.$this->name.'/unifiedpurse_transactions.php';
		
					$resp_str="<form action='https://unifiedpurse.com/sci' method='post' id='unifiedpurse_payment_form'>
					<input type='hidden' name='amount' value='$total' />
					<input type='hidden' name='receiver' value='$merchant_id' />
					<input type='hidden' name='currency' value='$currency' />
					<input type='hidden' name='email' value='$email' />
					<input type='hidden' name='ref' value='$time' />
					<input type='hidden' name='memo' value=\"$payment_memo\" />
					<input type='hidden' name='notification_url' value='$return_url' />
					<input type='hidden' name='success_url' value='$return_url' />
					<input type='hidden' name='cancel_url' value='$return_url' />
					<button class='btn btn-lg btn-success'>If you are not automatically redirected, please click this button</button>
					</form>";
					
					echo "<!DOCTYPE html><head><title>Redirecting to UnifiedPurse</title></head><body>$resp_str<script type='text/javascript'>document.getElementById('unifiedpurse_payment_form').submit();</script></body></html>";
					exit;
				}
			}
			/*
			$smarty->assign(array(
					"response"=>$resp_str
				));
			return $this->display(__FILE__, 'payment_execution.tpl');
			*/
			$home_url=(Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8').__PS_BASE_URI__;
			
		echo "<!DOCTYPE html><head><title>Payment Error</title></head><body><h3>$resp_str</h3><a href='$home_url'>Go back home</a></body></html>";
	}

	function hookPayment($params)
	{
		global $smarty;
		//count as well be
		//http://christheritagebc.org/presta/index.php?fc=module&module=bankwire&controller=payment
		$this_path=$this->_path;
		$this_path_ssl=(Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8').__PS_BASE_URI__.'modules/'.$this->name.'/';
		
		$smarty->assign(array(
            'this_path' => $this->_path,
            'this_path_ssl' => $this_path_ssl
            ));
		
		//return $this->display(__FILE__, 'payment.tpl');
		return "<p class='payment_module'>
			<a href='{$this_path_ssl}payment.php' title='Pay with Bitcoin, Litecoin, Ethereum, 80+ alternatives (UnifiedPurse)' style='padding-left:10px;'>
				<img src='{$this_path}unifiedpurse.gif' alt='Pay with Bitcoin, Litecoin, Ethereum, 80+ alternatives (UnifiedPurse)' style='max-height:40px;'/>
				Bitcoin, Litecoin, Ethereum, 80+ alternatives (UnifiedPurse)
			</a>
		</p>";
	}
}
?>