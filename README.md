# WooCommerce Customer Fix for Freescout

Freescout module that fixes the official [WooCommerce module](https://freescout.net/module/woocommerce/)'s "Recent Orders" sidebar for conversations that were created by an email sent **from the mailbox's own address to the real customer, with the mailbox Bcc'd** (for example, forwarding/Bcc'ing a WooCommerce order-confirmation email to the Freescout mailbox so it's logged as a conversation).

In that scenario Freescout core assigns the conversation's customer from the message's `From` address, which is the shop's own mailbox address — not the real recipient (this is standard Freescout behaviour, not a bug specific to WooCommerce: `app/Console/Commands/FetchEmails.php`'s `saveCustomerThread()` always sets `$conversation->customer_email = $from`, with no check for `$from` being one of the mailbox's own addresses). The WooCommerce module then searches WooCommerce for the shop's own email instead of the customer's, so "Recent Orders" always shows "No orders found".

This module doesn't change which customer a conversation is assigned to (that's a much bigger change, with a much bigger blast radius, since customer identity is used everywhere in Freescout — out of scope for a single-purpose module). It only corrects the email(s) the WooCommerce module searches for, by detecting when the assigned customer is entirely one of the mailbox's own addresses and, if so, using the real recipient's address from the conversation's first message instead.

PRs are welcome.

## Requirements

- The official **WooCommerce** module, installed and active.
- The WooCommerce module needs to expose the `woocommerce.customer_emails` filter hook this module hooks into (see "Changes needed on the WooCommerce module" below) — **this filter does not exist in the stock module as of v1.0.16 and needs to be added**, either by applying the patch below yourself or once/if it lands upstream.

## Changes needed on the WooCommerce module

The stock WooCommerce module has no extension point around which email address(es) it searches WooCommerce for — it always uses every email cached on the conversation's assigned `Customer` record, with no way for another module to intervene. This module can't work without one.

The patch below adds a single `woocommerce.customer_emails` filter, fired right after the WooCommerce module computes that list of email(s) and before it's used to query the store (both on initial page load and when the sidebar's "Refresh" link is clicked). It's a pure extension point: **with no third-party module hooking it, behaviour is 100% identical to the current stock module.**

This is intentionally the only change proposed to the WooCommerce module — it stays generic and reusable for any similar "which email do we actually search for" correction, rather than baking this specific Bcc-detection logic into the official module.

We intend to submit this patch to the Freescout/WooCommerce-module maintainers as a PR. Until it's merged (or if it never is), the four changes below need to be applied manually to your own copy of the WooCommerce module for this module to have any effect.

### Why this is safe to merge (zero impact when unused)

- Freescout's hook system (`tormjens/eventy`) implements `Eventy::filter($hook, $value, ...$args)` by returning `$value` unchanged when no listener is registered for `$hook` (see `Filter::fire()` in that package — the loop over listeners simply doesn't execute, and the untouched value is returned). So on any install without a module hooking `woocommerce.customer_emails`, every added line below is a no-op.
- The one non-additive change is `$customer->emails_cached->pluck('email')` → `->pluck('email')->all()` (an Eloquent `Collection` → plain PHP `array`). Every downstream use of that variable (`count()`, `foreach`, `json_encode()`, and `Cache` key string concatenation) behaves identically for both types.
- Everything else is purely additive: a new `conversation_id` view/JS/POST parameter, and a `$conversation`/`$customer` lookup in the ajax controller that's only used to build the filter's arguments. The one measurable cost on every install (hooked or not) is one extra indexed-PK `Conversation::find()` query per ajax "orders"/"Refresh" call — negligible next to the WooCommerce REST API HTTP round-trip that same request already makes.

### 1. `Providers/WooCommerceServiceProvider.php`

In the `conversation.after_prev_convs` action handler:

```diff
             // Check all customer emails.
-            $customer_emails = $customer->emails_cached->pluck('email');
+            $customer_emails = $customer->emails_cached->pluck('email')->all();
+
+            // Allow other modules to override which email(s) are used to look up orders
+            // (e.g. when the conversation's assigned customer is actually our own mailbox
+            // address, because the message was sent by us to the customer with us in Bcc).
+            $customer_emails = \Eventy::filter('woocommerce.customer_emails', $customer_emails, $customer, $conversation, $mailbox);

             if (!count($customer_emails)) {
                 return;
             }
```

A few lines further down, in the same handler, pass the conversation id to the view (needed by the ajax round-trip in change #2):

```diff
             echo \View::make('woocommerce::partials/orders', [
                 'orders'         => $orders,
                 'customer_emails' => $customer_emails,
+                'conversation_id' => $conversation->id,
                 'load'           => $load,
                 'url'            => \WooCommerce::getSanitizedUrl($url),
                 'admin_path'     => $admin_path,
             ])->render();
```

### 2. `Http/Controllers/WooCommerceController.php`

Add the `Conversation` import:

```diff
+use App\Conversation;
 use App\Mailbox;
 use Illuminate\Http\Request;
```

In `ajax()`, `case 'orders'`, resolve the conversation/customer and apply the same filter before the order-lookup loop, so the "Refresh" link is corrected too:

```diff
                 $mailbox = null;
                 if ($request->mailbox_id) {
                     $mailbox = Mailbox::find($request->mailbox_id);
                 }

+                $conversation = null;
+                if ($request->conversation_id) {
+                    $conversation = Conversation::find($request->conversation_id);
+                }
+                $customer = $conversation ? $conversation->customer : null;
+
+                // Allow other modules to override which email(s) are used to look up orders
+                // (e.g. when the conversation's assigned customer is actually our own mailbox
+                // address, because the message was sent by us to the customer with us in Bcc).
+                $customer_emails = \Eventy::filter('woocommerce.customer_emails', $request->customer_emails, $customer, $conversation, $mailbox);
+
                 $mailbox_api_enabled = \WooCommerce::isMailboxApiEnabled($mailbox);
                 $orders = [];

                 if (\WooCommerce::isApiEnabled() || $mailbox_api_enabled) {

-                    foreach ($request->customer_emails as $customer_email) {
+                    foreach ($customer_emails as $customer_email) {
```

### 3. `Resources/views/partials/orders.blade.php`

Pass the conversation id to the JS init call:

```diff
-    initWooCommerce({!! json_encode($customer_emails) !!}, {{ (int)$load }});
+    initWooCommerce({!! json_encode($customer_emails) !!}, {{ (int)$load }}, {{ (int)$conversation_id }});
```

### 4. `Public/js/module.js`

Carry the conversation id through to the ajax payload:

```diff
 var wc_customer_emails = [];
+var wc_conversation_id = 0;

-function initWooCommerce(customer_emails, load)
+function initWooCommerce(customer_emails, load, conversation_id)
 {
 	wc_customer_emails = customer_emails;
+	wc_conversation_id = conversation_id;

 	if (!Array.isArray(wc_customer_emails)) {
 		wc_customer_emails = [];
 	}
```

```diff
 	fsAjax({
 			action: 'orders',
 			customer_emails: wc_customer_emails,
+			conversation_id: wc_conversation_id,
 			mailbox_id: getGlobalAttr('mailbox_id')
 		},
```

## Filter signature

```php
\Eventy::filter('woocommerce.customer_emails', $customer_emails, $customer, $conversation, $mailbox);
```

| Argument | Type | Description |
|---|---|---|
| `$customer_emails` | `array` | Email address(es) the WooCommerce module is about to search WooCommerce for. Return the (possibly corrected) array to use instead. |
| `$customer` | `\App\Customer\|null` | The conversation's currently assigned customer. |
| `$conversation` | `\App\Conversation\|null` | The conversation being displayed/refreshed. `null` is only possible from the ajax path if the request didn't include a valid `conversation_id`. |
| `$mailbox` | `\App\Mailbox\|null` | The conversation's mailbox. |

This is exactly what this module (`WooCommerceCustomerFix`) hooks — see `Providers/WooCommerceCustomerFixServiceProvider.php` for the actual Bcc-detection logic built on top of it.

## Installation
* Apply the WooCommerce-module patch above (until/unless it's merged upstream)
* Download the latest version of this module from this repository
* Upload/extract to the Modules folder on your Freescout install, inside a folder named WooCommerceCustomerFix
* Go to Manage > Settings > Tools and Clear Cache
* Go to Modules and activate "WooCommerce Customer Fix"

No further configuration is needed.

## To do
* Submit the WooCommerce-module patch upstream to the Freescout team.
* Nothing else planned — open an issue or PR if you'd like something added.
