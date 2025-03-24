
<div class="control-group">
    <label class="control-label" for="payneteasy_live_url">{__("payneteasy_live_url_title")}:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][live_url]" id="payneteasy_live_url" value="{$processor_params.live_url}" size="120" required="required">
        <p class="muted description">{__("payneteasy_live_url_desc")}</p>
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="payneteasy_sandbox_url">{__("payneteasy_sandbox_url_title")}:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][sandbox_url]" id="payneteasy_sandbox_url" value="{$processor_params.sandbox_url}" size="120" required="required">
        <p class="muted description">{__("payneteasy_sandbox_url_desc")}</p>
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="payneteasy_login">{__("payneteasy_login_title")}:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][login]" id="payneteasy_login" value="{$processor_params.login}" size="120" required="required">
        <p class="muted description">{__("payneteasy_login_desc")}</p>
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="payneteasy_control_key">{__("payneteasy_control_key_title")}:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][control_key]" id="payneteasy_control_key" value="{$processor_params.control_key}" size="120" required="required">
        <p class="muted description">{__("payneteasy_control_key_desc")}</p>
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="payneteasy_endpoint_id">{__("payneteasy_endpoint_id_title")}:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][endpoint_id]" id="payneteasy_endpoint_id" value="{$processor_params.endpoint_id}" size="120">
        <p class="muted description">{__("payneteasy_endpoint_id_desc")}</p>
    </div>
</div>

{assign var="payment_methods" value=['form' => 'FORM', 'direct' => 'DIRECT']}

<div class="control-group">
    <label class="control-label" for="payneteasy_payment_method">{__("payneteasy_payment_method_title")}:</label>
    <div class="controls">
        <select name="payment_data[processor_params][payment_method]" id="payneteasy_payment_method">
            {foreach from=$payment_methods item="method" key="key"}
                <option value="{$key}" {if (isset($processor_params.payment_method) && !empty($processor_params.payment_method) && $processor_params.payment_method == $key)}selected="selected"{/if}>{$method}</option>
            {/foreach}
        </select>
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="payneteasy_sandbox">{__("payneteasy_sandbox_title")}:</label>
    <div class="controls media">
        <input type="checkbox" name="payment_data[processor_params][sandbox]" id="payneteasy_sandbox" class="pull-left" value="1" {if $processor_params.sandbox}checked="checked"{/if}>
        <p class="muted description">{__("payneteasy_sandbox_desc")}</p>
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="payneteasy_logging">{__("payneteasy_logging_title")}:</label>
    <div class="controls media">
        <input type="checkbox" name="payment_data[processor_params][logging]" id="payneteasy_logging" class="pull-left" value="1" {if $processor_params.logging}checked="checked"{/if}>
        <p class="muted description">{__("payneteasy_logging_desc")}</p>
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="payneteasy_three_d_secure_payment">{__("payneteasy_three_d_secure_payment_title")}:</label>
    <div class="controls media">
        <input type="checkbox" name="payment_data[processor_params][three_d_secure_payment]" id="payneteasy_three_d_secure_payment" class="pull-left" value="1" {if $processor_params.three_d_secure_payment}checked="checked"{/if}>
        <p class="muted description">{__("payneteasy_three_d_secure_payment_desc")}</p>
    </div>
</div>

<div id="text_status_map" class="in collapse">
    {assign var="statuses" value=$smarty.const.STATUSES_ORDER|fn_get_simple_statuses}

    <div class="control-group">
        <label class="control-label" for="payneteasy_status_paid">{__("payneteasy_status_paid_title")}:</label>
        <div class="controls">
            <select name="payment_data[processor_params][statuses][paid]" id="payneteasy_status_paid">
                {foreach from=$statuses item="s" key="k"}
                    <option value="{$k}" {if (isset($processor_params.statuses.paid) && !empty($processor_params.statuses.paid) && $processor_params.statuses.paid == $k) || (!isset($processor_params.statuses.paid) && $k == 'P')}selected="selected"{/if}>{$s}</option>
                {/foreach}
            </select>
            <p class="muted description">{__("payneteasy_status_paid_desc")}</p>
        </div>
    </div>
    <div class="control-group">
        <label class="control-label" for="payneteasy_status_failed">{__("payneteasy_status_failed_title")}:</label>
        <div class="controls">
            <select name="payment_data[processor_params][statuses][failed]" id="payneteasy_status_failed">
                {foreach from=$statuses item="s" key="k"}
                    <option value="{$k}" {if (isset($processor_params.statuses.failed) && !empty($processor_params.statuses.failed) && $processor_params.statuses.failed == $k) || (!isset($processor_params.statuses.failed) && $k == 'F')}selected="selected"{/if}>{$s}</option>
                {/foreach}
            </select>
            <p class="muted description">{__("payneteasy_status_failed_desc")}</p>
        </div>
    </div>
</div>
