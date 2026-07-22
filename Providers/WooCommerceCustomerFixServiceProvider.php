<?php

namespace Modules\WooCommerceCustomerFix\Providers;

use Illuminate\Support\ServiceProvider;

class WooCommerceCustomerFixServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->hooks();
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Module hooks.
     */
    public function hooks()
    {
        // Correct which email(s) the WooCommerce module uses to look up orders.
        \Eventy::addFilter('woocommerce.customer_emails', array($this, 'fixCustomerEmails'), 20, 4);
    }

    /**
     * Detect the "conversation initiated by us, sent to the customer, with us
     * in Bcc" case and, when found, swap the mailbox's own address for the
     * real customer's address (read from the first thread's "To").
     *
     * FreeScout core assigns the conversation's customer from the message's
     * From address unconditionally (app/Console/Commands/FetchEmails.php,
     * saveCustomerThread()), with no check for From being one of the
     * mailbox's own addresses. When a shop sends mail from its own mailbox
     * address to a customer and Bcc's itself (e.g. to log an order
     * confirmation), the conversation ends up with the shop's own address as
     * "customer" instead of the real recipient, and the WooCommerce module's
     * "Recent Orders" widget then searches WooCommerce for the shop's own
     * email instead of the customer's.
     *
     * @param array               $customer_emails Emails the WooCommerce module would search for.
     * @param \App\Customer|null  $customer        Conversation's assigned customer.
     * @param \App\Conversation|null $conversation
     * @param \App\Mailbox|null   $mailbox
     * @return array
     */
    public function fixCustomerEmails($customer_emails, $customer, $conversation, $mailbox)
    {
        if (empty($customer_emails) || !$mailbox || !$conversation) {
            return $customer_emails;
        }

        $mailbox_emails = $mailbox->getEmails();

        // Only intervene when the assigned "customer" is entirely one of our
        // own mailbox addresses — a real customer would never legitimately
        // share an address with the mailbox itself.
        $is_self = collect($customer_emails)->every(function ($email) use ($mailbox_emails) {
            return in_array($email, $mailbox_emails);
        });

        if (!$is_self) {
            return $customer_emails;
        }

        $thread = $conversation->getFirstThread();

        if (!$thread) {
            return $customer_emails;
        }

        // The real customer is whoever the original message was actually
        // addressed to, minus our own address(es) (in case the mailbox is
        // also in To/Cc alongside the real recipient).
        $real_customer_emails = array_diff($thread->getToArray(), $mailbox_emails);

        if (!$real_customer_emails) {
            return $customer_emails;
        }

        return array_values($real_customer_emails);
    }
}
