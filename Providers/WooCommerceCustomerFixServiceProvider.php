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
     * Remembers, per conversation, whether fixCustomerEmails() overrode the
     * email(s) on this request — used only to render the temporary debug
     * indicator below. Keyed by conversation id.
     *
     * @var array
     */
    protected static $last_result = [];

    /**
     * Module hooks.
     */
    public function hooks()
    {
        // Correct which email(s) the WooCommerce module uses to look up orders.
        \Eventy::addFilter('woocommerce.customer_emails', array($this, 'fixCustomerEmails'), 20, 4);

        // TEMPORARY debug aid — shows whether the email(s) above were
        // overridden, right under the WooCommerce module's "Recent Orders"
        // panel. Remove this hook (and showDebugIndicator()) once the fix is
        // confirmed working reliably. Priority 15 so it runs after the
        // WooCommerce module's own listener on this action (registered at
        // priority 12), which is what triggers fixCustomerEmails() above.
        \Eventy::addAction('conversation.after_prev_convs', array($this, 'showDebugIndicator'), 15, 2);
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
        $original_customer_emails = $customer_emails;
        $result = $this->detectRealCustomerEmails($customer_emails, $mailbox, $conversation);

        // TEMPORARY: remember whether we changed anything, for showDebugIndicator().
        if ($conversation) {
            static::$last_result[$conversation->id] = [
                'original' => $original_customer_emails,
                'result'   => $result,
                'filtered' => ($result !== $original_customer_emails),
            ];
        }

        return $result;
    }

    /**
     * The actual detection/override logic, split out from fixCustomerEmails()
     * so the latter can wrap it with the (temporary) debug bookkeeping above
     * without cluttering this method with anything but the real logic.
     *
     * @param array $customer_emails
     * @param \App\Mailbox|null $mailbox
     * @param \App\Conversation|null $conversation
     * @return array
     */
    protected function detectRealCustomerEmails($customer_emails, $mailbox, $conversation)
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

    /**
     * TEMPORARY debug aid. Echoes a small notice right after the WooCommerce
     * module's "Recent Orders" panel when fixCustomerEmails() overrode the
     * email(s) used for this conversation, so it's obvious at a glance
     * whether the fix actually kicked in. Only reflects the initial page
     * render — the sidebar's "Refresh" link re-runs the filter via ajax but
     * doesn't re-fire this action, so this notice won't update on refresh.
     * Safe to delete this method and its hook registration once no longer
     * needed.
     *
     * @param \App\Customer|null $customer
     * @param \App\Conversation|null $conversation
     * @return void
     */
    public function showDebugIndicator($customer, $conversation)
    {
        if (!$conversation || empty(static::$last_result[$conversation->id])) {
            return;
        }

        $info = static::$last_result[$conversation->id];

        if (empty($info['filtered'])) {
            return;
        }

        echo '<div class="text-danger small" style="padding:4px 15px;">'
            .'[DEBUG] WooCommerceCustomerFix overrode order-lookup email(s): '
            .htmlspecialchars(implode(', ', $info['original']))
            .' &rarr; '
            .htmlspecialchars(implode(', ', $info['result']))
            .'</div>';
    }
}
