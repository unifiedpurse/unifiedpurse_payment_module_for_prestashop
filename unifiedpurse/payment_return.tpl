{if $status == 'ok'}
	<p>{l s='Your credit card order from' mod='unifiedpurse'} <span class="bold">{$shop_name}</span> {l s='has been processed.' mod='unifiedpurse'}
		<br /><br />
        {l s='Your order reference number is: ' mod='unifiedpurse'}{$transactionID}
        <br /><br />
        {l s='For any questions or for further information, please contact our' mod='unifiedpurse'} <a href="{$base_dir_ssl}contact-form.php">{l s='customer support' mod='unifiedpurse'}</a>.
	</p>
{else}
	<p class="warning">
		{l s='We encountered a problem processing your order. If you think this is an error, you can contact our' mod='unifiedpurse'} 
		<a href="{$base_dir_ssl}contact-form.php">{l s='customer support department who will be pleased to assist you.' mod='unifiedpurse'}</a>.
	</p>
{/if}
