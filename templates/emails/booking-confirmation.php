<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmed - <?php echo $site_name; ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #1A1A1A 0%, #2A2A2A 100%);
            padding: 40px 40px;
            text-align: center;
        }
        .logo {
            font-size: 32px;
            font-weight: bold;
            color: #8B7355;
            margin: 0;
            font-family: 'Cormorant Garamond', Georgia, serif;
        }
        .content {
            padding: 40px;
        }
        h1 {
            color: #1A1A1A;
            margin: 0 0 10px 0;
            font-size: 28px;
        }
        h2 {
            color: #8B7355;
            font-size: 20px;
            margin: 30px 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #8B7355;
        }
        .booking-reference {
            background-color: #f8f9fa;
            padding: 15px;
            border-left: 4px solid #8B7355;
            margin: 20px 0;
            font-size: 16px;
        }
        .booking-reference strong {
            color: #1A1A1A;
            font-size: 18px;
        }
        .details-grid {
            margin: 20px 0;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            color: #666;
            font-weight: 600;
        }
        .detail-value {
            color: #1A1A1A;
            font-weight: 600;
        }
        .special-requests {
            background-color: #fff9e6;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            border-left: 3px solid #ffc107;
        }
        .special-requests h3 {
            margin: 0 0 10px 0;
            font-size: 16px;
            color: #856404;
        }
        .payment-info {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .payment-info h3 {
            margin: 0 0 10px 0;
            font-size: 18px;
        }
        .contact-box {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .contact-box h3 {
            margin: 0 0 15px 0;
            color: #1A1A1A;
        }
        .contact-item {
            margin: 10px 0;
            color: #666;
        }
        .contact-item strong {
            color: #1A1A1A;
        }
        .footer {
            background-color: #1A1A1A;
            color: #fff;
            padding: 20px;
            text-align: center;
                font-family: 'Cormorant Garamond', Georgia, serif;
        }
        .footer a {
            color: #8B7355;
            text-decoration: none;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #8B7355 0%, #6B5740 100%);
            color: #1A1A1A;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            margin-top: 20px;
        }
        .cta-button:hover {
            opacity: 0.9;
        }
        @media only screen and (max-width: 600px) {
            body {
                padding: 10px;
            }
            .header, .content {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <div class="logo"><?php echo $site_name; ?></div>
        </div>
        
        <div class="content">
            <h1>🎉 Booking Confirmed!</h1>
            <p style="margin: 0 0 20px 0;">Dear <?php echo $guest_name; ?>,</p>
            <p>Thank you for choosing <?php echo $site_name; ?>. Your reservation has been confirmed and we're excited to welcome you!</p>
            
            <div class="booking-reference">
                <strong>Booking Reference:</strong> <strong><?php echo $booking_reference; ?></strong>
            </div>
            
            <h2>📋 Booking Details</h2>
            
            <div class="details-grid">
                <div class="detail-row">
                    <span class="detail-label">Room:</span>
                    <span class="detail-value"><?php echo $room_name; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Check-in Date:</span>
                    <span class="detail-value"><?php echo $check_in_date; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Check-out Date:</span>
                    <span class="detail-value"><?php echo $check_out_date; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Number of Nights:</span>
                    <span class="detail-value"><?php echo $number_of_nights; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Number of Guests:</span>
                    <span class="detail-value"><?php echo $number_of_guests; ?></span>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <span class="detail-label">Total Amount:</span>
                    <span class="detail-value" style="color: #8B7355; font-size: 20px;"><?php echo $total_amount; ?></span>
                </div>
            </div>
            
            <?php if ($special_requests): ?>
            <div class="special-requests">
                <h3>📝 Special Requests</h3>
                <?php echo nl2br(htmlspecialchars($special_requests)); ?>
            </div>
            <?php endif; ?>
            
            <div class="payment-info">
                <h3>💳 Payment Information</h3>
                <p style="margin: 0;"><?php echo getSetting('payment_policy', 'Payment will be collected at the hotel upon arrival. We accept cash payments only.'); ?></p>
                <p style="margin: 10px 0 0 0; font-weight: 600;">Total to pay: <?php echo $total_amount; ?></p>
            </div>
            
            <div class="contact-box">
                <h3>📞 Need Assistance?</h3>
                <div class="contact-item">
                    <strong>Phone:</strong> <a href="tel:<?php echo $phone_main; ?>" style="color: #1A1A1A; text-decoration: none;"><?php echo $phone_main; ?></a>
                </div>
                <div class="contact-item">
                    <strong>Email:</strong> <a href="mailto:<?php echo $email_reservations; ?>" style="color: #1A1A1A; text-decoration: none;"><?php echo $email_reservations; ?></a>
                </div>
                <?php if ($whatsapp_number): ?>
                <div class="contact-item">
                    <strong>WhatsApp:</strong> <a href="https://wa.me/<?php echo $whatsapp_number; ?>" style="color: #25d366; text-decoration: none;" target="_blank">Chat with us</a>
                </div>
                <?php endif; ?>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <a href="<?php echo getSetting('site_url', '#'); ?>" class="cta-button">🏨 View Our Hotel</a>
            </div>
            
            <p style="margin-top: 30px; font-size: 13px; color: #666; line-height: 1.8;">
                Please save your booking reference: <strong><?php echo $booking_reference; ?></strong>.<br>
                We recommend arriving at your convenience before your check-in time.<br>
                If you have any questions or need to modify your reservation, please don't hesitate to contact us.
            </p>
        </div>
        
        <div class="footer">
            <p style="margin: 0;">&copy; 2026 <?php echo $site_name; ?>. All rights reserved.</p>
            <p style="margin: 5px 0 0 0;">This email was sent to <?php echo $guest_email; ?></p>
        </div>
    </div>
</body>
</html>