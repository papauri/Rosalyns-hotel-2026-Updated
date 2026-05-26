<?php

/**
 * Credit note email template — variables injected by sendCreditNoteEmail().
 *
 * @var array  $cn               Credit note row from the database.
 * @var string $site_name        Hotel/property name.
 * @var string $currency_symbol  Currency prefix (e.g. "MWK").
 * @var string $hotel_phone      Contact phone number (may be empty).
 * @var string $hotel_address    Hotel address line (may be empty).
 */
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit Note <?php echo htmlspecialchars($cn['credit_note_number']); ?> — <?php echo htmlspecialchars($site_name); ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #F7F3EE;
            margin: 0;
            padding: 20px;
        }

        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background: #fff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .1);
        }

        .header {
            background: linear-gradient(135deg, #231F1C 0%, #2A2723 100%);
            padding: 36px 40px;
            text-align: center;
        }

        .logo {
            font-size: 30px;
            font-weight: bold;
            color: #B18247;
            margin: 0;
            font-family: 'Cormorant Garamond', Georgia, serif;
            letter-spacing: .06em;
        }

        .header-sub {
            color: #C8C0B8;
            font-size: 13px;
            margin: 6px 0 0;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .cn-badge {
            display: inline-block;
            background: #B18247;
            color: #fff;
            border-radius: 4px;
            padding: 6px 18px;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: .06em;
            margin: 20px 0 4px;
            text-transform: uppercase;
        }

        .content {
            padding: 36px 40px;
        }

        .greeting {
            font-size: 17px;
            color: #2A2723;
            margin-bottom: 12px;
        }

        .intro {
            color: #5E554D;
            font-size: 15px;
            margin-bottom: 24px;
        }

        .cn-summary {
            background: #F7F3EE;
            border-radius: 8px;
            padding: 24px;
            margin: 20px 0;
        }

        .cn-summary table {
            width: 100%;
            border-collapse: collapse;
        }

        .cn-summary td {
            padding: 7px 0;
            font-size: 14px;
        }

        .cn-summary td:first-child {
            color: #5E554D;
        }

        .cn-summary td:last-child {
            text-align: right;
            font-weight: 600;
            color: #2A2723;
        }

        .cn-value {
            font-size: 22px !important;
            color: #B18247 !important;
        }

        .cn-number-highlight {
            display: block;
            margin: 18px 0;
            background: #fff;
            border: 2px dashed #B18247;
            border-radius: 6px;
            padding: 14px 20px;
            font-size: 20px;
            font-weight: 700;
            text-align: center;
            color: #231F1C;
            letter-spacing: .08em;
        }

        .expiry-box {
            background: #FFF8F0;
            border-left: 4px solid #B18247;
            padding: 14px 18px;
            border-radius: 0 6px 6px 0;
            margin: 20px 0;
            font-size: 14px;
            color: #5E554D;
        }

        .expiry-box strong {
            color: #231F1C;
        }

        .cta-note {
            font-size: 15px;
            color: #5E554D;
            margin: 20px 0;
        }

        .cta-note strong {
            color: #231F1C;
        }

        .sign-off {
            color: #2A2723;
            font-size: 15px;
            margin-top: 28px;
        }

        .sign-off strong {
            font-size: 16px;
        }

        .footer {
            background: #F3ECE4;
            padding: 18px 40px;
            font-size: 11px;
            color: #8A7560;
            text-align: center;
            line-height: 1.7;
        }

        .footer a {
            color: #8A775F;
            text-decoration: none;
        }

        .reason-tag {
            display: inline-block;
            background: #EDE6DC;
            color: #5E554D;
            font-size: 12px;
            padding: 3px 10px;
            border-radius: 12px;
            margin-left: 4px;
            vertical-align: middle;
        }

        @media (max-width: 480px) {
            .content {
                padding: 24px 20px;
            }

            .header {
                padding: 28px 20px;
            }
        }
    </style>
</head>

<body>
    <div class="email-container">

        <!-- Header -->
        <div class="header">
            <h1 class="logo"><?php echo htmlspecialchars($site_name); ?></h1>
            <p class="header-sub">Credit Note</p>
            <div class="cn-badge"><?php echo htmlspecialchars($cn['credit_note_number']); ?></div>
        </div>

        <!-- Content -->
        <div class="content">
            <p class="greeting">Dear <strong><?php echo htmlspecialchars($cn['guest_name']); ?></strong>,</p>
            <p class="intro">
                We have issued you a credit note in connection with your stay or reservation at <?php echo htmlspecialchars($site_name); ?>.
                The full details are summarised below and a PDF copy is attached to this email for your records.
            </p>

            <!-- CN summary table -->
            <div class="cn-summary">
                <table>
                    <tr>
                        <td>Credit Note Number</td>
                        <td><?php echo htmlspecialchars($cn['credit_note_number']); ?></td>
                    </tr>
                    <tr>
                        <td>Date Issued</td>
                        <td><?php echo date('d F Y', strtotime($cn['issued_at'])); ?></td>
                    </tr>
                    <tr>
                        <td>Reason</td>
                        <td>
                            <?php
                            $reasonLabels = [
                                'cancellation'  => 'Booking Cancellation',
                                'service_issue' => 'Service Issue',
                                'early_checkout' => 'Early Checkout',
                                'overpayment'   => 'Overpayment',
                                'goodwill'      => 'Goodwill Gesture',
                                'pricing_error' => 'Pricing Correction',
                                'other'         => 'Other',
                            ];
                            echo htmlspecialchars($reasonLabels[$cn['reason']] ?? ucfirst(str_replace('_', ' ', $cn['reason'])));
                            ?>
                        </td>
                    </tr>
                    <?php if (!empty($cn['booking_reference'])): ?>
                        <tr>
                            <td>Booking Reference</td>
                            <td><?php echo htmlspecialchars($cn['booking_reference']); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td>Credit Value</td>
                        <td class="cn-value"><?php echo htmlspecialchars($currency_symbol); ?> <?php echo number_format((float)$cn['original_amount'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Available Balance</td>
                        <td class="cn-value"><?php echo htmlspecialchars($currency_symbol); ?> <?php echo number_format((float)$cn['balance'], 2); ?></td>
                    </tr>
                </table>
            </div>

            <!-- CN number highlight for easy reference -->
            <p style="font-size:13px;color:#8A7560;text-align:center;margin-bottom:4px;">Quote this reference at booking or front desk</p>
            <span class="cn-number-highlight"><?php echo htmlspecialchars($cn['credit_note_number']); ?></span>

            <!-- Expiry -->
            <div class="expiry-box">
                <?php if (!empty($cn['expires_at'])): ?>
                    <i class="fas fa-calendar-alt"></i>
                    This credit note is valid until <strong><?php echo date('d F Y', strtotime($cn['expires_at'])); ?></strong>.
                    Please ensure you redeem it before this date.
                <?php else: ?>
                    This credit note does not have an expiry date.
                <?php endif; ?>
            </div>

            <!-- Usage instructions -->
            <p class="cta-note">
                To redeem your credit, simply quote reference <strong><?php echo htmlspecialchars($cn['credit_note_number']); ?></strong>
                when making your next booking online, by phone, or at our front desk. Credit notes can be applied as full or
                partial payment towards room bookings and conference reservations.
            </p>

            <?php if (!empty($cn['reason_notes'])): ?>
                <p class="cta-note" style="font-style:italic;border-left:3px solid #EDE6DC;padding-left:14px;">
                    &ldquo;<?php echo htmlspecialchars($cn['reason_notes']); ?>&rdquo;
                </p>
            <?php endif; ?>

            <p class="sign-off">
                We look forward to welcoming you back soon.<br><br>
                Warm regards,<br>
                <strong><?php echo htmlspecialchars($site_name); ?></strong><br>
                <?php if (!empty($hotel_phone)): ?>
                    <span style="color:#8A7560;font-size:13px;"><?php echo htmlspecialchars($hotel_phone); ?></span>
                <?php endif; ?>
            </p>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>
                Credit notes are issued in the name of the guest and are non-transferable.<br>
                They cannot be exchanged for cash. Reference: <?php echo htmlspecialchars($cn['credit_note_number']); ?>
            </p>
            <?php if (!empty($hotel_address)): ?>
                <p><?php echo htmlspecialchars($hotel_address); ?></p>
            <?php endif; ?>
            <p style="margin-top:8px;color:#A89880;">
                This email was sent by <?php echo htmlspecialchars($site_name); ?> — please do not reply directly to this address.
            </p>
        </div>

    </div>
</body>

</html>
