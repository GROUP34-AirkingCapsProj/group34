# TODO: Remove Fake Data and Make SystemConfiguration Functional

## Analyze Current Status
- Frontend: `frontend/src/admin/pages/SystemConfiguration.jsx`
- Backend: Has API endpoints for users, roles, locations in `routes/api.php`

## Fake/Hardcoded Data Found:
1. `MOCK_USERS` - 6 hardcoded user accounts
2. `MOCK_RESET_REQUESTS` - 2 fake password reset requests  
3. `MOCK_ACTIVE_CODES` - 1 fake active reset code
4. `ALL_USER_ROLES` - Hardcoded role list
5. `ALL_LOCATIONS` - Hardcoded location list
6. `DEFAULT_SETTINGS` - Hardcoded default security settings
7. `DEFAULT_PROFILE` - Hardcoded default profile

## Plan: Replace Hardcoded Data with API Calls

### Step 1: Update SystemConfiguration.jsx
- [ ] Fetch users from `/api/users` endpoint
- [ ] Fetch roles from `/api/roles` endpoint  
- [ ] Fetch locations from `/api/locations` endpoint
- [ ] Fetch current user profile data
- [ ] Replace MOCK_USERS with API data
- [ ] Replace ALL_USER_ROLES with API roles data
- [ ] Replace ALL_LOCATIONS with API locations data
- [ ] Update profile tab to use localStorage user data
- [ ] Update save handlers to make API calls

### Step 2: Add API Endpoints (if needed)
- [ ] Security settings endpoints (save password policy, session config)
- [ ] Password reset requests management

## Implementation Notes:
- The backend already provides `/api/users`, `/api/roles`, `/api/locations` 
- User data should come from authenticated user in localStorage
- Security settings currently use localStorage - need to persist to backend eventually

## Status: ✅ COMPLETED

### Implementation Done:
- [x] Fetch users, roles, locations from API
- [x] Replace hardcoded user list with API data
- [x] Replace hardcoded roles with API roles
- [x] Replace hardcoded locations with API locations
- [x] Add loading and error handling
- [x] Update profile tab with localStorage user

### Changes Made to SystemConfiguration.jsx:
1. Removed: MOCK_USERS, MOCK_RESET_REQUESTS, MOCK_ACTIVE_CODES
2. Removed: ALL_USER_ROLES, ALL_LOCATIONS (hardcoded arrays)
3. Added: API fetch logic with auth token
4. Added: loading/error states for user table
5. Added: roleOptions and locationOptions from API
6. Updated: Profile loads from localStorage user

### Remaining (Future Work):
- Password reset requests API endpoint
- Security settings persistence to backend
