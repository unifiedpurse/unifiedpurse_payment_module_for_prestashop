<?php
	session_start();
	include(dirname(__FILE__).'/../../config/config.inc.php');
	include(dirname(__FILE__).'/../../header.php');
	include(dirname(__FILE__).'/unifiedpurse.php');

	
	$home_url=(Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8').__PS_BASE_URI__;
	$page_url=(Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8').__PS_BASE_URI__.'modules/unifiedpurse/unifiedpurse_transactions.php';
	
		if(empty($_SESSION['access_type']))$_SESSION['access_type']='user';
		
		if(!empty($_GET['access_type']))
		{
			$access_type=$_GET['access_type'];
			$_SESSION['access_type']=$access_type;
		}
		else $access_type=$_SESSION['access_type'];

		$sql="";
		$toecho="";
		
		$results = Db::getInstance()->ExecuteS("SHOW TABLES LIKE '"._DB_PREFIX_."unifiedpurse'");
		
		if(empty($results))$toecho="<h3>This records does not exist yet.</h3>";
		elseif($access_type!='admin'&&empty($cookie->logged))$toecho="<h3>Please login first</h3>";
		else
		{
			if(!empty($_REQUEST['transaction_id']))
			{
				$transaction_id=addslashes($_REQUEST['transaction_id']);
				$success=false;
				$results = Db::getInstance()->ExecuteS("SELECT uc.*,o.id_order FROM "._DB_PREFIX_."unifiedpurse uc JOIN "._DB_PREFIX_."orders o ON uc.cart_id=o.id_cart  WHERE uc.transaction_id='$transaction_id' LIMIT 1");
				
				if(empty($results))$toecho="<h3>Order record $transaction_id not found!</h3>";
				elseif(!empty($results[0]['response_code'])){
					$toecho="<h3>".$results[0]['response_description']."</h3>";
				}
				else
				{
					$row=$results[0];
					$order_id=$row['id_order'];
					
					$refNo=strtotime($row['date_time']);
					
					$cipgid= Configuration::get('UNIFIEDPURSE_MERCHANT_ID');
					$total=$row['transaction_amount'];
					$currency=$row['currency'];
		
					$url="https://unifiedpurse.com/api_v1?ref=$transaction_id&action=get_transaction&receiver=$cipgid&amount=$total&currency=$currency";

					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, $url);
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
						WHERE transaction_id='$transaction_id' LIMIT 1");
					
					}
					
					$toecho="<h4>".$info."</h4>";;
				}
			}
		
		
			if($access_type=='admin')$sql="SELECT COUNT(*) FROM "._DB_PREFIX_."unifiedpurse ";
			else $sql="SELECT COUNT(*) FROM "._DB_PREFIX_."unifiedpurse  WHERE customer_id='".$cookie->id_customer."'";
			
			if( Db::getInstance()->getValue($sql)==0)$toecho.="<h3>No record found for transactions made through UnifiedPurse</h3>";
			else
			{
			
			$num=count($results);
			$perpage=10;
			$totalpages=ceil($num/$perpage);
			$p=empty($_GET['p'])?1:$_GET['p'];			
			if($p>$totalpages)$p=$totalpages;
			if($p<1)$p=1;
			$offset=($p-1) *$perpage;
			
			if($access_type=='admin')$sql="SELECT * FROM "._DB_PREFIX_."unifiedpurse ";
			else $sql="SELECT * FROM "._DB_PREFIX_."unifiedpurse  WHERE customer_id='".$cookie->id_customer."'";
			
			$sql.=" ORDER BY id DESC LIMIT $offset,$perpage ";
			$results = Db::getInstance()->ExecuteS($sql);
				$toecho.="
						<table style='width:100%;font-size:10px;border-width:1px;' class='table table-striped table-bordered table-condensed' >
							<tr style='width:100%;text-align:center;'>
								<th>
									S/N
								</th>
								<th>
									EMAIL
								</th>
								<th>
									TRANSACTION
									REFERENCE
								</th>
								<th>
									TRANSACTION DATE
								</th>
								<th>
									AMOUNT
								</th>
								<th>
									TRANSACTION<br/>
									RESPONSE
								</th>
								<th>
									ACTION
								</th>
							</tr>";
				$sn=0;
				foreach($results as $row)
				{
					$sn++;
					
					if(empty($row['response_description'])||$row['response_code']==0)
					{
						$transaction_response='(pending)';
						
						$trans_action="$page_url?p=$p&transaction_id={$row['transaction_id']}";
						
						$trans_action="<a href='$trans_action' style='color:#ffffff;background-color:#38B0E3;padding:4px;border-radius:3px;margin:2px;text-decoration:none;display:inline-block;'>REQUERY</a>";
					}
					else
					{
						$transaction_response=$row['response_description'];
						$trans_action='NONE';						
					}
					
					$toecho.="<tr align='center'>
								<td>
									$sn
								</td>
								<td>
									{$row['customer_email']}
								</td>
								<td>
									{$row['transaction_id']}
								</td>
								<td>
									{$row['date_time']}
								</td>
								<td>
									{$row['transaction_amount']} {$row['currency']}
								</td>
								<td>
									$transaction_response
								</td>
								<td>
									$trans_action
								</td>								
							 </tr>";
				}
				$toecho.="</table>";
				
				
				
		$pagination="";
		
			$prev=$p-1;
			$next=$p+1;
			
			if($prev>=1)$pagination.=" [<a href='$page_url?p=$prev'>previous</a>] ";
			
			if($next<=$totalpages)$pagination.=" [<a href='$page_url?p=$next'>next</a>] ";
		
		
		
		if($totalpages>2)
		{
			if($prev>1)$pagination.=" [<a href='$page_url?p=1'>first</a>] ";
			if($next<$totalpages)$pagination.=" [<a href='$page_url?p=$totalpages'>last</a>] ";
		}	
		
		$toecho.="<div>$pagination</div>";
				
			}
		}

		echo	$toecho;