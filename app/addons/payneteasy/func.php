<?php
// Preventing direct access to the script, because it must be included by the "include" directive.
defined('BOOTSTRAP') or die('Access denied');

/**
 * Install the Payneteasy payment processor.
 *
 * This function is responsible for installing the Payneteasy payment processor. It defines the processor data,
 * checks if the processor exists, and either inserts a new processor entry or updates an existing one in the
 * payment_processors table.
 */
function fn_payneteasy_install()
{
    $processor_data = [
        'processor' => 'PaynetEasy Payment',
        'processor_script' => 'payneteasy.php',
        'processor_template' => 'addons/payneteasy/views/orders/components/payments/payneteasy.tpl',
        'admin_template' => 'payneteasy.tpl',
        'callback' => 'Y',
        'type' => 'P',
        'position' => 10,
        'addon' => 'payneteasy',
    ];

    $processor_id = db_get_field(
        'SELECT processor_id FROM ?:payment_processors WHERE admin_template = ?s',
        $processor_data['admin_template']
    );

    if (empty($processor_id)) {
        db_query('INSERT INTO ?:payment_processors ?e', $processor_data);
    } else {
        db_query('UPDATE ?:payment_processors SET ?u WHERE processor_id = ?i', $processor_data, $processor_id);
    }

    db_query('CREATE TABLE ?:payneteasy_payments (paynet_order_id int NOT NULL, merchant_order_id int NOT NULL)');
}


/**
 * Uninstall the Payneteasy payment processor.
 *
 * This function is responsible for uninstalling the Payneteasy payment processor. It retrieves payment IDs
 * associated with the processor's admin_template, deletes those payments, removes the processor entry from
 * payment_processors, and sets processor IDs in payments to 0 and status to 'D' if applicable.
 */
function fn_payneteasy_uninstall()
{
    $admin_tpl = 'payneteasy.tpl';

    $payment_ids = db_get_fields(
        'SELECT a.payment_id FROM ?:payments AS a
        LEFT JOIN ?:payment_processors AS b ON a.processor_id = b.processor_id
        WHERE b.admin_template = ?s',
        $admin_tpl
    );
    foreach ($payment_ids as $payment_id) {
        fn_delete_payment($payment_id);
    }
    db_query('DELETE FROM ?:payment_processors WHERE admin_template = ?s', $admin_tpl);

    $processor_id = db_get_field('SELECT processor_id FROM ?:payment_processors WHERE admin_template = ?s', $admin_tpl);
    if (!empty($processor_id)) {
        db_query('UPDATE ?:payments SET processor_id = 0, status = "D" WHERE processor_id = ?i', $processor_id);
    }

    db_query('DROP TABLE ?:payneteasy_payments');
}
