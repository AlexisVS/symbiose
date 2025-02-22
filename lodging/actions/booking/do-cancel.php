<?php
/*
    This file is part of Symbiose Community Edition <https://github.com/yesbabylon/symbiose>
    Some Rights Reserved, Yesbabylon SRL, 2020-2021
    Licensed under GNU AGPL 3 license <http://www.gnu.org/licenses/>
*/

use lodging\sale\booking\Booking;
use lodging\sale\booking\Consumption;
use core\Task;


list($params, $providers) = announce([
    'description'   => "This will cancel the booking, whatever its current status. Balance will be adjusted if cancellation fees apply.",
    'params'        => [
        'id' =>  [
            'description'   => 'Identifier of the targeted booking.',
            'type'          => 'integer',
            'min'           => 1,
            'required'      => true
        ],
        // this must remain synched with field definition Booking::cancellation_reason
        'reason' =>  [
            'description'   => 'Reason of the booking cancellation.',
            'type'          => 'string',
            'selection'     => [
                'other',                    // customer cancelled for a non-listed reason or without mentionning the reason (cancellation fees might apply)
                'overbooking',              // the booking was cancelled due to failure in delivery of the service
                'duplicate',                // several contacts of the same group made distinct bookings for the same sojourn
                'internal_impediment',      // cancellation due to an incident impacting the rental units
                'external_impediment',      // cancellation due to external delivery failure (organisation, means of transport, ...)
                'health_impediment'         // cancellation for medical or mourning reason
            ],
            'required'       => true
        ]
    ],
    'access' => [
        'groups'            => ['booking.default.user'],
    ],
    'response'      => [
        'content-type'  => 'application/json',
        'charset'       => 'utf-8',
        'accept-origin' => '*'
    ],
    'providers'     => ['context', 'orm', 'auth']
]);

/**
 * @var \equal\php\Context                  $context
 * @var \equal\orm\ObjectManager            $orm
 * @var \equal\auth\AuthenticationManager   $auth
 */
list($context, $orm, $auth) = [$providers['context'], $providers['orm'], $providers['auth']];

// read booking object
$booking = Booking::id($params['id'])
                  ->read(['id', 'name', 'is_cancelled', 'status', 'contracts_ids', 'fundings_ids' => ['id', 'is_paid']])
                  ->first();

if(!$booking) {
    throw new Exception("unknown_booking", QN_ERROR_UNKNOWN_OBJECT);
}

// booking already cancelled
if($booking['is_cancelled']) {
    throw new Exception("incompatible_status", QN_ERROR_INVALID_PARAM);
}

// revert booking to quote
$json = run('do', 'lodging_booking_do-quote', ['id' => $params['id']]);
$data = json_decode($json, true);
if(isset($data['errors'])) {
    // raise an exception with returned error code
    foreach($data['errors'] as $name => $message) {
        throw new Exception($message, qn_error_code($name));
    }
}

// release rental units (remove consumptions, if any)
Consumption::search(['booking_id', '=', $params['id']])->delete(true);

// mark the booking as cancelled
Booking::id($params['id'])->update(['is_cancelled' => true, 'cancellation_reason' => $params['reason']]);

// if booking is balanced, set status to balanced and archive booking
$booking = Booking::id($params['id'])->read(['fundings_ids'])->first();

// #todo : if cancellation fees are applicable, set status to 'debit_balance'

if(count($booking['fundings_ids'])) {
    Booking::id($params['id'])->update(['status' => 'credit_balance']);
}
else {
    Booking::id($params['id'])->update(['status' => 'balanced'])->update(['state' => 'archive']);
}

$context->httpResponse()
        ->status(200)
        ->send();