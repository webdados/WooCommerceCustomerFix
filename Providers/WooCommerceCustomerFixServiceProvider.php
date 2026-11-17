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
     * Minimum FreeScout core version this module's assumptions about the
     * woocommerce.customer_emails filter's calling convention have been
     * verified against. There's no declarative way to require a minimum
     * *module* version in module.json (only a minimum *app* version), so
     * both this and REQUIRED_WOOCOMMERCE_VERSION are enforced at runtime
     * in boot().
     *
     * @var string
     */
    const REQUIRED_APP_VERSION = '1.8.229';

    /**
     * Minimum WooCommerce module version required. 1.0.18 is the first
     * release that fires the woocommerce.customer_emails filter this module
     * depends on — but with a different (and internally inconsistent)
     * argument order/count than originally proposed, which fixCustomerEmails()
     * below defends against defensively rather than assuming a fixed shape.
     *
     * @var string
     */
    const REQUIRED_WOOCOMMERCE_VERSION = '1.0.18';

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $incompatibility = $this->checkCompatibility();

        if ($incompatibility) {
            $this->hooksIncompatible($incompatibility);

            return;
        }

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
     * Checks whether the running FreeScout core and WooCommerce module
     * versions are ones this module's fixCustomerEmails() has been verified
     * against. Returns a human-readable reason if not, or null if compatible.
     *
     * @return string|null
     */
    protected function checkCompatibility()
    {
        $app_version = config('app.version');

        if ($app_version && version_compare($app_version, self::REQUIRED_APP_VERSION, '<')) {
            return sprintf(
                'requires FreeScout %s or newer (found %s).',
                self::REQUIRED_APP_VERSION,
                $app_version
            );
        }

        $wc_version = $this->getWooCommerceModuleVersion();

        if (!$wc_version) {
            return 'requires the WooCommerce module to be installed.';
        }

        if (version_compare($wc_version, self::REQUIRED_WOOCOMMERCE_VERSION, '<')) {
            return sprintf(
                'requires the WooCommerce module %s or newer (found %s).',
                self::REQUIRED_WOOCOMMERCE_VERSION,
                $wc_version
            );
        }

        return null;
    }

    /**
     * Reads the installed WooCommerce module's own version straight out of
     * its module.json, since FreeScout has no API to query another module's
     * version and module.json's "requires" only checks that an alias is
     * active, not any particular version of it.
     *
     * @return string|null
     */
    protected function getWooCommerceModuleVersion()
    {
        $path = base_path('Modules/WooCommerce/module.json');

        if (!file_exists($path)) {
            return null;
        }

        $data = json_decode(file_get_contents($path), true);

        return $data['version'] ?? null;
    }

    /**
     * Registered instead of hooks() when checkCompatibility() fails. Doesn't
     * touch the woocommerce.customer_emails filter at all (the whole point
     * is that we can't trust its shape here) — just makes it obvious, right
     * where "Recent Orders" would otherwise appear, that this module is
     * active but doing nothing.
     *
     * @param string $reason
     * @return void
     */
    protected function hooksIncompatible($reason)
    {
        \Log::warning('[WooCommerceCustomerFix] Module is active but not functional: '.$reason);

        \Eventy::addAction('conversation.after_prev_convs', function ($customer, $conversation, $mailbox) use ($reason) {
            echo '<div class="text-danger small" style="padding:4px 15px;">'
                .'[WooCommerce Customer Fix] Not active: '.htmlspecialchars($reason)
                .' The "Recent Orders" email lookup below is NOT corrected.'
                .'</div>';
        }, 12, 3);
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
     * As of WooCommerce module 1.0.18, this filter's two call sites disagree
     * with each other (and with what was originally proposed) on both the
     * argument order and the argument count:
     *  - conversation.after_prev_convs fires it as
     *    ($customer_emails, $mailbox, $conversation, $customer)
     *  - WooCommerceController::ajax()'s 'orders' case fires it as
     *    ($customer_emails, $mailbox, $conversation_id) — only 3 args, no
     *    $customer, and a raw scalar (or null) instead of a Conversation.
     * So rather than binding fixed-position parameters, this identifies
     * $mailbox/$conversation by type out of a variadic tail — that also
     * means we never hit a PHP ArgumentCountError no matter how many extra
     * args either call site passes.
     *
     * @param array $customer_emails Emails the WooCommerce module would search for.
     * @param mixed ...$args Whatever extra context this call site passed — order and count vary, see above.
     * @return array
     */
    public function fixCustomerEmails($customer_emails, ...$args)
    {
        $mailbox = null;
        $conversation = null;

        foreach ($args as $arg) {
            if ($arg instanceof \App\Mailbox) {
                $mailbox = $arg;
            } elseif ($arg instanceof \App\Conversation) {
                $conversation = $arg;
            } elseif (!$conversation && (is_int($arg) || (is_string($arg) && ctype_digit($arg)))) {
                $conversation = \App\Conversation::find($arg);
            }
        }

        return $this->detectRealCustomerEmails(collect($customer_emails)->values()->all(), $mailbox, $conversation);
    }

    /**
     * The detection/override logic: fixCustomerEmails() only works out which
     * of its arguments are the mailbox and the conversation.
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
}
