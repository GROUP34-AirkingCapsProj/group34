# Backend-Frontend Connection Guide

## Setup Instructions

### 1. Backend Setup

1. **Install Dependencies**
   ```bash
   cd back-end
   composer install
   ```

2. **Configure Environment**
   - Copy `.env.example` to `.env` if it doesn't exist
   - Set up your database configuration in `.env`
   - Add `SANCTUM_STATEFUL_DOMAINS=localhost,localhost:3000,127.0.0.1,127.0.0.1:8000`

3. **Run Migrations**
   ```bash
   php artisan migrate
   ```

4. **Create Admin User** (Optional - via Tinker)
   ```bash
   php artisan tinker
   ```
   Then run:
   ```php
   \App\Models\User::create([
       'first_name' => 'Admin',
       'last_name' => 'User',
       'username' => 'admin',
       'email' => 'admin@airking.com',
       'password' => \Hash::make('admin123'),
       'role' => 'System Admin',
       'status' => 'Active'
   ]);
   ```

5. **Start Laravel Server**
   ```bash
   # For other devices on the same Wi‑Fi/LAN, DO NOT use localhost-only serve.
   # This binds the API to all interfaces so phones/other PCs can reach it.
   composer run serve-lan
   # (equivalent) php artisan serve --host=0.0.0.0 --port=8000
   ```
   Server will run on `http://localhost:8000` and `http://<YOUR_PC_LAN_IP>:8000`
   - If other devices still can’t reach it, allow inbound TCP `8000` in **Windows Firewall** (Private network).

### 2. Frontend Setup

1. **Install Dependencies** (if not already done)
   ```bash
   cd frontend
   npm install
   ```

2. **Configure API URL**
   - Create `.env` file in `frontend` directory
   - Add: `REACT_APP_API_URL=http://localhost:8000/api`
   - If you will open the frontend from another device via `http://<YOUR_PC_LAN_IP>:3000`,
     you can keep `REACT_APP_API_URL` as localhost/127.0.0.1 because the app will automatically
     swap it to `http://<YOUR_PC_LAN_IP>:8000/api` at runtime (see `frontend/src/admin/services/api.js`).

3. **Start React App**
   ```bash
   npm start
   ```
   App will run on `http://localhost:3000`
   - For phone/other devices: open `http://<YOUR_PC_LAN_IP>:3000`

## API Endpoints

### Authentication
- `POST /api/login` - Login user
- `POST /api/logout` - Logout user
- `GET /api/user` - Get current user

### Branches
- `GET /api/branches` - List branches (with pagination, search, filters)
- `POST /api/branches` - Create branch
- `GET /api/branches/{id}` - Get branch
- `PUT /api/branches/{id}` - Update branch
- `DELETE /api/branches/{id}` - Delete branch

### Warehouses
- `GET /api/warehouses` - List warehouses
- `POST /api/warehouses` - Create warehouse
- `GET /api/warehouses/{id}` - Get warehouse
- `PUT /api/warehouses/{id}` - Update warehouse
- `DELETE /api/warehouses/{id}` - Delete warehouse

### Items
- `GET /api/items` - List items
- `POST /api/items` - Create item
- `GET /api/items/{id}` - Get item
- `PUT /api/items/{id}` - Update item
- `DELETE /api/items/{id}` - Delete item

### Inventory
- `GET /api/inventory` - List inventory items
- `POST /api/inventory` - Create inventory item
- `GET /api/inventory/{id}` - Get inventory item
- `PUT /api/inventory/{id}` - Update inventory item
- `DELETE /api/inventory/{id}` - Delete inventory item

### Transactions
- `GET /api/transactions` - List transactions
- `POST /api/transactions` - Create transaction
- `GET /api/transactions/{id}` - Get transaction
- `PUT /api/transactions/{id}` - Update transaction
- `DELETE /api/transactions/{id}` - Delete transaction

### Users
- `GET /api/users` - List users
- `POST /api/users` - Create user
- `GET /api/users/{id}` - Get user
- `PUT /api/users/{id}` - Update user
- `DELETE /api/users/{id}` - Delete user

## Query Parameters

All list endpoints support:
- `search` - Search term
- `per_page` - Items per page (default: 10)
- `page` - Page number

Resource-specific filters:
- **Branches**: `status`, `region`
- **Warehouses**: `branch`, `type`, `status`
- **Items**: `category`, `brand`, `status`
- **Inventory**: `category`, `warehouse`, `status`
- **Transactions**: `type`, `status`, `date_from`, `date_to`
- **Users**: `role`, `status`, `branch`

## Authentication

All API endpoints (except `/api/login`) require authentication via Bearer token.

Token is stored in `localStorage` as `auth_token` and automatically included in requests.

## Next Steps

1. Update React components to use API calls instead of local state
2. Add error handling and loading states
3. Add form validation
4. Add toast notifications
5. Implement proper error messages

## Notes

- CORS is configured for `localhost:3000`
- CSRF protection is disabled for API routes
- Sanctum is used for API authentication
- All timestamps are automatically managed by Laravel
