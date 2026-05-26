<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check-in Reminder - <?php echo $site_name; ?></title>
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
            background: linear-gradient(135deg, #8B7355 0%, #6B5740 100%);
            padding: 40px 40px;
            text-align: center;
        }
        .logo {
            font-size: 32px;
            font-weight: bold;
            color: #1A1A1A;
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
        .reminder-banner {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            color: #1A1A1A;
            padding: 20px;
            border-radius: 8px;
                font-family: 'Cormorant Garamond', Georgia, serif;
            margin: 20px 0;
            font-size: 16px;
            font-weight: 600;
        }
        .booking-details {
            background-color: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            margin: 20px 0;
        }
        .booking-reference {
            background: white;
            padding: 15px 20px;
            border-radius: 6px;
            text-align: center;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .booking-reference strong {
            font-size: 24px;
            color: #8B7355;
            display: block;
        }
        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        .detail-item {
            padding: 12px;
            background: white;
            border-radius: 5px;
        }
        .detail-label {
            color: #666;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .detail-value {
            color: #1A1A1A;
            font-weight: 600;
        }
        .total-amount {
            grid-column: 1 / -1;
            background: linear-gradient(135deg, #8B7355 0%, #6B5740 100%);
            color: #1A1A1A;
            padding: 15px;
            border-radius: 6px;
            text-align: center;
        }
        .total-amount strong {
            display: block;
            font-size: 28px;
            margin-bottom: 5px;
        }
        .check-in-info {
            background-color: #e7f3ff;
            color: #1A1A1A;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #4338ca;
        }
        .check-in-info h3 {
            margin: 0 0 10px 0;
            font-size: 18px;
        }
        .check-in-info ul {
            margin: 10px 0 0 20px;
            padding: 0;
        }
        .check-in-info li {
            margin: 8px 0;
            line-height: 1.8;
        }
        .check-in-info li strong {
            color: #1A1A1A;
        }
        .payment-box {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .payment-box h3 {
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
            font-size: 12px;
        }
        @media only screen and (max-width: 600px) {
            body {
                padding: 10px;
            }
            .header, .content {
                padding: 20px;
            }
            .details-grid {
                grid-template-columns: 1fr;
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
            <h1>🎯 Check-in Reminder</h1>
            <p style="margin: 0 0 20px 0;">Dear <?php echo $guest_name; ?>,</p>
            <p>This is a friendly reminder that your check-in date is approaching. We're excited to welcome you to <?php echo $site_name; ?>!</p>
            
            <div class="reminder-banner">
                📅 Please arrive on <?php echo $check_in_date; ?> for check-in
            </div>
            
            <div class="booking-details">
                <div class="booking-reference">
                    <strong>Booking Reference:</strong><br>
                    <strong><?php echo $booking_reference; ?></strong>
                </div>
                
                <div class="details-grid">
                    <div class="detail-item">
                        <div class="detail-label">Check-in Date</div>
                        <div class="detail-value"><?php echo $check_in_date; ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Check-out Date</div>
                        <div class="detail-value"><?php echo date('F j, Y', strtotime($check_in_date)); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Total Amount Due</div>
                        <div class="detail-value" style="color: #8B7355; font-size: 18px;"><?php echo $total_amount; ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Payment Method</div>
                        <div class="detail-value">Cash on Arrival</div>
                    </div>
                </div>
                
                <div class="total-amount">
                    <strong>Total Due</strong>
                    <strong><?php echo $total_amount; ?></strong>
                </div>
            </div>
            
            <div class="check-in-info">
                <h3>🏨 Check-in Information</h3>
                <ul>
                    <li><strong>Check-in Time:</strong> <?php echo $check_in_time ?? getSetting('check_in_time', '2:00 PM'); ?> onwards</li>
                    <li><strong>Check-out Time:</strong> <?php echo $check_out_time ?? getSetting('check_out_time', '11:00 AM'); ?></li>
                    <li><strong>ID Required:</strong> Please bring a valid ID for registration</li>
                    <li><strong>Payment:</strong> <?php echo $payment_method ?? getSetting('payment_method', 'Cash payment to be made at check-in'); ?></li>
                    <li><strong>Early Check-in:</strong> Available upon request (subject to availability)</li>
                </ul>
            </div>
            
            <div class="payment-box">
                <h3>💳 Payment Details</h3>
                <p style="margin: 0;"><?php echo $payment_info ?? getSetting('payment_info', 'Payment will be collected at the hotel reception upon check-in.'); ?></p>
                <p style="margin: 10px 0 0 0;"><strong>Total amount to pay:</strong> <strong style="font-size: 20px; color: #1A1A1A;"><?php echo $total_amount; ?></strong></p>
                <p style="margin: 10px 0 0 0; font-size: 14px; opacity: 0.9;"><?php echo $payment_method_note ?? getSetting('payment_method_note', 'We accept cash payments only.'); ?></p>
            </div>
            
            <div class="contact-box">
                <h3>📞 Contact Us</h3>
                <div class="contact-item">
                    <strong>Address:</strong><br>
                    <?php 
                        $addressParts = array_filter($address);
                        echo implode('<br>', $addressParts); 
                    ?>
                </div>
                <div class="contact-item">
                    <strong>Phone:</strong> <a href="tel:<?php echo $phone_main; ?>" style="color: #1A1A1A; text-decoration: none;"><?php echo $phone_main; ?></a>
                </div>
                <div class="contact-item">
                    <strong>Email:</strong> <a href="mailto:<?php echo $email_reservations; ?>" style="color: #1A1A1A; text-decoration: none;"><?php echo $email_reservations; ?></a>
                </div>
            </div>
            
            <p style="margin-top: 30px; font-size: 14px; color: #666; line-height: 1.8;">
                Please have your booking reference <strong><?php echo $booking_reference; ?></strong> ready for quick check-in.<br>
                If you need to modify or cancel your reservation, please contact us at least <?php echo $cancellation_policy ?? getSetting('booking_change_policy', '48 hours'); ?> before your check-in date.<br>
                We look forward to making your stay memorable!
            </p>
        </div>
        
        <div class="footer">
            <p style="margin: 0;">&copy; 2026 <?php echo $site_name; ?>. All rights reserved.</p>
            <p style="margin: 5px 0 0 0;">This is an automated reminder email</p>
        </div>
    </div>
</body>
</html>