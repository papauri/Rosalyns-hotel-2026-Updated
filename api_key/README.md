# API Key Testing Guide

This guide helps you test the Rosalyn's Hotel Booking API using your API key. The test page provides a simple interface to verify that your API integration is working correctly.

## 📋 Prerequisites

Before you start testing, make sure you have:

1. **A Test API Key** - You need a valid API key from the hotel administrator
   - The API key is used for authentication via the `X-API-Key` header
   - Test keys typically have the `availability.check` and `bookings.create` permissions

2. **API Base URL** - The URL of your hotel website
   - Example: `https://your-domain.com` or `http://localhost:8000` (for local testing)
   - Do NOT include `/api/` at the end - the test page adds this automatically

3. **Sample Room ID** - A valid room ID to test with
   - You can find room IDs by querying the `/api/rooms` endpoint
   - Common test room IDs are `1`, `2`, `3`, etc.

4. **Valid Test Dates** - Future dates for check-in and check-out
   - Check-in must be today or in the future
   - Check-out must be after check-in
   - Format: `YYYY-MM-DD` (e.g., `2026-02-20`)

---

## 🚀 How to Open the Test Page

### Option 1: Direct File Access
1. Navigate to the `API_KEY` folder in your project
2. Double-click `test-api-key.html` to open it in your default browser
3. Or right-click and select "Open with" → Your preferred browser

### Option 2: Via Local Web Server (Recommended)
If you have a local web server running:
```
http://localhost:8000/API_KEY/test-api-key.html
```

### Option 3: Via Live Server (VS Code)
1. Install the "Live Server" extension in VS Code
2. Right-click `test-api-key.html`
3. Select "Open with Live Server"

---

## ✅ How to Test Availability

The availability check verifies if a room is available for specific dates.

### Step-by-Step:

1. **Enter API Configuration**
   - **API Base URL**: Enter your hotel's base URL (e.g., `https://rosalynshotel.com`)
   - **API Key**: Paste your test API key

2. **Fill in Availability Parameters**
   - **Room ID**: Enter a valid room ID (e.g., `1`)
   - **Check-in Date**: Select a future date (e.g., tomorrow)
   - **Check-out Date**: Select a date after check-in (e.g., day after tomorrow)

3. **Click "Check Availability"**

4. **Review the Response**
   - **Success (200-299)**: Green status with room details
   - **Error (400+)**: Red status with error message

### Example Request:
```
GET /api/availability?room_id=1&check_in=2026-02-20&check_out=2026-02-22
```

### Example Success Response:
```json
{
  "success": true,
  "message": "Room available",
  "data": {
    "available": true,
    "room": {
      "id": 1,
      "name": "Deluxe Room",
      "price_per_night": 150.00,
      "max_guests": 2,
      "rooms_available": 5
    },
    "dates": {
      "check_in": "2026-02-20",
      "check_out": "2026-02-22",
      "nights": 2
    },
    "pricing": {
      "price_per_night": 150.00,
      "total": 300.00,
      "currency": "MK",
      "currency_code": "MWK"
    }
  }
}
```

---

## 📝 How to Test Booking Creation

Creating a booking actually adds a record to the database. Use test data only!

### Step-by-Step:

1. **Enter API Configuration** (if not already entered)

2. **Fill in Booking Details**
   
   **Required Fields:**
   - **Room ID**: Valid room ID (e.g., `1`)
   - **Guest Name**: Test customer name (e.g., `Test Customer`)
   - **Guest Email**: Valid email format (e.g., `test@example.com`)
   - **Guest Phone**: Phone number (e.g., `+265123456789`)
   - **Number of Guests**: Total guests (e.g., `2`)
   - **Check-in Date**: Future date
   - **Check-out Date**: Date after check-in

   **Optional Fields:**
   - **Guest Country**: Country name (e.g., `Malawi`)
   - **Guest Address**: Street address
   - **Child Guests**: Number of children (default: `0`)
   - **Occupancy Type**: `single`, `double`, or `triple` (default: `double`)
   - **Special Requests**: Any notes (e.g., `Test booking via API`)

3. **Click "Create Booking"**

4. **Review the Response**
   - **Success (201)**: Booking created with booking reference
   - **Error (400+)**: Validation error or availability issue

### Example Request:
```json
POST /api/bookings
{
  "room_id": 1,
  "guest_name": "Test Customer",
  "guest_email": "test@example.com",
  "guest_phone": "+265123456789",
  "guest_country": "Malawi",
  "number_of_guests": 2,
  "child_guests": 0,
  "occupancy_type": "double",
  "check_in_date": "2026-02-20",
  "check_out_date": "2026-02-22",
  "special_requests": "Test booking via API"
}
```

### Example Success Response:
```json
{
  "success": true,
  "message": "Booking created successfully",
  "data": {
    "booking": {
      "id": 123,
      "booking_reference": "LSH20260001",
      "status": "pending",
      "room": {
        "id": 1,
        "name": "Deluxe Room"
      },
      "guest": {
        "name": "Test Customer",
        "email": "test@example.com"
      },
      "dates": {
        "check_in": "2026-02-20",
        "check_out": "2026-02-22",
        "nights": 2
      },
      "pricing": {
        "total_amount": 300.00,
        "currency": "MK"
      }
    },
    "next_steps": [...]
  }
}
```

---

## ⚠️ Common Error Codes

| Status Code | Meaning | What to Do |
|-------------|---------|------------|
| **400** | Bad Request | Check your input parameters (dates, IDs, formats) |
| **401** | Unauthorized | Your API key is missing or invalid |
| **403** | Forbidden | Your API key lacks required permissions |
| **404** | Not Found | Room ID doesn't exist or is inactive |
| **409** | Conflict | Room is not available for selected dates |
| **422** | Validation Error | Required fields are missing or invalid |
| **429** | Rate Limit Exceeded | Too many requests - wait before trying again |
| **500** | Server Error | Internal server error - contact administrator |
| **503** | Service Unavailable | Booking system is disabled |

### Common Validation Errors:

| Error | Solution |
|-------|----------|
| `Room ID is required` | Enter a valid room ID |
| `Check-in date cannot be in the past` | Use today or a future date |
| `Check-out date must be after check-in date` | Select a later check-out date |
| `Invalid email address` | Use a valid email format (e.g., user@domain.com) |
| `This room can accommodate maximum X guests` | Reduce the number of guests |
| `This room is not available for the selected dates` | Choose different dates or room |

---

## 🔒 Safety Notes

### API Key Security:
- **NEVER** share your API key publicly
- **DO NOT** commit API keys to version control (Git)
- **DO NOT** expose API keys in client-side production code
- **USE** environment variables or secure configuration for production
- **ROTATE** API keys if they are accidentally exposed

### Testing Best Practices:
1. Use **test data only** - fake names, emails, and phone numbers
2. Use **future dates** that you can easily identify as test bookings
3. **Cancel or delete** test bookings after testing
4. **Monitor** your rate limit usage to avoid being blocked
5. **Keep records** of your test booking references for cleanup

### Production Deployment:
When moving to production:
- Use a **separate production API key**
- Implement **proper error handling** in your application
- Add **retry logic** for rate limit errors (429)
- Consider **webhook notifications** for booking confirmations
- Implement **idempotency keys** to prevent duplicate bookings

---

## 📚 Additional Resources

### API Endpoints Reference:
- `GET /api/rooms` - List all available rooms
- `GET /api/availability` - Check room availability
- `POST /api/bookings` - Create a new booking
- `GET /api/bookings?id={id}` - Get booking status by reference

### Authentication:
All API requests require the `X-API-Key` header:
```javascript
headers: {
  "Content-Type": "application/json",
  "X-API-Key": "your-api-key-here"
}
```

### Rate Limiting:
- Each API key has a configurable rate limit (requests per hour)
- Default rate limit: 100 requests per hour
- Rate limit resets every hour
- Exceeding the limit returns a `429` status code

---

## 🆘 Troubleshooting

### "Network error" message:
- Check your API Base URL is correct
- Verify your server is running and accessible
- Check browser console for CORS errors

### "Invalid API key" error:
- Verify the API key is correct (no extra spaces)
- Check with admin that the key is active
- Ensure the key has required permissions

### "Room not available" error:
- The room may be booked for those dates
- Try different dates or a different room
- Check the room is active in the database

### "Rate limit exceeded" error:
- Wait for the rate limit window to reset (1 hour)
- Contact admin to increase your rate limit
- Implement request throttling in your code

---

## 📞 Support

If you encounter issues not covered in this guide:
- Contact the hotel system administrator
- Check the API documentation for more details
- Review server logs for additional error information
