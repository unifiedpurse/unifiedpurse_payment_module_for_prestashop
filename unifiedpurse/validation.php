<?php

include(dirname(__FILE__).'/../../config/config.inc.php');
include(dirname(__FILE__).'/../../header.php');
include(dirname(__FILE__).'/unifiedpurse.php');

$success=false;
$home_url=(Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8').__PS_BASE_URI__;
$order_id=0;

if(empty($_GET['ref']))$info="Trasaction ID not supplied";
else
{
	$transactionId=addslashes($_GET['ref']);
	$results = Db::getInstance()->ExecuteS("SELECT uc.*,o.id_order FROM "._DB_PREFIX_."unifiedpurse uc JOIN "._DB_PREFIX_."orders o ON uc.cart_id=o.id_cart  WHERE uc.transaction_id='$transactionId' LIMIT 1");
	
	if(empty($results))$info="Order record $transactionId not found! ";
	elseif(!empty($results[0]['response_code'])){
		$info=$results[0]['response_description'];
		$success=($results[0]['response_code']==1);
	}
	else
	{
		$row=$results[0];
		$order_id=$row['id_order'];

		$unifiedpurse = new UnifiedPurse();
		$total=$row['transaction_amount'];
		$currency=$row['currency'];
		
		$cipgid= Configuration::get('UNIFIEDPURSE_MERCHANT_ID');
		$url="https://unifiedpurse.com/api_v1?ref=$transactionId&action=get_transaction&receiver=$cipgid&amount=$total&currency=$currency";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$url);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($ch);
		$returnCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	
		
		if($returnCode != 200)$info = 'HTTP ERROR ->  '.$returnCode.' :: '.curl_error($ch);		
		curl_close($ch);
		
		if(empty($info))$json=@json_decode($response,true);
		
		if(!empty($json['error']))$info=$json['error'];
		elseif(!empty($json))
		{
			$info=$json['info'];
			$new_status=$json['status'];
			$success=($new_status==1);
			
			if($new_status==1)
			{			
				$payment_status=Configuration::get('PS_OS_PAYMENT');
				
				$objOrder = new Order($order_id); 
				$history = new OrderHistory();
				$history->id_order = (int)$objOrder->id;
				$history->changeIdOrderState($payment_status, (int)($objOrder->id)); 
				
			}
			elseif($new_status==-1)
			{
				$cancelled_status=Configuration::get('PS_OS_CANCELED');
				
				$objOrder = new Order($order_id); 
				$history = new OrderHistory();
				$history->id_order = (int)$objOrder->id;
				$history->changeIdOrderState($cancelled_status, (int)($objOrder->id)); 
			}		
			
			

			Db::getInstance()->execute("UPDATE "._DB_PREFIX_."unifiedpurse SET
			response_code='$new_status',
			response_description='".addslashes($info)."'
			WHERE transaction_id='$transactionId' LIMIT 1");
		
		}		
	
	}
}

	$toecho= "<style type='text/css'>
					.errorMessage,.successMsg
					{
						color:#ffffff;
						font-size:18px;
						font-family:helvetica;
						border-radius:9px;
						display:inline-block;
						min-width:350px;
						border-radius: 8px;
						padding: 4px;
						margin:auto;
					}
					
					.errorMessage
					{
						background-color:#ff5500;
					}
					
					.successMsg
					{
						background-color:#00aa99;
					}
					
					body,html{min-width:100%;}
				</style>";
		
	if($success)
	{
		$toecho.="<div class='successMsg'>
				$info<br/>
				Your order has been successfully Processed <br/>
				ORDER ID: $order_id<br/>
				<a href='$home_url'>CLICK TO RETURN HOME</a></div>";
	}
	else
	{
		$toecho.="<div class='errorMessage'>
				Your transaction was not successful<br/>
				GATEWAY RESPONSE: $info<br/>
				ORDER ID: $order_id<br/>
				<a href='$home_url'>CLICK TO RETURN HOME</a></div>";
	}
	echo $toecho;
