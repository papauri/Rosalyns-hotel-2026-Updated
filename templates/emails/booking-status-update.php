<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Status Update - <?php echo $site_name; ?></title>
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
                font-family: 'Cormorant Garamond', Georgia, serif;
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
            font-size: 28px;
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
            font-size: 26px;
        }
        .status-badge {
            display: inline-block;
            padding: 12px 30px;
            border-radius: 30px;
            font-size: 18px;
            font-weight: bold;
            margin: 20px 0;
        }
        .status-confirmed {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            color: white;
        }
        .status-checked-in {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
        }
        .status-checked-out {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
        }
        .status-cancelled {
            background: linear-gradient(135deg, #dc3545 0%, #bd2130 100%);
            color: white;
        }
        .status-pending {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            color: #1A1A1A;
        }
        .details {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
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
        .info-box {
            background-color: #d1ecf1;
            border-left: 4px solid #17a2b8;
            padding: 15px 20px;
            margin: 20px 0;
            border-radius: 5px;
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
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <div class="logo"><?php echo $site_name; ?></div>
        </div>
        
        <div class="content">
            <h1>📋 Booking Status Update</h1>
            <p style="margin: 0 0 20px 0;">Dear <?php echo $guest_name; ?>,</p>
            <p>The status of your booking has been updated.</p>
            
            <div class="status-badge status-<?php echo strtolower($new_status); ?>">
                <?php echo $new_status; ?>
            </div>
            
            <div class="details">
                <div class="detail-row">
                    <span class="detail-label">Booking Reference:</span>
                    <span class="detail-value"><?php echo $booking_reference; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Room:</span>
                    <span class="detail-value"><?php echo $room_name; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Check-in Date:</span>
                    <span class="detail-value"><?php echo $check_in_date; ?></span>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <span class="detail-label">Check-out Date:</span>
                    <span class="detail-value"><?php echo $check_out_date; ?></span>
                </div>
            </div>
            
            <?php if ($new_status === 'confirmed'): ?>
            <div class="info-box">
                <p style="margin: 0;">✅ Your booking has been confirmed. We look forward to welcoming you!</p>
            </div>
            <?php elseif ($new_status === 'checked-in'): ?>
            <div class="info-box">
                <p style="margin: 0;">🎉 You've been checked in. We hope you enjoy your stay with us!</p>
            </div>
            <?php elseif ($new_status === 'checked-out'): ?>
            <div class="info-box">
                <p style="margin: 0;">🏨 Thank you for staying with us! We hope to welcome you back soon.</p>
            </div>
            <?php elseif ($new_status === 'cancelled'): ?>
            <div class="info-box" style="background-color: #f8d7da; border-left-color: #dc3545;">
                <p style="margin: 0;">⚠️ This booking has been cancelled. If you believe this is an error, please contact us immediately.</p>
            </div>
            <?php endif; ?>
            
            <div class="contact-box">
                <h3>📞 Have Questions?</h3>
                <div class="contact-item">
                    <strong>Phone:</strong> <a href="tel:<?php echo $phone_main; ?>" style="color: #1A1A1A; text-decoration: none;"><?php echo $phone_main; ?></a>
                </div>
                <div class="contact-item">
                    <strong>Email:</strong> <a href="mailto:<?php echo $email_reservations; ?>" style="color: #1A1A1A; text-decoration: none;"><?php echo $email_reservations; ?></a>
                </div>
            </div>
            
            <p style="margin-top: 30px; font-size: 13px; color: #666; line-height: 1.8;">
                Please keep your booking reference: <strong><?php echo $booking_reference; ?></strong> for any future communications.<br>
                If you need to make any changes to your reservation, please contact us as soon as possible.
            </p>
        </div>
        
        <div class="footer">
            <p style="margin: 0;">&copy; 2026 <?php echo $site_name; ?>. All rights reserved.</p>
            <p style="margin: 5px 0 0 0;">This email was sent to <?php echo $guest_name; ?> (<?php echo $booking['guest_email'] ?? ''; ?>)</p>
        </div>
    </div>
</body>
</html>