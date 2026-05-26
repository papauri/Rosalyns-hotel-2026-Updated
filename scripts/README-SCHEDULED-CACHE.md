# Scheduled Cache Clearing - Setup Guide

This directory contains scripts for automatic cache clearing on a schedule.

## Quick Start (Windows)

### 1. Run the Setup Script (as Administrator)
```powershell
# Right-click PowerShell > Run as Administrator
cd scripts
.\setup-windows-task-scheduler.ps1
```

This will:
- Create a Windows Task Scheduler task
- Run every 1 minute to check if cache should be cleared
- Follow the interval you set in the admin panel

### 2. Configure in Admin Panel
1. Go to **Admin Panel > Cache Management**
2. Enable "Auto-Clear" toggle
3. Select your frequency:
   - **Every 30 Seconds** - Very frequent clearing
   - **Every 1 Minute** - Frequent clearing
   - **Every 5 Minutes** - Regular clearing
   - **Every 15 Minutes** - Moderate clearing
   - **Every 30 Minutes** - Less frequent
   - **Every Hour** - Hourly clearing
   - **Every 6/12 Hours** - Periodic clearing
   - **Daily** - Once per day at specific time
   - **Weekly** - Once per week (Sunday) at specific time
   - **Custom Interval** - Set your own interval in seconds (10-86400)

4. Click "Save Schedule"

### 3. Verify It's Working
```powershell
# Run the scheduled script manually to test
php scripts/scheduled-cache-clear.php

# Monitor logs in real-time
Get-Content logs\cache-clear.log -Wait
```

## Available Intervals

### Time-Based Intervals (< 1 hour)
These intervals check the last run timestamp:
- `30sec` - Every 30 seconds
- `1min` - Every 60 seconds
- `5min` - Every 5 minutes (300 seconds)
- `15min` - Every 15 minutes (900 seconds)
- `30min` - Every 30 minutes (1800 seconds)
- `hourly` - Every hour (3600 seconds)

### Long Intervals (6+ hours)
These also use timestamp checking:
- `6hours` - Every 6 hours (21600 seconds)
- `12hours` - Every 12 hours (43200 seconds)

### Scheduled Intervals
These run at specific times:
- `daily` - Once per day at the time you specify
- `weekly` - Once per week (Sunday) at the time you specify

### Custom Interval
- `custom` - Specify any interval from 10 seconds to 86400 seconds (24 hours)

## How It Works

1. **Windows Task Scheduler** runs `scheduled-cache-clear.php` every minute
2. The script checks:
   - Is scheduling enabled? (from admin panel)
   - What interval is set? (from admin panel)
   - When was the last run? (from database)
3. If enough time has passed, it:
   - Clears the cache
   - Updates the `cache_last_run` timestamp
   - Logs the action to `logs/cache-clear.log`
4. If not enough time has passed, it exits silently

## Log Files

### Cache Clear Log
**Location:** `logs/cache-clear.log`

Contains entries like:
```
2026-02-07 10:30:00 - Scheduled cache clear executed. Interval: 1min, Files cleared: 42
2026-02-07 10:31:00 - Scheduled cache clear executed. Interval: 1min, Files cleared: 38
```

### Cron Errors Log
**Location:** `logs/cron-errors.log`

Contains PHP errors that occur during scheduled execution.

### PHP Errors Log
**Location:** `logs/php-errors.log`

General PHP errors including cache-related issues.

## Manual Testing

### Test the Scheduled Script
```powershell
php scripts/scheduled-cache-clear.php
```

### Check Current Settings
```powershell
php -r "require 'config/database.php'; $stmt = $pdo->query('SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE \"cache_%\"'); print_r($stmt->fetchAll(PDO::FETCH_KEY_PAIR));"
```

## Task Scheduler Commands

### View the Task
```powershell
Get-ScheduledTask -TaskName "Hotel Website - Cache Clearing"
```

### Run Manually
```powershell
Start-ScheduledTask -TaskName "Hotel Website - Cache Clearing"
```

### Disable the Task
```powershell
Disable-ScheduledTask -TaskName "Hotel Website - Cache Clearing"
```

### Remove the Task
```powershell
Unregister-ScheduledTask -TaskName "Hotel Website - Cache Clearing" -Confirm:$false
```

## Linux/Mac Setup (Cron)

For production Linux/Mac servers, use cron instead:

### Edit Crontab
```bash
crontab -e
```

### Add This Line (for minute-based intervals)
```bash
* * * * * /usr/bin/php /path/to/your-project/scripts/scheduled-cache-clear.php >> /dev/null 2>&1
```

This runs every minute, and the script internally decides if it should clear cache based on your admin panel settings.

### For Daily/Weekly Only
If you only use daily or weekly intervals, you can run less frequently:
```bash
# Daily at 3 AM
0 3 * * * /usr/bin/php /path/to/your-project/scripts/scheduled-cache-clear.php >> /dev/null 2>&1

# Weekly on Sunday at 3 AM
0 3 * * 0 /usr/bin/php /path/to/your-project/scripts/scheduled-cache-clear.php >> /dev/null 2>&1
```

## Troubleshooting

### Cache Not Clearing?

1. **Check if scheduling is enabled:**
   - Go to Admin Panel > Cache Management
   - Make sure "Enable Auto-Clear" toggle is ON

2. **Check Task Scheduler:**
   ```powershell
   Get-ScheduledTask -TaskName "Hotel Website - Cache Clearing" | Select-Object -Property *
   ```

3. **Check logs:**
   ```powershell
   Get-Content logs\cache-clear.log -Tail 20
   Get-Content logs\cron-errors.log -Tail 20
   Get-Content logs\php-errors.log | Select-String "cache"
   ```

4. **Manually run scheduled script:**
   ```powershell
   php scripts/scheduled-cache-clear.php
   ```

### PHP Not Found?

If you get "php: command not found":
1. Download PHP from https://windows.php.net/download/
2. Add PHP to your PATH environment variable
3. Restart PowerShell
4. Run setup script again

### Permission Denied?

Make sure you run PowerShell as Administrator:
1. Right-click PowerShell
2. Select "Run as Administrator"
3. Run the setup script

## Files in This Directory

- `scheduled-cache-clear.php` - Main cron/scheduled task script
- `setup-windows-task-scheduler.ps1` - Windows setup script (PowerShell)
- `setup-cron.sh` - Linux/Mac setup script (Bash)
- `README-SCHEDULED-CACHE.md` - This file

## Database Fields

The scheduled cache system uses these database settings:

- `cache_schedule_enabled` - 1 (enabled) or 0 (disabled)
- `cache_schedule_interval` - Interval type (e.g., "1min", "hourly", "daily")
- `cache_schedule_time` - Time for daily/weekly intervals (e.g., "03:00")
- `cache_custom_seconds` - Custom interval in seconds (for "custom" interval type)
- `cache_last_run` - Unix timestamp of last execution

## Support

For issues or questions:
1. Check the logs first
2. Run the test script
3. Review this README
4. Check admin panel settings
