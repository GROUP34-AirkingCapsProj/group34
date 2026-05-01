 # TODO: Add Mark All Read functionality to Alerts - COMPLETED

## Summary of Changes Made:

### 1. Updated `AlertsNotices.jsx`:
- Added import for `alertsAPI` from services
- Added `markingRead` state for loading indicator
- Added `handleMarkAllRead` function to call `alertsAPI.markAllRead()` 
- Added "Mark All Read" button in the page header
- Updates pending approvals after marking all as read

### 2. Updated `alerts_notices.css`:
- Added flex layout styles for `.an-page-header`
- Added `.an-page-header-content` wrapper for title/subtitle
- Added `.an-mark-all-read-btn` styles with hover/disabled states
- Added responsive styles for mobile

### 3. Updated `AdminLayout.jsx`:
- Changed import to include `alertsAPI`
- Modified unread count sync to fetch from API instead of localStorage
- Uses `alertsAPI.getUnreadCount()` with fallback to localStorage on error

## How it works:
1. Header badge shows unread alert count from API (`alertsAPI.getUnreadCount()`)
2. Alerts & Notices page has "Mark All Read" button
3. Clicking button calls API to mark all alerts as read
4. After marking, data refreshes and badge updates automatically
5. Event listeners trigger sync across all open admin pages
