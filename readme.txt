=== WPHEKA Edit & Cancel Orders for WooCommerce ===
Contributors: wpheka, akshayaswaroop
Tags: edit order, change order, cancel order, modify order, woocommerce
Requires at least: 6.6
Tested up to: 7.1.4
Requires PHP: 7.4
Stable tag: 1.4.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Edit and cancel paid WooCommerce orders. Refunds and extra payments are settled for you. Customers can change or cancel their own orders.

== Description ==

WooCommerce locks an order the moment it is paid. After that, cancelling it or changing a size, a quantity or a delivery address means refunding by hand, working out tax and shipping again, then sending a new invoice. **WPHEKA Edit & Cancel Orders for WooCommerce** does all of that in a few clicks. You see a preview of every change before it is applied. Your customers can cancel or change their own orders too, within a time you set. You decide whether a cancellation needs your approval first.

It always moves the money:

* **Order costs less?** It refunds the difference through your payment gateway, with the items put back in stock. If your payment method can't refund automatically, you are told to send the money yourself.
* **Order costs more?** It creates a small linked order for the difference and emails your customer a link to pay it. The change is made when it is paid; until then the order ships as it was.
* **Nothing changes in price?** The change is made straight away.

You always see a preview first: what changes, what is refunded, what the customer pays and what happens to stock.

= Store owners can =

* Cancel a paid order from the order screen, with the refund made through the payment gateway (or flagged for you to send by hand) and the stock put back
* Change quantities, remove items and add products to Processing and On hold orders, with an optional price for added products
* Swap a variation, such as a size or colour, with the price difference worked out from what the customer actually paid (coupons included)
* Change the shipping or billing address, with shipping re-rated from your shipping zones and tax worked out again for the new address
* See linked balance orders on the order screen, copy the pay link, send it again or cancel it
* Approve or decline cancellation requests in **WPHEKA > Edit Orders**, next to a log of every change
* See which orders customers can still change: a "Changes open, 2 h left" badge in the orders list, a "Customer can still change" view and a note on the order screen. Pack those orders once the time is up.
* Never offer changes for chosen products or categories, such as made-to-order or final-sale items. Your own edits still work.
* Hold new orders while customers can change them (off by default). Paid orders wait as **Open for changes** instead of Processing, so tools that ship Processing orders don't pick them up early. When the time for changes ends, the order moves to Processing once. It moves earlier when the customer says all is good, a tracking number is added or you click **Release now**.
* See the last 30 days at a glance: orders edited, refunds, pay links, customer changes and cancellations, plus how many orders customers can change right now.

= Customers can =

Within the time you allow (one hour by default), from the thank-you page, My Account or the link in their order email:

* Cancel the order, with a reason. Cancel straight away with a refund or send you a request to approve.
* Change the delivery address, with any difference in shipping or tax shown before they confirm
* Change the billing address (never the billing email). You choose whether customers can change the delivery address, the billing address or both.
* Change a size or colour to another option that is in stock
* Edit their order note
* Say "All good, no changes needed" to close the window early

Changes stop as soon as the order ships: when it is completed, a tracking number is added (WooCommerce Shipment Tracking, Advanced Shipment Tracking, AfterShip) or WooCommerce marks it fulfilled. Customers with an account make changes while logged in. Guests use the link in their order email. As on WooCommerce's own order pages, a guest who comes back later from another browser first confirms the email address on the order, so a forwarded link alone can't redirect a parcel.

= The money is always handled correctly =

* Never changes a paid total without refunding or charging the difference.
* A paid order's totals are never changed directly. Decreases are real WooCommerce refunds, with tax split per tax rate, so your reports stay right.
* If your payment method can't refund automatically, the refund is still recorded with stock restored, the order is flagged "Manual refund needed" and you get an email. It never claims a refund happened when it didn't.
* Cash on delivery: changes adjust the amount to collect instead of moving money.
* Orders paid by authorization only are left alone until they are captured.

= Emails =

Seven WooCommerce emails, which you can turn on or off in WPHEKA > Edit Orders > Settings and reword in WooCommerce > Settings > Emails: order updated, balance due (with the pay link), manual refund needed, cancellation requested, order cancelled, cancellation declined and customer changed an order. The order confirmation email gets a "change or cancel" link while changes are allowed.

= Built for WooCommerce today =

* Works with High-Performance Order Storage (HPOS) on or off and with the Cart and Checkout blocks
* Works with classic and block themes
* Templates and emails can be overridden in your theme; developer hooks are documented
* A debug log you can share with support, with no names, emails or addresses in it
* No tracking and no notices outside its own screens

= For developers =

Every hook starts with `edit_orders_for_woocommerce_`. Added in 1.4.0:

* The hold: `should_hold`, `hold_started`, `hold_released`. The time for changes: `window_end`, `window_max_seconds`.
* Customer actions and the panel: `customer_changes`, `customer_action`, `customer_change_intercept`, `panel_args`, `panel_actions`, `panel_state`, `customer_preview`, `preview_after`, `balance_redirect`.
* Changes and settlement: `normalise_change`, `plan_change`, `can_change_item`, `plan_run`, `refund_handler`, and the plan's methods `add_refund_line()`, `add_balance_item()`, `add_description()`, `add_error()`, `set_extra()` and `get_extra()`. A shipping method can now change on its own in an address change.
* Cancellations: `cancel_intercept`, `cancel_request_details`, `cancel_form_fields`, `cancel_refund`, `execute_cancel`, `request_summary`, `activity_requests`, `activity_requests_heading`, `activity_decide`.
* The editor and emails: `admin_preview_after`, `admin_apply_args` (and a fourth argument on `before_apply`), `admin_address_tab`, `email_data`, `email_after_summary`.
* The Edit Orders page: `page_tabs` (with `page_tab_{key}`), `settings_sections`, `settings_after_sections`, `settings_save`, `page_links`.

Each hook has a docblock with its arguments where it fires.

== Installation ==

1. Install and activate WooCommerce.
2. Install "WPHEKA Edit & Cancel Orders for WooCommerce" from Plugins > Add New (or upload the zip), then activate it.
3. Go to WPHEKA > Edit Orders > Settings to choose what customers may change and for how long.
4. Open any paid Processing or On hold order and use **Edit items or address** in the "Edit order" box.

== Frequently Asked Questions ==

= Which payment methods can refund automatically? =

Any WooCommerce payment method that supports refunds, such as Stripe, WooPayments and PayPal Payments. For methods that can't (for example bank transfer), the refund is recorded and you are reminded to send the money yourself.

= How does the customer pay when an order costs more? =

They get an email with a link to pay the difference, using any payment method your store offers. When the customer makes the change themselves, they are taken straight to that page.

= Can customers cancel their own orders without my approval? =

Only if you choose it. By default every cancellation comes to you to approve or decline in WPHEKA > Edit Orders. The customer is emailed either way. Switch the setting to "Cancel and refund straight away" and the order is cancelled and refunded the moment the customer confirms. You can turn customer cancellation off completely and keep the other changes.

= Which orders can I edit or cancel? =

Processing and On hold orders by default. You can change that list in WPHEKA > Edit Orders > Settings. A Stripe payment that is only authorized must be captured first. Customers can't change an order once it ships: when it is completed, when a tracking number is added or when WooCommerce marks it fulfilled. You can still edit it yourself.

= What happens to stock and to the refund when an order is cancelled? =

The items go back into stock and the refund is made through the payment method the customer used, as an ordinary WooCommerce refund with tax split per rate. If the payment method cannot refund automatically, the refund is still recorded, the stock is still restored, the order is flagged "Manual refund needed" and you get an email. The plugin never says a refund happened when it did not.

= Does it change my tax reports? =

Refunds and balance orders are ordinary WooCommerce records with tax split per rate, so WooCommerce Analytics reports them correctly. When an address change moves an order to a different tax rate, one refund can hold both the tax given back and the tax charged. A few third-party invoice or tax-export plugins read WooCommerce's per-rate refund figures without their sign, so they may show the new rate's tax as refunded. Totals and Analytics are not affected.

= What does "Open for changes" mean for my other plugins? =

With the hold on, a paid order waits as Open for changes until the time for changes ends. WooCommerce counts it as paid: stock is reduced, Analytics counts the sale and the customer and store get their usual emails at payment. Plugins that act when an order reaches Processing (shipping labels, warehouse and fulfilment tools, your own integrations reading `status=processing`) see the order when it moves to Processing, not before. The hold is off by default.

= How do I send a log to support? =

Go to WPHEKA > Edit Orders > Settings, turn on **Debug log** and save. Repeat what went wrong. Then open WooCommerce > Status > Logs, choose the source `wpheka-edit-orders-for-woocommerce`, download the log and attach it to your support request.

The plugin does not write names, emails, phone numbers or addresses to the log. It records order numbers, amounts, payment method IDs and country and state codes. Errors and warnings are recorded even with Debug log off. The plugin sends nothing anywhere: you decide what to share. Turn Debug log off again when you are done.

== Screenshots ==

1. Every edit shows a preview first: what changes, what is refunded, what the customer pays and what happens to stock.
2. Edit or cancel a paid order from the order screen. A linked order collects the difference when the change costs more.
3. Customers cancel or change their own order from the thank-you page, with the time left shown.
4. A customer confirms an address change, with the extra shipping and tax shown before they pay.
5. Approve or decline cancellation requests, next to a log of every change.
6. Choose what customers can cancel or change and for how long.
7. Seven emails for edits, cancellations and balance payments, each one switchable.

== Changelog ==

= 1.4.3 - 2026-11-10 =
* Changed: the plugin is now called WPHEKA Edit & Cancel Orders for WooCommerce. The description now covers cancelling paid orders, with new FAQ answers and screenshot captions. No change to how the plugin works.

= 1.4.2 - 2026-11-02 =
* Fixed: when a change to a cash on delivery order costs more, the linked order for the difference no longer sends WooCommerce's "Processing order" email to the customer or "New order" email to the store. The customer's "order updated" email now says how much more they pay on delivery. The store's "customer changed" email says it too.
* Fixed: with Open for changes on, that linked order waits with the original order and moves to Processing when the original does, instead of going to Processing at once.
* Fixed: cancelling, refunding or failing an order from WooCommerce's own order screen now also cancels its linked orders still to be collected on delivery. Completing the order completes them, without an email.
* Improved: on the Activity tab such a change shows as "Extra amount added, paid on delivery" and no longer counts as a pay link. Older entries show as before.

= 1.4.1 - 2026-10-29 =
* Improved: notices from WordPress and other plugins no longer show on the WPHEKA > Edit Orders page, where they printed above the header and then jumped into place. They still show on every other screen.
* Improved: on the Activity tab, the result of approving or declining a cancellation shows with an icon. A decision that fails shows in red and is announced by screen readers.
* Fixed: on sites in a right-to-left language the options under a switch on the Settings tab were indented on the wrong side.

= 1.4.0 - 2026-10-29 =
* New: hold new orders while customers can change them (WPHEKA > Edit Orders > Settings > Customer changes, off by default). Paid orders wait in a new status, Open for changes, instead of Processing. They move to Processing once when the time for changes ends, when the customer says all is good, when a tracking number or fulfilment is added or when you click "Release now" in the Edit order box. Cash on delivery orders follow the same switch. Customers see "You can still change this order until 3:40 pm." Turning the hold off, deactivating or deleting the plugin moves every held order to Processing.
* New: two more numbers on the Edit Orders page: cancellations (by customers, and requests declined) and orders customers can change right now. The customer changes number says how many customers confirmed their order as it was.
* New: hooks for extensions, including Edit Orders Pro. See "For developers" above.
* Improved: an order with a cancellation request can be approved while it is Open for changes.

= 1.3.0 - 2026-10-27 =
* New: the orders list shows which orders customers can still change, with the time left ("Changes open, 2 h left"). A "Customer can still change" view lists only those orders. The order screen says until when. Nothing about the order changes.
* New: never offer customer changes for chosen products or categories (WPHEKA > Edit Orders > Settings). Customers see "This order can't be changed online. Please contact us." Your own edits still work.
* New: separate switches for customer changes to the shipping address and the billing address. Customers can now change the billing address from their order page when it is on. It is on for new stores. If you are updating, it starts off so your customers see no change until you turn it on. If you had turned address changes off, both switches stay off.
* Improved: when a customer changes an address, the private order note shows the old and new values of the fields that changed.
* Improved: notes for changes a customer made say so ("Order changed by the customer").

= 1.2.1 - 2026-10-14 =
* Fix: on sites in a right-to-left language (such as Arabic or Hebrew) the Edit Orders page sat 20px off the edge of the window and scrolled sideways. The page now mirrors fully: switches, arrows, the save bar and the plugin row.
* Fix: the "Customers can" option cards now show a focus ring when you reach them with the keyboard.
* Fix: closing "Getting started" is remembered even if you leave the page straight away.
* The old WooCommerce > Settings > Edit Orders tab no longer shows an unused Save button.

= 1.2.0 - 2026-10-14 =
* New: an Edit Orders page under the WPHEKA menu, with Settings, Activity and Help tabs. It replaces WooCommerce > Edit Orders. The settings moved here from WooCommerce > Settings > Edit Orders, which now links to the new page. Your settings are kept as they are.
* New: the last 30 days at a glance: orders edited, amount refunded, pay links sent and paid. It also counts the changes customers made themselves.
* New: turn each Edit Orders email on or off from the Settings tab.
* New: a getting-started checklist, a search box for settings and a warning before you leave with unsaved changes.
* The Settings tab needs the same permission as WooCommerce's own settings (manage_woocommerce). Staff who can edit orders without it see Activity and Help. They can still answer cancellation requests.
* Tested with WooCommerce 11.2.1.

= 1.1.0 - 2026-10-12 =
* New: a Debug log setting in WooCommerce > Settings > Edit Orders. With it on, every step of each change is recorded in WooCommerce > Status > Logs, to share with support. Errors and warnings are always recorded. The log has no names, emails or addresses. Nothing is sent anywhere.
* New: one ID per change in the log, from the preview to the payment of a balance order and its refunds.
* New: refunds in the log name the payment method and, when the payment gateway keeps one, its refund ID, to find them in the gateway's dashboard.
* New: an Edit Orders section in WooCommerce > Status > System Status with the plugin's settings, tax and payment set-up and counts of orders that need attention.
* Developers: the class Edit_Orders_For_WooCommerce_Log and the filters edit_orders_for_woocommerce_debug_log and edit_orders_for_woocommerce_refund_transaction_meta_keys.

= 1.0.1 - 2026-10-12 =
* New: a line with extra units or a price difference paid on a balance order can now be decreased, removed or swapped. The refund includes what was paid on the balance order.
* Fix: after a swap to a dearer option, extra units now pay the price difference too.
* Fix: a swap to a cheaper option after a dearer one now refunds the price difference that was paid.
* Fix: units on an unpaid cash on delivery balance order now count when the line is changed.
* Fix: with tax rounded at subtotal, refunded tax now matches the tax charged and the last line can always be removed.
* Fix: if applying a paid change stops part way, it is never applied twice and the order notes say what to check. A slow payment gateway is no longer mistaken for a stop.
* Fix: wrong order keys are now limited per order as well as per visitor. They never lock out the order's own customer.
* Tested with WooCommerce 11.2.0.

= 1.0.0 - 2026-10-12 =
* First release.

== Upgrade Notice ==

= 1.4.3 =
New name and description only. No change to how the plugin works.

= 1.4.2 =
Cash on delivery orders: the linked order for an extra amount no longer sends its own emails and now follows the original order.

= 1.4.1 =
Keeps other plugins' notices off the Edit Orders page and fixes the right-to-left settings layout.

= 1.4.0 =
Adds Open for changes (off by default): paid orders wait while customers can still change them, then move to Processing. Also new numbers on the Edit Orders page and hooks for extensions.

= 1.3.0 =
Shows which orders customers can still change, lets you exclude products and categories, and adds a billing address switch (off after the update until you turn it on).

= 1.2.1 =
Fixes the Edit Orders page on right-to-left sites and adds a keyboard focus ring to the option cards.

= 1.2.0 =
A redesigned Edit Orders page under the WPHEKA menu. Settings moved there and are kept as they are.

= 1.1.0 =
Adds a Debug log to share with support and an Edit Orders section in System Status.

= 1.0.1 =
Edit lines partly paid on a balance order, plus fixes to refund and tax amounts. Recommended for all stores.

= 1.0.0 =
First release.
