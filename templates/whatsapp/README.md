# WhatsApp Message Templates

These plain-text files are the master copy of every WhatsApp message the system sends.

## How it works

`renderWhatsAppTemplate($key, $vars)` looks for messages in this order:

1. File `templates/whatsapp/{key}.txt` (this folder)
2. `site_settings.whatsapp_tpl_{key}` (database override)
3. Built-in default in `includes/whatsapp-functions.php`

So editing a file here is the simplest way to change wording.

## Variables

Use `{{variable_name}}` placeholders. Standard booking variables:

- `{{hotel_name}}`, `{{hotel_phone}}`, `{{hotel_whatsapp}}`
- `{{guest_name}}`, `{{guest_phone}}`
- `{{booking_reference}}`, `{{room_name}}`
- `{{check_in_date}}`, `{{check_in_time}}`, `{{check_out_date}}`, `{{check_out_time}}`
- `{{nights}}`, `{{guests}}`, `{{adults}}`, `{{children}}`
- `{{total_amount}}` (already formatted with currency)
- `{{special_requests}}`, `{{occupancy_type}}`, `{{status}}`

Invoice templates (`payment_invoice`, `restaurant_receipt`) also accept:

- `{{invoice_number}}`, `{{invoice_url}}`
- `{{amount_paid}}`, `{{amount_due}}`, `{{payment_method}}`, `{{payment_date}}`
- `{{order_reference}}`, `{{order_total}}`, `{{change_due}}`, `{{order_summary}}`

## Activating Meta WhatsApp Business

The provider stack supports Meta Cloud API, Twilio and CallMeBot. To go live with Meta:

1. In `admin/whatsapp-settings.php` set `whatsapp_provider = meta` and toggle WhatsApp on.
2. Set `whatsapp_meta_access_token` (Meta Graph API token).
3. Set `whatsapp_meta_phone_number_id` (the phone number id from Meta Business Manager).
4. Set `whatsapp_hotel_number` to the hotel WhatsApp number in E.164 form (`+265…`).
5. (Optional) Approve matching template names in WhatsApp Manager. The free-text body sent here is fine for **session messages** to numbers that have messaged the business in the last 24 h. For business-initiated messages you must use approved templates.

Until those keys are set, all `sendWhatsApp*` functions return `success=false` with reason "Meta WhatsApp credentials not configured" — **no API calls are made and no charges are incurred**.
