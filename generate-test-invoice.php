<?php
require 'config/email.php';
require 'config/invoice.php';

// Sync template to DB
$html = hotel_default_payment_invoice_document_html();
upsertBookingEmailTemplateConfig('payment_invoice_document', 'Payment Invoice', 'Invoice Confirmation', $html, '', 1);
echo "Template synced.\n";

// Generate invoice
$result = generateInvoicePDF(85);
if ($result) {
    echo "Invoice: {$result['relative_path']}\n";
} else {
    echo "Failed to generate invoice\n";
}
