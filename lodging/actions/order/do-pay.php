<?php
/*
    This file is part of Symbiose Community Edition <https://github.com/yesbabylon/symbiose>
    Some Rights Reserved, Yesbabylon SRL, 2020-2021
    Licensed under GNU AGPL 3 license <http://www.gnu.org/licenses/>
*/

use lodging\sale\pos\Order;
use lodging\sale\booking\BookingLineGroup;
use lodging\sale\booking\BookingLine;

list($params, $providers) = announce([
    'description'   => "This will mark the order as paid, and updated fundings and bookings involved in order lines, if any.",
    'params'        => [
        'id' =>  [
            'description'   => 'Identifier of the order that has been paid.',
            'type'          => 'integer',
            'min'           => 1,
            'required'      => true
        ]
    ],
    'access' => [
        'groups'            => ['booking.default.user', 'pos.default.user'],
    ],
    'response'      => [
        'content-type'  => 'application/json',
        'charset'       => 'utf-8',
        'accept-origin' => '*'
    ],
    'providers'     => ['context', 'orm', 'auth']
]);

list($context, $orm, $auth) = [$providers['context'], $providers['orm'], $providers['auth']];

// read order object
$order = Order::id($params['id'])
                ->read([
                    'id', 'name', 'status', 'has_invoice', 'invoice_id',
                    'has_funding',
                    'funding_id' => [
                        'type',
                        'invoice_id'
                    ],
                    'center_id' => [
                        'center_office_id'
                    ],
                    'order_payments_ids' => [
                        'order_lines_ids' => [
                            'product_id',
                            'qty',
                            'unit_price',
                            'vat_rate',
                            'discount',
                            'free_qty'
                        ],
                        'order_payment_parts_ids' => [
                            'payment_method', 'booking_id', 'voucher_ref'
                        ]
                    ]
                ])
                ->first();

if(!$order) {
    throw new Exception("unknown_order", QN_ERROR_UNKNOWN_OBJECT);
}

// order already paid
if($order['status'] == 'paid') {
    throw new Exception("incompatible_status", QN_ERROR_INVALID_PARAM);
}

/*
    Handle products (lines) that must be added as extra on a booking
*/

// update the funding_id related to the paymentPart, if any
// loop through order lines to check for payment method  voucher/booking_id if any
foreach($order['order_payments_ids'] as $pid => $payment) {
    // find out if the payment relates to a booking
    $booking_id = 0;
    foreach($payment['order_payment_parts_ids'] as $oid => $part) {
        if($part['payment_method'] == 'booking' && $part['booking_id'] > 0) {
            $booking_id = $part['booking_id'];
            break;
        }
    }
    if($booking_id) {
        /*
            add lines as extra consumption on the targeted booking
        */

        // find any existing 'extra' group id
        $groups_ids = BookingLineGroup::search([['booking_id', '=', $booking_id], ['is_extra', '=', true]])->ids();
        if($groups_ids > 0 && count($groups_ids)) {
            $group_id = reset(($groups_ids));
        }
        // no 'extra' group: create one
        else {
            $new_group = BookingLineGroup::create(['name' => 'Suppléments', 'booking_id' => $booking_id, 'is_extra' => true])->first();
            $group_id = $new_group['id'];
        }
        // create booking lines according to order lines
        foreach($payment['order_lines_ids'] as $lid => $line) {
            // create a new line in the 'extra' group
            $new_line = BookingLine::create(['booking_id' => $booking_id, 'booking_line_group_id' => $group_id, 'product_id' => $line['product_id']])->first();
            // #memo - at creation booking_line qty is always set accordingly to its parent group nb_pers
            BookingLine::id($new_line['id'])
                        // #memo - setting qty reset prices
                        ->update([
                            'qty'           => $line['qty']
                        ])
                        ->update([
                            'unit_price'    => $line['unit_price'],
                            'vat_rate'      => $line['vat_rate']
                        ]);
        }
    }
}

// mark the order as paid
Order::id($params['id'])->update(['status' => 'paid']);

// customer requested an invoice: generate an invoice for the order (only if payment do not relate to a funding)
if(!$order['has_funding'] && $order['has_invoice']) {
    try {
        eQual::run('do', 'lodging_order_do-invoice', ['id' => $params['id']]);
    }
    catch(Exception $e) {
        // ignore errors when trying to issue an invoice (some restrictions apply)
        trigger_error("QN_DEBUG_ORM::".$e->getMessage(), QN_REPORT_WARNING);
    }
}

$context->httpResponse()
        ->status(204)
        ->send();