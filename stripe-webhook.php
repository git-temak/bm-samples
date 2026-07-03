<?php

use WP_User;

add_action('rest_api_init', function () {
    register_rest_route('stripe', '/events', array(
        'methods' => 'POST',
        'callback' => 'handle_stripe_webhook',
        'permission_callback' => '__return_true'
    ));
});

// Callback function to handle the request
function handle_stripe_webhook(WP_REST_Request $request) {
    $body = json_decode($request->get_body(), true);

    if (!isset($body['type'])) {
        return new WP_REST_Response(['error' => 'Invalid event'], 400);
    }

    switch ($body['type']) {
        case 'customer.subscription.created':
            $session = $body['data']['object'];
            update_user_meta( 1, 'aaa_stripe.subscription.created', $session );
            break;
        case 'customer.subscription.deleted':
            $session = $body['data']['object'];
            update_user_meta( 1, 'aaa_stripe.subscription.deleted', $session );
            break;
        case 'customer.subscription.updated':
            $session = $body['data']['object'];
            update_user_meta( 1, 'aaa_stripe.subscription.updated', $session );
            break;
        case 'invoice.payment_succeeded':
            $session = $body['data']['object'];

            $stripe_invoice_id = $session['id'];
            $stripe_subscription_id = $session['subscription'] ?: $session['parent']['subscription'];

            $customer_email = $session['subscription_details']['metadata']['acc_email'];
            $customer_id = (int) $session['subscription_details']['metadata']['acc_id'];
            $cycle = $session['subscription_details']['metadata']['cycle'] ?: '';
            $forminator_sub_id = $session['subscription_details']['metadata']['forminator_sub_id'];
            $stripe_customer_id = $session['customer'];
            $stripe_subscription_url = 'https://dashboard.stripe.com/test/subscriptions/' . $stripe_subscription_id;
            $stripe_invoice_url = $session['hosted_invoice_url'];
            $stripe_invoice_pdf = $session['invoice_pdf'];
            $stripe_plan_end = (int) $session['lines']['data']['period']['end'];
            $readable_wp_date = wp_date('j M Y, g:ia', $stripe_plan_end);

            $user_id = get_user_by('id', $customer_id);
            $user = get_user_by('email', $customer_email);

            if (!($user instanceof WP_User) && !($user_id instanceof WP_User)) {
                update_user_meta( 1, 'stripe_user_not_found_' . time(), $body );
            }

            $sub_id = wp_insert_post([
                'post_title' => 'New ' . $cycle . ' subcription by #' . $customer_id,
                'post_content' => '',
                'post_name' => $stripe_invoice_id,
                'post_type' => 'cpt_accs',
                'post_status' => 'publish',
                'post_author' => $user_id->ID ?? $user->ID,
            ]);

            if (!is_wp_error($sub_id) || $sub_id == 0){
                update_user_meta( 1, 'failed_to_create_cpt_' . time(), $body );
            }

            $sub_meta = [
                'sub_forminator_sub_id' => $forminator_sub_id,
                'sub_stripe_customer_id' => $stripe_customer_id,
                'sub_stripe_subscription_id' => $stripe_subscription_id,
                'sub_stripe_subscription_url' => $stripe_subscription_url,
                'sub_stripe_invoice_url' => $stripe_invoice_url,
                'sub_stripe_invoice_pdf' => $stripe_invoice_pdf,
                'sub_stripe_plan_end' => $readable_wp_date,
                'sub_stripe_plan_end_ts' => $stripe_plan_end,
                'sub_wp_user' => $customer_email,
            ];

            foreach ($sub_meta as $key => $value) {
                update_post_meta($sub_id, $key, $value);
            }

            break;
    }

    return new WP_REST_Response(['success' => true], 200);
}