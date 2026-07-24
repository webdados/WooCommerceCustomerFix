# WooCommerce Customer Fix for Freescout

Freescout module that fixes the official [WooCommerce module](https://freescout.net/module/woocommerce/)'s "Recent Orders" sidebar for conversations that were created by an email sent **from the mailbox's own address to the real customer, with the mailbox Bcc'd** (for example, forwarding/Bcc'ing a WooCommerce order-confirmation email to the Freescout mailbox so it's logged as a conversation).

In that scenario Freescout core assigns the conversation's customer from the message's `From` address, which is the shop's own mailbox address — not the real recipient (this is standard Freescout behaviour, not a bug specific to WooCommerce: `app/Console/Commands/FetchEmails.php`'s `saveCustomerThread()` always sets `$conversation->customer_email = $from`, with no check for `$from` being one of the mailbox's own addresses). The WooCommerce module then searches WooCommerce for the shop's own email instead of the customer's, so "Recent Orders" always shows "No orders found".

This module doesn't change which customer a conversation is assigned to (that's a much bigger change, with a much bigger blast radius, since customer identity is used everywhere in Freescout — out of scope for a single-purpose module). It only corrects the email(s) the WooCommerce module searches for, by detecting when the assigned customer is entirely one of the mailbox's own addresses and, if so, using the real recipient's address from the conversation's first message instead.

**Temporary debug indicator:** while this fix is being validated, the module also shows a small `[DEBUG] ...` notice right under the "Recent Orders" panel whenever it overrides the email(s) used for the lookup, so it's obvious at a glance whether the fix kicked in for a given conversation (see `showDebugIndicator()` in `WooCommerceCustomerFixServiceProvider.php`). It's self-contained in this module (no WooCommerce-module changes needed for it) and only reflects the initial page render, not the sidebar's "Refresh" link. This is scaffolding, not a feature — it'll be removed once the fix is confirmed working reliably, and is **not** part of the WooCommerce-module patch proposed below.

**Compatibility gate:** this module checks the running FreeScout core version and the installed WooCommerce module's own version at boot, and refuses to hook anything unless both meet the minimums it's been verified against. If either is too old (or the WooCommerce module isn't installed), the module stays active but inert, and shows a visible `[WooCommerce Customer Fix] Not active: ...` notice right where "Recent Orders" would appear, so it's never silently doing nothing — see "Version compatibility" below.

PRs are welcome.

## Requirements

- FreeScout core **1.8.229** or newer.
- The official **WooCommerce** module, installed, active, and at version **1.0.18** or newer — that's the first release to fire the `woocommerce.customer_emails` filter this module hooks into. No manual patching of the WooCommerce module is needed anymore (see below).

## Version compatibility

We originally proposed the `woocommerce.customer_emails` filter to the FreeScout team as a patch (see git history for the patch this module used to require — the section below documents what we asked for). They implemented it in the official module starting with **1.0.18**, but not with the exact signature we proposed, and not consistently between its two call sites:

- `conversation.after_prev_convs` (initial page render) fires it as
  `Eventy::filter('woocommerce.customer_emails', $customer_emails, $mailbox, $conversation, $customer)`.
- `WooCommerceController::ajax()`'s `'orders'` case (the sidebar's "Refresh" link) fires it as
  `Eventy::filter('woocommerce.customer_emails', $customer_emails, $mailbox, $conversation_id)` — only 3 args, no `$customer`, and a raw conversation ID (or `null`, since `module.js`/`orders.blade.php` no longer pass it) instead of a `Conversation` object.

Trusting either shape positionally would break the other call site — including with a fatal error, not just a wrong result (see commit history for the full analysis). So `fixCustomerEmails()` no longer binds fixed-position parameters at all: it takes `($customer_emails, ...$args)` and identifies `$mailbox`/`$conversation` by `instanceof`/type out of the variadic tail, which is safe regardless of how many extra args a given FreeScout version passes.

Because this argument shape is themselves not something FreeScout has committed to keeping stable, `REQUIRED_APP_VERSION`/`REQUIRED_WOOCOMMERCE_VERSION` in `WooCommerceCustomerFixServiceProvider.php` pin this module to versions it's actually been verified against, and it goes inert (with a visible warning) rather than guessing on anything older or newer-but-unverified.

We flagged the call-site inconsistency to the FreeScout team; their response was that call sites should type-check `conversation_id`/resolve `$customer` themselves rather than expecting the two hooks to agree, i.e. they don't plan to align the signatures. `fixCustomerEmails()` already does both, so no further action is needed on our side there.

**Known limitation:** because `conversation_id` is never actually passed through to the ajax `'orders'` case anymore (dropped from `module.js`/`orders.blade.php`), `fixCustomerEmails()` can never resolve a `$conversation` on the sidebar's "Refresh" click — it always bails there. This happens to be harmless today only because the ajax request re-sends `$request->customer_emails`, which is the client's cached copy of the *already-corrected* array from the initial page render, so bailing on an already-correct value is a no-op. This is incidental, not guaranteed: if a future WooCommerce module release starts recomputing `customer_emails` server-side on that ajax path (instead of trusting the request payload) rather than reusing the value the client sent, this fix would silently stop applying on "Refresh" with no error — worth checking for if "Recent Orders" ever seems to regress specifically after clicking Refresh in a future WooCommerce module version.

## Changes previously needed on the WooCommerce module (obsolete since 1.0.18)

This section is kept for historical reference — it no longer needs to be applied to any WooCommerce module version 1.0.18 or newer, since the filter now ships in the stock module (with the different signature documented above).

The stock WooCommerce module used to have no extension point around which email address(es) it searches WooCommerce for — it always used every email cached on the conversation's assigned `Customer` record, with no way for another module to intervene.

The patch below is what we submitted, and adds a single `woocommerce.customer_emails` filter, fired right after the WooCommerce module computes that list of email(s) and before it's used to query the store (both on initial page load and when the sidebar's "Refresh" link is clicked).

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

## Filter signature (as originally proposed)

```php
\Eventy::filter('woocommerce.customer_emails', $customer_emails, $customer, $conversation, $mailbox);
```

| Argument | Type | Description |
|---|---|---|
| `$customer_emails` | `array` | Email address(es) the WooCommerce module is about to search WooCommerce for. Return the (possibly corrected) array to use instead. |
| `$customer` | `\App\Customer\|null` | The conversation's currently assigned customer. |
| `$conversation` | `\App\Conversation\|null` | The conversation being displayed/refreshed. `null` is only possible from the ajax path if the request didn't include a valid `conversation_id`. |
| `$mailbox` | `\App\Mailbox\|null` | The conversation's mailbox. |

**This is not what shipped.** See "Version compatibility" above for the actual (and inconsistent, between call sites) shape in WooCommerce module 1.0.18+. `fixCustomerEmails()` in `Providers/WooCommerceCustomerFixServiceProvider.php` handles that reality — it doesn't assume this original signature.

## Installation
* Update the WooCommerce module to 1.0.18 or newer (no manual patching needed — see "Version compatibility" above)
* Download the latest version of this module from this repository
* Upload/extract to the Modules folder on your Freescout install, inside a folder named WooCommerceCustomerFix
* Go to Manage > Settings > Tools and Clear Cache
* Go to Modules and activate "WooCommerce Customer Fix"

If either FreeScout core or the WooCommerce module is older than this module requires, activation will succeed but the module stays inert — watch for the `[WooCommerce Customer Fix] Not active: ...` notice under "Recent Orders" (see "Version compatibility" above).

No further configuration is needed.

## To do
* Nothing planned — open an issue or PR if you'd like something added. (The two `woocommerce.customer_emails` call sites in 1.0.18 still disagree with each other on argument order/count; this was flagged to the FreeScout team, who responded that call sites should type-check `conversation_id` themselves and fetch `$customer` independently if needed — i.e. they don't plan to align the signatures. `fixCustomerEmails()` already does both, so no further action is needed here.)
