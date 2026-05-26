$ErrorActionPreference = 'Stop'
$marker = 'E2E-' + (Get-Date -Format 'yyyyMMddHHmmss')
$publicSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession

# 1) Public form fetch + CSRF
$publicGet = Invoke-WebRequest -Uri 'http://localhost:8089/submit-review.php' -WebSession $publicSession -UseBasicParsing
$tokenMatch = [regex]::Match($publicGet.Content, 'name="_csrf_review"\s+value="([^"]+)"')
if (-not $tokenMatch.Success) { throw 'Could not find public CSRF token for submit-review form.' }
$csrf = $tokenMatch.Groups[1].Value

# 2) Public form submit
$reviewComment = "Automated end-to-end review check marker: $marker. Public submission validation and moderation flow test."
$submitBody = @{
  '_csrf_review' = $csrf
  'guest_name' = 'E2E Tester'
  'guest_email' = 'e2e.tester@example.com'
  'overall_rating' = '4'
  'review_title' = $marker
  'review_comment' = $reviewComment
  'review_type' = 'room'
  'room_id' = '2'
  'service_rating' = '4'
  'cleanliness_rating' = '4'
  'location_rating' = '4'
  'value_rating' = '4'
}
$submitResp = Invoke-WebRequest -Uri 'http://localhost:8089/submit-review.php?room_id=2' -Method Post -WebSession $publicSession -Body $submitBody -MaximumRedirection 5 -UseBasicParsing
$submitUrl = $submitResp.BaseResponse.ResponseUri.AbsoluteUri

# 3) Admin login
$adminSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest -Uri 'http://localhost:8089/admin/login.php' -WebSession $adminSession -UseBasicParsing
$null = Invoke-WebRequest -Uri 'http://localhost:8089/admin/login.php' -Method Post -WebSession $adminSession -Body @{ username='rosalyns_uat'; password='RosalynsUAT2026!' } -MaximumRedirection 5 -UseBasicParsing

# 4) Locate pending review in admin API
$pendingResp = Invoke-WebRequest -Uri 'http://localhost:8089/admin/api/reviews.php?status=pending&limit=200' -WebSession $adminSession -UseBasicParsing
$pendingJson = $pendingResp.Content | ConvertFrom-Json
$pendingMatch = $pendingJson.data.reviews | Where-Object { $_.title -eq $marker } | Select-Object -First 1
if (-not $pendingMatch) { throw "Submitted review not found in pending queue for marker $marker" }
$reviewId = [int]$pendingMatch.id

# 5) Approve review in admin API
$approvePayload = @{ review_id = $reviewId; status = 'approved' } | ConvertTo-Json
$approveResp = Invoke-WebRequest -Uri 'http://localhost:8089/admin/api/reviews.php' -Method Put -WebSession $adminSession -ContentType 'application/json' -Body $approvePayload -UseBasicParsing
$approveJson = $approveResp.Content | ConvertFrom-Json
if (-not $approveJson.success) { throw "Approve failed for review $reviewId" }

# 6) Verify approved appears publicly (room API + submit-review feedback section)
$publicApiResp = Invoke-WebRequest -Uri 'http://localhost:8089/api/reviews.php?room_id=2&status=approved&limit=200' -UseBasicParsing
$publicApiJson = $publicApiResp.Content | ConvertFrom-Json
$publicApiFound = @($publicApiJson.reviews | Where-Object { $_.comment -like "*$marker*" }).Count -gt 0

$publicFeedbackPage = Invoke-WebRequest -Uri 'http://localhost:8089/submit-review.php' -UseBasicParsing
$feedbackFound = $publicFeedbackPage.Content -like "*$marker*"

# 7) Verify admin approved listing also contains it
$adminPage = Invoke-WebRequest -Uri ("http://localhost:8089/admin/reviews.php?status=approved&search=" + [uri]::EscapeDataString($marker)) -WebSession $adminSession -UseBasicParsing
$adminFound = $adminPage.Content -like "*$marker*"

# 8) Cleanup test review
$deleteResp = Invoke-WebRequest -Uri ("http://localhost:8089/admin/api/reviews.php?review_id=" + $reviewId) -Method Delete -WebSession $adminSession -UseBasicParsing
$deleteJson = $deleteResp.Content | ConvertFrom-Json
if (-not $deleteJson.success) { throw "Cleanup delete failed for review $reviewId" }

# 9) Print concise results
"MARKER=$marker"
"PUBLIC_SUBMIT_URL=$submitUrl"
"PENDING_FOUND_ID=$reviewId"
"APPROVE_SUCCESS=$($approveJson.success)"
"PUBLIC_API_FOUND=$publicApiFound"
"PUBLIC_FEEDBACK_FOUND=$feedbackFound"
"ADMIN_APPROVED_FOUND=$adminFound"
"CLEANUP_DELETED=$($deleteJson.success)"
